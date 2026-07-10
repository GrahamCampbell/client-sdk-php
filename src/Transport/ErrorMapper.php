<?php
declare(strict_types=1);

namespace Momento\Transport;

use GuzzleHttp\ClientInterface;
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
 * Maps a rejected Guzzle transfer to a gRPC Status, branching on the
 * installed Guzzle line (7.14 vs 8.0 exception taxonomies and errno access).
 */
class ErrorMapper
{
    private const ERRNO_TIMEOUT = 28; // CURLE_OPERATION_TIMEDOUT

    private const ERRNOS_H2 = [16, 92]; // CURLE_HTTP2, CURLE_HTTP2_STREAM

    private const ERRNOS_NETWORK = [5, 6, 7, 35, 52];

    private const ERRNOS_MID_TRANSFER = [18, 55, 56];

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

        if (ClientInterface::MAJOR_VERSION >= 8) {
            return self::mapGuzzle8($reason, $hadDeadline, $state);
        }

        return self::mapGuzzle7($reason, $hadDeadline, $state);
    }

    /**
     * The PSR-7 response attached to a failure exception, when one exists.
     *
     * @param Throwable $reason the rejection reason
     * @return ResponseInterface|null
     */
    public static function responseFrom(Throwable $reason): ?ResponseInterface
    {
        if (ClientInterface::MAJOR_VERSION >= 8) {
            return $reason instanceof ResponseException
                ? $reason->getResponse()
                : null;
        }

        return $reason instanceof RequestException ? $reason->getResponse() : null;
    }

    /**
     * @param Throwable $reason the rejection reason
     * @param bool $hadDeadline whether a finite deadline governed
     * @param CallState $state per-call capture slot (timer values)
     * @return Status
     * @throws Throwable when not mappable
     */
    private static function mapGuzzle7(Throwable $reason, bool $hadDeadline, CallState $state): Status
    {
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
                return self::statusFromH2Reset($reason->getMessage())
                    ?? new Status(StatusCode::INTERNAL, $reason->getMessage());
            }
            if (in_array($errno, self::ERRNOS_MID_TRANSFER, true) || in_array($errno, self::ERRNOS_NETWORK, true)) {
                return new Status(StatusCode::UNAVAILABLE, $reason->getMessage());
            }

            return new Status(StatusCode::UNKNOWN, $reason->getMessage());
        }

        throw $reason;
    }

    /**
     * @param Throwable $reason the rejection reason
     * @param bool $hadDeadline whether a finite deadline governed
     * @param CallState $state per-call capture slot
     * @return Status
     * @throws Throwable when not mappable
     */
    private static function mapGuzzle8(Throwable $reason, bool $hadDeadline, CallState $state): Status
    {
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
     * The connect-phase wordings libcurl uses for CURLE_OPERATION_TIMEDOUT,
     * mirroring Guzzle 8's CURL_CONNECT_TIMEOUT_ERRORS (matched
     * case-insensitively); this is the sole connect-vs-operation
     * disambiguator on the Guzzle 7 path.
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
     * @param string $message the exception message
     * @return bool
     */
    private static function isConnectPhaseTimeout(string $message): bool
    {
        foreach (self::CONNECT_TIMEOUT_MESSAGES as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
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
