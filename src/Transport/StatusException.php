<?php
declare(strict_types=1);

namespace Momento\Transport;

use RuntimeException;

/**
 * Internal signal used by the transport codecs to abort processing with a
 * specific gRPC status (frame truncation, compression violations, size cap).
 * Never escapes the transport: AbstractCall converts it into the call status.
 *
 * @internal
 */
class StatusException extends RuntimeException
{
    private Status $status;

    /**
     * @param Status $status the status this failure maps to
     */
    public function __construct(Status $status)
    {
        parent::__construct($status->details, $status->code);
        $this->status = $status;
    }

    /**
     * The gRPC status carried by this failure.
     *
     * @return Status
     */
    public function getStatus(): Status
    {
        return $this->status;
    }
}
