<?php
declare(strict_types=1);

namespace Momento\Transport;

use RuntimeException;

/**
 * Runtime environment guard for the Guzzle/curl gRPC transport. Composer
 * enforces the Guzzle API floor; this class checks the PHP cURL bindings and
 * libcurl features needed by the HTTP/2 CurlMultiHandler path.
 */
class TransportRequirements
{
    private const MIN_CURL_VERSION_STRING = '8.14.0';

    /**
     * @var array{version: string, features: int}|false|null
     */
    private static $curlVersionInfo = null;

    /**
     * Assert the runtime can carry gRPC-over-HTTP/2.
     *
     * @return void
     * @throws RuntimeException with an actionable message when ext-curl is
     *                          missing, libcurl is too old, or the PHP cURL
     *                          bindings/libcurl build lacks required features
     */
    public static function assertSupported(): void
    {
        $versionInfo = self::curlVersionInfo();

        if ($versionInfo === null) {
            throw new RuntimeException('Momento requires the PHP cURL extension (ext-curl).');
        }

        if (!\function_exists('curl_multi_exec')) {
            throw new RuntimeException('Momento requires curl_multi_exec() from ext-curl because its transport uses Guzzle CurlMultiHandler.');
        }

        if (\version_compare($versionInfo['version'], self::MIN_CURL_VERSION_STRING, '<')) {
            throw new RuntimeException(sprintf(
                'Momento requires libcurl %s or newer for its Guzzle gRPC transport; libcurl %s is installed.',
                self::MIN_CURL_VERSION_STRING,
                $versionInfo['version']
            ));
        }

        if (!\defined('CURL_VERSION_SSL') || !\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURLMOPT_MAX_HOST_CONNECTIONS') || !\defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            throw new RuntimeException('Momento requires PHP cURL bindings with TLS 1.2 support.');
        }

        if (0 === (\CURL_VERSION_SSL & $versionInfo['features'])) {
            throw new RuntimeException('Momento requires libcurl built with SSL support.');
        }

        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt')) {
            throw new RuntimeException('Momento requires cURL share support for handler transport sharing.');
        }

        if (!\defined('CURLOPT_SHARE') || !\defined('CURLSHOPT_SHARE') || !\defined('CURL_LOCK_DATA_DNS') || !\defined('CURL_LOCK_DATA_SSL_SESSION')) {
            throw new RuntimeException('Momento requires PHP cURL bindings exposing cURL share constants for DNS and SSL session sharing.');
        }

        if (!\defined('CURL_VERSION_HTTP2') || !\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT')) {
            throw new RuntimeException('Momento requires PHP cURL bindings with HTTP/2 prior-knowledge and PIPEWAIT support.');
        }

        if (0 === (\CURL_VERSION_HTTP2 & $versionInfo['features'])) {
            throw new RuntimeException('Momento requires libcurl built with HTTP/2 support.');
        }
    }

    /**
     * Forget the cached cURL version data so the guard re-probes.
     *
     * @internal test use only
     * @return void
     */
    public static function reset(): void
    {
        self::$curlVersionInfo = null;
    }

    /**
     * @return array{version: string, features: int}|null
     */
    private static function curlVersionInfo(): ?array
    {
        if (self::$curlVersionInfo === null) {
            if (!\function_exists('curl_version')) {
                self::$curlVersionInfo = false;
            } else {
                $versionInfo = \curl_version();
                self::$curlVersionInfo = \is_array($versionInfo)
                    && isset($versionInfo['version'], $versionInfo['features'])
                    && \is_string($versionInfo['version'])
                    && \is_int($versionInfo['features'])
                        ? [
                            'version' => $versionInfo['version'],
                            'features' => $versionInfo['features'],
                        ]
                        : false;
            }
        }

        return self::$curlVersionInfo === false ? null : self::$curlVersionInfo;
    }
}
