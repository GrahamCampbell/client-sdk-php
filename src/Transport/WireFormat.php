<?php
declare(strict_types=1);

namespace Momento\Transport;

use Google\Protobuf\Internal\GPBDecodeException;

/**
 * Schema-agnostic protobuf wire-format validator. The pure-PHP protobuf
 * runtime treats an undecodable tag as a clean end of input and discards the
 * rest of the payload, where the C extension rejects it.
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
     * @throws GPBDecodeException when the payload is not structurally well-formed
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
     * @return int the decoded value, which may wrap negative for a 10-byte varint
     * @throws GPBDecodeException when the varint is truncated or overlong
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
