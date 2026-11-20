<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use InvalidArgumentException;
use Momento\Transport\Metadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Momento\Transport\Metadata
 */
class MetadataTest extends TestCase
{
    public function testBinaryMetadataEncodesUnpaddedBase64()
    {
        $raw = "\x00\x01\xfe\xff";
        $headers = Metadata::encode(["token-bin" => [$raw]]);
        $this->assertSame([rtrim(base64_encode($raw), "=")], $headers["token-bin"]);
    }

    public function testBinaryMetadataRoundTrips()
    {
        $raw = str_repeat("\x01", 34);
        $decoded = Metadata::decodeBlock(Metadata::encode(["token-bin" => [$raw]]));
        $this->assertSame([$raw], $decoded["token-bin"]);
    }

    public function testBinaryMetadataDecodeAcceptsPaddedValues()
    {
        $raw = "\x01\x02\x03\x04";
        $decoded = Metadata::decodeBlock(["token-bin" => [base64_encode($raw)]]);
        $this->assertSame([$raw], $decoded["token-bin"]);
    }

    public function testBinaryMetadataDecodeSplitsCommaJoinedValues()
    {
        $first = "abc";
        $second = "\x01\x02";
        $joined = rtrim(base64_encode($first), "=") . "," . base64_encode($second);
        $decoded = Metadata::decodeBlock(["probe-bin" => [$joined]]);
        $this->assertSame([$first, $second], $decoded["probe-bin"]);
    }

    public function testMalformedBinaryPartsPassThroughRawAndMixedValuesDecodePerPart()
    {
        $joined = "!!!!," . rtrim(base64_encode("\x01\x02"), "=");
        $decoded = Metadata::decodeBlock(["probe-bin" => [$joined]]);
        $this->assertSame(["!!!!", "\x01\x02"], $decoded["probe-bin"]);
        $this->assertSame(["YWI!"], Metadata::decodeBlock(["p-bin" => ["YWI!"]])["p-bin"]);
    }

    public function testMultiValueAsciiKeysArePreservedInOrder()
    {
        $headers = Metadata::encode(["cookie" => ["a", "b", "c"]]);
        $this->assertSame(["a", "b", "c"], $headers["cookie"]);
        $decoded = Metadata::decodeBlock(["cookie" => ["a", "b", "c"]]);
        $this->assertSame(["a", "b", "c"], $decoded["cookie"]);
    }

    public function testKeysAreLowercasedOnNormalization()
    {
        $normalized = Metadata::validateAndNormalize(["Authorization" => ["t"]]);
        $this->assertSame(["authorization" => ["t"]], $normalized);
    }

    public function testDotHyphenUnderscoreKeysAreAccepted()
    {
        $metadata = ["read-concern" => ["x"], "runtime_version" => ["y"], "a.b" => ["z"]];
        $this->assertSame($metadata, Metadata::validateAndNormalize($metadata));
    }

    public function testDecodeLowercasesKeys()
    {
        $decoded = Metadata::decodeBlock(["X-Mixed-Case" => ["v"]]);
        $this->assertSame(["x-mixed-case" => ["v"]], $decoded);
    }

    public function testStripKeysAreDropped()
    {
        $decoded = Metadata::decodeBlock(
            ["Content-Type" => ["application/grpc"], "x-kept" => ["v"]],
            ["content-type"]
        );
        $this->assertSame(["x-kept" => ["v"]], $decoded);
    }

    public static function invalidKeyProvider(): array
    {
        return [
            "empty key" => [""],
            "space" => ["bad key"],
            "at sign" => ["bad@key"],
            "non-ascii" => ["k\xC3\xBCy"],
            "trailing newline" => ["good\n"],
        ];
    }

    /**
     * @dataProvider invalidKeyProvider
     */
    public function testInvalidKeysAreRejectedWithTheBaseStubMessage(string $key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Metadata keys must be nonempty strings containing only alphanumeric characters, hyphens, underscores and dots"
        );
        Metadata::validateAndNormalize([$key => ["v"]]);
    }

    public static function invalidAsciiValueProvider(): array
    {
        return [
            "horizontal tab" => ["\t"],
            "DEL" => ["\x7f"],
            "high byte" => ["\x80"],
            "NUL inside" => ["col\x00on"],
        ];
    }

    /**
     * @dataProvider invalidAsciiValueProvider
     */
    public function testNonPrintableAsciiValuesAreRejectedForNonBinKeys(string $value)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata value for key "probe" must contain only printable ASCII characters');
        Metadata::validateAndNormalize(["probe" => [$value]]);
    }

    public function testPrintableAsciiValuesIncludingSpacesAreAccepted()
    {
        $metadata = ["probe" => ["spaced value", ""]];
        $this->assertSame($metadata, Metadata::validateAndNormalize($metadata));
    }

    public function testBinKeysAcceptArbitraryBytes()
    {
        $metadata = ["probe-bin" => ["\x00\xff\x80\t"]];
        $this->assertSame($metadata, Metadata::validateAndNormalize($metadata));
    }
}
