<?php

namespace Config;

use App\Libraries\Gateway\GatewayAssertion;
use CodeIgniter\Config\BaseConfig;

/**
 * WorkSuite API Gateway (api.worksuitecloud.com) in front of the public AI API: whether AI Studio trusts the
 * gateway's signed identity assertion (`Authorization: WS-API-Gateway <jwt>`, API Management data-plane contract §8).
 *
 * Off by default. Off, a request carrying that scheme is refused (401 GATEWAY_DISABLED) and nothing else changes.
 * On, such requests are verified (docs/api-gateway.md) and every other request keeps today's key/secret or access
 * token authentication. Read from the environment (.env):
 *
 *   APIM_GATEWAY_ENABLED       = false
 *   APIM_GATEWAY_JWKS_URL      = https://api.worksuitecloud.com/.well-known/apim-gateway-jwks.json
 *   APIM_GATEWAY_ISSUER        = https://api.worksuitecloud.com
 *   APIM_GATEWAY_AUDIENCE      = ai-studio
 *   APIM_GATEWAY_TRUSTED_CIDRS =            (the gateway node's egress IPs; empty trusts nothing)
 *   APIM_GATEWAY_ORGS          =            (API console organization ids whose credentials may call; empty: none)
 */
class ApiGateway extends BaseConfig
{
    public bool $enabled = false;
    public string $jwksUrl = GatewayAssertion::DEFAULT_JWKS_URL;
    public string $issuer = GatewayAssertion::DEFAULT_ISSUER;
    public string $audience = 'ai-studio';
    /** CIDRs or single addresses, comma or space separated. */
    public string $trustedCidrs = '';
    /** API console organization ids allowed for `sub_type: credential` callers (Livezen Technologies' own). */
    public string $orgs = '';

    public function __construct()
    {
        parent::__construct();
        $get = static fn (string $k): string => trim((string) (env($k) ?? ''));
        $this->enabled = strtolower($get('APIM_GATEWAY_ENABLED')) === 'true' || $get('APIM_GATEWAY_ENABLED') === '1';
        $this->jwksUrl = $get('APIM_GATEWAY_JWKS_URL') ?: $this->jwksUrl;
        $this->issuer = $get('APIM_GATEWAY_ISSUER') ?: $this->issuer;
        $this->audience = $get('APIM_GATEWAY_AUDIENCE') ?: $this->audience;
        $this->trustedCidrs = $get('APIM_GATEWAY_TRUSTED_CIDRS');
        $this->orgs = $get('APIM_GATEWAY_ORGS');
    }
}
