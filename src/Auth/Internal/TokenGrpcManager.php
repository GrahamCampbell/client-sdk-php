<?php
declare(strict_types=1);

namespace Momento\Auth\Internal;

use Momento\Auth\ITokenCredentialProvider;
use Momento\Cache\Interceptors\AgentInterceptor;
use Momento\Transport\Channel;
use Momento\Transport\TransportRequirements;
use Token\TokenClient;

class TokenGrpcManager
{
    public TokenClient $client;
    private Channel $channel;

    /**
     * @param ITokenCredentialProvider $authProvider
     * @param array $channelOptions @internal extra options forwarded to the
     *                              transport Channel (test injection seam)
     */
    public function __construct(ITokenCredentialProvider $authProvider, array $channelOptions = [])
    {
        TransportRequirements::assertSupported();

        $endpoint = $authProvider->getTokenEndpoint();
        $this->channel = new Channel($endpoint, $channelOptions);

        // The token service authenticates the API key carried in the
        // request message, so no authorization metadata is sent.
        $agentInterceptor = new AgentInterceptor("auth");
        $updateMetadata = function (array $metadata) use ($agentInterceptor): array {
            return $agentInterceptor->apply($metadata);
        };

        $options = ["update_metadata" => $updateMetadata];
        $this->client = new TokenClient($endpoint, $options, $this->channel);
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
