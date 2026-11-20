<?php
declare(strict_types=1);

namespace Momento\Transport;

use RuntimeException;

/**
 * Aborts decoding with a specific gRPC status. AbstractCall converts it into
 * the call status, so it never escapes the transport.
 *
 * @internal
 */
class StatusException extends RuntimeException
{
    private Status $status;

    public function __construct(Status $status)
    {
        parent::__construct($status->details, $status->code);
        $this->status = $status;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }
}
