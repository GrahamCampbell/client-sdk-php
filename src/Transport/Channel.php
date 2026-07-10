<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransportSharing;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use const CURLOPT_CONNECT_TO;

/**
 * A gRPC channel equivalent built on Guzzle + ext-curl: owns one
 * CurlMultiHandler, builds the PSR-7 request and handler options for each
 * call, and tears everything down on close().
 *
 * Proxy caveat: Guzzle selects HTTPS_PROXY/NO_PROXY by the request URI host.
 * With ssl_target_name_override active the URI host is the override name,
 * not the real endpoint, so a NO_PROXY entry must list the override name to
 * bypass a proxy (grpc-core matches no_proxy against the real target).
 */
class Channel
{
    private const DEFAULT_CONNECT_TIMEOUT_SECS = 5.0;

    private const DEFAULT_MAX_RECEIVE_MESSAGE_BYTES = 4194304; // 4 MiB, C-core default

    private const CONTENT_TYPE = 'application/grpc';

    private const USER_AGENT_PREFIX = 'grpc-php-guzzle/';

    private const USER_AGENT_VERSION = '1.19.0'; // x-release-please-version

    private string $endpointHost;

    private int $endpointPort;

    /** @var string host[:port] placed in request URIs (drives :authority and SNI) */
    private string $uriAuthority;

    private string $scheme;

    /** @var string a Multiplexing constant; REQUIRE_WAIT outside tests */
    private string $multiplex;

    private ?string $sslTargetNameOverride;

    private float $connectTimeoutSecs;

    private int $maxReceiveMessageBytes;

    private string $userAgent;

    /** @var CurlMultiHandler|callable|null the owned handler; null once closed */
    private $handler;

    private bool $closed = false;

    /** @var array<int, PromiseInterface> outstanding transfers, by promise object id */
    private array $pendingPromises = [];

    /**
     * @param string $endpoint bare hostname or host:port (no scheme); TLS to
     *                         port 443 is implied when no port is given
     * @param array $options channel and test-seam options
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
            // URI host = override name (port 443), so SNI, certificate
            // verification, and :authority all carry the override; TCP is
            // redirected back to the real endpoint via CURLOPT_CONNECT_TO.
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
    }

    /**
     * Fire a unary RPC and return its call object.
     *
     * @param string $method full gRPC method path, e.g. '/cache_client.Scs/Get'
     * @param Message $argument request message
     * @param array{0: class-string, 1: string} $deserialize [class, 'decode'] pair
     * @param array<string, string[]> $metadata custom metadata
     * @param array $options 'timeout' in MICROSECONDS (optional; absent or
     *                       non-numeric means no deadline)
     * @return UnaryCall
     */
    public function startUnary(string $method, $argument, array $deserialize, array $metadata = [], array $options = []): UnaryCall
    {
        [$promise, $state, $hadDeadline] = $this->start($method, $argument, $metadata, $options);

        return new UnaryCall($this, $promise, $state, $deserialize, $hadDeadline);
    }

    /**
     * Fire a server-streaming RPC and return its call object.
     *
     * @param string $method full gRPC method path, e.g. '/cache_client.Scs/GetBatch'
     * @param Message $argument request message
     * @param array{0: class-string, 1: string} $deserialize [class, 'decode'] pair
     * @param array<string, string[]> $metadata custom metadata
     * @param array $options 'timeout' in MICROSECONDS (optional)
     * @return ServerStreamingCall
     */
    public function startServerStreaming(string $method, $argument, array $deserialize, array $metadata = [], array $options = []): ServerStreamingCall
    {
        [$promise, $state, $hadDeadline] = $this->start($method, $argument, $metadata, $options);

        return new ServerStreamingCall($this, $promise, $state, $deserialize, $hadDeadline);
    }

    /**
     * Drive the owned handler until the given promise settles.
     *
     * @internal used by the call objects
     * @param PromiseInterface $promise the call's transfer promise
     * @return void
     */
    public function pump(PromiseInterface $promise): void
    {
        while (Is::pending($promise)) {
            if ($this->closed || $this->handler === null) {
                throw new RuntimeException('The channel was closed with this call still in flight');
            }
            if ($this->handler instanceof CurlMultiHandler) {
                $this->handler->tick();
            } else {
                $promise->wait(false);
            }
        }

        Utils::queue()->run();
    }

    /**
     * Close the channel: cancel every outstanding call, then tear down the handler.
     *
     * @return void
     */
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

        // Guzzle 7's CurlMultiHandler has no teardown API; dropping the
        // reference releases the multi handle.
        $this->handler = null;
    }

    /**
     * The per-message receive cap for calls on this channel.
     *
     * @internal used by the call objects
     * @return int
     */
    public function getMaxReceiveMessageBytes(): int
    {
        return $this->maxReceiveMessageBytes;
    }

    /**
     * Shared firing path: serialize, validate metadata, build the PSR-7
     * request + handler options, invoke the handler, track the promise.
     *
     * @param string $method full gRPC method path
     * @param Message $argument request message
     * @param array<string, string[]> $metadata custom metadata
     * @param array $options Grpc-shaped call options
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
     * Caller-metadata keys never forwarded even when no fixed header claims
     * them: hop-by-hop headers, proxy credentials (Guzzle diverts
     * proxy-authorization to a configured HTTP proxy), and grpc-timeout
     * (which must stay paired with the locally-enforced deadline).
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

    /**
     * Build the PSR-7 request.
     *
     * @param string $method full gRPC method path (verbatim, case-sensitive)
     * @param string $frame the framed request body
     * @param array<string, list<string>> $encodedMetadata wire-ready metadata
     * @param int|null $timeoutMicros finite deadline, or null for none
     * @return Request
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
            // Empty value lists send nothing (ext-grpc parity); PSR-7 3.0
            // rejects empty header arrays outright.
            if ($values === [] || isset($headers[$name]) || isset(self::HAZARDOUS_METADATA[$name])) {
                continue;
            }
            $headers[$name] = $values;
        }

        return new Request('POST', $this->scheme . '://' . $this->uriAuthority . $method, $headers, $frame, '2.0');
    }

    /**
     * Build the per-call handler options.
     *
     * @param CallState $state per-call capture slot
     * @param int|null $timeoutMicros finite deadline, or null for none
     * @return array
     */
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
     * Track an in-flight promise so close() can cancel it; settled promises
     * unregister themselves.
     *
     * @param PromiseInterface $promise the transfer promise
     * @return void
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
     * Serialize a protobuf message to binary.
     *
     * @param Message $argument the request message
     * @return string
     */
    private static function serializeMessage($argument): string
    {
        return $argument->serializeToString();
    }

    /**
     * Split 'host' / 'host:port' (port = trailing all-digit segment after
     * the last colon); default port 443.
     *
     * @param string $endpoint the endpoint string
     * @return array{0: string, 1: int}
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
