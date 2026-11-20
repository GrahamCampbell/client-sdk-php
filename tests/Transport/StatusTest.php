<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Momento\Transport\Status;
use Momento\Transport\StatusCode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Momento\Transport\Status
 */
class StatusTest extends TestCase
{
    public function testBlockWithoutGrpcStatusYieldsNull()
    {
        $this->assertNull(Status::fromBlock(["content-type" => ["application/grpc"]]));
    }

    public function testOkStatusWithTrailingMetadata()
    {
        $status = Status::fromBlock(["grpc-status" => ["0"], "X-Trailing" => ["v"]]);
        $this->assertSame(StatusCode::OK, $status->code);
        $this->assertSame("", $status->details);
        $this->assertSame(["x-trailing" => ["v"]], $status->metadata);
        $this->assertTrue($status->isOk());
    }

    public function testUnparseableGrpcStatusIsUnknown()
    {
        $status = Status::fromBlock(["grpc-status" => ["abc"]]);
        $this->assertSame(StatusCode::UNKNOWN, $status->code);
        $this->assertSame('Error parsing gRPC status "abc"', $status->details);
    }

    public function testOutOfRangeGrpcStatusIsPropagatedAsIs()
    {
        $this->assertSame(20, Status::fromBlock(["grpc-status" => ["20"]])->code);
    }

    public function testFirstValueWinsWhenGrpcStatusIsDuplicated()
    {
        $status = Status::fromBlock(["grpc-status" => ["5", "13"], "grpc-message" => ["first", "second"]]);
        $this->assertSame(StatusCode::NOT_FOUND, $status->code);
        $this->assertSame("first", $status->details);
    }

    public static function grpcMessageDecodingProvider(): array
    {
        return [
            "simple escapes" => ["hello%20world%21", "hello world!"],
            "broken escapes preserved" => ["bad%zzescape%2", "bad%zzescape%2"],
            "plus is not a space" => ["a+b%2Bc", "a+b+c"],
            "utf8 bytes" => ["%E2%9C%93 done", "\u{2713} done"],
        ];
    }

    /**
     * @dataProvider grpcMessageDecodingProvider
     */
    public function testGrpcMessagePercentDecodingIsLenient(string $wire, string $expected)
    {
        $status = Status::fromBlock(["grpc-status" => ["13"], "grpc-message" => [$wire]]);
        $this->assertSame($expected, $status->details);
    }

    public function testAbsentGrpcMessageYieldsEmptyDetails()
    {
        $status = Status::fromBlock(["grpc-status" => ["13"]]);
        $this->assertSame("", $status->details);
        $this->assertSame([], $status->metadata);
    }

    public function testStatusDetailsBinIsBase64DecodedAndPassedThrough()
    {
        $raw = "\x0a\x03\x62\x61\x64";
        $status = Status::fromBlock([
            "grpc-status" => ["3"],
            "grpc-status-details-bin" => [rtrim(base64_encode($raw), "=")],
        ]);
        $this->assertSame([$raw], $status->metadata["grpc-status-details-bin"]);
    }

    public function testTrailerKeysAreLowercasedForTheStatusObject()
    {
        $status = Status::fromBlock(["Grpc-Status" => ["5"], "Err" => ["item_not_found"]]);
        $this->assertSame(StatusCode::NOT_FOUND, $status->code);
        $this->assertSame(["item_not_found"], $status->metadata["err"]);
    }

    public function testTransportKeysAreStrippedFromStatusMetadata()
    {
        $status = Status::fromBlock([
            "grpc-status" => ["5"],
            "grpc-message" => ["nope"],
            "content-type" => ["application/grpc"],
            "grpc-encoding" => ["identity"],
            "grpc-accept-encoding" => ["identity"],
            "err" => ["item_not_found"],
        ]);
        $this->assertSame(["err" => ["item_not_found"]], $status->metadata);
    }
}
