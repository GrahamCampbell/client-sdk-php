<?php
declare(strict_types=1);

namespace Momento\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\CancellationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps a rejected Guzzle transfer to a gRPC Status.
 */
class ErrorMapper
{
    private const ERRNOS_H2 = [16, 92]; // CURLE_HTTP2, CURLE_HTTP2_STREAM

    /**
     * Convert a promise rejection reason into the call's terminal Status.
     *
     * @param Throwable $reason the rejection reason
     * @param bool $hadDeadline whether the call carried a finite deadline
     * @param CallState $state per-call capture slot
     * @return Status
     * @throws Throwable rethrows $reason when it is not a mappable transfer failure
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

        if ($reason instanceof ConnectTimeoutException) {
            return ($hadDeadline && self::deadlineTimerWasBinding($state))
                ? new Status(StatusCode::DEADLINE_EXCEEDED, $reason->getMessage())
                : new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }
        if ($reason instanceof ResponseTimeoutException) {
            return new Status(StatusCode::DEADLINE_EXCEEDED, $reason->getMessage());
        }
        if ($reason instanceof NetworkTimeoutException) {
            return $hadDeadline
                ? new Status(StatusCode::DEADLINE_EXCEEDED, $reason->getMessage())
                : new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }
        if ($reason instanceof ConnectException) {
            return new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }
        if ($reason instanceof ResponseTransferException) {
            return in_array(self::errnoFromStats($state), self::ERRNOS_H2, true)
                ? (self::statusFromH2Reset($reason->getMessage())
                    ?? new Status(StatusCode::INTERNAL, $reason->getMessage()))
                : new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }
        if ($reason instanceof ResponseException) {
            return new Status(StatusCode::INTERNAL, $reason->getMessage());
        }
        if ($reason instanceof HandlerClosedException) {
            throw $reason;
        }
        if ($reason instanceof NetworkException) {
            return in_array(self::errnoFromStats($state), self::ERRNOS_H2, true)
                ? (self::statusFromH2Reset($reason->getMessage())
                    ?? new Status(StatusCode::INTERNAL, $reason->getMessage()))
                : new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
        }
        if ($reason instanceof RequestException) {
            return new Status(StatusCode::UNKNOWN, $reason->getMessage());
        }

        throw $reason;
    }

    /**
     * The PSR-7 response attached to a failure exception, when one exists.
     *
     * @param Throwable $reason the rejection reason
     * @return ResponseInterface|null
     */
    public static function responseFrom(Throwable $reason): ?ResponseInterface
    {
        return $reason instanceof ResponseException
            ? $reason->getResponse()
            : null;
    }

    /**
     * @param CallState $state per-call capture slot
     * @return int|null
     */
    private static function errnoFromStats(CallState $state): ?int
    {
        if ($state->stats === null) {
            return null;
        }
        $data = $state->stats->getHandlerErrorData();

        return is_int($data) ? $data : null;
    }

    /**
     * @param string $message the exception message
     * @return Status|null null when no reset detail is recoverable
     */
    private static function statusFromH2Reset(string $message): ?Status
    {
        $code = null;
        // libcurl >= 8.19 appends the nghttp2 error name after the hex code:
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

    /**
     * @param CallState $state per-call capture slot
     * @return bool
     */
    private static function deadlineTimerWasBinding(CallState $state): bool
    {
        return $state->deadlineMilliseconds !== null
            && $state->connectTimeoutMilliseconds !== null
            && $state->deadlineMilliseconds <= $state->connectTimeoutMilliseconds;
    }
}
