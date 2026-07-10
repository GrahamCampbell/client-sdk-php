<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Cache_client\ScsClient;
use Momento\Transport\TransportRequirements;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Environment-conditional: one side runs below the libcurl floor and the
 * other side runs on compliant machines.
 *
 * @covers \Momento\Transport\TransportRequirements
 */
class TransportRequirementsTest extends TestCase
{
    private const MIN_CURL_VERSION = "8.14.0";

    public function tearDown(): void
    {
        TransportRequirements::reset();
    }

    public function testLibcurlBelowTheFloorIsRejectedWithAnActionableMessage()
    {
        if (version_compare(curl_version()["version"], self::MIN_CURL_VERSION, ">=")) {
            $this->markTestSkipped("Requires a libcurl below 8.14.0");
        }
        TransportRequirements::reset();
        try {
            TransportRequirements::assertSupported();
            $this->fail("Expected RuntimeException");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("libcurl 8.14.0 or newer", $e->getMessage());
            $this->assertStringContainsString(curl_version()["version"], $e->getMessage());
        }
    }

    public function testDirectStubConstructionRunsTheGuard()
    {
        if (version_compare(curl_version()["version"], self::MIN_CURL_VERSION, ">=")) {
            $this->markTestSkipped("Requires a libcurl below 8.14.0");
        }
        TransportRequirements::reset();
        try {
            new ScsClient("cache.test", []);
            $this->fail("Expected RuntimeException");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("libcurl 8.14.0 or newer", $e->getMessage());
        }
    }

    public function testSupportedEnvironmentPassesOnRepeatedChecks()
    {
        if (version_compare(curl_version()["version"], self::MIN_CURL_VERSION, "<")) {
            $this->markTestSkipped("Requires libcurl 8.14.0 or newer");
        }
        TransportRequirements::reset();
        TransportRequirements::assertSupported();
        TransportRequirements::assertSupported();
        $this->addToAssertionCount(1);
    }
}
