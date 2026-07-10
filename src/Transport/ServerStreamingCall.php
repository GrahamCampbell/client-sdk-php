<?php
declare(strict_types=1);

namespace Momento\Transport;

use Exception;
use Generator;
use Google\Protobuf\Internal\Message;

/**
 * An active call that sends a single message and receives a stream of responses:
 * the server-streaming call replacement returned by BaseStub::_serverStreamRequest().
 *
 * Unlike ext-grpc, responses are NOT delivered incrementally: the entire
 * transfer completes (including trailers) before the first message is
 * yielded, and every decoded payload is buffered in memory. This suits the
 * finite GetBatch/SetBatch streams the SDK issues; long-lived streams
 * (e.g. Pubsub subscriptions) are unsupported by this transport.
 */
class ServerStreamingCall extends AbstractCall
{
    /**
     * Iterate the streamed response messages in wire order.
     *
     * @return Generator yields Message
     * @throws Exception when a message payload does not parse
     */
    public function responses(): Generator
    {
        $this->complete();
        foreach ($this->messages as $payload) {
            yield $this->deserializeMessage($payload);
        }
    }

    /**
     * The terminal status of the stream.
     *
     * @return Status object with int code, string details, array metadata
     */
    public function getStatus(): Status
    {
        $this->complete();

        return $this->status;
    }
}
