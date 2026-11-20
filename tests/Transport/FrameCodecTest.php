<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Momento\Transport\FrameCodec;
use Momento\Transport\StatusCode;
use Momento\Transport\StatusException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Momento\Transport\FrameCodec
 * @covers \Momento\Transport\StatusException
 */
class FrameCodecTest extends TestCase
{
    private const DEFAULT_MAX = 4194304;

    public function testEncodeEmptyMessage()
    {
        $this->assertSame("\x00\x00\x00\x00\x00", FrameCodec::encode(""));
    }

    public function testEncodePrefixesFlagAndBigEndianLength()
    {
        $message = str_repeat("m", 300);
        $encoded = FrameCodec::encode($message);
        $this->assertSame("\x00\x00\x00\x01\x2c", substr($encoded, 0, 5));
        $this->assertSame($message, substr($encoded, 5));
    }

    public static function chunkSplitProvider(): array
    {
        return [
            "whole buffer" => [PHP_INT_MAX],
            "one byte at a time" => [1],
            "two-byte chunks" => [2],
            "three-byte chunks" => [3],
            "four-byte chunks" => [4],
            "prefix-sized chunks" => [5],
            "mid-payload splits" => [7],
            "prime-sized chunks" => [13],
        ];
    }

    /**
     * @dataProvider chunkSplitProvider
     */
    public function testDecodesFramesAcrossArbitraryChunkBoundaries(int $chunkSize)
    {
        $messages = ["hello", "", str_repeat("\xAB", 300)];
        $wire = "";
        foreach ($messages as $message) {
            $wire .= FrameCodec::encode($message);
        }

        $decoder = new FrameCodec(self::DEFAULT_MAX);
        $decoded = [];
        foreach (str_split($wire, min($chunkSize, strlen($wire))) as $chunk) {
            foreach ($decoder->feed($chunk) as $payload) {
                $decoded[] = $payload;
            }
        }
        $decoder->finish();

        $this->assertSame($messages, $decoded);
    }

    public function testMultipleCompleteFramesInOneChunkAreAllYielded()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX);
        $decoded = $decoder->feed(FrameCodec::encode("a") . FrameCodec::encode("bb") . FrameCodec::encode(""));
        $decoder->finish();
        $this->assertSame(["a", "bb", ""], $decoded);
    }

    public function testCompressedFlagWithoutGrpcEncodingIsInternal()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX);
        try {
            $decoder->feed("\x01" . pack("N", 2) . "ab");
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getStatus()->code);
            $this->assertSame("Compressed-Flag set without a grpc-encoding", $e->getMessage());
        }
    }

    public function testCompressedFlagWithIdentityEncodingIsInternal()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX, "identity");
        try {
            $decoder->feed("\x01" . pack("N", 2) . "ab");
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getStatus()->code);
            $this->assertSame("Compressed-Flag set without a grpc-encoding", $e->getMessage());
        }
    }

    public function testCompressedFrameWithUnsupportedEncodingIsInternal()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX, "gzip");
        try {
            $decoder->feed("\x01" . pack("N", 2) . "ab");
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getStatus()->code);
            $this->assertSame(
                'Compression algorithm "gzip" not supported by client (accepted: identity)',
                $e->getMessage()
            );
        }
    }

    public function testNonIdentityEncodingWithUncompressedFramesIsAccepted()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX, "gzip");
        $decoded = $decoder->feed(FrameCodec::encode("plain"));
        $decoder->finish();
        $this->assertSame(["plain"], $decoded);
    }

    public function testInvalidFrameFlagIsInternal()
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX);
        try {
            $decoder->feed("\x02" . pack("N", 0));
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getStatus()->code);
            $this->assertSame("Invalid gRPC frame flag 2", $e->getMessage());
        }
    }

    public function testOversizeLengthFailsOnThePrefixBeforeBufferingPayload()
    {
        $decoder = new FrameCodec(16);
        try {
            $decoder->feed("\x00" . pack("N", 17));
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::RESOURCE_EXHAUSTED, $e->getStatus()->code);
            $this->assertSame("Received message larger than max (17 vs. 16)", $e->getMessage());
        }
    }

    public function testMessageAtExactlyTheLimitIsAccepted()
    {
        $decoder = new FrameCodec(16);
        $decoded = $decoder->feed(FrameCodec::encode(str_repeat("x", 16)));
        $decoder->finish();
        $this->assertSame([str_repeat("x", 16)], $decoded);
    }

    public static function truncatedTailProvider(): array
    {
        return [
            "one stray prefix byte" => ["\x00"],
            "partial length prefix" => ["\x00\x00\x00"],
            "complete prefix, truncated payload" => ["\x00" . pack("N", 4) . "ab"],
        ];
    }

    /**
     * @dataProvider truncatedTailProvider
     */
    public function testLeftoverBytesAtEndOfBodyAreInternal(string $tail)
    {
        $decoder = new FrameCodec(self::DEFAULT_MAX);
        $decoder->feed(FrameCodec::encode("ok") . $tail);
        try {
            $decoder->finish();
            $this->fail("Expected StatusException");
        } catch (StatusException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getStatus()->code);
            $this->assertSame("Truncated gRPC frame at end of response body", $e->getMessage());
        }
    }
}
