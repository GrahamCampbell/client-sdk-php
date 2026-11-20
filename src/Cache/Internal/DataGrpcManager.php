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
        $this->channel = new Channel($endpoint, $channelOptions);

        $authToken = $authProvider->getAuthToken();
        $readConcern = $configuration->getReadConcern();
        $agentInterceptor = new AgentInterceptor("cache");
        $updateMetadata = function (array $metadata, string $method, bool $isServerStreaming) use ($authToken, $readConcern, $agentInterceptor): array {
            $metadata["authorization"] = [$authToken];
            if (!$isServerStreaming) {
                // The ext-grpc interceptors only acted on unary calls.
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
