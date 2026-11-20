<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\Message;
use Throwable;

/**
 * A call that sends a single message and receives a single response.
 */
class UnaryCall extends AbstractCall
{
    /**
     * @return array{0: Message|null, 1: Status} [response, status]
     * @throws Throwable when the transfer failed for a reason that has no gRPC status
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
