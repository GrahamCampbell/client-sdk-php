<?php
declare(strict_types=1);

namespace Momento\Utilities;

use Momento\Cache\Errors\AlreadyExistsError;
use Momento\Cache\Errors\AuthenticationError;
use Momento\Cache\Errors\BadRequestError;
use Momento\Cache\Errors\CacheNotFoundError;
use Momento\Cache\Errors\CancelledError;
use Momento\Cache\Errors\FailedPreconditionError;
use Momento\Cache\Errors\InternalServerError;
use Momento\Cache\Errors\InvalidArgumentError;
use Momento\Cache\Errors\ItemNotFoundError;
use Momento\Cache\Errors\LimitExceededError;
use Momento\Cache\Errors\NotFoundError;
use Momento\Cache\Errors\PermissionError;
use Momento\Cache\Errors\SdkError;
use Momento\Cache\Errors\ServerUnavailableError;
use Momento\Cache\Errors\TimeoutError;
use Momento\Cache\Errors\UnknownError;
use Momento\Cache\Errors\UnknownServiceError;
use Momento\Transport\StatusCode;

class _ErrorConverter
{

    public static array $rpcToError = [
        StatusCode::INVALID_ARGUMENT => InvalidArgumentError::class,
        StatusCode::OUT_OF_RANGE => BadRequestError::class,
        StatusCode::UNIMPLEMENTED => BadRequestError::class,
        StatusCode::FAILED_PRECONDITION => FailedPreconditionError::class,
        StatusCode::CANCELLED => CancelledError::class,
        StatusCode::DEADLINE_EXCEEDED => TimeoutError::class,
        StatusCode::PERMISSION_DENIED => PermissionError::class,
        StatusCode::UNAUTHENTICATED => AuthenticationError::class,
        StatusCode::RESOURCE_EXHAUSTED => LimitExceededError::class,
        StatusCode::ALREADY_EXISTS => AlreadyExistsError::class,
        StatusCode::NOT_FOUND => NotFoundError::class,
        StatusCode::UNKNOWN => UnknownServiceError::class,
        StatusCode::ABORTED => InternalServerError::class,
        StatusCode::INTERNAL => InternalServerError::class,
        StatusCode::UNAVAILABLE => ServerUnavailableError::class,
        StatusCode::DATA_LOSS => InternalServerError::class
    ];

    public static function convert($grpcStatus, ?array $metadata = null): SdkError
    {
        $status = $grpcStatus->code;
        $details = $grpcStatus->details;
        if (array_key_exists($status, self::$rpcToError)) {
            // If the status code is STATUS_NOT_FOUND, we need to check the details to determine if it was a
            // cache or item that was not found.
            if ($status === StatusCode::NOT_FOUND) {
                if (!array_key_exists("err", $grpcStatus->metadata)) {
                    $class = CacheNotFoundError::class;
                } elseif ($grpcStatus->metadata["err"][0] == "item_not_found") {
                    $class = ItemNotFoundError::class;
                } else {
                    $class = CacheNotFoundError::class;
                }
            } else {
                $class = self::$rpcToError[$status];
            }
            return new $class($details, $status, null, $grpcStatus->metadata);
        }

        return new UnknownError(
            "CacheService failed due to an internal error", 0, null, $metadata
        );
    }
}
