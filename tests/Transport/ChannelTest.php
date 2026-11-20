<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\_GetRequest;
use Cache_client\_GetResponse;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\Channel;
use PHPUnit\Framework\TestCase;
use const CURLOPT_CONNECT_TO;

/**
 * Constructor validation: the endpoint and ssl_target_name_override parsers
 * gate what reaches the request URI (SNI, certificate verification,
 * :authority) and CURLOPT_CONNECT_TO, so every reject branch is pinned.
 *
 * @covers \Momento\Transport\Channel
 */
class ChannelTest extends TestCase
{
    public static function invalidEndpointProvider(): array
    {
        return [
            "empty" => [""],
            "space" => ["cache host"],
            "control byte" => ["cache\x01host"],
            "delete byte" => ["cache\x7fhost"],
            "path" => ["cache.host/path"],
            "userinfo" => ["user@cache.host"],
            "query" => ["cache.host?x"],
            "fragment" => ["cache.host#x"],
            "backslash" => ["cache\\host"],
            "empty host" => [":443"],
            "empty port" => ["cache.host:"],
            "non-numeric port" => ["cache.host:44x"],
            "ipv6 colon host" => ["::1:443"],
            "port zero" => ["cache.host:0"],
            "port too large" => ["cache.host:65536"],
        ];
    }

    /**
     * @dataProvider invalidEndpointProvider
     */
    public function testInvalidEndpointsAreRejected(string $endpoint)
    {
        $this->expectException(InvalidArgumentException::class);
        new Channel($endpoint);
    }

    public static function invalidOverrideProvider(): array
    {
        return [
            "embedded colon" => ["cert:8443"],
            "only the stripped port" => [":443"],
            "space" => ["cert name"],
            "path" => ["cert/name"],
            "userinfo" => ["user@cert"],
            "backslash" => ["cert\\name"],
            "control byte" => ["cert\x01name"],
        ];
    }

    /**
     * @dataProvider invalidOverrideProvider
     */
    public function testInvalidSslTargetNameOverridesAreRejected(string $override)
    {
        $this->expectException(InvalidArgumentException::class);
        new Channel("cache.test.momentohq.com", ["ssl_target_name_override" => $override]);
    }

    public function testOverrideWithPort443SuffixIsStrippedAndRoutesViaConnectTo()
    {
        $handler = new FakeGrpcHandler();
        $handler->respond(
            new Response(200, ["content-type" => "application/grpc"], ""),
            ["grpc-status" => ["12"]]
        );
        $channel = new Channel("real.endpoint.example:4443", [
            "handler" => $handler,
            "ssl_target_name_override" => "cachecert:443",
        ]);

        $channel->startUnary(
            "/cache_client.Scs/Get",
            new _GetRequest(),
            [_GetResponse::class, "decode"]
        )->wait();

        $this->assertSame(
            "https://cachecert/cache_client.Scs/Get",
            (string)$handler->lastRequest()->getUri()
        );
        $this->assertSame(
            ["cachecert:443:real.endpoint.example:4443"],
            $handler->lastOptions()["curl"][CURLOPT_CONNECT_TO]
        );
    }
}
