<?php

declare(strict_types=1);

namespace Momento\Tests\Transport;

use Momento\Transport\BaseStub;
use PHPUnit\Framework\TestCase;

/**
 * Autoload smoke test: every generated service client must extend the
 * in-repo transport base stub. A client left on \Grpc\BaseStub fatals the
 * moment it is loaded, because ext-grpc is no longer a dependency.
 *
 * @coversNothing
 */
class GeneratedClientsTest extends TestCase
{
    public static function generatedClientProvider(): array
    {
        return [
            ["Auth\\AuthClient"],
            ["Cache_client\\HttpCacheClient"],
            ["Cache_client\\PingClient"],
            ["Cache_client\\Pubsub\\PubsubClient"],
            ["Cache_client\\ScsClient"],
            ["Control_client\\ScsControlClient"],
            ["Leaderboard\\LeaderboardClient"],
            ["Token\\TokenClient"],
            ["Webhook\\WebhookClient"],
        ];
    }

    /**
     * @dataProvider generatedClientProvider
     */
    public function testGeneratedClientLoadsAndExtendsTheTransportBaseStub(string $class)
    {
        $this->assertTrue(class_exists($class));
        $this->assertTrue(is_subclass_of($class, BaseStub::class));
    }

    public function testNoGeneratedClientWasMissedByTheProvider()
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . "/../../types", \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (substr($file->getFilename(), -10) === "Client.php") {
                $found[] = $file->getFilename();
            }
        }
        sort($found);

        $expected = array_map(static function (array $row): string {
            $parts = explode("\\", $row[0]);

            return end($parts) . ".php";
        }, self::generatedClientProvider());
        sort($expected);

        $this->assertSame($expected, $found);
    }
}
