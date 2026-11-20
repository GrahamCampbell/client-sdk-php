<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Momento\Transport\GrpcTimeout;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Momento\Transport\GrpcTimeout
 */
class GrpcTimeoutTest extends TestCase
{
    public static function encodingProvider(): array
    {
        return [
            "zero clamps to one nanosecond" => [0, "1n"],
            "negative clamps to one nanosecond" => [-5, "1n"],
            "one microsecond" => [1, "1u"],
            "momento default deadline" => [5000000, "5000000u"],
            "largest value that fits in microseconds" => [99999999, "99999999u"],
            "first value forced into milliseconds" => [100000000, "100000m"],
            "millisecond rescale rounds up" => [100000001, "100001m"],
            "largest value that fits in milliseconds" => [99999999000, "99999999m"],
            "first value forced into seconds rounds up" => [99999999001, "100000S"],
            "largest value that fits in seconds" => [99999999000000, "99999999S"],
            "first value forced into minutes rounds up" => [99999999000001, "1666667M"],
            "value forced into hours rounds up" => [6000000000000000, "1666667H"],
            "hours cap at eight digits" => [PHP_INT_MAX, "99999999H"],
        ];
    }

    /**
     * @dataProvider encodingProvider
     */
    public function testEncodesMicrosecondsToTheShortestSufficientUnit(int $micros, string $expected)
    {
        $this->assertSame($expected, GrpcTimeout::encode($micros));
    }

    public static function guzzleSecondsProvider(): array
    {
        return [
            "zero clamps to one millisecond" => [0, 0.0015],
            "one microsecond clamps up" => [1, 0.0015],
            "sub-millisecond rounds up" => [999, 0.0015],
            "exactly one millisecond" => [1000, 0.0015],
            "first bare-float truncation hazard value" => [1001000, 1.0015],
            "momento default deadline" => [5000000, 5.0005],
            "rounds up mid-millisecond" => [5000001, 5.0015],
        ];
    }

    /**
     * @dataProvider guzzleSecondsProvider
     */
    public function testConvertsMicrosecondsToGuzzleSeconds(int $micros, float $expected)
    {
        $this->assertSame($expected, GrpcTimeout::toGuzzleSeconds($micros));
    }
}
