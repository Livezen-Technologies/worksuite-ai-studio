# WorkSuite AI Studio

Self-hosted AI control plane, live at https://aistudio.livezentech.com (server 40.160.57.17, `/var/www/ollamahub`).

- **Backend:** CodeIgniter 4 (`app/`, `spark`); `composer install` to restore `vendor/`.
- **Frontend:** React + Ant Design, **built** into `public/app/`. The frontend source was not on the server and is
  not in this repository yet.
- **Deploy:** see `deploy/` (nginx config, `ollamahub-queue.service`, `install-server.sh`, `rsync-deploy.sh`,
  `env.production.example`). Copy `env` or `deploy/env.production.example` to `.env`; never commit `.env`.

Imported from the production server on 2026-10-24 (version in `VERSION`).

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
