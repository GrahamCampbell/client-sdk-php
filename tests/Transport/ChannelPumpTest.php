<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\ScsClient;
use Cache_client\_GetBatchRequest;
use Cache_client\_GetRequest;
use Cache_client\_GetResponse;
use GuzzleHttp\Multiplexing;
use Momento\Transport\Channel;
use Momento\Transport\StatusCode;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real CurlMultiHandler against a local HTTP/1.1 fixture that
 * emits chunked trailer sections. h2-specific behavior stays in live coverage.
 *
 * @covers \Momento\Transport\Channel
 * @covers \Momento\Transport\BaseStub
 */
class ChannelPumpTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    private ?string $scenarioFile = null;

    /** @var array<string, string|false> */
    private array $proxyEnv = [];

    public function setUp(): void
    {
        foreach (["http_proxy", "all_proxy", "ALL_PROXY"] as $name) {
            $this->proxyEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        if ($this->scenarioFile !== null && file_exists($this->scenarioFile)) {
            unlink($this->scenarioFile);
        }
        foreach ($this->proxyEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
    }

    /**
     * @param array<int, string> $rawResponses
     */
    private function startServer(array $rawResponses): int
    {
        $this->scenarioFile = tempnam(sys_get_temp_dir(), "momento-trailer-server-");
        file_put_contents($this->scenarioFile, implode("\n", array_map("base64_encode", $rawResponses)) . "\n");

        $this->serverProcess = proc_open(
            [PHP_BINARY, __DIR__ . "/Support/trailer-server.php", $this->scenarioFile],
            [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $this->serverPipes
        );
        $this->assertIsResource($this->serverProcess);
        $port = (int)trim((string)fgets($this->serverPipes[1]));
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function stub(int $port): ScsClient
    {
        $endpoint = "127.0.0.1:{$port}";
        $channel = new Channel($endpoint, [
            "scheme" => "http",
            "multiplex" => Multiplexing::WAIT,
        ]);

        return new ScsClient($endpoint, [], $channel);
    }

    private static function grpcFrame(string $cacheBody): string
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Hit);
        $reply->setCacheBody($cacheBody);
        $serialized = $reply->serializeToString();

        return "\x00" . pack("N", strlen($serialized)) . $serialized;
    }

    /**
     * @param array<string, string> $trailers
     */
    private static function chunkedGrpcResponse(string $wireBody, array $trailers): string
    {
        $response = "HTTP/1.1 200 OK\r\n"
            . "content-type: application/grpc\r\n"
            . "trailer: grpc-status\r\n"
            . "transfer-encoding: chunked\r\n"
            . "connection: close\r\n"
            . "\r\n";
        if ($wireBody !== "") {
            $response .= dechex(strlen($wireBody)) . "\r\n" . $wireBody . "\r\n";
        }
        $response .= "0\r\n";
        foreach ($trailers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }

        return $response . "\r\n";
    }

    public function testUnaryCallOverTheRealHandlerParsesChunkedTrailers()
    {
        $port = $this->startServer([
            self::chunkedGrpcResponse(self::grpcFrame("real-handler-value"), ["grpc-status" => "0"]),
        ]);

        $argument = new _GetRequest();
        $argument->setCacheKey("k");
        [$response, $status] = $this->stub($port)
            ->Get($argument, ["cache" => ["c"]], ["timeout" => 10000000])
            ->wait();

        $this->assertSame(StatusCode::OK, $status->code);
        $this->assertSame("real-handler-value", $response->getCacheBody());
    }

    public function testStreamingCallOverTheRealHandlerDecodesMultipleFrames()
    {
        $port = $this->startServer([
            self::chunkedGrpcResponse(
                self::grpcFrame("s0") . self::grpcFrame("s1"),
                ["grpc-status" => "0"]
            ),
        ]);

        $call = $this->stub($port)->GetBatch(new _GetBatchRequest(), ["cache" => ["c"]], ["timeout" => 10000000]);
        $bodies = [];
        foreach ($call->responses() as $reply) {
            $bodies[] = $reply->getCacheBody();
        }
        $this->assertSame(["s0", "s1"], $bodies);
        $this->assertSame(StatusCode::OK, $call->getStatus()->code);
    }

    public function testTrailersOnlyErrorOverTheRealHandler()
    {
        $port = $this->startServer([
            "HTTP/1.1 200 OK\r\n"
            . "content-type: application/grpc\r\n"
            . "grpc-status: 5\r\n"
            . "grpc-message: not%20found\r\n"
            . "err: item_not_found\r\n"
            . "content-length: 0\r\n"
            . "connection: close\r\n"
            . "\r\n",
        ]);

        [$response, $status] = $this->stub($port)
            ->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 10000000])
            ->wait();

        $this->assertNull($response);
        $this->assertSame(StatusCode::NOT_FOUND, $status->code);
        $this->assertSame("not found", $status->details);
        $this->assertSame(["item_not_found"], $status->metadata["err"]);
    }

    public function testTwoInFlightCallsCanBeWaitedOutOfOrder()
    {
        $port = $this->startServer([
            self::chunkedGrpcResponse(self::grpcFrame("first"), ["grpc-status" => "0"]),
            self::chunkedGrpcResponse(self::grpcFrame("second"), ["grpc-status" => "0"]),
        ]);
        $stub = $this->stub($port);

        $callA = $stub->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 10000000]);
        $callB = $stub->Get(new _GetRequest(), ["cache" => ["c"]], ["timeout" => 10000000]);

        [$responseB, $statusB] = $callB->wait();
        [$responseA, $statusA] = $callA->wait();

        $this->assertSame(StatusCode::OK, $statusA->code);
        $this->assertSame(StatusCode::OK, $statusB->code);
        $this->assertEqualsCanonicalizing(
            ["first", "second"],
            [$responseA->getCacheBody(), $responseB->getCacheBody()]
        );
    }
}
