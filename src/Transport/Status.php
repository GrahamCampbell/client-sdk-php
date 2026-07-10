<?php
declare(strict_types=1);

namespace Momento\Transport;

use Momento\Utilities\_ErrorConverter as ErrorConverter;
use stdClass;

/**
 * The terminal status of a gRPC call. Shape-compatible with the stdClass
 * status object ext-grpc produced (public ->code, ->details, ->metadata),
 * which is exactly what ErrorConverter consumes. The
 * metadata property is the TRAILING metadata and is always an array (never
 * null): _ErrorConverter calls array_key_exists("err", $status->metadata)
 * unconditionally on NOT_FOUND.
 */
class Status
{
    /**
     * Keys that never appear as user-visible metadata: the status trio is
     * lifted into code/details, and the content-type/encoding headers are
     * transport-level (C-core strips them as typed traits). Everything else
     * passes through - including grpc-status-details-bin (decoded raw bytes)
     * and Momento's "err" convention.
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

    /** @var array<string, list<string>> */
    public array $metadata;

    /**
     * @param int $code a StatusCode constant (codes outside 0-16 are propagated as-is)
     * @param string $details human-readable status message (already percent-decoded)
     * @param array<string, list<string>> $metadata trailing metadata
     */
    public function __construct(int $code, string $details = '', array $metadata = [])
    {
        $this->code = $code;
        $this->details = $details;
        $this->metadata = $metadata;
    }

    /**
     * Whether this status is OK (code 0).
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->code === StatusCode::OK;
    }

    /**
     * Build a Status from a header or trailer block that carries grpc-status,
     * or return null when the block has none (the trailers-only/normal-path
     * discriminator and the R1 precedence probe).
     *
     * grpc-status parsing: ASCII decimal via ctype_digit; non-numeric or empty
     * values produce UNKNOWN with details 'Error parsing gRPC status "<raw>"'.
     * grpc-message is decoded leniently with rawurldecode (broken %-escapes
     * pass through untouched); absent means ''. First value wins when either
     * key is duplicated. All other keys become trailing metadata via
     * Metadata::decodeBlock (lowercased, -bin base64-decoded, TRANSPORT_KEYS
     * stripped).
     *
     * @param array<string, string[]> $block header/trailer map, casing as received
     * @return Status|null
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
