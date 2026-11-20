<?php
declare(strict_types=1);

namespace Momento\Transport;

use Exception;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Pumps the channel until the call's transfer settles, then classifies the
 * outcome once into initial metadata, message payloads and a terminal Status.
 */
abstract class AbstractCall
{
    private const INITIAL_METADATA_STRIP = ['content-type', 'grpc-encoding', 'grpc-accept-encoding'];

    private const BODY_READ_CHUNK_BYTES = 65536;

    protected Channel $channel;

    protected PromiseInterface $promise;

    protected CallState $state;

    /** @var array{0: class-string, 1: string} */
    protected array $deserialize;

    protected bool $hadDeadline;

    protected bool $completed = false;

    /** @var array<string, list<string>> */
    protected array $initialMetadata = [];

    /** @var string[] */
    protected array $messages = [];

    protected ?Status $status = null;

    /**
     * @internal
     * @param array{0: class-string, 1: string} $deserialize
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
     * @return array<string, list<string>> The initial metadata sent by the server.
     */
    public function getMetadata(): array
    {
        $this->complete();

        return $this->initialMetadata;
    }

    /**
     * @return array<string, list<string>> The trailing metadata sent by the server.
     */
    public function getTrailingMetadata(): array
    {
        $this->complete();

        return $this->status !== null ? $this->status->metadata : [];
    }

    public function cancel(): void
    {
        $this->promise->cancel();
    }

    /**
     * @throws Throwable when the transfer failed for a reason that has no gRPC status
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
     * @return Message
     * @throws Exception when the payload does not parse as the message type
     */
    protected function deserializeMessage(string $payload)
    {
        // The pure-PHP protobuf runtime silently truncates at an
        // undecodable tag, where the C extension throws.
        if (!\extension_loaded('protobuf')) {
            WireFormat::assertValid($payload);
        }

        [$className] = $this->deserialize;
        $message = new $className();
        $message->mergeFromString($payload);

        return $message;
    }

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
            // Messages are still yielded, as ext-grpc does regardless of
            // the terminal status.
            $this->status = new Status(StatusCode::UNKNOWN, 'Missing grpc-status in response');
            $this->decodeBody($response);

            return;
        }

        $this->status = $trailerStatus;
        $this->decodeBody($response);
    }

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

    private function failed(Throwable $reason): void
    {
        $response = ErrorMapper::responseFrom($reason);
        if ($response !== null && Status::fromBlock($response->getHeaders()) === null) {
            $this->initialMetadata = Metadata::decodeBlock($response->getHeaders(), self::INITIAL_METADATA_STRIP);
        }
        $this->status = ErrorMapper::map($reason, $this->hadDeadline, $this->state);
    }

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
     * Used only when the response carried no grpc-status.
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
