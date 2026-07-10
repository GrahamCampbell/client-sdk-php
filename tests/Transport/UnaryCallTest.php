<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\ScsClient;
use Cache_client\_GetRequest;
use Cache_client\_GetResponse;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Psr7\Response;
use Momento\Cache\Errors\ItemNotFoundError;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\Channel;
use Momento\Transport\StatusCode;
use Momento\Utilities\_ErrorConverter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * @covers \Momento\Transport\AbstractCall
 * @covers \Momento\Transport\BaseStub
 * @covers \Momento\Transport\Channel
 * @covers \Momento\Transport\UnaryCall
 */
class UnaryCallTest extends TestCase
{
    private FakeGrpcHandler $handler;

    public function setUp(): void
    {
        $this->handler = new FakeGrpcHandler();
    }

    private function channel(string $endpoint = "cache.test.momentohq.com", array $channelOptions = []): Channel
    {
        return new Channel($endpoint, array_merge(["handler" => $this->handler], $channelOptions));
    }

    private function stub(string $endpoint = "cache.test.momentohq.com", array $channelOptions = []): ScsClient
    {
        return new ScsClient($endpoint, [], $this->channel($endpoint, $channelOptions));
    }

    private static function frames(string ...$messages): string
    {
        $wire = "";
        foreach ($messages as $message) {
            $wire .= "\x00" . pack("N", strlen($message)) . $message;
        }

        return $wire;
    }

    private static function okGetResponse(string $body = "my-value"): Response
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Hit);
        $reply->setCacheBody($body);

        return new Response(
            200,
            ["content-type" => "application/grpc"],
            self::frames($reply->serializeToString())
        );
    }

    private static function pendingPromiseCount(Channel $channel): int
    {
        $property = new ReflectionProperty(Channel::class, "pendingPromises");
        $property->setAccessible(true);

        return count($property->getValue($channel));
    }

    public function testUnaryHappyPathBuildsTheGrpcRequest()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $stub = $this->stub();

        $argument = new _GetRequest();
        $argument->setCacheKey("my-key");
        $call = $stub->Get($argument, ["cache" => ["my-cache"]], ["timeout" => 5000000]);
        [$response, $status] = $call->wait();

        $this->assertSame(StatusCode::OK, $status->code);
        $this->assertSame("", $status->details);
        $this->assertSame([], $status->metadata);
        $this->assertNotNull($response);
        $this->assertSame(ECacheResult::Hit, $response->getResult());
        $this->assertSame("my-value", $response->getCacheBody());

        $sent = $this->handler->lastRequest();
        $this->assertSame("POST", $sent->getMethod());
        $this->assertSame("https://cache.test.momentohq.com/cache_client.Scs/Get", (string)$sent->getUri());
        $this->assertSame("2.0", $sent->getProtocolVersion());
        $this->assertSame(["trailers"], $sent->getHeader("te"));
        $this->assertSame(["application/grpc"], $sent->getHeader("content-type"));
        $this->assertSame(["identity"], $sent->getHeader("grpc-accept-encoding"));
        $this->assertFalse($sent->hasHeader("grpc-encoding"));
        $this->assertFalse($sent->hasHeader("accept-encoding"));
        $this->assertSame(["5000000u"], $sent->getHeader("grpc-timeout"));
        $this->assertSame(["my-cache"], $sent->getHeader("cache"));
        $this->assertMatchesRegularExpression(
            "/^grpc-php-guzzle\/\d+\.\d+\.\d+/",
            $sent->getHeaderLine("user-agent")
        );

        $expectedBody = self::frames($argument->serializeToString());
        $this->assertSame($expectedBody, (string)$sent->getBody());
        $this->assertSame((string)strlen($expectedBody), $sent->getHeaderLine("content-length"));

        $options = $this->handler->lastOptions();
        $this->assertSame(Multiplexing::REQUIRE_WAIT, $options["multiplex"]);
        $this->assertSame(5.0005, $options["timeout"]);
        $this->assertSame(5.0, $options["connect_timeout"]);
        $this->assertFalse($options["decode_content"]);
        $this->assertTrue($options["verify"]);
        $this->assertIsCallable($options["on_trailers"]);
        $this->assertArrayNotHasKey("sink", $options);
    }

    public function testCallerMetadataCannotReplaceTransportOwnedHeaders()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $stub = $this->stub();

        $stub->Get(new _GetRequest(), [
            "te" => ["compress"],
            "content-length" => ["1"],
            "grpc-timeout" => ["1u"],
            "host" => ["evil.example"],
            "proxy-authorization" => ["Basic cHJveHk="],
            "authorization" => ["Bearer caller-token"],
        ], ["timeout" => 5000000])->wait();

        $sent = $this->handler->lastRequest();
        $this->assertSame(["trailers"], $sent->getHeader("te"));
        $this->assertSame(["5000000u"], $sent->getHeader("grpc-timeout"));
        $this->assertNotSame("1", $sent->getHeaderLine("content-length"));
        $this->assertSame("cache.test.momentohq.com", $sent->getHeaderLine("host"));
        $this->assertFalse($sent->hasHeader("proxy-authorization"));
        $this->assertSame(["Bearer caller-token"], $sent->getHeader("authorization"));
    }

    public function testCallWithoutTimeoutOptionOmitsDeadlineEntirely()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $this->stub()->Get(new _GetRequest(), [], [])->wait();

        $this->assertFalse($this->handler->lastRequest()->hasHeader("grpc-timeout"));
        $options = $this->handler->lastOptions();
        $this->assertArrayNotHasKey("timeout", $options);
        $this->assertSame(5.0, $options["connect_timeout"]);
    }

    public function testCallerMetadataCannotInjectGrpcTimeoutWithoutALocalDeadline()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $this->stub()->Get(new _GetRequest(), ["grpc-timeout" => ["1u"]], [])->wait();

        $this->assertFalse($this->handler->lastRequest()->hasHeader("grpc-timeout"));
        $this->assertArrayNotHasKey("timeout", $this->handler->lastOptions());
    }

    public function testEmptyMetadataValueListSendsNoHeader()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $this->stub()->Get(new _GetRequest(), ["x-empty" => []], [])->wait();

        $this->assertFalse($this->handler->lastRequest()->hasHeader("x-empty"));
    }

    public function testEndpointWithExplicitPortIsPreserved()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $this->stub("localhost:4443")->Get(new _GetRequest(), [], [])->wait();
        $this->assertSame(
            "https://localhost:4443/cache_client.Scs/Get",
            (string)$this->handler->lastRequest()->getUri()
        );
    }

    public function testGetMetadataExposesFilteredResponseHeaders()
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Miss);
        $this->handler->respond(
            new Response(200, [
                "content-type" => "application/grpc",
                "x-custom" => "abc",
            ], self::frames($reply->serializeToString())),
            ["grpc-status" => ["0"]]
        );

        $call = $this->stub()->Get(new _GetRequest(), [], []);
        $call->wait();
        $metadata = $call->getMetadata();
        $this->assertSame(["abc"], $metadata["x-custom"]);
        $this->assertArrayNotHasKey("content-type", $metadata);
    }

    public function testTrailersOnlyNotFoundFlowsThroughErrorConverter()
    {
        $this->handler->respond(
            new Response(200, [
                "content-type" => "application/grpc",
                "grpc-status" => "5",
                "grpc-message" => "item%20not%20found",
                "err" => "item_not_found",
            ], ""),
            []
        );

        $call = $this->stub()->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000]);
        [$response, $status] = $call->wait();

        $this->assertNull($response);
        $this->assertSame(StatusCode::NOT_FOUND, $status->code);
        $this->assertSame("item not found", $status->details);
        $this->assertSame(["item_not_found"], $status->metadata["err"]);
        $this->assertSame([], $call->getMetadata());

        $error = _ErrorConverter::convert($status, $call->getMetadata());
        $this->assertInstanceOf(ItemNotFoundError::class, $error);
    }

    public function testTrailersOnlyResponseWithBodyIsInternal()
    {
        $this->handler->respond(
            new Response(200, ["grpc-status" => "0"], "\x00\x00\x00\x00\x00"),
            []
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertSame("Trailers-only response carried body data", $status->details);
    }

    public function testMissingGrpcStatusOnHttp200IsUnknown()
    {
        $this->handler->respond(new Response(200, ["content-type" => "application/grpc"], ""), []);
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
        $this->assertSame("Missing grpc-status in response", $status->details);
    }

    public static function httpStatusSynthesisProvider(): array
    {
        return [
            "400 Bad Request" => [400, StatusCode::INTERNAL],
            "401 Unauthorized" => [401, StatusCode::UNAUTHENTICATED],
            "403 Forbidden" => [403, StatusCode::PERMISSION_DENIED],
            "404 Not Found" => [404, StatusCode::UNIMPLEMENTED],
            "429 Too Many Requests" => [429, StatusCode::UNAVAILABLE],
            "502 Bad Gateway" => [502, StatusCode::UNAVAILABLE],
            "503 Service Unavailable" => [503, StatusCode::UNAVAILABLE],
            "504 Gateway Timeout" => [504, StatusCode::UNAVAILABLE],
            "500 unmapped" => [500, StatusCode::UNKNOWN],
            "302 unmapped" => [302, StatusCode::UNKNOWN],
        ];
    }

    /**
     * @dataProvider httpStatusSynthesisProvider
     */
    public function testNonOkHttpStatusWithoutGrpcStatusIsSynthesized(int $httpStatus, int $grpcCode)
    {
        $this->handler->respond(new Response($httpStatus, [], "<html>proxy error</html>"), []);
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame($grpcCode, $status->code);
        $this->assertSame(
            sprintf("Received HTTP status %d with no grpc-status", $httpStatus),
            $status->details
        );
    }

    public function testParseableGrpcStatusWinsOverNonOkHttpStatus()
    {
        $this->handler->respond(
            new Response(503, ["grpc-status" => "8", "grpc-message" => "throttled"], ""),
            []
        );
        $call = $this->stub()->Get(new _GetRequest(), [], []);
        [$response, $status] = $call->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::RESOURCE_EXHAUSTED, $status->code);
        $this->assertSame("throttled", $status->details);
        $this->assertSame([], $call->getMetadata());
    }

    public function testGrpcStatusInTrailerBlockWinsOnNonOkHttpStatus()
    {
        $this->handler->respond(
            new Response(503, [], ""),
            ["grpc-status" => ["14"], "grpc-message" => ["go away"]]
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::UNAVAILABLE, $status->code);
        $this->assertSame("go away", $status->details);
    }

    public function testNonOkHttpStatusWithOkGrpcStatusIsUnaryCardinalityViolation()
    {
        $this->handler->respond(new Response(503, ["grpc-status" => "0"], ""), []);
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::UNIMPLEMENTED, $status->code);
        $this->assertSame("Unary call completed without a response message", $status->details);
    }

    public function testUnaryWithZeroMessagesAndOkStatusIsUnimplemented()
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], ""),
            ["grpc-status" => ["0"]]
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::UNIMPLEMENTED, $status->code);
        $this->assertSame("Unary call completed without a response message", $status->details);
    }

    public function testUnaryWithTwoMessagesAndOkStatusIsUnimplemented()
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Miss);
        $serialized = $reply->serializeToString();
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], self::frames($serialized, $serialized)),
            ["grpc-status" => ["0"]]
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::UNIMPLEMENTED, $status->code);
        $this->assertSame("Unary call received multiple response messages", $status->details);
    }

    public function testUnaryWithNonOkStatusSkipsCardinalityAndDiscardsPayload()
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Miss);
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], self::frames($reply->serializeToString())),
            ["grpc-status" => ["13"], "grpc-message" => ["boom"]]
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertSame("boom", $status->details);
    }

    public function testUndecodableUnaryResponseIsInternal()
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], self::frames("\xFF")),
            ["grpc-status" => ["0"]]
        );
        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], [])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertStringStartsWith("Error parsing response proto: ", $status->details);
    }

    public function testDeadlineExpiryMapsToDeadlineExceeded()
    {
        $this->handler->fail(static function (RequestInterface $request) {
            return new ConnectException(
                "cURL error 28: Operation timed out after 5000 milliseconds",
                $request,
                null,
                ["errno" => 28]
            );
        });

        [$response, $status] = $this->stub()->Get(new _GetRequest(), [], ["timeout" => 5000000])->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::DEADLINE_EXCEEDED, $status->code);
        $this->assertSame("cURL error 28: Operation timed out after 5000 milliseconds", $status->details);
    }

    public function testFulfilledUnaryDropsSettledPromiseFromChannel()
    {
        $this->handler->respond(self::okGetResponse(), ["grpc-status" => ["0"]]);
        $channel = $this->channel();
        $stub = new ScsClient("cache.test.momentohq.com", [], $channel);

        $stub->Get(new _GetRequest(), [], [])->wait();

        $this->assertSame(0, self::pendingPromiseCount($channel));
    }

    public function testRejectedUnaryDropsSettledPromiseFromChannel()
    {
        $this->handler->fail(static function (RequestInterface $request) {
            return new CancellationException("cancelled");
        });
        $channel = $this->channel();
        $stub = new ScsClient("cache.test.momentohq.com", [], $channel);

        [$response, $status] = $stub->Get(new _GetRequest(), [], [])->wait();

        $this->assertNull($response);
        $this->assertSame(StatusCode::CANCELLED, $status->code);
        $this->assertSame(0, self::pendingPromiseCount($channel));
    }

    public function testClosedChannelRejectsNewCallsWithoutInvokingTheHandler()
    {
        $channel = new Channel("cache.test.momentohq.com", ["handler" => $this->handler]);
        $stub = new ScsClient("cache.test.momentohq.com", [], $channel);
        $channel->close();

        try {
            $stub->Get(new _GetRequest(), [], []);
            $this->fail("Expected RuntimeException");
        } catch (RuntimeException $e) {
            $this->assertSame("The channel has been closed", $e->getMessage());
        }
        $this->assertSame([], $this->handler->invocations);
    }

    public function testCloseCancelsInFlightCallsToCancelled()
    {
        $this->handler->respondPending();
        $channel = new Channel("cache.test.momentohq.com", ["handler" => $this->handler]);
        $stub = new ScsClient("cache.test.momentohq.com", [], $channel);
        $call = $stub->Get(new _GetRequest(), [], ["timeout" => 5000000]);

        $channel->close();
        $channel->close();

        [$response, $status] = $call->wait();
        $this->assertNull($response);
        $this->assertSame(StatusCode::CANCELLED, $status->code);
        $this->assertSame("Cancelled", $status->details);
    }
}
