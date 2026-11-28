<?php

declare(strict_types=1);

namespace App\Libraries\Gateway;

use CodeIgniter\HTTP\RequestInterface;
use Config\ApiGateway;

/**
 * AI Studio's side of the WorkSuite API Gateway (WIRING.md §7, data-plane contract §8): checks that a request came
 * over the trusted network path, verifies its `WS-API-Gateway` assertion, maps the caller to an API key row and
 * narrows that key to what the assertion's scopes allow. ApiKeyFilter calls it; tests swap it through
 * Services::injectMock('gatewayReceiver', …) to fix the keys and the clock.
 */
class GatewayReceiver
{
    /** Gateway scope → the API key scopes it may use (deny by default: a scope not listed grants nothing). */
    public const SCOPE_GRANTS = [
        'aistudio.models.read' => ['models', 'download_models', 'download_datasets'],
        'aistudio.chat'        => ['chat', 'generate'],
        'aistudio.embeddings'  => ['embeddings'],
        'aistudio.audio'       => ['transcribe', 'tts'],
        'aistudio.translate'   => ['chat'],
        'aistudio.predict'     => ['classify'],
    ];

    /**
     * Route (method + path under /api) → the gateway scope it needs. Anything not listed is refused through the
     * gateway: model and dataset file downloads stay direct (large bodies), and so does everything else.
     */
    private const ROUTES = [
        ['GET',  '#^openai/v1/models(/.+)?$#',                       'aistudio.models.read'],
        ['POST', '#^openai/v1/(chat/completions|completions)$#',     'aistudio.chat'],
        ['POST', '#^openai/v1/embeddings$#',                         'aistudio.embeddings'],
        ['POST', '#^openai/v1/audio/(transcriptions|translations|speech)$#', 'aistudio.audio'],
        ['GET',  '#^v1/(models|catalog)$#',                          'aistudio.models.read'],
        ['POST', '#^v1/(chat|chat/completions|generate|summarize)$#', 'aistudio.chat'],
        ['POST', '#^v1/embeddings$#',                                'aistudio.embeddings'],
        ['POST', '#^v1/translate$#',                                 'aistudio.translate'],
        ['GET',  '#^v1/classifiers$#',                               'aistudio.predict'],
        ['POST', '#^v1/(classify|predict)$#',                        'aistudio.predict'],
    ];

    private ?GatewayAssertion $verifier = null;

    /**
     * @param callable(string): mixed|null $keys  kid → key (default: the gateway JWKS, cached in cache())
     * @param callable(): float|null $now
     */
    public function __construct(
        public readonly ApiGateway $config,
        private $keys = null,
        private $now = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->enabled;
    }

    /**
     * Verifies the request: trusted path first, then the token against the request's method and path.
     *
     * @return array<string, mixed> the claims
     *
     * @throws GatewayAssertionError
     */
    public function verify(RequestInterface $request): array
    {
        $cidrs = GatewayAssertion::parseCidrs($this->config->trustedCidrs);
        if (! GatewayAssertion::inCidrs($this->peer($request), $cidrs)) {
            throw new GatewayAssertionError('untrusted_peer', 'request did not arrive over the trusted network path');
        }
        $token = GatewayAssertion::token($request->getHeaderLine('Authorization'));
        if ($token === null) {
            throw new GatewayAssertionError('missing', 'no WS-API-Gateway authorization');
        }

        return $this->verifier()->verify($token, strtoupper($request->getMethod()), self::requestPath($request));
    }

    /**
     * The address that connected: the socket peer, or — when that peer is one of the configured reverse proxies
     * (`app.proxyIPs`, the local nginx) — the last hop that proxy added to its header. Never the first
     * X-Forwarded-For entry: a client can write that one itself.
     */
    public function peer(RequestInterface $request): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        foreach (config('App')->proxyIPs as $proxy => $header) {
            if (GatewayAssertion::inCidrs($remote, GatewayAssertion::parseCidrs((string) $proxy))) {
                return GatewayAssertion::peer($remote, $request->getHeaderLine((string) $header) ?: null, true);
            }
        }

        return $remote;
    }

    /** The path as this app received it, decoded, without the query (the gateway's `htp` after its path rewrite). */
    public static function requestPath(RequestInterface $request): string
    {
        $raw = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($raw === '' || $raw[0] !== '/') {
            // No REQUEST_URI (CLI, tests): the routed path, relative to the site root (without index.php).
            $raw = '/' . ltrim(method_exists($request, 'getPath') ? $request->getPath() : $request->getUri()->getPath(), '/');
        }

        return GatewayAssertion::requestPath($raw);
    }

    /** The gateway scope a route needs, or null when the route is not offered through the gateway. */
    public static function scopeFor(string $method, string $path): ?string
    {
        $p = preg_replace('#^/?(index\.php/)?api/#', '', $path) ?? '';
        $p = rtrim($p, '/');
        foreach (self::ROUTES as [$m, $re, $scope]) {
            if ($m === strtoupper($method) && preg_match($re, $p)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * The API key scopes the assertion allows: the union of what its gateway scopes grant, intersected with the
     * key's own scopes. Never wider than the key.
     *
     * @param list<string> $gatewayScopes
     */
    public static function narrowScopes(string $keyScopes, array $gatewayScopes): string
    {
        $allowed = [];
        foreach ($gatewayScopes as $s) {
            $allowed = array_merge($allowed, self::SCOPE_GRANTS[$s] ?? []);
        }
        $own = array_filter(array_map('trim', explode(',', $keyScopes)));

        return implode(',', array_values(array_intersect($own, array_unique($allowed))));
    }

    /** The organizations whose credentials may call through the gateway. */
    public function orgAllowed(string $org): bool
    {
        $orgs = array_filter(array_map('trim', preg_split('/[\s,]+/', $this->config->orgs) ?: []));

        return $org !== '' && in_array($org, $orgs, true);
    }

    private function verifier(): GatewayAssertion
    {
        if ($this->verifier === null) {
            $cacheKey = 'apim_gateway_jwks_' . md5($this->config->jwksUrl);
            $keys = $this->keys ?? GatewayAssertion::remoteKeys(
                $this->config->jwksUrl,
                static fn () => is_array($v = cache($cacheKey)) ? $v : null,
                static function (array $state) use ($cacheKey): void { cache()->save($cacheKey, $state, 86400); },
            );
            $this->verifier = new GatewayAssertion($keys, $this->config->issuer, $this->config->audience, [$this, 'claimJti'], $this->now);
        }

        return $this->verifier;
    }

    /** Shared replay record (every PHP-FPM worker sees it): a primary-key insert, so a concurrent duplicate loses. */
    public function claimJti(string $id, int $until): bool
    {
        $db = \Config\Database::connect();
        $key = hash('sha256', $id);
        $now = (int) floor($this->now ? ($this->now)() : time());
        $db->table('gateway_seen_jti')->where('expires_at <', date('Y-m-d H:i:s', $now - 60))->delete();
        if ($db->table('gateway_seen_jti')->where('jti', $key)->countAllResults() > 0) {
            return false;
        }
        try {
            return (bool) $db->table('gateway_seen_jti')->insert(['jti' => $key, 'expires_at' => date('Y-m-d H:i:s', $until)]);
        } catch (\Throwable) {
            return false;
        }
    }

    // ----------------------------------------------------------------------------------------------------------
    // Per-request state: the verified caller and the token use to report back (contract §4 metering)
    // ----------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $claims */
    public static function setCaller(array $claims): void
    {
        $_SERVER['ohub_gateway'] = $claims;
        $_SERVER['ohub_gateway_usage'] = null;
    }

    /** @return array<string, mixed>|null the verified claims, when this request came through the gateway */
    public static function caller(): ?array
    {
        $c = $_SERVER['ohub_gateway'] ?? null;

        return is_array($c) ? $c : null;
    }

    public static function reset(): void
    {
        unset($_SERVER['ohub_gateway'], $_SERVER['ohub_gateway_usage'], $_SERVER['ohub_gateway_audited']);
    }

    /** Adds one successful model call's token use (summed when a call makes more than one, e.g. tool follow-ups). */
    public static function meter(?string $model, ?int $promptTokens, ?int $completionTokens): void
    {
        if (self::caller() === null) {
            return;
        }
        $u = $_SERVER['ohub_gateway_usage'] ?? null;
        $u = is_array($u) ? $u : ['in' => 0, 'out' => 0, 'model' => null];
        $u['in'] += (int) $promptTokens;
        $u['out'] += (int) $completionTokens;
        $u['model'] = $model ?: $u['model'];
        $_SERVER['ohub_gateway_usage'] = $u;
    }

    /**
     * Metering headers for a gateway caller (the gateway removes them before the client sees the answer); empty for
     * every other caller and when no model call was made.
     *
     * @return array<string, string>
     */
    public static function meteringHeaders(): array
    {
        $u = $_SERVER['ohub_gateway_usage'] ?? null;
        if (self::caller() === null || ! is_array($u)) {
            return [];
        }
        $h = ['X-WS-AI-Tokens-In' => (string) $u['in'], 'X-WS-AI-Tokens-Out' => (string) $u['out']];
        if ($u['model']) {
            $h['X-WS-AI-Model'] = (string) $u['model'];
        }
        $app = ($_SERVER['ohub_api_key'] ?? [])['application'] ?? null;
        if (is_string($app) && $app !== '') {
            $h['X-WS-AI-App'] = $app;
        }

        return $h;
    }

    /** The audit fields WIRING §0.7 asks for, as stored in audit_logs.meta. */
    public static function auditMeta(): ?string
    {
        $c = self::caller();
        if ($c === null) {
            return null;
        }

        return json_encode(['gateway' => [
            'jti' => $c['jti'], 'sub' => $c['sub'], 'sub_type' => $c['sub_type'], 'cred' => $c['cred'],
            'client_id' => $c['client_id'], 'project' => $c['project'], 'env' => $c['env'], 'node' => $c['node'],
            'org' => $c['org'], 'scopes' => $c['scopes'],
        ]], JSON_UNESCAPED_SLASHES);
    }
}
