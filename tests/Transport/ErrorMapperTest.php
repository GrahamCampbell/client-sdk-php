<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use LogicException;
use Momento\Transport\CallState;
use Momento\Transport\ErrorMapper;
use Momento\Transport\StatusCode;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \Momento\Transport\ErrorMapper
 */
class ErrorMapperTest extends TestCase
{
    private function request(): Request
    {
        return new Request("POST", "https://cache.test.momentohq.com/cache_client.Scs/Get");
    }

    /**
     * @param mixed $handlerErrorData
     */
    private function state($handlerErrorData = null, ?int $deadlineMilliseconds = null, ?int $connectTimeoutMilliseconds = null): CallState
    {
        $state = new CallState();
        $state->stats = new TransferStats($this->request(), null, 0.01, $handlerErrorData, []);
        $state->deadlineMilliseconds = $deadlineMilliseconds;
        $state->connectTimeoutMilliseconds = $connectTimeoutMilliseconds;

        return $state;
    }

    public function testPromiseCancellationMapsToCancelled()
    {
        $status = ErrorMapper::map(new CancellationException("Promise has been cancelled"), true, new CallState());
        $this->assertSame(StatusCode::CANCELLED, $status->code);
        $this->assertSame("Cancelled", $status->details);
        $this->assertSame([], $status->metadata);
    }

    public function testNonGuzzleThrowablePropagates()
    {
        $this->expectException(LogicException::class);
        ErrorMapper::map(new LogicException("transport bug"), false, new CallState());
    }

    public function testGrpcStatusInAttachedResponseHeadersWins()
    {
        $response = new Response(200, [
            "grpc-status" => "7",
            "grpc-message" => "access%20denied",
            "err" => "denied",
        ]);
        $status = ErrorMapper::map($this->responseCarryingException($response), true, new CallState());
        $this->assertSame(StatusCode::PERMISSION_DENIED, $status->code);
        $this->assertSame("access denied", $status->details);
        $this->assertSame(["denied"], $status->metadata["err"]);
    }

    private function responseCarryingException(ResponseInterface $response): ResponseException
    {
        return new ResponseException("stream reset mid-transfer", $this->request(), $response);
    }

    public function testConnectTimeoutBeyondTheDeadlineWindowIsUnavailable()
    {
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5001 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 30000, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testConnectTimeoutUnderBindingDeadlineIsDeadlineExceeded()
    {
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 1100 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 1100, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testConnectTimeoutWithoutDeadlineIsUnavailable()
    {
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5001 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, false, $this->state(28, null, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testConnectTimeoutAtTheTimerTieIsDeadlineExceeded()
    {
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5000 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testResponseTimeoutIsDeadlineExceeded()
    {
        $message = "cURL error 28: Operation timed out after 5000 milliseconds";
        $reason = new ResponseTimeoutException($message, $this->request(), new Response(200));
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testResponseTimeoutWithGrpcStatusHeaderHonorsR1()
    {
        $reason = new ResponseTimeoutException(
            "cURL error 28: Operation timed out",
            $this->request(),
            new Response(200, ["grpc-status" => "8"])
        );
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::RESOURCE_EXHAUSTED, $status->code);
    }

    public function testNetworkTimeoutWithDeadlineIsDeadlineExceeded()
    {
        $reason = new NetworkTimeoutException("cURL error 28: Operation timed out", $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testNetworkTimeoutWithoutDeadlineIsUnavailable()
    {
        $reason = new NetworkTimeoutException("cURL error 28: Operation timed out", $this->request());
        $status = ErrorMapper::map($reason, false, $this->state(28));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testConnectExceptionIsUnavailable()
    {
        $reason = new ConnectException("cURL error 7: Failed to connect", $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(7));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public static function networkErrnoProvider(): array
    {
        return [
            "errno 16 h2 protocol error" => [16, StatusCode::INTERNAL],
            "errno 92 h2 stream error" => [92, StatusCode::INTERNAL],
            "errno 52 got nothing" => [52, StatusCode::UNAVAILABLE],
            "errno 55 send error" => [55, StatusCode::UNAVAILABLE],
            "errno 56 recv error" => [56, StatusCode::UNAVAILABLE],
            "errno unavailable" => [null, StatusCode::UNAVAILABLE],
        ];
    }

    /**
     * @dataProvider networkErrnoProvider
     */
    public function testNetworkExceptionErrnoMapping(?int $errno, int $expected)
    {
        $reason = new NetworkException("cURL error: connection broke", $this->request());
        $status = ErrorMapper::map($reason, true, $errno === null ? new CallState() : $this->state($errno));
        $this->assertSame($expected, $status->code);
    }

    public static function h2ResetDetailProvider(): array
    {
        return [
            "8.19+ CANCEL" => ["cURL error 92: HTTP/2 stream 1 reset by server (error 0x8 CANCEL)", StatusCode::CANCELLED],
            "8.19+ ENHANCE_YOUR_CALM" => ["cURL error 92: HTTP/2 stream 1 reset by server (error 0xb ENHANCE_YOUR_CALM)", StatusCode::RESOURCE_EXHAUSTED],
            "8.19+ INADEQUATE_SECURITY" => ["cURL error 92: HTTP/2 stream 1 reset by server (error 0xc INADEQUATE_SECURITY)", StatusCode::PERMISSION_DENIED],
            "bare hex code without a name" => ["cURL error 92: HTTP/2 stream 1 reset by server (error 0x8)", StatusCode::CANCELLED],
            "8.14-8.18 CANCEL" => ["cURL error 92: HTTP/2 stream 3 was not closed cleanly: CANCEL (err 8)", StatusCode::CANCELLED],
            "8.14-8.18 ENHANCE_YOUR_CALM" => ["cURL error 92: HTTP/2 stream 3 was not closed cleanly: ENHANCE_YOUR_CALM (err 11)", StatusCode::RESOURCE_EXHAUSTED],
            "8.14-8.18 INADEQUATE_SECURITY" => ["cURL error 92: HTTP/2 stream 3 was not closed cleanly: INADEQUATE_SECURITY (err 12)", StatusCode::PERMISSION_DENIED],
            "unparseable reset detail" => ["cURL error 92: HTTP/2 stream 1 went away", StatusCode::INTERNAL],
        ];
    }

    /**
     * @dataProvider h2ResetDetailProvider
     */
    public function testH2ResetDetailMapping(string $message, int $expected)
    {
        $reason = new NetworkException($message, $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(92));
        $this->assertSame($expected, $status->code);
    }

    public static function responseTransferErrnoProvider(): array
    {
        return [
            "errno 16" => [16, StatusCode::INTERNAL],
            "errno 92" => [92, StatusCode::INTERNAL],
            "errno 18" => [18, StatusCode::UNAVAILABLE],
            "errno 52" => [52, StatusCode::UNAVAILABLE],
            "errno 55" => [55, StatusCode::UNAVAILABLE],
            "errno 56" => [56, StatusCode::UNAVAILABLE],
            "errno unavailable" => [null, StatusCode::UNAVAILABLE],
        ];
    }

    /**
     * @dataProvider responseTransferErrnoProvider
     */
    public function testResponseTransferExceptionErrnoMapping(?int $errno, int $expected)
    {
        $reason = new ResponseTransferException(
            "cURL error: body truncated",
            $this->request(),
            new Response(200)
        );
        $status = ErrorMapper::map($reason, true, $errno === null ? new CallState() : $this->state($errno));
        $this->assertSame($expected, $status->code);
    }

    public function testResponseExceptionIsInternal()
    {
        $reason = new ResponseException(
            "An error was encountered during the on_trailers event",
            $this->request(),
            new Response(200)
        );
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame(StatusCode::INTERNAL, $status->code);
    }

    public function testPlainRequestExceptionIsUnknown()
    {
        $status = ErrorMapper::map(new RequestException("something odd", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
    }

    public function testHandlerClosedExceptionPropagates()
    {
        $this->expectException(HandlerClosedException::class);
        ErrorMapper::map(
            new HandlerClosedException(
                "The cURL multi handler was closed before the transfer completed.",
                $this->request()
            ),
            true,
            new CallState()
        );
    }
}
