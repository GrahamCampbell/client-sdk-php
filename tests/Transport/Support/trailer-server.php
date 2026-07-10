<?php

/**
 * Minimal HTTP/1.1 fixture for ChannelPumpTest. argv[1] is a file of
 * base64-encoded raw responses, one per line. The script prints its ephemeral
 * port on stdout, serves one scripted response per accepted connection, then exits.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$responses = array_map(
    static function (string $line): string {
        return (string)base64_decode($line, true);
    },
    file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
);

$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int)strrpos($name, ":") + 1) . "\n");
fflush(STDOUT);

foreach ($responses as $raw) {
    $conn = stream_socket_accept($server, 30);
    if ($conn === false) {
        exit(1);
    }
    $request = "";
    while (strpos($request, "\r\n\r\n") === false) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === "") {
            break;
        }
        $request .= $chunk;
    }
    $bodyStart = strpos($request, "\r\n\r\n") + 4;
    $contentLength = 0;
    if (preg_match("/^content-length:\s*(\d+)/mi", $request, $m) === 1) {
        $contentLength = (int)$m[1];
    }
    while (strlen($request) - $bodyStart < $contentLength) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === "") {
            break;
        }
        $request .= $chunk;
    }
    fwrite($conn, $raw);
    fclose($conn);
}
fclose($server);
