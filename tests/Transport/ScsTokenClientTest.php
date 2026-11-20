<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use GuzzleHttp\Psr7\Response;
use Momento\Auth\AccessControl\CacheItemSelector;
use Momento\Auth\AccessControl\CachePermission;
use Momento\Auth\AccessControl\CacheRole;
use Momento\Auth\AccessControl\CacheSelector;
use Momento\Auth\AccessControl\DisposableTokenScope;
use Momento\Auth\AccessControl\DisposableTokenScopes;
use Momento\Auth\AccessControl\TopicPermission;
use Momento\Auth\AccessControl\TopicRole;
use Momento\Auth\AccessControl\TopicSelector;
use Momento\Auth\ApiKeyV2TokenProvider;
use Momento\Auth\AuthOperationTypes\GenerateDisposableTokenError;
use Momento\Auth\AuthOperationTypes\GenerateDisposableTokenSuccess;
use Momento\Auth\Internal\ScsTokenClient;
use Momento\Cache\Errors\InvalidArgumentError;
use Momento\Cache\Errors\PermissionError;
use Momento\Config\Configurations;
use Momento\Tests\Transport\Support\FakeGrpcHandler;
use Momento\Transport\TransportRequirements;
use Permission_messages\CacheRole as GrpcCacheRole;
use Permission_messages\TopicRole as GrpcTopicRole;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Token\_GenerateDisposableTokenRequest;
use Token\_GenerateDisposableTokenResponse;

/**
 * @covers \Momento\Auth\Internal\ScsTokenClient
 * @covers \Momento\Auth\Internal\TokenGrpcManager
 */
class ScsTokenClientTest extends TestCase
{
    private const TEST_API_KEY = "eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJ0IjoiZyIsImlkIjoidGVzdCJ9.c2lnbmF0dXJl";

    private FakeGrpcHandler $handler;
    private ScsTokenClient $client;

    public function setUp(): void
    {
        try {
            TransportRequirements::assertSupported();
        } catch (RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $this->handler = new FakeGrpcHandler();
        $this->client = new ScsTokenClient(
            Configurations\Laptop::latest(),
            new ApiKeyV2TokenProvider(self::TEST_API_KEY, "cell.test.com"),
            ["handler" => $this->handler]
        );
    }

    private function enqueueToken(): void
    {
        $reply = new _GenerateDisposableTokenResponse();
        $reply->setApiKey("disposable.jwt");
        $reply->setEndpoint("cell.test.com");
        $reply->setValidUntil(1800000000);
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

    private function sentRequest(): _GenerateDisposableTokenRequest
    {
        $request = new _GenerateDisposableTokenRequest();
        $request->mergeFromString(substr((string)$this->handler->lastRequest()->getBody(), 5));

        return $request;
    }

    public function testGenerateDisposableTokenReturnsTheEncodedToken()
    {
        $this->enqueueToken();
        $response = $this->client->generateDisposableToken(
            DisposableTokenScopes::topicSubscribeOnly("my-cache", "my-topic"),
            900
        );

        $this->assertInstanceOf(GenerateDisposableTokenSuccess::class, $response);
        $success = $response->asSuccess();
        $this->assertSame(
            base64_encode('{"endpoint":"cell.test.com","api_key":"disposable.jwt"}'),
            $success->authToken()
        );
        $this->assertSame("cell.test.com", $success->endpoint());
        $this->assertSame(1800000000, $success->expiresAt());
    }

    public function testGenerateDisposableTokenSendsTheApiKeyInTheRequestOnly()
    {
        $this->enqueueToken();
        $this->client->generateDisposableToken(
            DisposableTokenScopes::topicSubscribeOnly("my-cache", "my-topic"),
            900,
            "my-token-id"
        );

        $sent = $this->handler->lastRequest();
        $this->assertSame("https://token.cell.test.com/token.Token/GenerateDisposableToken", (string)$sent->getUri());
        $this->assertFalse($sent->hasHeader("authorization"));
        $this->assertMatchesRegularExpression("/^php:auth:\d+\.\d+\.\d+$/", $sent->getHeaderLine("agent"));
        $this->assertNotSame("", $sent->getHeaderLine("grpc-timeout"));

        $request = $this->sentRequest();
        $this->assertSame(self::TEST_API_KEY, $request->getAuthToken());
        $this->assertSame(900, $request->getExpires()->getValidForSeconds());
        $this->assertSame("my-token-id", $request->getTokenId());
    }

    public function testNamedSelectorsAreSentAsSelectors()
    {
        $this->enqueueToken();
        $this->client->generateDisposableToken(
            new DisposableTokenScope([
                new TopicPermission(TopicRole::PUBLISH_ONLY, CacheSelector::byName("my-cache"), TopicSelector::byName("my-topic")),
                new CachePermission(CacheRole::READ_ONLY, CacheSelector::byName("my-cache"), CacheItemSelector::byKey("my-key")),
                new CachePermission(CacheRole::WRITE_ONLY, CacheSelector::byName("my-cache"), CacheItemSelector::byKeyPrefix("my-prefix")),
            ]),
            60
        );

        $permissions = $this->sentRequest()->getPermissions()->getExplicit()->getPermissions();
        $this->assertCount(3, $permissions);

        $topic = $permissions[0]->getTopicPermissions();
        $this->assertSame(GrpcTopicRole::TopicWriteOnly, $topic->getRole());
        $this->assertSame("my-cache", $topic->getCacheSelector()->getCacheName());
        $this->assertSame("my-topic", $topic->getTopicSelector()->getTopicName());
        $this->assertFalse($topic->hasAllCaches());
        $this->assertFalse($topic->hasAllTopics());

        $key = $permissions[1]->getCachePermissions();
        $this->assertSame(GrpcCacheRole::CacheReadOnly, $key->getRole());
        $this->assertSame("my-cache", $key->getCacheSelector()->getCacheName());
        $this->assertSame("my-key", $key->getItemSelector()->getKey());

        $prefix = $permissions[2]->getCachePermissions();
        $this->assertSame(GrpcCacheRole::CacheWriteOnly, $prefix->getRole());
        $this->assertSame("my-prefix", $prefix->getItemSelector()->getKeyPrefix());
    }

    public function testAllSelectorsAreSentAsWildcards()
    {
        $this->enqueueToken();
        $this->client->generateDisposableToken(
            new DisposableTokenScope([
                new TopicPermission(TopicRole::PUBLISH_SUBSCRIBE, CacheSelector::allCaches(), TopicSelector::allTopics()),
                new CachePermission(CacheRole::READ_WRITE, CacheSelector::allCaches(), CacheItemSelector::allCacheItems()),
                new CachePermission(CacheRole::READ_WRITE, CacheSelector::allCaches()),
            ]),
            60
        );

        $permissions = $this->sentRequest()->getPermissions()->getExplicit()->getPermissions();

        $topic = $permissions[0]->getTopicPermissions();
        $this->assertSame(GrpcTopicRole::TopicReadWrite, $topic->getRole());
        $this->assertTrue($topic->hasAllCaches());
        $this->assertTrue($topic->hasAllTopics());
        $this->assertFalse($topic->hasCacheSelector());
        $this->assertFalse($topic->hasTopicSelector());

        $allItems = $permissions[1]->getCachePermissions();
        $this->assertTrue($allItems->hasAllCaches());
        $this->assertTrue($allItems->hasAllItems());

        $noItems = $permissions[2]->getCachePermissions();
        $this->assertTrue($noItems->hasAllCaches());
        $this->assertFalse($noItems->hasAllItems());
        $this->assertFalse($noItems->hasItemSelector());
    }

    public function testServiceFailureIsAnError()
    {
        $this->handler->respond(
            new Response(200, [
                "content-type" => "application/grpc",
                "grpc-status" => "7",
                "grpc-message" => "denied",
            ], ""),
            []
        );

        $response = $this->client->generateDisposableToken(
            DisposableTokenScopes::cacheReadOnly("my-cache"),
            900
        );
        $this->assertInstanceOf(GenerateDisposableTokenError::class, $response);
        $this->assertInstanceOf(PermissionError::class, $response->asError()->innerException());
    }

    /**
     * @return array<string, array{0: DisposableTokenScope, 1: int, 2: string|null}>
     */
    public function invalidArguments(): array
    {
        $scope = DisposableTokenScopes::topicSubscribeOnly("my-cache", "my-topic");

        return [
            "zero expiry" => [$scope, 0, null],
            "negative expiry" => [$scope, -1, null],
            "long token id" => [$scope, 900, str_repeat("a", 65)],
            "empty cache name" => [DisposableTokenScopes::cacheReadOnly(""), 900, null],
            "empty topic name" => [DisposableTokenScopes::topicSubscribeOnly("my-cache", ""), 900, null],
            "empty item key" => [DisposableTokenScopes::cacheKeyReadOnly("my-cache", ""), 900, null],
            "unknown cache role" => [new DisposableTokenScope([new CachePermission("subscribeonly", CacheSelector::allCaches())]), 900, null],
            "unknown topic role" => [new DisposableTokenScope([new TopicPermission("readonly", CacheSelector::allCaches(), TopicSelector::allTopics())]), 900, null],
            "unknown permission" => [new DisposableTokenScope(["subscribeonly"]), 900, null],
        ];
    }

    /**
     * @dataProvider invalidArguments
     */
    public function testInvalidArgumentsAreRejectedWithoutACall(DisposableTokenScope $scope, int $validForSeconds, ?string $tokenId)
    {
        $response = $this->client->generateDisposableToken($scope, $validForSeconds, $tokenId);

        $this->assertInstanceOf(GenerateDisposableTokenError::class, $response);
        $this->assertInstanceOf(InvalidArgumentError::class, $response->asError()->innerException());
        $this->assertCount(0, $this->handler->invocations);
    }
}
