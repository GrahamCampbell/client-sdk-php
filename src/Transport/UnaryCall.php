<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use Throwable;

/**
 * An active call that sends a single message and receives a single response:
 * the unary call replacement returned by BaseStub::_simpleRequest().
 */
class UnaryCall extends AbstractCall
{
    /**
     * Wait for the response message and terminal status.
     *
     * @return array{0: Message|null, 1: Status} [response|null, status]
     * @throws Throwable only for unmappable transport/programming failures
     */
    public function wait(): array
    {
        $this->complete();
        $status = $this->status;

        if (!$status->isOk()) {
            return [null, $status];
        }

        $count = count($this->messages);
        if ($count === 0) {
            return [null, new Status(
                StatusCode::UNIMPLEMENTED,
                'Unary call completed without a response message',
                $status->metadata
            )];
        }
        if ($count > 1) {
            return [null, new Status(
                StatusCode::UNIMPLEMENTED,
                'Unary call received multiple response messages',
                $status->metadata
            )];
        }

        try {
            $response = $this->deserializeMessage($this->messages[0]);
        } catch (Throwable $e) {
            return [null, new Status(
                StatusCode::INTERNAL,
                'Error parsing response proto: ' . $e->getMessage(),
                $status->metadata
            )];
        }

        return [$response, $status];
    }
}
