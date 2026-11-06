# WorkSuite AI Studio MCP server

Manage AI Studio from Claude (Desktop, Code, claude.ai) or ChatGPT through the
[Model Context Protocol](https://modelcontextprotocol.io). The server signs in to your AI Studio
install as one user and exposes its admin API as tools:

| Area | Tools (examples) |
|---|---|
| Overview | `whoami`, `dashboard_get`, `metrics_get`, `health_get`, `analytics_get` |
| Servers | `servers_list`, `servers_create`, `servers_health_check`, `servers_disable`, `servers_control` (restart/shutdown/update) |
| Models | `models_list`, `models_pull`, `models_load`, `models_unload`, `models_config_save`, `models_set_default` |
| Chat | `chat_send` (collects the streamed reply), `generate`, `chat_sessions_*` |
| Gateway | `apikeys_*` (create/rotate/revoke), `apilogs_list` |
| Admin | `users_*`, `roles_*`, `audit_list`, `notifications_*`, `settings_get` / `settings_save` |
| AI workspace | `prompts_*`, `knowledge_*` (incl. `knowledge_search`), `agents_*` |
| SinLama | `datasets_*`, `training_*`, `pipelines_*`, `studio_*`, `ml_models_*` |
| Storage | `storage_*`, `backups_*`, `backups_restore` |
| Compute | `compute_*`, `portal_*` |
| Sinhala engine | `engine_*` (evaluations, packages, bindings, rollbacks) |
| Anything else | `api_request` (any `/api/...` route except `/auth/*`) |

188 tools in total. Every tool carries MCP annotations (read-only / destructive), so clients
can ask for confirmation before changes. **Permissions are enforced by AI Studio**: the tools can
do exactly what the signed-in user can do in the web app.

## 1. Create a service user

In AI Studio, create a dedicated user (for example `mcp-bot@yourcompany.com`) with a role that has
only the permissions you want the assistant to have. For a look-but-don't-touch setup, also set
`AISTUDIO_MCP_READ_ONLY=true`, which hides every create/update/delete/control tool.

If that user has two-factor sign-in on, put its TOTP secret (the base32 key shown during setup)
in `AISTUDIO_TOTP_SECRET` so the server can produce codes itself.

## 2. Build

```bash
cd mcp-server
npm install
npm run build
```

Requires Node.js 20+.

## 3a. Local: Claude Desktop / Claude Code (stdio)

Claude Desktop, `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "aistudio": {
      "command": "node",
      "args": ["/path/to/worksuite-ai-studio/mcp-server/dist/index.js"],
      "env": {
        "AISTUDIO_URL": "https://aistudio.example.com",
        "AISTUDIO_EMAIL": "mcp-bot@example.com",
        "AISTUDIO_PASSWORD": "…",
        "AISTUDIO_TOTP_SECRET": ""
      }
    }
  }
}
```

Claude Code:

```bash
claude mcp add aistudio --env AISTUDIO_URL=https://aistudio.example.com --env AISTUDIO_EMAIL=mcp-bot@example.com --env AISTUDIO_PASSWORD=… -- node /path/to/mcp-server/dist/index.js
```

## 3b. Remote: claude.ai and ChatGPT (HTTP)

Web clients need the server reachable over HTTPS.

```bash
cp .env.example .env   # fill in AISTUDIO_* and a long random MCP_AUTH_SECRET
set -a; . ./.env; set +a
npm run start:http      # listens on $PORT (default 8787), endpoint /mcp
```

Put it behind your HTTPS reverse proxy (e.g. `https://aistudio.example.com/mcp-gw/` → `http://127.0.0.1:8787/`)
and keep it running with systemd, pm2 or Docker. A health check is at `/healthz`.

Generate the secret with e.g. `openssl rand -hex 32`. The server refuses to start in HTTP mode
without one (at least 24 characters). Give each client its own secret with
`MCP_AUTH_SECRETS=secretA,secretB` so you can revoke one without touching the others.

Clients send the secret as `Authorization: Bearer <secret>`. Connector UIs that cannot set headers
(claude.ai, ChatGPT) need it in the URL instead, `https://host/mcp/<secret>`: turn that on with
`MCP_ALLOW_URL_SECRET=true`, treat the URL like a password, and switch access logging off for
`/mcp` on your reverse proxy (the commented `location /mcp` block in `deploy/nginx-ollamahub.conf`
does this) so the secret never lands in log files.

- **claude.ai** (needs `MCP_ALLOW_URL_SECRET=true`): Settings → Connectors → *Add custom connector* → URL `https://your-host/mcp/<secret>`.
- **ChatGPT** (needs `MCP_ALLOW_URL_SECRET=true`): Settings → Apps & Connectors → Advanced → turn on *Developer mode*, then *Create* a
  connector with URL `https://your-host/mcp/<secret>` and authentication *No authentication*
  (the secret in the URL is the authentication).
- **Any client that can send headers** (Claude Code `--transport http`, MCP Inspector, scripts):
  URL `https://your-host/mcp` with header `Authorization: Bearer <secret>`.

## Configuration

| Variable | Meaning |
|---|---|
| `AISTUDIO_URL` | Base URL of AI Studio (required) |
| `AISTUDIO_EMAIL` / `AISTUDIO_PASSWORD` | Service user sign-in (recommended: the server re-signs in on its own) |
| `AISTUDIO_TOTP_SECRET` | Base32 TOTP secret if the user has two-factor on |
| `AISTUDIO_REFRESH_TOKEN` | Alternative to email/password; valid 14 days (browser localStorage `aistudio.refresh`) |
| `AISTUDIO_MCP_READ_ONLY` | `true` exposes read tools only, and `api_request` allows GET only |
| `MCP_AUTH_SECRET` / `MCP_AUTH_SECRETS` | Required for `--http`; the second is a comma-separated list, one per client |
| `MCP_ALLOW_URL_SECRET` | `true` accepts `/mcp/<secret>` for connectors that cannot send headers (default off) |
| `PORT` | HTTP port, default 8787 |

## Notes

- Results longer than 100,000 characters are truncated; use `query` (`page`, `per_page`, filters) to narrow.
- Every action is made by the service user and shows up in AI Studio's audit log under that user.
- Adding a tool for a new API route is one line in `src/tools.ts`.
