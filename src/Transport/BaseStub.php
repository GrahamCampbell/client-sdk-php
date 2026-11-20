<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use InvalidArgumentException;

/**
 * Base class for the generated client stubs, in place of the ext-grpc one.
 *
 * Of $opts only 'update_metadata' is honored, and its callback receives
 * (array $metadata, string $method, bool $isServerStreaming).
 */
class BaseStub
{
    private Channel $channel;

    /** @var callable|null */
    private $updateMetadata;

    /**
     * @param string $hostname used only when no channel is given
     * @param array $opts
     * @param Channel|null $channel
     */
    public function __construct($hostname, $opts, $channel = null)
    {
        $this->updateMetadata = null;
        if (isset($opts['update_metadata']) && is_callable($opts['update_metadata'])) {
            $this->updateMetadata = $opts['update_metadata'];
        }

        if ($channel !== null) {
            if (!$channel instanceof Channel) {
                throw new InvalidArgumentException(
                    'The channel argument is not a Momento\Transport\Channel object'
                );
            }
            $this->channel = $channel;
        } else {
            TransportRequirements::assertSupported();
            $this->channel = new Channel((string)$hostname);
        }
    }

    public function close(): void
    {
        $this->channel->close();
    }

    /**
     * @param string $method
     * @param Message $argument
     * @param array{0: class-string, 1: string} $deserialize
     * @param array<string, string[]> $metadata
     * @param array $options 'timeout' is a deadline in microseconds
     * @return UnaryCall
     */
    protected function _simpleRequest(
        $method,
        $argument,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        return $this->channel->startUnary(
            (string)$method,
            $argument,
            $deserialize,
            $this->applyUpdateMetadata($metadata, (string)$method, false),
            $options
        );
    }

    /**
     * @param string $method
     * @param Message $argument
     * @param array{0: class-string, 1: string} $deserialize
     * @param array<string, string[]> $metadata
     * @param array $options 'timeout' is a deadline in microseconds
     * @return ServerStreamingCall
     */
    protected function _serverStreamRequest(
        $method,
        $argument,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        return $this->channel->startServerStreaming(
            (string)$method,
            $argument,
            $deserialize,
            $this->applyUpdateMetadata($metadata, (string)$method, true),
            $options
        );
    }

    /**
     * @param array<string, string[]> $metadata
     * @return array<string, string[]>
     */
    private function applyUpdateMetadata(array $metadata, string $method, bool $isServerStreaming): array
    {
        if ($this->updateMetadata !== null) {
            $metadata = ($this->updateMetadata)($metadata, $method, $isServerStreaming);
        }

        return $metadata;
    }
}
