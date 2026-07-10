<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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

    private function state(?int $deadlineMilliseconds = null, ?int $connectTimeoutMilliseconds = null): CallState
    {
        $state = new CallState();
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
        return new RequestException("cURL error 92: stream reset", $this->request(), $response, null, ["errno" => 92]);
    }

    public static function connectExceptionProvider(): array
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
     * @dataProvider connectExceptionProvider
     */
    public function testConnectExceptionMapping(int $errno, bool $hadDeadline, int $expected)
    {
        $message = "cURL error {$errno}: transfer failed";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => $errno]);
        $status = ErrorMapper::map($reason, $hadDeadline, new CallState());
        $this->assertSame($expected, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testOperationPhaseTimeoutWithDeadlineIsDeadlineExceeded()
    {
        $message = "cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
        $this->assertSame($message, $status->details);
    }

    public function testConnectPhaseTimeoutUnderBindingDeadlineIsDeadlineExceeded()
    {
        $message = "cURL error 28: Connection timed out after 1100 milliseconds";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(1100, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public static function connectPhaseTimeoutWordingProvider(): array
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
     * @dataProvider connectPhaseTimeoutWordingProvider
     */
    public function testConnectPhaseTimeoutBeyondTheDeadlineWindowIsUnavailable(string $message)
    {
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(30000, 5000));
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public function testConnectPhaseTimeoutAtTheTimerTieIsDeadlineExceeded()
    {
        $message = "cURL error 28: Connection timed out after 5000 milliseconds";
        $reason = new ConnectException($message, $this->request(), null, ["errno" => 28]);
        $status = ErrorMapper::map($reason, true, $this->state(5000, 5000));
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
    }

    public function testConnectExceptionWithoutErrnoIsUnavailable()
    {
        $status = ErrorMapper::map(new ConnectException("connection refused", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
    }

    public static function requestExceptionProvider(): array
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
     * @dataProvider requestExceptionProvider
     */
    public function testRequestExceptionErrnoMapping(int $errno, int $expected)
    {
        $message = "cURL error {$errno}: transfer failed";
        $reason = new RequestException($message, $this->request(), new Response(200), null, ["errno" => $errno]);
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame($expected, $status->code);
        $this->assertSame($message, $status->details);
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
        $reason = new RequestException($message, $this->request(), new Response(200), null, ["errno" => 92]);
        $status = ErrorMapper::map($reason, true, new CallState());
        $this->assertSame($expected, $status->code);
    }

    public function testRequestExceptionWithoutErrnoOrResponseIsUnknown()
    {
        $status = ErrorMapper::map(new RequestException("something odd", $this->request()), true, new CallState());
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
        $this->assertSame("something odd", $status->details);
    }

}
