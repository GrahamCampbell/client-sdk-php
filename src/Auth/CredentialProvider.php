<?php
declare(strict_types=1);

namespace Momento\Auth;

use Momento\Cache\Errors\InvalidArgumentError;
use function Momento\Utilities\isNullOrEmpty;

/**
 * Provides information that the CacheClient needs in order to establish a connection to and authenticate with
 * the Momento service.
 */
abstract class CredentialProvider
{
    private string $authToken;
    private string $controlEndpoint;
    private string $cacheEndpoint;
    private ?string $trustedControlEndpointCertificateName = null;
    private ?string $trustedCacheEndpointCertificateName = null;

    public function __construct(
        string  $authToken,
        ?string $controlEndpoint = null,
        ?string $cacheEndpoint = null,
        ?string $trustedControlEndpointCertificateName = null,
        ?string $trustedCacheEndpointCertificateName = null
    )
    {
        if (isNullOrEmpty($authToken)) {
            throw new InvalidArgumentError("String $authToken is empty or null.");
        }
        if ($trustedControlEndpointCertificateName xor $trustedCacheEndpointCertificateName) {
            throw new InvalidArgumentError(
                "If either of trustedCacheEndpointCertificateName or trustedControlEndpointCertificateName " .
                "are provided, they must both be."
            );
        }
        $this->authToken = $authToken;
        $payload = AuthUtils::parseAuthToken($authToken);
        $this->controlEndpoint = $controlEndpoint ?? $payload->cp;
        $this->cacheEndpoint = $cacheEndpoint ?? $payload->c;
        $this->trustedControlEndpointCertificateName = $trustedControlEndpointCertificateName;
        $this->trustedCacheEndpointCertificateName = $trustedCacheEndpointCertificateName;
    }

    /**
     * @return string Auth token provided by user, required to authenticate with the service.
     */
    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    /**
     * @return string The host which the Momento client will connect to for Momento control plane operations.
     */
    public function getControlEndpoint(): string
    {
        return $this->controlEndpoint;
    }

    /**
     * @return string The host which the Momento client will connect to for Momento data plane operations.
     */
    public function getCacheEndpoint(): string
    {
        return $this->cacheEndpoint;
    }

    public function getTrustedControlEndpointCertificateName(): string|null
    {
        return $this->trustedControlEndpointCertificateName;
    }

    public function getTrustedCacheEndpointCertificateName(): string|null
    {
        return $this->trustedCacheEndpointCertificateName;
    }
}
