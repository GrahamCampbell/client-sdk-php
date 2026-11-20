<?php
declare(strict_types=1);

namespace Momento\Transport;

use InvalidArgumentException;

/**
 * Validates and encodes outgoing metadata, and decodes incoming header and
 * trailer blocks, base64-coding the values of -bin keys in both directions.
 */
class Metadata
{
    private const KEY_PATTERN = '/^[.A-Za-z\d_-]+$/D';

    private const BIN_SUFFIX = '-bin';

    /**
     * Validate and lowercase the keys of an outgoing metadata map, as the
     * ext-grpc base stub did.
     *
     * @param array<string, string[]> $metadata
     * @return array<string, list<string>>
     * @throws InvalidArgumentException on an invalid key or value
     */
    public static function validateAndNormalize(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $values) {
            $key = (string)$key;
            if (!preg_match(self::KEY_PATTERN, $key)) {
                throw new InvalidArgumentException(
                    'Metadata keys must be nonempty strings containing only '
                    . 'alphanumeric characters, hyphens, underscores and dots'
                );
            }
            if (!is_array($values)) {
                throw new InvalidArgumentException(
                    "Metadata value for key \"{$key}\" must be an array of strings"
                );
            }
            $lower = strtolower($key);
            $list = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        "Metadata value for key \"{$key}\" must be an array of strings"
                    );
                }
                if (!self::isBinaryKey($lower) && preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                    throw new InvalidArgumentException(
                        "Metadata value for key \"{$key}\" must contain only printable ASCII characters"
                    );
                }
                $list[] = $value;
            }
            $normalized[$lower] = $list;
        }

        return $normalized;
    }

    /**
     * @param array<string, list<string>> $metadata
     * @return array<string, list<string>>
     */
    public static function encode(array $metadata): array
    {
        $headers = [];
        foreach ($metadata as $key => $values) {
            $encoded = [];
            foreach ($values as $value) {
                $encoded[] = self::isBinaryKey($key) ? rtrim(base64_encode($value), '=') : $value;
            }
            $headers[$key] = $encoded;
        }

        return $headers;
    }

    /**
     * Decode a header or trailer block, lowercasing keys and passing any
     * undecodable -bin value through raw.
     *
     * @param array<string, string[]> $block
     * @param string[] $stripKeys lowercase keys to drop
     * @return array<string, list<string>>
     */
    public static function decodeBlock(array $block, array $stripKeys = []): array
    {
        $metadata = [];
        foreach ($block as $key => $values) {
            $key = strtolower((string)$key);
            if (in_array($key, $stripKeys, true)) {
                continue;
            }
            $decoded = [];
            foreach ($values as $value) {
                $value = (string)$value;
                if (self::isBinaryKey($key)) {
                    // Intermediaries may join duplicate headers with commas.
                    foreach (explode(',', $value) as $part) {
                        $part = trim($part);
                        $padded = $part . str_repeat('=', (4 - strlen($part) % 4) % 4);
                        $bytes = base64_decode($padded, true);
                        $decoded[] = ($bytes === false) ? $part : $bytes;
                    }
                } else {
                    $decoded[] = $value;
                }
            }
            if (isset($metadata[$key])) {
                $metadata[$key] = array_merge($metadata[$key], $decoded);
            } else {
                $metadata[$key] = $decoded;
            }
        }

        return $metadata;
    }

    public static function isBinaryKey(string $key): bool
    {
        return substr($key, -4) === self::BIN_SUFFIX;
    }
}
