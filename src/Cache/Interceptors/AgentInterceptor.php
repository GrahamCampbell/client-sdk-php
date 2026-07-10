<?php
declare(strict_types=1);

namespace Momento\Cache\Interceptors;

class AgentInterceptor
{
    private bool $isFirstRequest = true;
    private string $agent;
    private string $runtimeVersion;
    private string $sdkVersion = "1.19.1"; // x-release-please-version


    public function __construct(string $clientType)
    {
        $this->agent = sprintf("php:%s:%s", $clientType, $this->sdkVersion);
        $this->runtimeVersion = PHP_VERSION;
    }

    /**
     * Add the one-time agent/runtime-version metadata. The managers invoke
     * this for unary calls only, preserving the ext-grpc interceptor's
     * unary-only quirk: streaming calls never carry the headers and never
     * consume the first-request flag.
     *
     * @param array $metadata per-call metadata
     * @return array
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
