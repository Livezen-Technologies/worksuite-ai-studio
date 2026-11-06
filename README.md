# WorkSuite AI Studio

Self-hosted AI control plane, live at https://aistudio.livezentech.com (`/var/www/ollamahub`).

- **Backend:** CodeIgniter 4 (`app/`, `spark`). `composer install`, then `vendor/bin/phpunit` for the tests.
- **Frontend:** React + Tailwind source in `frontend/`, built into `public/app/` (`cd frontend && npm ci && npm run build`;
  commit `public/app` with the change, CI checks they match).
- **Docs:** [architecture](docs/architecture.md), [local development](docs/development.md),
  [API reference](docs/api-reference.md).
- **Deploy and operations:** [`docs/runbook.md`](docs/runbook.md) (deploy, backups, restore, server hardening).
  Scripts and configs are in `deploy/`. Copy `env` or `deploy/env.production.example` to `.env`; never commit `.env`.
- **CI:** `.github/workflows/ci.yml` runs backend tests, the frontend type-check/tests/build and the MCP server build
  on every pull request.

## WorkSuite Sinhala AI Engine

AI Studio is the **build & training zone** for the offline WorkSuite Sinhala AI Engine: dataset rights and frozen
splits, training lineage, evaluation release gates and Ed25519-signed model packages (`/api/engine/*`).
Spec: [`docs/sinhala-ai-engine/SRS.md`](docs/sinhala-ai-engine/SRS.md) · what's built here:
[`docs/sinhala-ai-engine/IMPLEMENTATION.md`](docs/sinhala-ai-engine/IMPLEMENTATION.md).
After deploying run `php spark migrate`.

## MCP server (Claude and ChatGPT)

`mcp-server/` lets Claude (Desktop, Code, claude.ai) and ChatGPT manage AI Studio through the Model
Context Protocol: servers, models, chat, API keys, users and roles, datasets and training, storage,
compute and the Sinhala engine. See [mcp-server/README.md](mcp-server/README.md).
