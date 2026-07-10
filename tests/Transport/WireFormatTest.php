<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ECacheResult;
use Cache_client\_GetResponse;
use Google\Protobuf\Internal\GPBDecodeException;
use Momento\Transport\WireFormat;
use PHPUnit\Framework\TestCase;

/**
 * The corruption cases mirror what the strict C protobuf extension rejects;
 * the tag-varint rows are the ones the pure-PHP runtime silently swallows.
 *
 * @covers \Momento\Transport\WireFormat
 */
class WireFormatTest extends TestCase
{
    public static function validPayloadProvider(): array
    {
        return [
            "empty message" => [""],
            "varint field" => ["\x08\x01"],
            "ten-byte varint value" => ["\x08" . str_repeat("\x80", 9) . "\x01"],
            "fixed64 field" => ["\x09" . str_repeat("\x00", 8)],
            "length-delimited field" => ["\x0A\x03abc"],
            "zero-length delimited field" => ["\x0A\x00"],
            "fixed32 field" => ["\x0D" . str_repeat("\x00", 4)],
            "several fields" => ["\x08\x01\x0A\x02hi\x0D\x00\x00\x00\x00"],
        ];
    }

    /**
     * @dataProvider validPayloadProvider
     */
    public function testWellFormedPayloadsAreAccepted(string $payload)
    {
        WireFormat::assertValid($payload);
        $this->addToAssertionCount(1);
    }

    public function testRealSerializedMessageIsAccepted()
    {
        $reply = new _GetResponse();
        $reply->setResult(ECacheResult::Hit);
        $reply->setCacheBody("my-value");
        WireFormat::assertValid($reply->serializeToString());
        $this->addToAssertionCount(1);
    }

    public static function corruptPayloadProvider(): array
    {
        return [
            "truncated tag varint" => ["\xFF"],
            "truncated tag varint 0x80" => ["\x80"],
            "valid field then truncated tag" => ["\x08\x01\xFF"],
            "overlong tag varint" => [str_repeat("\x80", 10) . "\x01"],
            "field number zero" => ["\x00"],
            "truncated varint value" => ["\x08"],
            "overlong varint value" => ["\x08" . str_repeat("\x80", 10) . "\x01"],
            "truncated fixed64 value" => ["\x09\x00\x00\x00"],
            "truncated fixed32 value" => ["\x0D\x00\x00"],
            "truncated length varint" => ["\x0A\xFF"],
            "length past end of buffer" => ["\x0A\x05A"],
            "huge declared length" => ["\x0A\xFF\xFF\xFF\xFF\x0FA"],
            "sgroup wire type" => ["\x0B"],
            "egroup wire type" => ["\x0C"],
            "wire type six" => ["\x0E"],
            "wire type seven" => ["\x0F"],
        ];
    }

    /**
     * @dataProvider corruptPayloadProvider
     */
    public function testStructurallyCorruptPayloadsAreRejected(string $payload)
    {
        $this->expectException(GPBDecodeException::class);
        WireFormat::assertValid($payload);
    }
}
