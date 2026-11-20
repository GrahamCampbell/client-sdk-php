<?php
declare(strict_types=1);

namespace Momento\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\CancellationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps a rejected Guzzle transfer to a gRPC Status.
 */
class ErrorMapper
{
    private const ERRNO_TIMEOUT = 28; // CURLE_OPERATION_TIMEDOUT

    private const ERRNO_H2_STREAM = 92; // CURLE_HTTP2_STREAM

    private const ERRNOS_H2 = [16, 92]; // CURLE_HTTP2, CURLE_HTTP2_STREAM

    private const ERRNOS_NETWORK = [5, 6, 7, 16, 35, 51, 52, 60, 83, 90, 91, 97, 98];

    private const ERRNOS_MID_TRANSFER = [18, 55, 56];

    /**
     * libcurl's connect-phase wordings for CURLE_OPERATION_TIMEDOUT, which
     * Guzzle 7 does not otherwise tell apart from an operation timeout.
     */
    private const CONNECT_TIMEOUT_MESSAGES = [
        'Connection timed out',
        'Connection timeout',
        'Connection time-out',
        'Resolving timed out',
        'name lookup timed out',
        'Proxy CONNECT aborted due to timeout',
        'SSL connection timeout',
    ];

    /**
     * @throws Throwable rethrows $reason when it is not a transfer failure
     */
    public static function map(Throwable $reason, bool $hadDeadline, CallState $state): Status
    {
        if ($reason instanceof CancellationException) {
            return new Status(StatusCode::CANCELLED, 'Cancelled');
        }

        $response = self::responseFrom($reason);
        if ($response !== null) {
            $status = Status::fromBlock($response->getHeaders());
            if ($status !== null) {
                return $status;
            }
        }

        if ($reason instanceof ConnectException) {
            $errno = $reason->getHandlerContext()['errno'] ?? null;
            if ($errno === self::ERRNO_TIMEOUT && $hadDeadline) {
                if (!self::isConnectPhaseTimeout($reason->getMessage()) || self::deadlineTimerWasBinding($state)) {
                    return new Status(StatusCode::DEADLINE_EXCEEDED, $reason->getMessage());
                }

                return new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
            }

            return new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }

        if ($reason instanceof RequestException) {
            $errno = $reason->getHandlerContext()['errno'] ?? null;
            if (in_array($errno, self::ERRNOS_H2, true)) {
                $status = self::statusFromH2Reset($reason->getMessage());
                if ($status !== null) {
                    return $status;
                }
            }
            // A stream reset is a protocol error, whereas CURLE_HTTP2 is
            // also what a connection dropped mid-call reports.
            if ($errno === self::ERRNO_H2_STREAM) {
                return new Status(StatusCode::INTERNAL, $reason->getMessage());
            }
            if (in_array($errno, self::ERRNOS_MID_TRANSFER, true) || in_array($errno, self::ERRNOS_NETWORK, true)) {
                return new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
            }

            return new Status(StatusCode::UNKNOWN, $reason->getMessage());
        }

        throw $reason;
    }

    public static function responseFrom(Throwable $reason): ?ResponseInterface
    {
        return $reason instanceof RequestException ? $reason->getResponse() : null;
    }

    private static function statusFromH2Reset(string $message): ?Status
    {
        $code = null;
        // Newer libcurl appends the nghttp2 error name after the hex code:
        // "HTTP/2 stream 1 reset by server (error 0x8 CANCEL)".
        if (preg_match('/HTTP\/2 stream \d+ reset by (?:server|curl) \(error 0x([0-9a-fA-F]+)(?: [A-Z0-9_]+)?\)/', $message, $m) === 1) {
            $code = (int)hexdec($m[1]);
        } elseif (preg_match('/HTTP\/2 stream \d+ was not closed cleanly: [A-Z0-9_]+ \(err (\d+)\)/', $message, $m) === 1) {
            $code = (int)$m[1];
        }

        switch ($code) {
            case 8:
                return new Status(StatusCode::CANCELLED, $message);
            case 11:
                return new Status(StatusCode::RESOURCE_EXHAUSTED, $message);
            case 12:
                return new Status(StatusCode::PERMISSION_DENIED, $message);
            default:
                return null;
        }
    }

    private static function isConnectPhaseTimeout(string $message): bool
    {
        foreach (self::CONNECT_TIMEOUT_MESSAGES as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function deadlineTimerWasBinding(CallState $state): bool
    {
        return $state->deadlineMilliseconds !== null
            && $state->connectTimeoutMilliseconds !== null
            && $state->deadlineMilliseconds <= $state->connectTimeoutMilliseconds;
    }
}
