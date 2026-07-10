<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\ScsClient;
use Cache_client\_GetBatchRequest;
use Cache_client\_GetRequest;
use Cache_client\_GetResponse;
use Exception;
use GuzzleHttp\Psr7\Response;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\Channel;
use Momento\Transport\StatusCode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Momento\Transport\AbstractCall
 * @covers \Momento\Transport\BaseStub
 * @covers \Momento\Transport\Channel
 * @covers \Momento\Transport\ServerStreamingCall
 */
class ServerStreamingCallTest extends TestCase
{
    private FakeGrpcHandler $handler;

    public function setUp(): void
    {
        $this->handler = new FakeGrpcHandler();
    }

    private function stub(): ScsClient
    {
        $channel = new Channel("cache.test.momentohq.com", ["handler" => $this->handler]);

        return new ScsClient("cache.test.momentohq.com", [], $channel);
    }

    private static function batchRequest(int $count): _GetBatchRequest
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $item = new _GetRequest();
            $item->setCacheKey("key-{$i}");
            $items[] = $item;
        }
        $request = new _GetBatchRequest();
        $request->setItems($items);

        return $request;
    }

    private static function hitFrame(string $body): string
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Hit);
        $reply->setCacheBody($body);
        $serialized = $reply->serializeToString();

        return "\x00" . pack("N", strlen($serialized)) . $serialized;
    }

    public function testStreamYieldsEveryMessageInFrameOrderThenOkStatus()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::hitFrame("v0") . self::hitFrame("v1") . self::hitFrame("v2")
            ),
            ["grpc-status" => ["0"], "x-trailing" => ["t"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(3), ["cache" => ["c"]], ["timeout" => 5000000]);

        $bodies = [];
        foreach ($call->responses() as $reply) {
            $bodies[] = $reply->getCacheBody();
        }
        $this->assertSame(["v0", "v1", "v2"], $bodies);

        $status = $call->getStatus();
        $this->assertSame(StatusCode::OK, $status->code);
        $this->assertSame(["x-trailing" => ["t"]], $status->metadata);
    }

    public function testEmptyStreamWithOkStatusIsValid()
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], ""),
            ["grpc-status" => ["0"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(1), [], []);
        $this->assertSame([], iterator_to_array($call->responses(), false));
        $this->assertSame(StatusCode::OK, $call->getStatus()->code);
    }

    public function testMissingGrpcStatusYieldsReceivedMessagesAndUnknownStatus()
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], self::hitFrame("v0")),
            []
        );

        $call = $this->stub()->GetBatch(self::batchRequest(1), [], []);
        $this->assertCount(1, iterator_to_array($call->responses(), false));
        $status = $call->getStatus();
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
        $this->assertSame("Missing grpc-status in response", $status->details);
    }

    public function testMessagesAreYieldedEvenWhenTerminalStatusIsNonOk()
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], self::hitFrame("v0")),
            ["grpc-status" => ["13"], "grpc-message" => ["mid-stream failure"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(2), [], []);
        $this->assertCount(1, iterator_to_array($call->responses(), false));
        $status = $call->getStatus();
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertSame("mid-stream failure", $status->details);
    }

    public function testTruncatedFrameSurfacesAsInternalStatusAfterYieldedMessages()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::hitFrame("v0") . "\x00\x00\x00"
            ),
            ["grpc-status" => ["0"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(2), [], []);
        $bodies = [];
        foreach ($call->responses() as $reply) {
            $bodies[] = $reply->getCacheBody();
        }
        $this->assertSame(["v0"], $bodies);

        $status = $call->getStatus();
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertSame("Truncated gRPC frame at end of response body", $status->details);
    }

    public function testNonOkStatusSurvivesUndecodableBody()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::hitFrame("v0") . "\x00\x00\x00"
            ),
            ["grpc-status" => ["13"], "grpc-message" => ["server says no"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(2), [], []);
        $this->assertCount(1, iterator_to_array($call->responses(), false));
        $status = $call->getStatus();
        $this->assertSame(StatusCode::INTERNAL, $status->code);
        $this->assertSame("server says no", $status->details);
    }

    public function testUndecodableStreamMessageThrowsOutOfTheGeneratorMidIteration()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::hitFrame("v0") . "\x00" . pack("N", 1) . "\xFF"
            ),
            ["grpc-status" => ["0"]]
        );

        $call = $this->stub()->GetBatch(self::batchRequest(2), [], []);
        $generator = $call->responses();
        $this->assertSame("v0", $generator->current()->getCacheBody());
        $this->expectException(Exception::class);
        $generator->next();
    }
}
