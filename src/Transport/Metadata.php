<?php
declare(strict_types=1);

namespace Momento\Transport;

use InvalidArgumentException;

/**
 * Metadata codec: outgoing validation/normalization (exact
 * ext-grpc base-stub metadata behavior), -bin base64
 * handling in both directions, and incoming header/trailer block decoding
 * into the array<string, list<string>> shape the SDK consumes.
 */
class Metadata
{
    private const KEY_PATTERN = '/^[.A-Za-z\d_-]+$/D';

    private const BIN_SUFFIX = '-bin';

    /**
     * Validate and lowercase an outgoing metadata map. Same accept/reject
     * behavior and exception message as the ext-grpc base stub (keys matching
     * [.A-Za-z0-9_-]+, then strtolower) except that the /D modifier also
     * rejects keys with a trailing newline, which the ext-grpc PHP layer
     * accepted; plus the value checks ext-grpc performed at the C boundary:
     * every value must be a list of strings, and non-binary values must be
     * printable ASCII. \x20 is legal anywhere in a value, so leading/trailing
     * spaces are NOT rejected; PSR-7's OWS trim is the owned delta.
     *
     * @param array<string, string[]> $metadata caller-supplied metadata
     * @return array<string, list<string>> validated, lowercased-key metadata
     * @throws InvalidArgumentException on an invalid key, value shape, or value byte
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
     * Encode normalized metadata for the wire: -bin values are
     * base64-encoded without padding (RFC 4648 section 4), one header line
     * per list element; ASCII values pass through unchanged.
     *
     * @param array<string, list<string>> $metadata validated metadata
     * @return array<string, list<string>> header name => header values
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
     * Decode an incoming header or trailer block into metadata: keys are
     * lowercased (same-key entries merge in order), keys in $stripKeys are
     * dropped, and -bin values are split on ',' (intermediaries may join
     * duplicates) then base64-decoded accepting padded or unpadded input.
     * An undecodable -bin part passes through raw (lenient receive).
     *
     * @param array<string, string[]> $block header/trailer map, casing as received
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

    /**
     * Whether a (lowercased) metadata key is a binary key.
     *
     * @param string $key the metadata key
     * @return bool
     */
    public static function isBinaryKey(string $key): bool
    {
        return substr($key, -4) === self::BIN_SUFFIX;
    }
}
