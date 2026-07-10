<?php
declare(strict_types=1);

namespace Momento\Transport;

/**
 * Deadline conversions from the Grpc-shaped 'timeout' option, which is in
 * MICROSECONDS (ext-grpc call semantics; ScsDataClient multiplies its
 * millisecond deadline by 1000).
 */
class GrpcTimeout
{
    private const MAX_TIMEOUT_VALUE = 99999999; // 8 digits, spec cap

    /**
     * Encode a microseconds deadline as a grpc-timeout header value: ASCII
     * integer of at most 8 digits plus a unit, rescaling u -> m -> S -> M -> H
     * with round-up division so the server-side deadline is never shorter
     * than the locally-enforced one. Non-positive inputs clamp to '1n'
     * (C-core parity).
     *
     * @param int $micros deadline in microseconds
     * @return string e.g. '5000000u'
     */
    public static function encode(int $micros): string
    {
        if ($micros <= 0) {
            return '1n';
        }
        if ($micros <= self::MAX_TIMEOUT_VALUE) {
            return $micros . 'u';
        }
        $millis = intdiv($micros - 1, 1000) + 1;
        if ($millis <= self::MAX_TIMEOUT_VALUE) {
            return $millis . 'm';
        }
        $seconds = intdiv($millis - 1, 1000) + 1;
        if ($seconds <= self::MAX_TIMEOUT_VALUE) {
            return $seconds . 'S';
        }
        $minutes = intdiv($seconds - 1, 60) + 1;
        if ($minutes <= self::MAX_TIMEOUT_VALUE) {
            return $minutes . 'M';
        }
        $hours = intdiv($minutes - 1, 60) + 1;

        return min($hours, self::MAX_TIMEOUT_VALUE) . 'H';
    }

    /**
     * Convert a microseconds deadline to the Guzzle 'timeout' option: float
     * seconds, rounded UP to a whole millisecond and then offset by half a
     * millisecond so both lines' float-to-milliseconds truncation lands on
     * the exact millisecond.
     *
     * @param int $micros deadline in microseconds
     * @return float seconds
     */
    public static function toGuzzleSeconds(int $micros): float
    {
        return (self::toGuzzleMilliseconds($micros) + 0.5) / 1000.0;
    }

    /**
     * The EFFECTIVE whole-millisecond deadline both lines hand to
     * CURLOPT_TIMEOUT_MS for this deadline; toGuzzleSeconds() is exactly
     * this value plus the half-millisecond truncation offset.
     *
     * @param int $micros deadline in microseconds
     * @return int whole milliseconds (>= 1)
     */
    public static function toGuzzleMilliseconds(int $micros): int
    {
        return (int)ceil(max($micros, 1) / 1000);
    }
}
