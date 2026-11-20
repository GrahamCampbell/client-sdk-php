<?php
declare(strict_types=1);

namespace Momento\Transport;

use GuzzleHttp\TransferStats;

/**
 * Per-call slot that the Channel's handler callbacks write into and the call
 * object later reads.
 *
 * @internal
 */
class CallState
{
    /**
     * @var array<string, string[]>|null null when on_trailers never fired
     */
    public ?array $trailers = null;

    /**
     * @var TransferStats|null the only source of the libcurl error number
     */
    public ?TransferStats $stats = null;

    /**
     * @var int|null the deadline in milliseconds, or null without one
     */
    public ?int $deadlineMilliseconds = null;

    /**
     * @var int|null the connect timeout in milliseconds
     */
    public ?int $connectTimeoutMilliseconds = null;
}
