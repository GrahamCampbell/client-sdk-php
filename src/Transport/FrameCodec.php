<?php
declare(strict_types=1);

namespace Momento\Transport;

/**
 * Encoder and incremental decoder for gRPC length-prefixed message frames:
 * 1-byte Compressed-Flag + 4-byte big-endian unsigned length + payload.
 * Requests always frame with flag 0 (this client only ever sends identity).
 *
 * The decoder tolerates arbitrary chunking: partial prefixes and partial
 * payloads across feed() boundaries are handled, and multiple complete
 * frames per chunk are all returned. Failures are reported as
 * StatusException (RESOURCE_EXHAUSTED for the size cap, INTERNAL for
 * framing/compression violations) per the gRPC spec.
 */
class FrameCodec
{
    private const PREFIX_LENGTH = 5;

    private int $maxReceiveMessageBytes;

    private ?string $grpcEncoding;

    private string $buffer = '';

    private ?int $pendingLength = null;

    /**
     * @param int $maxReceiveMessageBytes per-message receive cap (C-core default 4 MiB)
     * @param string|null $grpcEncoding the response's grpc-encoding header value, if any
     */
    public function __construct(int $maxReceiveMessageBytes, ?string $grpcEncoding = null)
    {
        $this->maxReceiveMessageBytes = $maxReceiveMessageBytes;
        $this->grpcEncoding = ($grpcEncoding === null || $grpcEncoding === '') ? null : $grpcEncoding;
    }

    /**
     * Frame a serialized protobuf message for the request body. Zero-length
     * messages are valid ('' frames to "\x00\x00\x00\x00\x00").
     *
     * @param string $message serialized protobuf bytes
     * @return string the 5-byte-prefixed frame
     */
    public static function encode(string $message): string
    {
        return "\x00" . pack('N', strlen($message)) . $message;
    }

    /**
     * Feed the decoder a chunk of body bytes; returns every message payload
     * completed by this chunk (possibly none, possibly several).
     *
     * @param string $chunk raw body bytes
     * @return string[] completed message payloads, in wire order
     * @throws StatusException on an invalid flag, a compression violation, or
     *                         a message exceeding the receive cap
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $messages = [];

        while (true) {
            if ($this->pendingLength === null) {
                if (strlen($this->buffer) < self::PREFIX_LENGTH) {
                    break;
                }
                $flag = ord($this->buffer[0]);
                $length = unpack('N', substr($this->buffer, 1, 4))[1];
                $this->checkFlag($flag);
                // On 32-bit PHP a length >= 2^31 unpacks negative; treat it
                // as over-cap rather than letting the comparisons underflow.
                if ($length < 0 || $length > $this->maxReceiveMessageBytes) {
                    throw new StatusException(new Status(
                        StatusCode::RESOURCE_EXHAUSTED,
                        sprintf('Received message larger than max (%u vs. %d)', $length, $this->maxReceiveMessageBytes)
                    ));
                }
                $this->pendingLength = $length;
                $this->buffer = substr($this->buffer, self::PREFIX_LENGTH);
            }

            if (strlen($this->buffer) < $this->pendingLength) {
                break;
            }

            $messages[] = substr($this->buffer, 0, $this->pendingLength);
            $this->buffer = substr($this->buffer, $this->pendingLength);
            $this->pendingLength = null;
        }

        return $messages;
    }

    /**
     * Signal end of body. Any buffered leftover (a partial prefix or a
     * partial payload) is a framing violation.
     *
     * @return void
     * @throws StatusException INTERNAL on a truncated frame
     */
    public function finish(): void
    {
        if ($this->buffer !== '' || $this->pendingLength !== null) {
            throw new StatusException(new Status(
                StatusCode::INTERNAL,
                'Truncated gRPC frame at end of response body'
            ));
        }
    }

    /**
     * @param int $flag the Compressed-Flag byte
     * @return void
     * @throws StatusException INTERNAL per compression.md when the flag is
     *                         set (this client only accepts identity) or invalid
     */
    private function checkFlag(int $flag): void
    {
        if ($flag === 0) {
            return;
        }
        if ($flag === 1) {
            if ($this->grpcEncoding !== null && $this->grpcEncoding !== 'identity') {
                throw new StatusException(new Status(
                    StatusCode::INTERNAL,
                    sprintf('Compression algorithm "%s" not supported by client (accepted: identity)', $this->grpcEncoding)
                ));
            }
            throw new StatusException(new Status(
                StatusCode::INTERNAL,
                'Compressed-Flag set without a grpc-encoding'
            ));
        }
        throw new StatusException(new Status(
            StatusCode::INTERNAL,
            sprintf('Invalid gRPC frame flag %d', $flag)
        ));
    }
}
