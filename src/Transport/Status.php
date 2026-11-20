<?php
declare(strict_types=1);

namespace Momento\Transport;

use Momento\Utilities\_ErrorConverter as ErrorConverter;
use stdClass;

/**
 * The terminal status of a gRPC call, with the same public properties as the
 * status object that ext-grpc produced.
 */
class Status
{
    /**
     * Keys that never appear as user-visible metadata.
     */
    public const TRANSPORT_KEYS = [
        'grpc-status',
        'grpc-message',
        'content-type',
        'grpc-encoding',
        'grpc-accept-encoding',
    ];

    public int $code;

    public string $details;

    /** @var array<string, list<string>> the trailing metadata */
    public array $metadata;

    /**
     * @param array<string, list<string>> $metadata
     */
    public function __construct(int $code, string $details = '', array $metadata = [])
    {
        $this->code = $code;
        $this->details = $details;
        $this->metadata = $metadata;
    }

    public function isOk(): bool
    {
        return $this->code === StatusCode::OK;
    }

    /**
     * Build a Status from a header or trailer block, or return null when the
     * block carries no grpc-status.
     *
     * @param array<string, string[]> $block
     */
    public static function fromBlock(array $block): ?self
    {
        $rawStatus = null;
        $rawMessage = null;
        foreach ($block as $key => $values) {
            $lower = strtolower((string)$key);
            if ($rawStatus === null && $lower === 'grpc-status') {
                $rawStatus = isset($values[0]) ? trim((string)$values[0]) : '';
            } elseif ($rawMessage === null && $lower === 'grpc-message') {
                $rawMessage = isset($values[0]) ? (string)$values[0] : '';
            }
        }

        if ($rawStatus === null) {
            return null;
        }

        $metadata = Metadata::decodeBlock($block, self::TRANSPORT_KEYS);

        if ($rawStatus === '' || !ctype_digit($rawStatus)) {
            return new self(
                StatusCode::UNKNOWN,
                sprintf('Error parsing gRPC status "%s"', $rawStatus),
                $metadata
            );
        }

        return new self((int)$rawStatus, rawurldecode($rawMessage ?? ''), $metadata);
    }
}
