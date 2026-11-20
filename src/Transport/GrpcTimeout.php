<?php
declare(strict_types=1);

namespace Momento\Transport;

/**
 * Deadline conversions from the 'timeout' call option, which is in
 * microseconds as it was for ext-grpc.
 */
class GrpcTimeout
{
    private const MAX_TIMEOUT_VALUE = 99999999;

    /**
     * Encode a deadline as a grpc-timeout header value of at most 8 digits
     * plus a unit, rounding up so that the server never gives up first.
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
     * The deadline as a Guzzle 'timeout' in seconds, offset by half a
     * millisecond so that Guzzle's truncation lands on the exact millisecond.
     */
    public static function toGuzzleSeconds(int $micros): float
    {
        return (self::toGuzzleMilliseconds($micros) + 0.5) / 1000.0;
    }

    /**
     * The whole-millisecond deadline that reaches CURLOPT_TIMEOUT_MS.
     */
    public static function toGuzzleMilliseconds(int $micros): int
    {
        return (int)ceil(max($micros, 1) / 1000);
    }
}
