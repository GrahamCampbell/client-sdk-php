<?php

/**
 * Fixture for ChannelPumpTest. Starts a call against the port in argv[1],
 * then exits without ever waiting on it.
 */

declare(strict_types=1);

use Cache_client\ScsClient;
use Cache_client\_GetRequest;
use GuzzleHttp\Multiplexing;
use Momento\Transport\Channel;

require __DIR__ . "/../../../vendor/autoload.php";

$endpoint = "127.0.0.1:{$argv[1]}";
$channel = new Channel($endpoint, [
    "scheme" => "http",
    "multiplex" => Multiplexing::WAIT,
]);
$pending = [(new ScsClient($endpoint, [], $channel))->Get(new _GetRequest(), [], ["timeout" => 5000000])];
