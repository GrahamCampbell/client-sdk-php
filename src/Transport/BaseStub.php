<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use InvalidArgumentException;

/**
 * Base class for the generated Momento client stubs, replacing the ext-grpc base stub
 * with the same constructor and _simpleRequest / _serverStreamRequest shapes.
 *
 * Divergences from the ext-grpc base stub, for direct low-level users:
 * $opts honors only 'update_metadata' (other channel arguments are ignored,
 * and constructing without an injected Channel builds a default one), and
 * the update_metadata callback receives (array $metadata, string $method,
 * bool $isServerStreaming) rather than (array $metadata, string $jwt_aud_uri).
 */
class BaseStub
{
    private Channel $channel;

    /** @var callable|null fn(array $metadata, string $method, bool $isServerStreaming): array */
    private $updateMetadata;

    /**
     * @param string $hostname endpoint as 'host' or 'host:port'; used only
     *                         when no channel is injected
     * @param array $opts
     * @param Channel|null $channel an already created transport Channel
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

    /**
     * Close the underlying channel.
     *
     * @return void
     */
    public function close(): void
    {
        $this->channel->close();
    }

    /**
     * Call a remote method that takes a single argument and returns a single result.
     *
     * @param string $method full method path, e.g. '/cache_client.Scs/Get'
     * @param Message $argument request message
     * @param array{0: class-string, 1: string} $deserialize [class, 'decode'] pair
     * @param array<string, string[]> $metadata metadata map (values are lists)
     * @param array $options call options; 'timeout' is a deadline in MICROSECONDS
     * @return UnaryCall the active call object
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
     * Call a remote method that takes a single argument and returns a stream of responses.
     *
     * @param string $method full method path, e.g. '/cache_client.Scs/GetBatch'
     * @param Message $argument request message
     * @param array{0: class-string, 1: string} $deserialize [class, 'decode'] pair
     * @param array<string, string[]> $metadata metadata map (values are lists)
     * @param array $options call options; 'timeout' is a deadline in MICROSECONDS
     * @return ServerStreamingCall the active call object
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
     * Run the update_metadata hook, when configured, before validation/normalization.
     *
     * @param array<string, string[]> $metadata caller metadata
     * @param string $method full method path
     * @param bool $isServerStreaming whether this is a server-streaming call
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
