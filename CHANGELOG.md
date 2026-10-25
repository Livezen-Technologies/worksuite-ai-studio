# Changelog — OllamaHub

All notable changes to the OllamaHub control plane are documented here.

## [1.8.0] — 2026-10-25 — Voice input in Chat, Sinhala + English speech

- **Voice input in Chat:** a microphone button beside the message box. Speak and the words are typed in as you
  talk (Whisper Sinhala on the server); the button turns into a red recording pill with live sound bars, a timer and
  Stop, and the message stays editable before sending. A small switch picks Sinhala (default), Sinhala + English or
  English, remembered per device.
- **Sinhala + English (mixed)** on Speech to Text and in Chat: speech is cut at pauses, each phrase is identified as
  Sinhala or English (Whisper base) and transcribed by the right model — Whisper Sinhala, or OpenAI Whisper small for
  English — so both appear in their own script, tagged per line. English words inside a Sinhala sentence still come
  out in Sinhala letters. Neighbouring phrases share one pass; sub-word tails are dropped.
- The live recording engine is now one shared hook (`useLiveTranscriber`) used by the Speech page and Chat.
- Speech service: multilingual models `faster-whisper-small` and `faster-whisper-base` (MIT), 6 CPU threads, and a
  higher CPU priority (`Nice=-5`) so voice input stays responsive while Ollama is answering chats.

## [1.7.0] — 2026-10-25 — Live transcription, captions and subtitles

- **Live transcription:** while you record, speech is cut at natural pauses (4–12 s) and each piece is transcribed
  as you keep talking, so lines appear with their times during the recording. The whole recording is kept for
  playback and captions.
- **Recording animation:** live audio bars driven by the microphone, a LIVE badge, pulsing stop button and timer;
  respects reduced motion.
- **Captions & subtitles:** per-word timings from the model; captions follow subtitle conventions (characters per
  line, lines per caption, longest time on screen, new caption at pauses and sentence ends, never overlapping);
  a caption editor (times and text per line, add / remove, click a time to jump there); `.srt` and `.vtt` downloads.
- **Video:** upload MP4, MOV or WebM video (up to 500 MB) and preview it with the subtitles over the picture; audio
  previews show the current caption under the player.
- Speech service: word timings (`words=1`), a fast greedy mode for live pieces (`fast=1`), uploads spooled to a
  temporary file so MP4/MOV with a trailing index decode, silence skipped in every mode and punctuation-only lines
  dropped. Caption rules covered by unit tests.

## [1.6.1] — 2026-10-25 — Stream speech uploads

- `POST /api/speech/transcribe` streams the uploaded audio from disk to the speech service instead of reading it
  into PHP memory, so long recordings (up to 60 MB) stay within PHP's memory limit. Verified: 42 MB / 22.7 min of
  audio transcribed with 2 MB peak PHP memory in 160 s.

## [1.6.0] — 2026-10-25 — Speech to Text

- New **Speech to Text** page (AI Workspace → Speech to Text, `/speech`): record from the microphone (live level
  meter, timer, 10-minute cap) or upload / drag in an audio file (WAV, MP3, M4A, OGG, WebM, FLAC, up to 60 MB),
  choose Sinhala, English or automatic detection, and get the transcript — editable, with time-coded lines, Copy,
  `.txt` and `.srt` subtitle downloads, and **Send to chat**. Audio is transcribed on the server and not stored.
- API: `GET /api/speech/status`, `POST /api/speech/transcribe` (multipart `audio`, `language`), permission `chat.use`.
- Engine: the local **aistudio-asr** service (`deploy/asr/asr_service.py`, `deploy/aistudio-asr.service`) keeps
  Whisper Sinhala (SinhaSpeech whisper-small run11, CTranslate2 int8 via faster-whisper) loaded on 127.0.0.1:8096
  and decodes any browser or phone audio with ffmpeg; one transcription at a time.

## [1.5.7] — 2026-10-25 — Speech models on the Models page

- The Models page gains a **Speech models** section listing the Sinhala Engine's speech-recognition and voice
  packages (e.g. Whisper Sinhala via faster-whisper): type, runtime and quantisation, size, licence and release state,
  each linking to its package. Speech models don't run in Ollama, so they stay out of the node list and the Chat
  model picker.

## [1.5.6] — 2026-10-25 — Datasets list fixed

- The Datasets list no longer lets long descriptions stretch the table and crush the Created column: name and
  description are one line each (full text on hover), and every other column stays on one line.
- New columns: dataset number (#) and Rights (Approved / Quarantined / Withdrawn, with icon and text); a rights
  filter with counts; search also matches the dataset number; newest first.

## [1.5.5] — 2026-10-25 — Polish and de-duplicate datasets

- `php spark datasets:polish [ids…] [--cross] [--drop-empty] [--dry-run]` cleans datasets with set-based SQL (minutes
  for millions of rows): strips control and invisible characters but keeps the zero-width joiner Sinhala needs
  (ශ්‍රී, ක්‍ය), collapses spaces and blank lines, drops empty rows, answers identical to the question and outputs
  over 8,000 characters, then removes duplicates inside each dataset. Hashes and token estimates are recomputed
  exactly as an import computes them.
- `--cross` keeps each row once across datasets — in an approved dataset before a quarantined one, then in the
  larger one; `--drop-empty` deletes datasets left empty. Frozen datasets are never changed.

## [1.5.4] — 2026-10-25 — Stream large dataset imports

- `spark datasets:import` streams `.jsonl` files line by line (`DatasetService::importJsonlFile`), inserting in
  batches of 1,000 with progress every 50,000 lines, so open corpora of millions of rows import without loading the
  file into memory. Same rules as an upload: field aliases, duplicate skipping, token estimates, group keys.

## [1.5.3] — 2026-10-25 — Freeze and export from the command line

- `php spark datasets:freeze <id> [--train 0.8] [--dev 0.1]` — the Splits tab's freeze (grouped, deterministic,
  new manifest version) for scripted pipelines.
- `php spark datasets:export <id> <dir>` — writes a frozen, rights-approved dataset's `train.jsonl` and `dev.jsonl`
  plus `manifest.json` (version, manifest and split hashes) for a training runner. The test split is never exported
  (TRNPIPE 003); unfrozen or unapproved datasets are refused.

## [1.5.2] — 2026-10-25 — Imports keep group keys

- Dataset imports (upload and `spark datasets:import`) now keep an optional `group_key` (or `group`) field per row.
  Rows that come from the same source item — a speaker, a document, or a Sinhala sentence and its Singlish form —
  share a key, so freezing keeps them in the same train / dev / test split and the locked test set cannot leak into
  training (TRNPIPE 003). Verified: 400 grouped rows frozen, no group split across sets.

## [1.5.1] — 2026-10-25 — Server-side dataset import

- `php spark datasets:import <file>` imports a JSONL / JSON / CSV file that is already on the server into a new or
  existing dataset (`--dataset ID`), through the same import as an upload (field aliases, duplicate skipping, token
  estimates), and records source, permitted use and rights in the same step; `--approve` follows the Governance
  tab's rule (refused unless source, use and rights are all given). For open corpora too large for the browser.

## [1.5.0] — 2026-10-24 — Sinhala AI Engine: release core (Phase 0 + 1)

### Phase 1 — release core
- **Sinhala Engine** sidebar section with Engine Overview (S01), Evaluations with the gate checklist and dual
  approval (S07), Approvals queue (S08), Package registry (S09), Package detail with lifecycle, artifacts, signed
  manifest and history (S10), and Bindings per task × device profile with activation and rollback (S11).
- Datasets gain **Governance** (rights, consent, approve / withdraw — S02) and **Splits** (grouped freeze, hashes,
  coverage — S03) tabs; Training jobs gain **Lineage** (S05) and show the server's refusal when a kit is blocked.
- API: `GET /engine/evaluations/{id}`, `GET /engine/packages/{id}` (with `allowed_next` and the `missing` evidence),
  `POST`/`DELETE /engine/packages/{id}/artifacts` (server-side sha256), `GET /engine/approvals`,
  `GET`/`PUT /engine/bindings`, `POST /engine/bindings/rollback`; `split_counts` and `group_coverage` on datasets.
- Migration `2026-10-24-000014_CreateEngineBindings` (task × profile bindings, backfilled from active packages;
  engine.* permissions granted to admin roles).
- Behaviour changes: training kits need a frozen, non-withdrawn dataset (409); `engine.sign` no longer falls back
  to `models.manage`; activation verifies the package signature; a candidate can be registered before its
  artifacts are uploaded.
- Verified locally end to end (35 checks: rights → freeze → kit → evaluation → dual approval → upload → lifecycle →
  signed manifest → activate → rollback → permissions) on a fresh database.

### Phase 0 — backend from PR #1

Implements the AI Studio side of the **WorkSuite Sinhala AI Engine SRS v1.0**
(`docs/sinhala-ai-engine/SRS.md`; mapping in `docs/sinhala-ai-engine/IMPLEMENTATION.md`).

- **Dataset governance** (TRNPIPE 001/003/006): rights & provenance fields, quarantine until approved,
  grouped deterministic train/dev/test freeze with manifest + split hashes, withdrawal.
- **Training lineage** (TRNPIPE 002): kits export train + dev only (locked test split stays on the server),
  write `lineage.json`, and record dataset/config hashes and seed on the job.
- **Evaluation reports & release gates** (§17): `EngineGateService` with SRS thresholds per task,
  ≤2-point regression and stratum rules, dual approval (release owner + language lead).
- **Signed model packages** (§10.1, §14.5, ADM 001–004, SEC 007): lifecycle state machine, artifact/licence
  checks, Ed25519 detached signatures, atomic activation and rollback.
- New API group `/api/engine/*`, permissions `engine.view|manage|sign`,
  migration `2026-10-24-000013_CreateEngineReleaseZone`.

## [1.4.0] — 2026-10-24 — WorkSuite AI Studio redesign

The whole admin UI is rebuilt as **WorkSuite AI Studio** on Tailwind CSS v4 (Tailwind Plus v4 application UI
patterns), in the WorkSuite design language, with full light/dark/system themes and phone, tablet and desktop
layouts. The original React source was not on the server, so the frontend is recreated in `frontend/` against the
unchanged API; every screen keeps the features of 1.3, recovered from the shipped bundle. No backend changes.

### Foundation
- `frontend/`: Vite 6 + React 19 + TypeScript, Tailwind CSS v4, Headless UI, Heroicons, TanStack Query, Recharts 3 —
  the same stack as WorkSuite. Builds into `public/app/` and serves from `/` exactly as the live nginx expects.
- WorkSuite tokens (slate neutrals, blue-600 accent, `brand-*` utilities), class-based dark mode with Light / Dark /
  System, applied before first paint; reduced-motion respected.
- Shared component kit: buttons, form controls with field errors, cards, stat cards, meters, badges with one
  status→colour map, data table with built-in skeleton / error-with-retry / empty states, pagination, tabs, segmented
  control, modals, form modals, confirm dialogs, row action menus, toasts.
- App shell: WorkSuite-style sidebar with the same six sections, routes and permissions as 1.3 (drawer on phones),
  sticky header with ⌘K page search, notifications popover with unread count, theme menu and user menu,
  breadcrumbs, skip link, per-page error boundary and permission guard; old `/apikeys`, `/apilogs`, `/dashboard`
  URLs redirect.
- API client: `{status, message, data}` envelope, field errors from 422s, one-shot access-token refresh on 401,
  NDJSON streaming and authenticated downloads.
- New sign-in screen. Sessions from 1.3 are not carried over: everyone signs in once after the update.

### Screens
- **Dashboard:** the 8 KPI cards plus GPU, gateway error rate and active users; 24 h requests, 7-day tokens, model
  usage, node health (worst first), recent gateway activity; live every 15 s.
- **Monitoring:** health summary from `/health`, per-node CPU/RAM/disk/GPU with in-session trend lines, health score,
  heartbeat, re-check with per-node latency; choosable auto-refresh.
- **Analytics:** 7 / 30 / 90 / 365-day ranges, requests, tokens, latency, model popularity, gateway outcomes donut.
- **Servers:** Ollama nodes and OpenAI / Anthropic / Gemini providers, health checks, node controls, a detail dialog
  with hardware, health history and installed models (`/servers/:id` links still work).
- **Models:** sync, catalogue install with streamed pull progress, load/unload, default, delete, full model config.
- **Queue:** jobs with counters, filters, enqueue with priority/payload, retry/cancel, job detail; scheduled tasks
  with schedule picker, enable switch and Run now.
- **Model Studio, Datasets, Training (SinLama):** presets, GGUF / LoRA uploads with progress, streamed build log,
  model cloning; dataset import, preview, dedupe, clean, export; training jobs with all hyperparameters and kits.
- **Chat:** sessions with rename/delete/export, streamed replies with a safe Markdown renderer and code copy, stop
  and regenerate, image attachments for vision models, start from an agent or a saved prompt.
- **Prompt Library, Knowledge Base, AI Agents:** search and filters, variable fill-in, file upload to knowledge
  bases, retrieval test with top-k, agent tools/knowledge/API access and one-click chat.
- **API Keys:** scopes, rate and token quotas, allowed models/IPs, expiry; a copy-once secret reveal with a curl
  example; gateway endpoint reference. **API Logs** and **Audit Logs:** filters and a detail view per entry.
- **Users, Roles, Settings:** search and filters, suspend/reactivate, a grouped permission matrix, six settings
  groups with unsaved-change tracking and write-only secrets.
- **Notifications:** All/Unread, mark one or all read, broadcast and channel test for admins.

### Notes
- Everyone signs in once after the update (1.3 sessions are not carried over).
- Not possible without backend changes: per-API-key analytics, before/after diffs in the audit log, per-chat
  parameters and knowledge-base retrieval in chat.

## [1.3.0] — 2026-06-29 — SinLama AI (Sinhala LLM platform)

Integrates the SinLama blueprint into the existing platform (new sidebar section **SinLama**).

### S1 — Model Studio (`/studio`)
- Build custom models via Ollama's structured `/api/create`: pick a **base model + Sinhala
  persona system prompt + parameters** (optionally quantize), or **import a trained GGUF** /
  attach a **LoRA adapter** (uploaded straight into the node's blob store via
  `/api/blobs/sha256:…`). Streams create progress; auto-syncs the model list so the new model
  appears in Chat + gateway immediately. 5 SinLama presets ship in code (Assistant persona,
  Translator si⇄en, Support agent, Writer, Code — Sinhala system prompts + tuned params).
- `OllamaService`: `createModel()`, `copy()`, `blobExists()`, `pushBlob()` (streamed from disk).
- New `ModelStudioController` (`/api/studio/{presets,upload,create,copy}`).

### S2 — Dataset Manager (`/datasets`)
- Alpaca-style instruction datasets: upload **JSON / JSONL / CSV** (field aliases
  question/answer, prompt/response recognised), automatic duplicate skip, **dedupe** &
  **clean** passes, token estimates (~1 tok / 3 bytes — Sinhala-aware), searchable row
  preview, per-row delete, **export Alpaca JSONL**.
- Tables `datasets` + `dataset_items` (migration `…000011_CreateSinlamaTables`),
  `DatasetService`, `DatasetsController`.

### S3 — Training Manager (`/training`)
- LoRA/QLoRA job records with hyperparameters (epochs/LR/rank/alpha/batch/grad-accum/
  max-seq/GGUF quant). **Generate kit** → zip of `train.py` (Unsloth + PEFT + TRL, Alpaca
  format, GGUF export), `dataset.jsonl`, `README.md` with RunPod/Colab steps — because this
  server is CPU-only, training runs on an external GPU; the resulting GGUF is imported via
  Model Studio (job statuses: draft → kit_ready → running → completed → imported).
- Table `training_jobs`, `TrainingService`, `TrainingController`.

### S4 — Sinhala gateway endpoints + prompt pack
- Gateway: **`POST /api/v1/translate`** ({model, text, target?: si|en} — auto-detects and
  flips languages; Singlish treated as Sinhala) and **`POST /api/v1/summarize`**
  ({model, text, language?, sentences?}). Both use the `chat` scope and work with local
  and external-provider models.
- Migration `…000012_SeedSinhalaPrompts` seeds 6 Sinhala prompts (category **Sinhala**) into
  the Prompt Library: SinLama persona, translate si/en, Singlish→Sinhala script, grammar
  correction, summariser.

## [1.2.1] — 2026-06-29 — Browse & install models from a catalogue

### Added
- **Model catalogue in the Install dialog** (Models page). The old free-text "Pull model" box is
  now a searchable, category-filtered catalogue (`MODEL_CATALOG` in `api/models.ts`) of ~28 popular
  Ollama-library models grouped **General / Reasoning / Code / Vision / Embedding**, each showing an
  approximate size, a one-line description, and a **vision** tag where applicable. Click to select,
  then Install; already-installed models are marked (handles the `:latest` alias). A custom field
  still accepts any other `ollama.com/library` tag. The node picker lists only Ollama nodes
  (external providers can't pull library models). Frontend-only — uses the existing pull-stream
  endpoint. Button relabelled "Install model" / "Browse & install".

## [1.2.0] — 2026-06-29 — External LLM providers (OpenAI / Claude / Gemini)

### Added
- **Connect commercial LLM APIs as model sources.** A registered "server" now carries a `kind`
  (`ollama` | `openai` | `anthropic` | `gemini`). External providers store their API key in
  `auth_token` and an optional base URL in `api_url`; their models flow into the existing `models`
  table, so they appear in the **Chat playground** picker and the **`/api/v1` gateway** alongside
  self-hosted Ollama models — no separate plumbing.
- **`ProviderService`** — one streaming client across all kinds. Adapters:
  - OpenAI → `POST {base}/chat/completions` (SSE, `stream_options.include_usage`).
  - Anthropic → `POST {base}/messages` (SSE, `x-api-key` + `anthropic-version: 2023-06-01`,
    extracts the system turn, `max_tokens` 4096).
  - Gemini → `POST {base}/models/{m}:streamGenerateContent?alt=sse` (`systemInstruction`, key in query).
  Translates the Ollama message shape (incl. base64 `images[]` for vision) per provider, normalises
  usage to `{prompt_tokens, completion_tokens, total_ms}`, and surfaces provider error bodies.
- **Model discovery** — `ProviderService::listModels()` fetches each provider's catalogue
  (OpenAI `/models` filtered to chat models, Anthropic `/models`, Gemini `:generateContent`-capable),
  with curated fallbacks (e.g. `claude-opus-4-8`, `gpt-4o`, `gemini-2.0-flash`). `ModelService::syncServer()`
  and `ServerService::check()` are provider-aware; adding a provider auto-syncs its models.
- **Servers UI** — the add/edit modal has a **Type** selector (Ollama node / OpenAI / Anthropic /
  Gemini); choosing a provider swaps the node specs for an **API key** + optional **base URL**, and
  the table tags provider rows. API keys are write-only (blank on edit keeps the existing key).

### Migration
- `2026-06-29-000010_AddProviderKind` — adds the `servers.kind` ENUM (default `ollama`; existing
  nodes unaffected).

## [1.1.0] — 2026-06-29 — Vision chat + more models

### Added
- **Vision / image analysis in Chat** — the chat now accepts image attachments so vision models
  can analyze screenshots. `ChatController::chat()` takes `images[]` (data-URI prefix stripped),
  defaults the prompt for image-only messages, and attaches them to the latest user turn in
  Ollama's vision format. React composer gains an attach button (shows an **eye** icon for vision
  models via `isVisionModel()`), multi-image previews with remove, and thumbnails in the chat
  bubble. 8 MB / 4-image client cap.

### Models (on 40.160.57.17)
- Pulled **qwen2.5:3b** (general), **moondream** (fast vision), **llava:7b** (vision) — alongside
  the existing llama3.2:1b.

### Verified
- End-to-end on the live node: attached a generated screenshot and both **moondream** (~28 s) and
  **llava:7b** (~69 s, CPU) returned an analysis through the platform chat stream. Local attach UI
  (preview chip + remove + send) confirmed in the browser.


## [1.0.0] — 2026-06-29 — Phase 10: Reverse-proxy/LB + deploy hardening

### Added
- **ErrorBoundary** wrapping every page so a single component error shows a recoverable
  panel instead of white-screening the SPA.
- **Load-balancing config** — new `gateway` settings group (strategy: round_robin | random |
  least_busy; failover; include-degraded; sticky; max_retries; health interval). `ApiGatewayService`
  now orders candidate nodes by the configured strategy (round-robin rotation, seeded shuffle, or
  least-busy by recent api_logs) and honours the failover toggle. Surfaced as a **Settings →
  Load Balancing** tab.
- **Deployment artifacts** under `deploy/` — `install-server.sh` (Ubuntu 24.04 provisioning:
  nginx, php8.3-fpm, MySQL, Redis, Ollama, Prometheus + node_exporter, migrate/seed, systemd
  queue worker, schedule cron), `nginx-ollamahub.conf` (SPA fallback + long stream timeouts),
  `ollamahub-queue.service`, `prometheus-ollamahub.yml`, `rsync-deploy.sh`, `env.production.example`.

### Fixed
- **Chat session `touch()`** updated only `updated_at` (not in `allowedFields`), so CI4 stripped
  it to empty → "There is no data to update" at the end of a real stream. Now uses the query
  builder to bump the timestamp. (Surfaced only against a live node during deployment.)

### Deployed
- **LIVE on http://40.160.57.17/** — provisioned the full stack (nginx, php8.3-fpm, MySQL 8,
  Redis, **Ollama 0.30.11 CPU-only**, Prometheus + node_exporter), migrated/seeded, started the
  systemd queue worker + schedule cron. Registered the local node (**online, health 100**), pulled
  **llama3.2:1b**, and verified a **real streaming chat** (62 tokens / 4 s) with persisted history,
  plus live Prometheus metrics (cluster + per-node CPU/RAM/disk).

### Status
- **Feature-complete (all 10 phases) and deployed.** TODO: point a domain at the server +
  Let's Encrypt SSL (`certbot --nginx`), then flip `app.baseURL`/`forceGlobalSecureRequests`.

## [0.9.0] — 2026-06-29 — Phase 9: Queue Manager, Scheduler, Analytics

### Added
- **Schema** — `queues` (DB-backed job queue, Redis-ready) and `cron_jobs` (scheduled maintenance).
- **QueueService** — enqueue / atomic claim (concurrency-safe) / process with retry+backoff;
  handlers for `sync_models`, `health_check`, `pull_model`, `generate_report`, `cleanup_logs`, `noop`.
- **SchedulerService** — seeds 6 default jobs (health check, sync models, cleanup logs, clear cache,
  daily report, backup), preset schedules (every_minute…weekly), due detection, run-now.
- **AnalyticsService** — daily requests, token usage, model popularity, response-time, gateway
  outcome breakdown, totals; plus `report()` rollup into `usage_statistics`.
- **Commands** — `spark queue:work` (worker) and `spark schedule:run` (cron entrypoint).
- **APIs** — `/api/queue` (list+stats, enqueue, work, cancel, retry), `/api/cron` (list/toggle/
  set-schedule/run), `/api/analytics`. Gated by `queues.*` / `analytics.view`.
- **React** — Queue page (Jobs tab: stat cards + table + enqueue + run-worker + cancel/retry;
  Scheduler tab: jobs with inline schedule edit, enable toggle, run-now) and Analytics page
  (5 recharts + totals, time-range selector).

### Verified
- Enqueue→work processed 3 jobs (incl. real health-check/sync); `spark queue:work` + cron seed/run/
  toggle; `generate_report` rolled up usage stats; analytics charts render real DB data.

## [0.8.0] — 2026-06-29 — Phase 8: Prompt Library, Knowledge Base, AI Agents

### Added
- **Schema** — `prompt_templates`, `knowledge_bases`, `knowledge_documents`, `knowledge_chunks`
  (with `embedding` LONGTEXT), `agents`.
- **KnowledgeService** — document ingest → paragraph-aware chunking (with overlap) → best-effort
  embedding via a node's Ollama `/api/embed`; retrieval by **cosine similarity** when embeddings
  exist, **tokenised keyword** fallback (ranked by word matches) otherwise. (OllamaService got `embeddings()`.)
- **APIs** — `/api/prompts` CRUD (auto-extracts `{{variables}}`), `/api/knowledge` (bases CRUD,
  add text/file documents, delete, **search**, reindex), `/api/agents` CRUD. File ingest supports
  text types (txt/md/csv/html/json). Gated by `prompts.*` / `knowledge.*` / `agents.*`.
- **React** — Prompt Library (category cards, variable tags, copy), Knowledge Base (collections +
  detail modal: documents, add-text, **live retrieval test**, re-embed), AI Agents (avatar, model,
  temperature, KB, tools, API-access — full builder).

### Fixed
- TINYINT/string gotchas in the new pages: `bytes()` crashed on a string `size_bytes`
  (`.toFixed` on a string) — coerce with `Number()`; `embedded`/`api_access` `"0"`/`"1"` strings
  compared with `Number(x) === 1`.

### Verified
- Prompt variable extraction, KB ingest→chunk→**keyword retrieval in the UI** (score 0.4 on the
  refund-policy doc), agent builder with KB/model/tools. Vector search activates once a node with
  an embedding model is online.

## [0.7.0] — 2026-06-29 — Phase 7: Users, Roles, Audit, Notifications, Settings

### Added
- **Schema** — `audit_logs`, `notifications`, `settings` (grouped key/value).
- **AuditFilter** (after-filter on `api/*`) — transparently records every mutating request
  (POST/PUT/PATCH/DELETE) with acting user (from JWT), action label, method, path, status, IP.
- **SettingsService** — grouped config (general/ai/smtp/storage/notifications) over defaults,
  with secret masking on read and masked-placeholder-preserving save.
- **NotificationService** — in-app notifications + best-effort dispatch to Slack/Discord/
  Telegram/generic webhook (real POSTs) and SMTP email.
- **APIs** — `/api/users` CRUD (guards self-delete + last-super-admin), `/api/roles` (+ permission
  catalog, custom-role create, `syncPermissions`, system-role protection), `/api/audit` feed,
  `/api/notifications` (feed/read/broadcast/test), `/api/settings/{group}` get/save. Gated by the
  matching `*.view`/`*.manage` permissions.
- **React** — Users (CRUD + role/status), **Roles** (cards + grouped permission-matrix modal with
  toggle-all, custom-role builder, super-admin lock), Audit (filterable feed), Notifications
  (in-app feed + broadcast + channel test), Settings (5 tabs, secret masking).

### Verified
- Users/roles CRUD, custom role "Auditor" creation, settings persistence + SMTP secret masking,
  notification broadcast→feed + channel dispatch report, and the **audit log auto-capturing**
  every mutation this session (create users/roles, update settings, broadcast) with attribution.
  Permission matrix correctly reflects each role's real grants in the UI.

## [0.6.0] — 2026-06-29 — Phase 6: API Gateway + API Logs

### Added
- **Schema** — `api_keys` (key/secret-hash, scopes, allowed models/IPs, daily/monthly token
  limits, per-minute rate, status/expiry) and `api_logs` (per-request: endpoint/model/node,
  tokens, latency, status, IP, error).
- **ApiKeyFilter** — authenticates `/api/v1/*` via `X-Api-Key`/`X-Api-Secret` (or
  `Bearer key:secret`), enforcing status/expiry, IP allowlist, and a per-minute rate limit
  (framework throttler). Validated key stashed in `$_SERVER['ohub_api_key']`.
- **ApiGatewayService** — round-robin node selection across online replicas hosting a model
  (cache-rotated) with caller-side **failover**, request logging, daily/monthly **token-quota**
  checks, and available-models listing.
- **Gateway `/api/v1`** — `chat`, `generate`, `embeddings`, `models`. Enforces per-endpoint
  **scopes** + **allowed-models**, routes with failover, and logs every call (success + error)
  with token accounting. (OllamaService gained `embeddings()`.)
- **Dashboard** — `/api/apikeys` CRUD with **secret shown once** + rotate, and `/api/apilogs`
  filtered/paginated feed (by key/model/status/range). Gated by `apikeys.*` / `logs.view`.
- **React** — API Keys page (table, create/edit modal, scopes/limits/allowlists, one-time
  secret reveal with copy, rotate, disable) and API Logs page (filterable, auto-refreshing
  table with status tags).

### Verified
- End-to-end: key create (secret once, no hash leak), gateway 401 (missing/bad creds),
  **scope 403**, **allowed-model 403**, **rate-limit 429** (5/min), 503 + failover when no node
  hosts the model, and every call written to `api_logs` (visible in the UI feed).

## [0.5.0] — 2026-06-29 — Phase 5: Monitoring + Dashboard

### Added
- **Schema** — `system_metrics`, `gpu_metrics`, `usage_statistics` rollup tables (populated by
  the metrics scraper; reserved for Prometheus-fed history + analytics).
- **MonitoringService** — DB-derived dashboard `stats()` (servers/online, models/active,
  requests, tokens, avg latency, users/active, error rate) and `charts()` (requests-per-hour,
  tokens-per-day prompt/completion, model usage, server health). Live per-node `nodeMetrics()`
  (CPU/RAM/disk/GPU) via **Prometheus instant queries** — all null-safe, flagged
  `available:false` and `prometheus:false` when unreachable.
- **API** — `/api/dashboard[/stats|/charts]`, `/api/metrics`, `/api/health`,
  `/api/metrics/refresh` (re-probe all nodes). Gated by `dashboard.view` / `monitoring.view`.
- **React** — live **Dashboard** (8 real stat cards + 4 recharts: requests/hour line,
  tokens/day stacked bar, model-usage bar, server-health bars; 30s auto-refresh) and a
  **Monitoring** page (per-node CPU/RAM/disk/GPU dashboard gauges, Prometheus-status banner,
  graceful "n/a" + exporter notice, re-check button, 15s refresh).

### Verified
- Dashboard shows **real DB data** end-to-end (seeded chat turns → 2 requests, 232 tokens,
  1.48s avg; charts populated). Monitoring degrades gracefully with Prometheus offline. Metrics
  fill in once Prometheus + node_exporter are provisioned on the server.

## [0.4.0] — 2026-06-28 — Phase 4: Chat Playground (streaming)

### Added
- **Schema** — `chat_sessions` (user/title/model/server/system_prompt) and `chat_messages`
  (role/content/model/token stats), cascade-deleted with their session.
- **OllamaService** — streaming `chat()` and `generate()` convenience wrappers over `stream()`.
- **Chat API** — `/api/chat/sessions` CRUD (rename/delete), and a streaming `POST /api/chat`
  that persists the user turn, folds in the model's saved system prompt + options
  (`ModelConfigModel::ollamaOptions()`), proxies Ollama `/api/chat` as NDJSON (meta → delta →
  done lines), and persists the assistant turn with token/duration stats. Plus one-shot
  `POST /api/generate`. All gated by `chat.use`; sessions are per-user (ownership-checked).
- **React Chat Playground** — conversation sidebar (new/load/rename/delete), streaming thread
  with **markdown + syntax-highlighted code** (`Markdown` component via react-markdown +
  react-syntax-highlighter), model picker (model · node · status), token/latency footer,
  Stop, Regenerate, and Export-to-JSON.

### Verified
- Full UI round-trip: send → fetch/NDJSON stream → meta (auto session create) → assistant
  bubble updates → session persists in sidebar; graceful ⚠️ rendering of the offline-node
  error (token streaming uses the identical delta path). Session ownership + `chat.use` RBAC
  enforced (viewer → 403).

## [0.3.0] — 2026-06-28 — Phase 3: Model Manager + Model Config

### Added
- **OllamaService streaming** — `pull()` (NDJSON progress via curl `WRITEFUNCTION`, or
  non-streaming), `load()`/`unload()` (keep_alive -1/0), and a reusable `stream()` helper.
- **ModelService** — reconciles the `models` table with each node's `/api/tags`
  (upsert + prune vanished models); `syncServer`/`syncAll`.
- **Schema** — `models` (per-node installed model: family/params/quant/size/digest/status/default)
  and `model_configs` (per-model generation params, keyed by model name).
- **Model Manager API** under `/api/models` — list (node-joined), sync, **streaming pull**,
  delete, load/unload, set-default, and get/save per-model config. `ModelConfigModel::ollamaOptions()`
  builds the Ollama `options`/`system`/`keep_alive` payload for the Phase 4 chat proxy.
- **React** — Models page (table: params, size, node status, default tag, per-row config/load/
  unload/default/delete), **Pull Model dialog** with live NDJSON progress bar + popular-model
  quick-picks (reads the stream via fetch + ReadableStream since EventSource can't send the
  Bearer token), and a full **Model Config modal** (temperature slider, top_p/k, penalties,
  context, max tokens, keep-alive, seed/threads/GPU layers, stop sequences, system prompt).

### Verified
- Config round-trip (save via API → DB → reload in modal), set-default, node-joined list,
  graceful 502/NDJSON-error on the offline node, and **RBAC with a real `viewer` account**
  (GET config 200, save config 403).

### Notes
- Used `ModelModel::builder()->update()` for the bulk default-reset to avoid the
  `Model::set()/update()` collision.

## [0.2.0] — 2026-06-28 — Phase 2: Server Manager + Ollama core

### Added
- **OllamaService** (`app/Services/OllamaService.php`) — HTTP client for a node's Ollama API
  (`version`, `tags`, `ps`, `show`, `delete`) plus a `probe()` that returns reachability,
  latency, and model counts in one call.
- **ServerService** — registers nodes, runs health probes, derives a 0–100 health score
  (latency-penalised), persists status onto the server row, and appends a `server_health`
  time-series record per check.
- **Schema** — `servers` (specs, api_url, token, status, health_score, heartbeat) and
  `server_health` (time-series probes).
- **Server Manager API** under `/api/servers` — CRUD, `/{id}/health`, `/{id}/models`, and
  `/{id}/(restart|shutdown|update)` (501 until the node control agent in Phase 10).
  Permission-gated via new `BaseApiController::can()/requirePermission()` (queries the role's
  permission set; admins bypass). Node auth tokens never leak to the API surface.
- **React** — Servers list (antd Table, add/edit modal, health-check + delete actions,
  status badges, relative "last seen") and Server Details (overview, hardware specs, health-history
  line chart via recharts, installed-models list with graceful offline state).
- `lib/datetime.ts` (naive-UTC-safe formatting) and `StatusBadge`/`HealthScore` components.

### Fixed
- TINYINT-as-string gotcha: MySQL returns `is_local` as `"0"` (truthy in JS) — coerce with
  `Number(x) === 1` before boolean display.

## [0.1.0] — 2026-06-28 — Phase 1: Scaffold + Auth

### Added
- **CI4 4.7 backend** (`/Applications/MAMP/htdocs/ollamahub`) with a clean standard
  layout (`Controllers/Api`, `Services`, `Models`, `Database/Migrations|Seeds`, `Filters`).
- **JWT auth** — `JwtService` (HMAC-SHA256, access + refresh), `JwtAuthFilter` (Bearer +
  coarse role gating), `BaseApiController` with the `{status,message,data}` envelope.
- **Auth API** — `POST /api/auth/login`, `POST /api/auth/refresh`, `GET /api/auth/profile`,
  `POST /api/auth/logout`, plus `GET /api/ping` liveness probe.
- **RBAC schema + seed** — `users`, `roles`, `permissions`, `role_permissions`; six default
  roles (super_admin, admin, developer, support, customer, viewer) with a full permission
  matrix; Super Admin user `admin@ollamahub.local`.
- **React 18 + Vite + TS SPA** (`react-app/`, served at `/app/`) — JWT axios client with
  silent refresh-on-401, Zustand auth store, antd + Tailwind UI kit, dark sidebar admin
  layout with permission-gated nav, Login page, Dashboard shell, route placeholders for all
  modules.

### Notes
- Target server `40.160.57.17` assessed: bare Ubuntu 24.04, 8-core Xeon, 31 GiB RAM,
  **no compute GPU** — will run Ollama CPU-only as node 1 (confirmed with user).
