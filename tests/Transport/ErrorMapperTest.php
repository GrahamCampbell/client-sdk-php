<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use GuzzleHttp\ClientInterface;
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
 * The 7.x and 8.x groups are line-guarded: only the installed Guzzle line's
 * rows execute (the other line's rows are skipped), so exercising both
 * taxonomies requires two installs resolved to the two majors.
 *
 * @covers \Momento\Transport\ErrorMapper
 */
class ErrorMapperTest extends TestCase
{
    private function requireGuzzle(int $major): void
    {
        if (ClientInterface::MAJOR_VERSION !== $major) {
            $this->markTestSkipped(sprintf(
                "Requires the Guzzle %d line; Guzzle %d is installed",
                $major,
                ClientInterface::MAJOR_VERSION
            ));
        }
    }

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

    private function responseCarryingException(ResponseInterface $response): RequestException
    {
        if (ClientInterface::MAJOR_VERSION === 7) {
            return new RequestException("cURL error 92: stream reset", $this->request(), $response, null, ["errno" => 92]);
        }

        return new ResponseException("stream reset mid-transfer", $this->request(), $response);
    }

    public static function guzzle7ConnectExceptionProvider(): array
    {
        return [
            "errno 28 without a deadline is UNAVAILABLE" => [28, false, StatusCode::UNAVAILABLE],
            "errno 6 resolve failure" => [6, true, StatusCode::UNAVAILABLE],
            "errno 7 connect refused" => [7, true, StatusCode::UNAVAILABLE],
            "errno 35 tls handshake" => [35, true, StatusCode::UNAVAILABLE],
            "errno 52 got nothing" => [52, true, StatusCode::UNAVAILABLE],
        ];
    }

    /**
     * @dataProvider guzzle7ConnectExceptionProvider
     */
    public function testGuzzle7ConnectExceptionMapping(int $errno, bool $hadDeadline, int $expected)
    {
        $this->requireGuzzle(7);
        $message = "cURL error {$errno}: transfer failed";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => $errno]);
        $status = ErrorMapper::map($reason, $hadDeadline, new CallState());
        $this->assertSame($expected, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testGuzzle7OperationPhaseTimeoutWithDeadlineIsDeadlineExceeded()
    {
        $this->requireGuzzle(7);
        $message = "cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(null, 5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testGuzzle7ConnectPhaseTimeoutUnderBindingDeadlineIsDeadlineExceeded()
    {
        $this->requireGuzzle(7);
        $message = "cURL error 28: Connection timed out after 1100 milliseconds";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(null, 1100, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public static function guzzle7ConnectPhaseTimeoutWordingProvider(): array
    {
        return [
            "connection timed out" => ["cURL error 28: Connection timed out after 5001 milliseconds"],
            "connection timeout" => ["cURL error 28: Connection timeout after 5001 ms"],
            "connection time-out" => ["cURL error 28: Connection time-out"],
            "resolving timed out" => ["cURL error 28: Resolving timed out after 5001 milliseconds"],
            "name lookup timed out" => ["cURL error 28: name lookup timed out"],
            "proxy connect timeout" => ["cURL error 28: Proxy CONNECT aborted due to timeout"],
            "ssl connection timeout" => ["cURL error 28: SSL connection timeout"],
        ];
    }

    /**
     * @dataProvider guzzle7ConnectPhaseTimeoutWordingProvider
     */
    public function testGuzzle7ConnectPhaseTimeoutBeyondTheDeadlineWindowIsUnavailable(string $message)
    {
        $this->requireGuzzle(7);
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(null, 30000, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testGuzzle7ConnectPhaseTimeoutAtTheTimerTieIsDeadlineExceeded()
    {
        $this->requireGuzzle(7);
        $message = "cURL error 28: Connection timed out after 5000 milliseconds";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(null, 5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testGuzzle7ConnectExceptionWithoutErrnoIsUnavailable()
    {
        $this->requireGuzzle(7);
        $status = ErrorMapper::map(new ConnectException("connection refused", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public static function guzzle7RequestExceptionProvider(): array
    {
        return [
            "errno 16 h2 protocol error" => [16, StatusCode::INTERNAL],
            "errno 92 h2 stream error" => [92, StatusCode::INTERNAL],
            "errno 18 partial file" => [18, StatusCode::UNAVAILABLE],
            "errno 55 send error" => [55, StatusCode::UNAVAILABLE],
            "errno 56 recv error" => [56, StatusCode::UNAVAILABLE],
        ];
    }

    /**
     * @dataProvider guzzle7RequestExceptionProvider
     */
    public function testGuzzle7RequestExceptionErrnoMapping(int $errno, int $expected)
    {
        $this->requireGuzzle(7);
        $message = "cURL error {$errno}: transfer failed";
        $reason = new RequestException($message, $this->request(), new Response(200), null, ["errno" => $errno]);
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame($expected, $status->code);
        $this->assertSame($message, $status->details);
    }

    public static function guzzle7H2ResetDetailProvider(): array
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
     * @dataProvider guzzle7H2ResetDetailProvider
     */
    public function testGuzzle7H2ResetDetailMapping(string $message, int $expected)
    {
        $this->requireGuzzle(7);
        $reason = new RequestException($message, $this->request(), new Response(200), null, ["errno" => 92]);
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame($expected, $status->code);
    }

    public function testGuzzle7RequestExceptionWithoutErrnoOrResponseIsUnknown()
    {
        $this->requireGuzzle(7);
        $status = ErrorMapper::map(new RequestException("something odd", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
        $this->assertSame("something odd", $status->details);
    }

    public function testGuzzle8ConnectTimeoutBeyondTheDeadlineWindowIsUnavailable()
    {
        $this->requireGuzzle(8);
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5001 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 30000, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testGuzzle8ConnectTimeoutUnderBindingDeadlineIsDeadlineExceeded()
    {
        $this->requireGuzzle(8);
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 1100 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 1100, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testGuzzle8ConnectTimeoutWithoutDeadlineIsUnavailable()
    {
        $this->requireGuzzle(8);
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5001 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, false, $this->state(28, null, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testGuzzle8ConnectTimeoutAtTheTimerTieIsDeadlineExceeded()
    {
        $this->requireGuzzle(8);
        $reason = new ConnectTimeoutException(
            "cURL error 28: Connection timed out after 5000 milliseconds",
            $this->request()
        );
        $status = ErrorMapper::map($reason, true, $this->state(28, 5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testGuzzle8ResponseTimeoutIsDeadlineExceeded()
    {
        $this->requireGuzzle(8);
        $message = "cURL error 28: Operation timed out after 5000 milliseconds";
        $reason = new ResponseTimeoutException($message, $this->request(), new Response(200));
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testGuzzle8ResponseTimeoutWithGrpcStatusHeaderHonorsR1()
    {
        $this->requireGuzzle(8);
        $reason = new ResponseTimeoutException(
            "cURL error 28: Operation timed out",
            $this->request(),
            new Response(200, ["grpc-status" => "8"])
        );
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::RESOURCE_EXHAUSTED, $status->code);
    }

    public function testGuzzle8NetworkTimeoutWithDeadlineIsDeadlineExceeded()
    {
        $this->requireGuzzle(8);
        $reason = new NetworkTimeoutException("cURL error 28: Operation timed out", $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(28));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testGuzzle8NetworkTimeoutWithoutDeadlineIsUnavailable()
    {
        $this->requireGuzzle(8);
        $reason = new NetworkTimeoutException("cURL error 28: Operation timed out", $this->request());
        $status = ErrorMapper::map($reason, false, $this->state(28));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testGuzzle8ConnectExceptionIsUnavailable()
    {
        $this->requireGuzzle(8);
        $reason = new ConnectException("cURL error 7: Failed to connect", $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(7));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public static function guzzle8NetworkErrnoProvider(): array
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
     * @dataProvider guzzle8NetworkErrnoProvider
     */
    public function testGuzzle8NetworkExceptionErrnoMapping(?int $errno, int $expected)
    {
        $this->requireGuzzle(8);
        $reason = new NetworkException("cURL error: connection broke", $this->request());
        $status = ErrorMapper::map($reason, true, $errno === null ? new CallState() : $this->state($errno));
        $this->assertSame($expected, $status->code);
    }

    public static function guzzle8H2ResetDetailProvider(): array
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
     * @dataProvider guzzle8H2ResetDetailProvider
     */
    public function testGuzzle8H2ResetDetailMapping(string $message, int $expected)
    {
        $this->requireGuzzle(8);
        $reason = new NetworkException($message, $this->request());
        $status = ErrorMapper::map($reason, true, $this->state(92));
        $this->assertSame($expected, $status->code);
    }

    public static function guzzle8ResponseTransferErrnoProvider(): array
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
     * @dataProvider guzzle8ResponseTransferErrnoProvider
     */
    public function testGuzzle8ResponseTransferExceptionErrnoMapping(?int $errno, int $expected)
    {
        $this->requireGuzzle(8);
        $reason = new ResponseTransferException(
            "cURL error: body truncated",
            $this->request(),
            new Response(200)
        );
        $status = ErrorMapper::map($reason, true, $errno === null ? new CallState() : $this->state($errno));
        $this->assertSame($expected, $status->code);
    }

    public function testGuzzle8ResponseExceptionIsInternal()
    {
        $this->requireGuzzle(8);
        $reason = new ResponseException(
            "An error was encountered during the on_trailers event",
            $this->request(),
            new Response(200)
        );
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame(StatusCode::INTERNAL, $status->code);
    }

    public function testGuzzle8PlainRequestExceptionIsUnknown()
    {
        $this->requireGuzzle(8);
        $status = ErrorMapper::map(new RequestException("something odd", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
    }

    public function testGuzzle8HandlerClosedExceptionPropagates()
    {
        $this->requireGuzzle(8);
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
