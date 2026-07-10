<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\_GetResponse;
use Cache_client\_SetResponse;
use GuzzleHttp\Psr7\Response;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheOperationTypes\GetBatchError;
use Momento\Cache\CacheOperationTypes\GetBatchSuccess;
use Momento\Cache\CacheOperationTypes\GetHit;
use Momento\Cache\CacheOperationTypes\GetMiss;
use Momento\Cache\CacheOperationTypes\SetBatchError;
use Momento\Cache\CacheOperationTypes\SetBatchSuccess;
use Momento\Cache\Errors\AuthenticationError;
use Momento\Cache\Errors\InternalServerError;
use Momento\Cache\Errors\LimitExceededError;
use Momento\Cache\Errors\UnknownServiceError;
use Momento\Cache\Internal\ScsDataClient;
use Momento\Config\Configurations;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\TransportRequirements;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Batch calls ride the server-streaming transport, so their terminal status
 * must convert into SDK errors instead of leaking empty/partial successes.
 *
 * @covers \Momento\Cache\Internal\ScsDataClient
 */
class ScsDataClientBatchTest extends TestCase
{
    private const TEST_API_KEY = "eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyQHRlc3QuY29tIiwiY3AiOiJjb250cm9sLnRlc3QuY29tIiwiYyI6ImNhY2hlLnRlc3QuY29tIn0.c0Z8Ipetl6raCNHSHs7Mpq3qtWkFy4aLvGhIFR4CoR0OnBdGbdjN-4E58bAabrSGhRA8-B2PHzgDd4JF4clAzg";

    private FakeGrpcHandler $handler;
    private ScsDataClient $client;

    public function setUp(): void
    {
        try {
            TransportRequirements::assertSupported();
        } catch (RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $this->handler = new FakeGrpcHandler();
        $this->client = new ScsDataClient(
            Configurations\Laptop::latest(),
            new StringMomentoTokenProvider(self::TEST_API_KEY),
            60,
            ["handler" => $this->handler]
        );
    }

    private static function getFrame(int $result, string $body = ""): string
    {
        $reply = new _GetResponse();
        $reply->setResult($result);
        $reply->setCacheBody($body);
        $serialized = $reply->serializeToString();

        return "\x00" . pack("N", strlen($serialized)) . $serialized;
    }

    private static function setFrame(int $result): string
    {
        $reply = new _SetResponse();
        $reply->setResult($result);
        $serialized = $reply->serializeToString();

        return "\x00" . pack("N", strlen($serialized)) . $serialized;
    }

    public function testGetBatchSuccessCollectsHitsAndMisses()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::getFrame(ECacheResult::Hit, "v0") . self::getFrame(ECacheResult::Miss)
            ),
            ["grpc-status" => ["0"]]
        );

        $response = $this->client->getBatch("my-cache", ["k0", "k1"])->wait();
        $this->assertInstanceOf(GetBatchSuccess::class, $response);
        $results = $response->asSuccess()->results();
        $this->assertCount(2, $results);
        $this->assertInstanceOf(GetHit::class, $results[0]);
        $this->assertSame("v0", $results[0]->valueString());
        $this->assertInstanceOf(GetMiss::class, $results[1]);
    }

    public function testGetBatchTrailersOnlyAuthFailureIsAnError()
    {
        $this->handler->respond(
            new Response(200, [
                "content-type" => "application/grpc",
                "grpc-status" => "16",
                "grpc-message" => "no%20auth",
            ], ""),
            []
        );

        $response = $this->client->getBatch("my-cache", ["k0"])->wait();
        $this->assertInstanceOf(GetBatchError::class, $response);
        $this->assertInstanceOf(AuthenticationError::class, $response->asError()->innerException());
    }

    public function testGetBatchMissingGrpcStatusIsAnError()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::getFrame(ECacheResult::Hit, "v0")
            ),
            []
        );

        $response = $this->client->getBatch("my-cache", ["k0"])->wait();
        $this->assertInstanceOf(GetBatchError::class, $response);
        $this->assertInstanceOf(UnknownServiceError::class, $response->asError()->innerException());
    }

    public function testGetBatchNonOkStatusAfterMessagesIsAnError()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::getFrame(ECacheResult::Hit, "v0")
            ),
            ["grpc-status" => ["13"], "grpc-message" => ["mid-stream failure"]]
        );

        $response = $this->client->getBatch("my-cache", ["k0", "k1"])->wait();
        $this->assertInstanceOf(GetBatchError::class, $response);
        $this->assertInstanceOf(InternalServerError::class, $response->asError()->innerException());
    }

    public function testGetBatchTruncatedFrameAfterAMessageIsAnError()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::getFrame(ECacheResult::Hit, "v0") . "\x00\x00\x00"
            ),
            ["grpc-status" => ["0"]]
        );

        $response = $this->client->getBatch("my-cache", ["k0", "k1"])->wait();
        $this->assertInstanceOf(GetBatchError::class, $response);
        $this->assertInstanceOf(InternalServerError::class, $response->asError()->innerException());
    }

    public function testSetBatchSuccess()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::setFrame(ECacheResult::Ok) . self::setFrame(ECacheResult::Ok)
            ),
            ["grpc-status" => ["0"]]
        );

        $response = $this->client->setBatch("my-cache", ["k0" => "v0", "k1" => "v1"], 60)->wait();
        $this->assertInstanceOf(SetBatchSuccess::class, $response);
        $this->assertCount(2, $response->asSuccess()->results());
    }

    public function testSetBatchNonOkStatusConvertsThroughTheErrorConverter()
    {
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                self::setFrame(ECacheResult::Ok)
            ),
            ["grpc-status" => ["8"], "grpc-message" => ["throttled"]]
        );

        $response = $this->client->setBatch("my-cache", ["k0" => "v0"], 60)->wait();
        $this->assertInstanceOf(SetBatchError::class, $response);
        $error = $response->asError()->innerException();
        $this->assertInstanceOf(LimitExceededError::class, $error);
        $this->assertStringContainsString("throttled", $error->getMessage());
    }
}
