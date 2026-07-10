<?php
declare(strict_types=1);

namespace Momento\Cache\Internal;

use Cache_client\ScsClient;
use Momento\Auth\ICredentialProvider;
use Momento\Cache\Interceptors\AgentInterceptor;
use Momento\Config\IConfiguration;
use Momento\Config\ReadConcern;
use Momento\Transport\Channel;
use Momento\Transport\TransportRequirements;

class DataGrpcManager
{
    public ScsClient $client;
    private Channel $channel;

    /**
     * @param ICredentialProvider $authProvider
     * @param IConfiguration $configuration
     * @param array $channelOptions @internal extra options forwarded to the
     *                              transport Channel (test injection seam)
     */
    public function __construct(ICredentialProvider $authProvider, IConfiguration $configuration, array $channelOptions = [])
    {
        TransportRequirements::assertSupported();

        $endpoint = $authProvider->getCacheEndpoint();
        if ($authProvider->getTrustedCacheEndpointCertificateName()) {
            $channelOptions["ssl_target_name_override"] = $authProvider->getTrustedCacheEndpointCertificateName();
        }
        // One transport channel (one curl multi handle / HTTP/2 pool) per
        // manager. numGrpcChannels > 1 builds N managers, so N channels.
        // The grpc.keepalive_* channel args have no per-handle libcurl
        // analogue and grpc.service_config_disable_resolution has nothing
        // to disable here (both are documented divergences).
        $this->channel = new Channel($endpoint, $channelOptions);

        $authToken = $authProvider->getAuthToken();
        $readConcern = $configuration->getReadConcern();
        $agentInterceptor = new AgentInterceptor("cache");
        $updateMetadata = function (array $metadata, string $method, bool $isServerStreaming) use ($authToken, $readConcern, $agentInterceptor): array {
            $metadata["authorization"] = [$authToken];
            if (!$isServerStreaming) {
                // Unary-only, matching the ext-grpc interceptors: the agent
                // pair goes out on the first unary call per channel, and
                // read-concern never rides streaming calls.
                $metadata = $agentInterceptor->apply($metadata);
                if ($readConcern !== ReadConcern::BALANCED) {
                    $metadata["read-concern"] = [$readConcern];
                }
            }

            return $metadata;
        };

        $options = ["update_metadata" => $updateMetadata];
        $this->client = new ScsClient($endpoint, $options, $this->channel);
    }

    public function close(): void {
        $this->channel->close();
    }
}
