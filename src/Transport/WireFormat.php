<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\GPBDecodeException;

/**
 * Schema-agnostic protobuf wire-format validator. The pure-PHP protobuf
 * runtime treats a tag varint that fails to decode (truncated or overlong)
 * as a clean end of input and silently discards the rest of the payload;
 * the C extension rejects it. Walking the tag/value structure and requiring
 * the buffer to be consumed exactly restores the strict behavior, so a
 * corrupt frame surfaces as a decode error on every backend.
 *
 * @internal
 */
class WireFormat
{
    private const WIRE_TYPE_VARINT = 0;
    private const WIRE_TYPE_FIXED64 = 1;
    private const WIRE_TYPE_LEN = 2;
    private const WIRE_TYPE_FIXED32 = 5;

    private const MAX_VARINT_BYTES = 10;

    /**
     * Assert a message payload is structurally well-formed: every tag and
     * varint terminates, every field's bytes are present, field numbers are
     * non-zero, wire types are valid (groups are rejected, matching both
     * protobuf backends for these proto3 messages), and the payload ends
     * exactly on a field boundary.
     *
     * @param string $payload one frame's message bytes
     * @return void
     * @throws GPBDecodeException on structural corruption
     */
    public static function assertValid(string $payload): void
    {
        $length = strlen($payload);
        $offset = 0;

        while ($offset < $length) {
            $tag = self::varint($payload, $length, $offset);
            if (($tag >> 3) === 0) {
                throw new GPBDecodeException('Invalid field number 0.');
            }

            switch ($tag & 0x7) {
                case self::WIRE_TYPE_VARINT:
                    self::varint($payload, $length, $offset);
                    break;
                case self::WIRE_TYPE_FIXED64:
                    $offset += 8;
                    break;
                case self::WIRE_TYPE_LEN:
                    $fieldLength = self::varint($payload, $length, $offset);
                    if ($fieldLength < 0 || $fieldLength > $length - $offset) {
                        throw new GPBDecodeException('Unexpected EOF inside length delimited data.');
                    }
                    $offset += $fieldLength;
                    break;
                case self::WIRE_TYPE_FIXED32:
                    $offset += 4;
                    break;
                default:
                    throw new GPBDecodeException('Unexpected wire type.');
            }

            if ($offset > $length) {
                throw new GPBDecodeException('Unexpected EOF inside fixed length data.');
            }
        }
    }

    /**
     * Decode one varint at $offset, advancing it past the terminating byte.
     *
     * @param string $payload the message bytes
     * @param int $length strlen($payload)
     * @param int $offset read position, advanced on success
     * @return int the decoded value (may wrap negative for 10-byte varints)
     * @throws GPBDecodeException when the varint runs past the buffer end or
     *                            past the 10-byte maximum
     */
    private static function varint(string $payload, int $length, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        while (true) {
            if ($offset >= $length) {
                throw new GPBDecodeException('Unexpected EOF inside varint.');
            }
            if ($shift >= self::MAX_VARINT_BYTES * 7) {
                throw new GPBDecodeException('Varint overflow.');
            }
            $byte = ord($payload[$offset]);
            $offset++;
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
        }
    }
}
