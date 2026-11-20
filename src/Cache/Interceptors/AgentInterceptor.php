<?php
declare(strict_types=1);

namespace Momento\Cache\Interceptors;

class AgentInterceptor
{
    private bool $isFirstRequest = true;
    private string $agent;
    private string $runtimeVersion;
    private string $sdkVersion = "1.20.0"; // x-release-please-version


    public function __construct(string $clientType)
    {
        $this->agent = sprintf("php:%s:%s", $clientType, $this->sdkVersion);
        $this->runtimeVersion = PHP_VERSION;
    }

    /**
     * Add the agent metadata to the first call only.
     */
    public function apply(array $metadata): array
    {
        if ($this->isFirstRequest) {
            $metadata["agent"] = [$this->agent];
            $metadata["runtime-version"] = [$this->runtimeVersion];
            $this->isFirstRequest = false;
        }

        return $metadata;
    }
}
