# WorkSuite AI Studio architecture

A self-hosted control plane for Ollama nodes, model training and the WorkSuite Sinhala AI Engine.
One server runs the control plane; GPU/CPU **nodes** do inference and training.

```
 Browser ──HTTPS──▶ nginx ─┬─ /api/*      ─▶ PHP-FPM ─▶ CodeIgniter 4 (app/) ─▶ MySQL
                           │                                 │
                           └─ everything else ─▶ public/app (React build)
                                                             │
     Claude / ChatGPT ─▶ mcp-server (Node) ─▶ /api/*         ├─▶ Ollama nodes (api_url, HTTP)
     API clients ─▶ /api/v1/* (key + secret, gateway)        ├─▶ local ASR / ML services (Python)
     Desktop node app ─▶ /api/nodes/enrol, SSH reverse tunnel└─▶ object storage (disk, S3)
```

## Components

| Part | Where | What it does |
|---|---|---|
| Backend | `app/` (CodeIgniter 4, PHP 8.3) | JSON API under `/api`: servers, models, chat, gateway, users/roles, datasets, training, storage, compute, Sinhala engine |
| Frontend | `frontend/` → built into `public/app/` | React + TanStack Query + Tailwind admin app and public site, served from `/` |
| Database | MySQL (`ollamahub`) | All state; migrations in `app/Database/Migrations` |
| Queue worker | `ollamahub-queue` systemd unit (`spark queue:work`) | Background jobs: pulls, training kits, imports, backups |
| Scheduler | cron → `spark schedule:run` every minute | Health checks, metrics, scheduled backups |
| Gateway | `/api/v1/*` (`app/Controllers/Api/Gateway`) | OpenAI-style chat/generate/embeddings for apps, routed across nodes (least-busy, random, sticky) |
| Nodes | Ollama on GPU/CPU machines | Registered by `api_url`; enrolled desktop nodes reach the server through an SSH reverse tunnel (`deploy/node-tunnel`) |
| Desktop node app | `node-app/` (Tauri) | Enrols a machine (one-time token over HTTPS), runs the agent, keeps the tunnel up |
| Speech | `deploy/asr` (`aistudio-asr` unit) | Whisper Sinhala transcription for Speech to Text |
| Table ML | `deploy/ml` (`aistudio-ml` unit) | scikit-learn classifiers for Table models |
| Monitoring | Prometheus + node_exporter | Live metrics on the Monitoring page; alert rules in `deploy/prometheus-alerts.yml` |
| MCP server | `mcp-server/` (Node) | Exposes the admin API as MCP tools for Claude and ChatGPT |

## Authentication and permissions

- **People** sign in at `/api/auth/login` (password, optional TOTP, or passkey) and get a 1-hour
  access token and a 14-day refresh token (HMAC JWTs signed with `JWT_SECRET`).
- Every token carries the user's `token_version`. The auth filter (`app/Filters/JwtAuthFilter.php`)
  re-reads the user on each request: suspended users and revoked sessions (sign-out, password, role
  or status change) are refused immediately, and the role used is the current one from the database.
- **Permissions** are role-based (`roles`, `permissions`, `role_permissions`); controllers call
  `requirePermission('servers.manage')` and similar. `super_admin` and `admin` pass every check.
- **Apps** use the gateway with an API key and secret (`app/Filters/ApiKeyFilter.php`), with
  per-key rate limits and quotas.
- **Audit**: every change, download and sign-in attempt is written to `audit_logs`
  (`app/Filters/AuditFilter.php`).
- **CORS** is not enabled: the frontend is served from the same origin as the API. Enable the
  `cors` filter in `app/Config/Cors.php` only if another origin must call the API from a browser.

## Versioning

- `VERSION` is the product version (also the newest `## [x.y.z]` in `CHANGELOG.md`, which becomes a
  blog post and the "New in" banner on the landing page). `frontend/package.json` follows it.
- `node-app/` and `mcp-server/` are released separately and keep their own versions (the desktop
  app is tagged `node-app-vX.Y.Z` to build installers).

## Further reading

- [Local development](development.md)
- [Operations runbook](runbook.md): deploy, backups, restore, server hardening
- [API reference](api-reference.md)
- [Sinhala AI Engine](sinhala-ai-engine/IMPLEMENTATION.md)
- [MCP server](../mcp-server/README.md)
