<?php
declare(strict_types=1);

namespace Momento\Transport;

use Exception;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Shared machinery for UnaryCall and ServerStreamingCall: pump the channel
 * until this call's transfer settles, then classify the outcome exactly
 * once into initial metadata, decoded message payloads, and terminal Status.
 */
abstract class AbstractCall
{
    private const INITIAL_METADATA_STRIP = ['content-type', 'grpc-encoding', 'grpc-accept-encoding'];

    private const BODY_READ_CHUNK_BYTES = 65536;

    protected Channel $channel;

    protected PromiseInterface $promise;

    protected CallState $state;

    /** @var array{0: class-string, 1: string} [class, 'decode'] pair */
    protected array $deserialize;

    protected bool $hadDeadline;

    protected bool $completed = false;

    /** @var array<string, list<string>> */
    protected array $initialMetadata = [];

    /** @var string[] decoded frame payloads, populated at completion */
    protected array $messages = [];

    protected ?Status $status = null;

    /**
     * @internal calls are constructed by Channel::startUnary()/startServerStreaming()
     * @param Channel $channel the owning channel (pump + receive cap)
     * @param PromiseInterface $promise the transfer promise
     * @param CallState $state per-call trailer/stats capture slot
     * @param array{0: class-string, 1: string} $deserialize [class, 'decode'] pair
     * @param bool $hadDeadline whether a finite deadline governs this call
     */
    public function __construct(Channel $channel, PromiseInterface $promise, CallState $state, array $deserialize, bool $hadDeadline)
    {
        $this->channel = $channel;
        $this->promise = $promise;
        $this->state = $state;
        $this->deserialize = $deserialize;
        $this->hadDeadline = $hadDeadline;
    }

    /**
     * The initial response-header metadata sent by the server.
     *
     * @return array<string, list<string>>
     */
    public function getMetadata(): array
    {
        $this->complete();

        return $this->initialMetadata;
    }

    /**
     * The trailing metadata sent by the server.
     *
     * @return array<string, list<string>>
     */
    public function getTrailingMetadata(): array
    {
        $this->complete();

        return $this->status !== null ? $this->status->metadata : [];
    }

    /**
     * Best-effort cancellation: cancels the transfer promise.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->promise->cancel();
    }

    /**
     * Pump until this call's transfer settles, then classify the outcome.
     *
     * @return void
     * @throws Throwable rethrows unmappable rejection reasons
     */
    protected function complete(): void
    {
        if ($this->completed) {
            return;
        }

        $this->channel->pump($this->promise);

        try {
            /** @var ResponseInterface $response */
            $response = $this->promise->wait();
        } catch (Throwable $reason) {
            $this->failed($reason);
            $this->completed = true;

            return;
        }

        $this->fulfilled($response);
        $this->completed = true;
    }

    /**
     * Deserialize one message payload; the ext-grpc call code ignores the
     * 'decode' function name in the [class, 'decode'] pair and always
     * constructs + mergeFromString.
     *
     * The pure-PHP protobuf runtime silently truncates at an undecodable
     * tag varint, so payloads are structurally validated first when the
     * strict C extension is not loaded.
     *
     * @param string $payload one frame's message bytes
     * @return Message
     * @throws Exception when the payload does not parse as the message type
     */
    protected function deserializeMessage(string $payload)
    {
        if (!\extension_loaded('protobuf')) {
            WireFormat::assertValid($payload);
        }

        [$className] = $this->deserialize;
        $message = new $className();
        $message->mergeFromString($payload);

        return $message;
    }

    /**
     * Classify a fulfilled transfer per the gRPC response state machine.
     *
     * @param ResponseInterface $response the PSR-7 response
     * @return void
     */
    private function fulfilled(ResponseInterface $response): void
    {
        $headerBlock = $response->getHeaders();
        $headerStatus = Status::fromBlock($headerBlock);
        $trailerStatus = ($this->state->trailers !== null) ? Status::fromBlock($this->state->trailers) : null;
        $httpStatus = $response->getStatusCode();

        if ($httpStatus !== 200) {
            $this->initialMetadata = ($headerStatus !== null)
                ? []
                : Metadata::decodeBlock($headerBlock, self::INITIAL_METADATA_STRIP);
            $status = $headerStatus ?? $trailerStatus;
            if ($status === null) {
                $status = new Status(
                    self::statusCodeFromHttpStatus($httpStatus),
                    sprintf('Received HTTP status %d with no grpc-status', $httpStatus),
                    Metadata::decodeBlock(
                        array_merge($headerBlock, $this->state->trailers ?? []),
                        Status::TRANSPORT_KEYS
                    )
                );
            }
            $this->status = $status;

            return;
        }

        if ($headerStatus !== null) {
            if (self::bodyHasBytes($response)) {
                $this->status = new Status(StatusCode::INTERNAL, 'Trailers-only response carried body data');

                return;
            }
            $this->initialMetadata = [];
            $this->status = $headerStatus;

            return;
        }

        $this->initialMetadata = Metadata::decodeBlock($headerBlock, self::INITIAL_METADATA_STRIP);

        if ($trailerStatus === null) {
            // Still decode the body: ext-grpc yields received messages
            // independently of the terminal status, and decodeBody() never
            // overrides an already-non-OK status.
            $this->status = new Status(StatusCode::UNKNOWN, 'Missing grpc-status in response');
            $this->decodeBody($response);

            return;
        }

        $this->status = $trailerStatus;
        $this->decodeBody($response);
    }

    /**
     * Eagerly split the buffered body into message payloads.
     *
     * @param ResponseInterface $response the PSR-7 response
     * @return void
     */
    private function decodeBody(ResponseInterface $response): void
    {
        $grpcEncoding = $response->getHeaderLine('grpc-encoding');
        $codec = new FrameCodec(
            $this->channel->getMaxReceiveMessageBytes(),
            ($grpcEncoding === '') ? null : $grpcEncoding
        );
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::BODY_READ_CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                foreach ($codec->feed($chunk) as $payload) {
                    $this->messages[] = $payload;
                }
            }
            $codec->finish();
        } catch (StatusException $e) {
            if ($this->status !== null && $this->status->isOk()) {
                $this->status = $e->getStatus();
            }
        }
    }

    /**
     * Classify a rejected transfer.
     *
     * @param Throwable $reason the rejection reason
     * @return void
     * @throws Throwable rethrows unmappable reasons
     */
    private function failed(Throwable $reason): void
    {
        $response = ErrorMapper::responseFrom($reason);
        if ($response !== null && Status::fromBlock($response->getHeaders()) === null) {
            $this->initialMetadata = Metadata::decodeBlock($response->getHeaders(), self::INITIAL_METADATA_STRIP);
        }
        $this->status = ErrorMapper::map($reason, $this->hadDeadline, $this->state);
    }

    /**
     * Whether the response body contains any bytes.
     *
     * @param ResponseInterface $response the PSR-7 response
     * @return bool
     */
    private static function bodyHasBytes(ResponseInterface $response): bool
    {
        $body = $response->getBody();
        $size = $body->getSize();
        if ($size !== null) {
            return $size > 0;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->read(1) !== '';
    }

    /**
     * The HTTP-to-gRPC synthesis table used only when no grpc-status was provided.
     *
     * @param int $httpStatus the HTTP status code
     * @return int a StatusCode constant
     */
    private static function statusCodeFromHttpStatus(int $httpStatus): int
    {
        switch ($httpStatus) {
            case 400:
                return StatusCode::INTERNAL;
            case 401:
                return StatusCode::UNAUTHENTICATED;
            case 403:
                return StatusCode::PERMISSION_DENIED;
            case 404:
                return StatusCode::UNIMPLEMENTED;
            case 429:
            case 502:
            case 503:
            case 504:
                return StatusCode::UNAVAILABLE;
            default:
                return StatusCode::UNKNOWN;
        }
    }
}
