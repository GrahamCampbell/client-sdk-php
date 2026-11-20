<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WeakReference;
use const CURLOPT_CONNECT_TO;

/**
 * A gRPC channel built on Guzzle and ext-curl, owning one CurlMultiHandler.
 *
 * Guzzle selects HTTPS_PROXY/NO_PROXY by the request URI host, so with
 * ssl_target_name_override a NO_PROXY entry must list the override name.
 */
class Channel
{
    private const DEFAULT_CONNECT_TIMEOUT_SECS = 5.0;

    private const DEFAULT_MAX_RECEIVE_MESSAGE_BYTES = 4194304;

    private const CONTENT_TYPE = 'application/grpc';

    private const USER_AGENT_PREFIX = 'grpc-php-guzzle/';

    private const USER_AGENT_VERSION = '1.20.0'; // x-release-please-version

    /**
     * Caller metadata that is never forwarded: headers the transport owns,
     * proxy credentials, and grpc-timeout, which must match the local deadline.
     */
    private const HAZARDOUS_METADATA = [
        'host' => true,
        'accept' => true,
        'accept-encoding' => true,
        'expect' => true,
        'transfer-encoding' => true,
        'connection' => true,
        'grpc-timeout' => true,
        'proxy-authorization' => true,
    ];

    /** @var array<int, WeakReference> */
    private static array $channels = [];

    private static bool $drainsAtShutdown = false;

    private string $endpointHost;

    private int $endpointPort;

    private string $uriAuthority;

    private string $scheme;

    private string $multiplex;

    private ?string $sslTargetNameOverride;

    private float $connectTimeoutSecs;

    private int $maxReceiveMessageBytes;

    private string $userAgent;

    /** @var CurlMultiHandler|callable|null */
    private $handler;

    private bool $closed = false;

    /** @var array<int, PromiseInterface> */
    private array $pendingPromises = [];

    /**
     * @param string $endpoint host or host:port, without a scheme
     */
    public function __construct(string $endpoint, array $options = [])
    {
        [$host, $port] = self::parseEndpoint($endpoint);
        $this->endpointHost = $host;
        $this->endpointPort = $port;
        $this->scheme = (string)($options['scheme'] ?? 'https');
        $this->multiplex = (string)($options['multiplex'] ?? Multiplexing::REQUIRE_WAIT);
        $this->connectTimeoutSecs = (float)($options['connect_timeout_secs'] ?? self::DEFAULT_CONNECT_TIMEOUT_SECS);
        $this->maxReceiveMessageBytes = (int)($options['max_receive_message_bytes'] ?? self::DEFAULT_MAX_RECEIVE_MESSAGE_BYTES);
        $this->userAgent = (string)($options['user_agent'] ?? (self::USER_AGENT_PREFIX . self::USER_AGENT_VERSION));

        $override = $options['ssl_target_name_override'] ?? null;
        if ($override !== null && $override !== '') {
            // The override becomes the URI host so that SNI, certificate
            // verification and :authority all carry it; CURLOPT_CONNECT_TO
            // points the connection back at the real endpoint.
            $override = (string)$override;
            if (substr($override, -4) === ':443') {
                $override = substr($override, 0, -4);
            }
            if ($override === '' || preg_match('/[\x00-\x20\x7f\/@?#\\\\:]/', $override) === 1) {
                throw new InvalidArgumentException("Invalid ssl_target_name_override \"{$override}\": expected a bare host name");
            }
            $this->sslTargetNameOverride = $override;
            $this->uriAuthority = $this->sslTargetNameOverride;
        } else {
            $this->sslTargetNameOverride = null;
            $this->uriAuthority = ($port === 443) ? $host : "{$host}:{$port}";
        }

        $this->handler = $options['handler'] ?? new CurlMultiHandler([
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
            'max_host_connections' => 1,
        ]);

        self::$channels[spl_object_id($this)] = WeakReference::create($this);
        if (!self::$drainsAtShutdown) {
            self::$drainsAtShutdown = true;
            // Once PHP starts destroying objects the handler may be gone, so
            // calls that nothing has waited on yet are completed first.
            register_shutdown_function(static function (): void {
                self::drain();
            });
        }
    }

    public function __destruct()
    {
        unset(self::$channels[spl_object_id($this)]);
    }

    /**
     * @param Message $argument
     * @param array{0: class-string, 1: string} $deserialize
     * @param array<string, string[]> $metadata
     * @param array $options 'timeout' is a deadline in microseconds
     */
    public function startUnary(string $method, $argument, array $deserialize, array $metadata = [], array $options = []): UnaryCall
    {
        [$promise, $state, $hadDeadline] = $this->start($method, $argument, $metadata, $options);

        return new UnaryCall($this, $promise, $state, $deserialize, $hadDeadline);
    }

    /**
     * @param Message $argument
     * @param array{0: class-string, 1: string} $deserialize
     * @param array<string, string[]> $metadata
     * @param array $options 'timeout' is a deadline in microseconds
     */
    public function startServerStreaming(string $method, $argument, array $deserialize, array $metadata = [], array $options = []): ServerStreamingCall
    {
        [$promise, $state, $hadDeadline] = $this->start($method, $argument, $metadata, $options);

        return new ServerStreamingCall($this, $promise, $state, $deserialize, $hadDeadline);
    }

    /**
     * Drive the handler until the given promise settles.
     *
     * @internal
     */
    public function pump(PromiseInterface $promise): void
    {
        // Waiting returns as soon as this transfer settles, where ticking
        // the handler would also block on the other transfers.
        $promise->wait(false);

        Utils::queue()->run();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->pendingPromises as $promise) {
            $promise->cancel();
        }
        $this->pendingPromises = [];

        try {
            // close() rethrows teardown failures, and the handler must be
            // dropped either way.
            if ($this->handler instanceof CurlMultiHandler) {
                $this->handler->close();
            }
        } finally {
            $this->handler = null;
        }
    }

    /**
     * @internal
     */
    public function getMaxReceiveMessageBytes(): int
    {
        return $this->maxReceiveMessageBytes;
    }

    private static function drain(): void
    {
        foreach (self::$channels as $reference) {
            $channel = $reference->get();
            if ($channel === null) {
                continue;
            }
            foreach ($channel->pendingPromises as $promise) {
                $promise->wait(false);
            }
        }
    }

    /**
     * @param Message $argument
     * @param array<string, string[]> $metadata
     * @return array{0: PromiseInterface, 1: CallState, 2: bool} [promise, state, hadDeadline]
     */
    private function start(string $method, $argument, array $metadata, array $options): array
    {
        if ($this->closed || $this->handler === null) {
            throw new RuntimeException('The channel has been closed');
        }

        $timeoutMicros = null;
        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $timeoutMicros = (int)$options['timeout'];
        }

        $request = $this->buildRequest(
            $method,
            FrameCodec::encode(self::serializeMessage($argument)),
            Metadata::encode(Metadata::validateAndNormalize($metadata)),
            $timeoutMicros
        );

        $state = new CallState();

        try {
            $promise = ($this->handler)($request, $this->buildOptions($state, $timeoutMicros));
            $this->track($promise);
        } catch (Throwable $e) {
            $promise = Create::rejectionFor($e);
        }

        return [$promise, $state, $timeoutMicros !== null];
    }

    /**
     * @param array<string, list<string>> $encodedMetadata
     */
    private function buildRequest(string $method, string $frame, array $encodedMetadata, ?int $timeoutMicros): Request
    {
        $headers = [];
        if ($timeoutMicros !== null) {
            $headers['grpc-timeout'] = GrpcTimeout::encode($timeoutMicros);
        }
        $headers['te'] = 'trailers';
        $headers['content-type'] = self::CONTENT_TYPE;
        $headers['grpc-accept-encoding'] = 'identity';
        $headers['user-agent'] = $this->userAgent;
        $headers['content-length'] = (string)strlen($frame);

        foreach ($encodedMetadata as $name => $values) {
            // An empty value list sends nothing, and fixed headers win
            // over caller metadata.
            if ($values === [] || isset($headers[$name]) || isset(self::HAZARDOUS_METADATA[$name])) {
                continue;
            }
            $headers[$name] = $values;
        }

        return new Request('POST', $this->scheme . '://' . $this->uriAuthority . $method, $headers, $frame, '2.0');
    }

    private function buildOptions(CallState $state, ?int $timeoutMicros): array
    {
        $options = [
            'multiplex' => $this->multiplex,
            'decode_content' => false,
            'verify' => true,
            'connect_timeout' => $this->connectTimeoutSecs,
            'on_trailers' => static function (array $trailers) use ($state): void {
                $state->trailers = $trailers;
            },
            'on_stats' => static function (TransferStats $stats) use ($state): void {
                $state->stats = $stats;
            },
        ];

        if ($timeoutMicros !== null) {
            $options['timeout'] = GrpcTimeout::toGuzzleSeconds($timeoutMicros);
            $state->deadlineMilliseconds = GrpcTimeout::toGuzzleMilliseconds($timeoutMicros);
        }
        $state->connectTimeoutMilliseconds = (int)($this->connectTimeoutSecs * 1000);

        if ($this->sslTargetNameOverride !== null) {
            $options['curl'] = [
                CURLOPT_CONNECT_TO => [
                    sprintf('%s:443:%s:%d', $this->sslTargetNameOverride, $this->endpointHost, $this->endpointPort),
                ],
            ];
        }

        return $options;
    }

    /**
     * Track an in-flight promise so that close() can cancel it.
     */
    private function track(PromiseInterface $promise): void
    {
        $id = spl_object_id($promise);
        $this->pendingPromises[$id] = $promise;
        $untrack = function () use ($id): void {
            unset($this->pendingPromises[$id]);
        };
        $promise->then($untrack, $untrack);
    }

    /**
     * @param Message $argument
     */
    private static function serializeMessage($argument): string
    {
        return $argument->serializeToString();
    }

    /**
     * @return array{0: string, 1: int} [host, port]
     */
    private static function parseEndpoint(string $endpoint): array
    {
        if ($endpoint === '' || preg_match('/[\x00-\x20\x7f\/@?#\\\\]/', $endpoint) === 1) {
            throw new InvalidArgumentException("Invalid endpoint \"{$endpoint}\": endpoints must be bare host or host:port values");
        }

        $host = $endpoint;
        $port = 443;
        $pos = strrpos($endpoint, ':');
        if ($pos !== false) {
            $host = substr($endpoint, 0, $pos);
            $suffix = substr($endpoint, $pos + 1);
            if ($host === '' || $suffix === '' || !ctype_digit($suffix) || strpos($host, ':') !== false) {
                throw new InvalidArgumentException("Invalid endpoint \"{$endpoint}\": endpoints must be bare host or host:port values");
            }

            $port = (int)$suffix;
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("Invalid endpoint \"{$endpoint}\": port out of range");
            }
        }

        return [$host, $port];
    }
}
