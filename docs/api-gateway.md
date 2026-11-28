# AI Studio behind the WorkSuite API Gateway

The API console (api_maanger) publishes AI Studio's public AI API at `api.worksuitecloud.com` as `aistudio.v1`:
`/v1/aistudio/openai/v1/*` → `/api/openai/v1/*` and `/v1/aistudio/v1/*` → `/api/v1/*` (WIRING.md §7). The gateway
removes the caller's `Authorization`, `X-Api-Key` and `Cookie` and sends its own signed identity assertion:

```
Authorization: WS-API-Gateway <ES256 JWT, typ wsapi-gateway+jwt, aud ai-studio, 30 s>
```

AI Studio trusts it only when `APIM_GATEWAY_ENABLED=true`. Direct calls with a key/secret or an access token keep
working exactly as before, with the flag on or off.

## Settings (`.env`)

| Setting | Default | |
| --- | --- | --- |
| `APIM_GATEWAY_ENABLED` | `false` | Off: the `WS-API-Gateway` scheme gets `401` (`GATEWAY_DISABLED`). |
| `APIM_GATEWAY_JWKS_URL` | `https://api.worksuitecloud.com/.well-known/apim-gateway-jwks.json` | The gateway's public keys. Cached 5 min; an unknown `kid` refetches at most every 30 s; the cached keys keep working while the gateway is unreachable. |
| `APIM_GATEWAY_ISSUER` | `https://api.worksuitecloud.com` | |
| `APIM_GATEWAY_AUDIENCE` | `ai-studio` | |
| `APIM_GATEWAY_TRUSTED_CIDRS` | empty (trusts nothing) | The gateway node's egress IP(s). |
| `APIM_GATEWAY_ORGS` | empty (none) | API console organization ids whose credentials may call (Livezen Technologies). |
| `app.proxyIPs` | as today | Add `127.0.0.1=X-Forwarded-For` (the local nginx), so the gateway's address is the **last** `X-Forwarded-For` hop. Only that hop is used, and only when the socket peer is a listed proxy. |

Mind the trailing-newline trap when appending to `/var/www/ollamahub/.env`: check `tail -c1` first.

## What is checked (data-plane contract §8)

In order: the peer is in `APIM_GATEWAY_TRUSTED_CIDRS`; the header is exactly ES256 / `wsapi-gateway+jwt` with no
`crit`; the `kid` is in the JWKS and the 64-byte `r||s` signature verifies; `iss` and `aud` (a single string) match;
`0 < exp − iat ≤ 60`, not expired and `iat` not in the future (30 s leeway); `htm`/`htp` equal the request's method and
path (`/api/…`, after the gateway's rewrite, without the query); the `jti` was not seen (table `gateway_seen_jti`,
shared by every PHP-FPM worker, recorded only after every other check passed). A refusal is `401` with
`WWW-Authenticate: WS-API-Gateway` and the reason code in `X-WS-Gateway-Reason` and the message (`alg`, `typ`,
`unknown_kid`, `signature`, `iss`, `aud`, `lifetime`, `expired`, `iat_future`, `htm`, `htp`, `replay`,
`untrusted_peer`, `malformed`, `missing`, `unmapped`, `GATEWAY_DISABLED`). The verifier
(`app/Libraries/Gateway/GatewayAssertion.php`) passes the 16 cross-language vectors.

## Who the caller is

The caller becomes an **API key row**, so the key's allowed models, quotas, rate limit, owner checks, logging and
`AuditFilter` all apply unchanged. Nothing is created or linked by the gateway.

- `sub_type: credential` (`sub = cred:<id>`: console API keys, service accounts, certificates, client-credentials
  apps): the one key with `api_keys.gateway_credential_id = <id>`, and `org` must be in `APIM_GATEWAY_ORGS`.
- `sub_type: user` (OneAuth user tokens): the AI Studio user already linked to that OneAuth subject
  (`users.wsc_sub`, active), and that user's key with `gateway_credential_id` = the OAuth app's credential (`cred`).
- `anonymous`, or no or several matching keys: `401 unmapped`.
- A key with an IP allowlist cannot be used through the gateway (`403 ip_allowlist`): the gateway's address is not
  the client's.

Bind a key by setting its `gateway_credential_id` to the console credential id (Credentials page of the AI Studio
project in the API console), for example `UPDATE api_keys SET gateway_credential_id = '0193…' WHERE id = 42;`.

## Scopes (deny by default)

The route must need a scope the assertion carries, and the key is narrowed to what those scopes grant (never wider
than the key's own scopes):

| Gateway scope | Routes | Key scopes it may use |
| --- | --- | --- |
| `aistudio.models.read` | `GET openai/v1/models[/…]`, `GET v1/models`, `GET v1/catalog` | `models`, `download_models`, `download_datasets` (catalogue listing only) |
| `aistudio.chat` | `openai/v1/chat/completions`, `openai/v1/completions`, `v1/chat`, `v1/chat/completions`, `v1/generate`, `v1/summarize` | `chat`, `generate` |
| `aistudio.embeddings` | `openai/v1/embeddings`, `v1/embeddings` | `embeddings` |
| `aistudio.audio` | `openai/v1/audio/{transcriptions,translations,speech}` | `transcribe`, `tts` |
| `aistudio.translate` | `v1/translate` | `chat` |
| `aistudio.predict` | `GET v1/classifiers`, `v1/classify`, `v1/predict` | `classify` |

Everything else under `apikey` is refused through the gateway (`403 route`): model and dataset file downloads stay
direct. The dashboard and admin routes (`jwt`) never accept the scheme. Also not through the gateway: engine signing,
node enrolment, agent heartbeats, training callbacks and `/api/v1/oauth/token`.

## Metering (contract §4)

For gateway callers only, a successful model call answers with `X-WS-AI-Tokens-In`, `X-WS-AI-Tokens-Out`,
`X-WS-AI-Model` and, when the key has an application, `X-WS-AI-App` (the same numbers as `api_logs`). The gateway
meters and removes them.

**Streams are not metered yet.** Streamed answers (`stream: true`, server-sent events) send their headers with the
first token, before the usage is known, and PHP-FPM behind nginx cannot send HTTP trailers (FastCGI has no trailer
support), so no `Trailer:` is declared and the counts are not reported to the gateway. The call is still logged with
its tokens in `api_logs` and counts against the key's own quotas. Closing this needs either the gateway reading the
final SSE `usage` chunk, or a gateway-side estimate.

## Audit

Every call through the gateway, reads included, writes an `audit_logs` row: `user_name` `gateway <sub>`, and in
`meta` the `jti` (request id), `sub`, `sub_type`, `cred`, `client_id`, `project`, `env`, `node`, `org` and `scopes`.
Streams, which exit before after-filters run, are recorded at shutdown.
