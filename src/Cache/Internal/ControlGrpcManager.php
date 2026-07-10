<?php
declare(strict_types=1);

namespace Momento\Cache\Internal;

use Control_client\ScsControlClient;
use Momento\Auth\ICredentialProvider;
use Momento\Cache\Interceptors\AgentInterceptor;
use Momento\Transport\Channel;
use Momento\Transport\TransportRequirements;


class ControlGrpcManager
{

    public ScsControlClient $client;
    private Channel $channel;

    /**
     * @param ICredentialProvider $authProvider
     * @param array $channelOptions @internal extra options forwarded to the
     *                              transport Channel (test injection seam)
     */
    public function __construct(ICredentialProvider $authProvider, array $channelOptions = [])
    {
        TransportRequirements::assertSupported();

        $endpoint = $authProvider->getControlEndpoint();
        if ($authProvider->getTrustedControlEndpointCertificateName()) {
            $channelOptions["ssl_target_name_override"] = $authProvider->getTrustedControlEndpointCertificateName();
        }
        $this->channel = new Channel($endpoint, $channelOptions);

        $authToken = $authProvider->getAuthToken();
        $agentInterceptor = new AgentInterceptor("cache");
        $updateMetadata = function (array $metadata, string $method, bool $isServerStreaming) use ($authToken, $agentInterceptor): array {
            $metadata["authorization"] = [$authToken];
            if (!$isServerStreaming) {
                $metadata = $agentInterceptor->apply($metadata);
            }

            return $metadata;
        };

        $options = ["update_metadata" => $updateMetadata];
        $this->client = new ScsControlClient($endpoint, $options, $this->channel);
    }

    public function close(): void {
        $this->channel->close();
    }

}
