<?php
declare(strict_types=1);

namespace Momento\Transport;

/**
 * Encoder and incremental decoder for gRPC length-prefixed message frames:
 * a 1-byte compressed flag, a 4-byte big-endian length, then the payload.
 */
class FrameCodec
{
    private const PREFIX_LENGTH = 5;

    private int $maxReceiveMessageBytes;

    private ?string $grpcEncoding;

    private string $buffer = '';

    private ?int $pendingLength = null;

    public function __construct(int $maxReceiveMessageBytes, ?string $grpcEncoding = null)
    {
        $this->maxReceiveMessageBytes = $maxReceiveMessageBytes;
        $this->grpcEncoding = ($grpcEncoding === null || $grpcEncoding === '') ? null : $grpcEncoding;
    }

    public static function encode(string $message): string
    {
        return "\x00" . pack('N', strlen($message)) . $message;
    }

    /**
     * @return string[] the message payloads completed by this chunk
     * @throws StatusException on an invalid flag or an oversized message
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
                // On 32-bit PHP a length >= 2^31 unpacks negative.
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
     * @throws StatusException when the body ended inside a frame
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
     * @throws StatusException when the flag is set, as only identity is accepted
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
