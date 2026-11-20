<?php
declare(strict_types=1);

namespace Momento\Transport;

use Exception;
use Generator;
use Google\Protobuf\Internal\Message;

/**
 * A call that sends a single message and receives a stream of responses.
 *
 * Unlike ext-grpc, the whole transfer completes and is buffered in memory
 * before the first message is yielded, so long-lived streams such as topic
 * subscriptions are unsupported.
 */
class ServerStreamingCall extends AbstractCall
{
    /**
     * @return Generator<Message>
     * @throws Exception when a message payload does not parse
     */
    public function responses(): Generator
    {
        $this->complete();
        foreach ($this->messages as $payload) {
            yield $this->deserializeMessage($payload);
        }
    }

    public function getStatus(): Status
    {
        $this->complete();

        return $this->status;
    }
}
