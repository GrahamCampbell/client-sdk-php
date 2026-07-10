<?php
declare(strict_types=1);

namespace Momento\Transport;

/**
 * Mutable per-call slot the Channel's record-only on_trailers callback
 * writes into and the call object later reads.
 *
 * @internal
 */
class CallState
{
    /**
     * Trailers exactly as delivered by on_trailers (casing preserved,
     * array<string, list<string>>), or null when it never fired.
     *
     * @var array<string, string[]>|null
     */
    public ?array $trailers = null;

    /**
     * The EFFECTIVE CURLOPT_TIMEOUT_MS value (whole milliseconds,
     * GrpcTimeout::toGuzzleMilliseconds) when the call carried a finite
     * deadline, or null.
     *
     * @var int|null
     */
    public ?int $deadlineMilliseconds = null;

    /**
     * The EFFECTIVE CURLOPT_CONNECTTIMEOUT_MS value (whole milliseconds)
     * for this call.
     *
     * @var int|null
     */
    public ?int $connectTimeoutMilliseconds = null;
}
