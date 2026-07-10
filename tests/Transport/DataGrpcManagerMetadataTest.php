<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\_GetBatchRequest;
use Cache_client\_GetRequest;
use Cache_client\_GetResponse;
use GuzzleHttp\Psr7\Response;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\Internal\DataGrpcManager;
use Momento\Config\Configurations;
use Momento\Config\IConfiguration;
use Momento\Config\ReadConcern;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\TransportRequirements;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use const CURLOPT_CONNECT_TO;

/**
 * @covers \Momento\Cache\Internal\DataGrpcManager
 */
class DataGrpcManagerMetadataTest extends TestCase
{
    private const TEST_API_KEY = "eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyQHRlc3QuY29tIiwiY3AiOiJjb250cm9sLnRlc3QuY29tIiwiYyI6ImNhY2hlLnRlc3QuY29tIn0.c0Z8Ipetl6raCNHSHs7Mpq3qtWkFy4aLvGhIFR4CoR0OnBdGbdjN-4E58bAabrSGhRA8-B2PHzgDd4JF4clAzg";

    private FakeGrpcHandler $handler;
    private StringMomentoTokenProvider $authProvider;

    public function setUp(): void
    {
        try {
            TransportRequirements::assertSupported();
        } catch (RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $this->handler = new FakeGrpcHandler();
        $this->authProvider = new StringMomentoTokenProvider(self::TEST_API_KEY);
    }

    private function configuration(string $readConcern = ReadConcern::BALANCED): IConfiguration
    {
        return Configurations\Laptop::latest()->withReadConcern($readConcern);
    }

    private function manager(?IConfiguration $configuration = null, ?StringMomentoTokenProvider $authProvider = null): DataGrpcManager
    {
        return new DataGrpcManager(
            $authProvider ?? $this->authProvider,
            $configuration ?? $this->configuration(),
            ["handler" => $this->handler]
        );
    }

    private function enqueueOkUnary(): void
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Miss);
        $serialized = $reply->serializeToString();
        $this->handler->respond(
            new Response(
                200,
                ["content-type" => "application/grpc"],
                "\x00" . pack("N", strlen($serialized)) . $serialized
            ),
            ["grpc-status" => ["0"]]
        );
    }

    private function enqueueOkStream(): void
    {
        $this->handler->respond(
            new Response(200, ["content-type" => "application/grpc"], ""),
            ["grpc-status" => ["0"]]
        );
    }

    public function testFirstUnaryCallCarriesAgentAndRuntimeVersion()
    {
        $this->enqueueOkUnary();
        $manager = $this->manager();
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();

        $sent = $this->handler->lastRequest();
        $this->assertSame([$this->authProvider->getAuthToken()], $sent->getHeader("authorization"));
        $this->assertMatchesRegularExpression("/^php:cache:\d+\.\d+\.\d+$/", $sent->getHeaderLine("agent"));
        $this->assertSame([PHP_VERSION], $sent->getHeader("runtime-version"));
        $agentVersion = explode(":", $sent->getHeaderLine("agent"))[2];
        $this->assertStringStartsWith("grpc-php-guzzle/{$agentVersion}", $sent->getHeaderLine("user-agent"));
        $this->assertSame(["c"], $sent->getHeader("cache"));
    }

    public function testSecondUnaryCallOmitsAgentHeaders()
    {
        $this->enqueueOkUnary();
        $this->enqueueOkUnary();
        $manager = $this->manager();
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();

        $second = $this->handler->lastRequest();
        $this->assertFalse($second->hasHeader("agent"));
        $this->assertFalse($second->hasHeader("runtime-version"));
        $this->assertSame([$this->authProvider->getAuthToken()], $second->getHeader("authorization"));
    }

    public function testServerStreamingCallsNeverCarryAgentHeaders()
    {
        $this->enqueueOkStream();
        $this->enqueueOkUnary();
        $manager = $this->manager($this->configuration(ReadConcern::CONSISTENT));

        $batch = $manager->client->GetBatch(new _GetBatchRequest(), ["cache" => ["c"]], ["timeout" => 5000000]);
        iterator_to_array($batch->responses(), false);
        $this->assertFalse($this->handler->invocations[0]["request"]->hasHeader("agent"));
        $this->assertFalse($this->handler->invocations[0]["request"]->hasHeader("read-concern"));
        $this->assertSame(
            [$this->authProvider->getAuthToken()],
            $this->handler->invocations[0]["request"]->getHeader("authorization")
        );

        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();
        $this->assertTrue($this->handler->invocations[1]["request"]->hasHeader("agent"));
        $this->assertSame([ReadConcern::CONSISTENT], $this->handler->invocations[1]["request"]->getHeader("read-concern"));
    }

    public function testBalancedReadConcernSendsNoHeader()
    {
        $this->enqueueOkUnary();
        $manager = $this->manager($this->configuration(ReadConcern::BALANCED));
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();
        $this->assertFalse($this->handler->lastRequest()->hasHeader("read-concern"));
    }

    public function testConsistentReadConcernSendsTheHeaderVerbatim()
    {
        $this->enqueueOkUnary();
        $manager = $this->manager($this->configuration(ReadConcern::CONSISTENT));
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();
        $this->assertSame([ReadConcern::CONSISTENT], $this->handler->lastRequest()->getHeader("read-concern"));
    }

    public function testTrustedCertificateNameRoutesViaConnectTo()
    {
        $this->enqueueOkUnary();
        $authProvider = new StringMomentoTokenProvider(
            self::TEST_API_KEY,
            "control.test.com",
            "proxy.local:4443",
            "ctlCert",
            "cacheCert"
        );
        $manager = $this->manager(null, $authProvider);
        $manager->client->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 5000000])->wait();

        $sent = $this->handler->lastRequest();
        $this->assertSame("https://cachecert/cache_client.Scs/Get", (string)$sent->getUri());
        $options = $this->handler->lastOptions();
        $this->assertSame(
            ["cacheCert:443:proxy.local:4443"],
            $options["curl"][CURLOPT_CONNECT_TO]
        );
    }
}
