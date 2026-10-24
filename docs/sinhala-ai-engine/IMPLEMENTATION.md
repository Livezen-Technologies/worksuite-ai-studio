# WorkSuite Sinhala AI Engine — AI Studio implementation map

Source of truth: [`SRS.md`](SRS.md) (converted from `WorkSuite_Sinhala_AI_Engine_SRS.docx`, v1.0, 24 Oct 2026).

The SRS describes two zones:

| Zone | Where it lives | What it does |
| --- | --- | --- |
| **On-device engine** (gateway, ASR/TTS/text workers, SDK, adapters) | `worksuite-desktop`, `worksuite-office`, WorkSuite Meet / FrameFlow / OS | Offline inference per OS user. **Not** in this repo. |
| **Build & training zone** (SRS §3.1 “Separate build and training zone”, §10.1, §16, §17) | **This repo — WorkSuite AI Studio** | Approved data → training → evaluation → signed packages. |

AI Studio never serves inference to end-user devices for this engine (FR CORE 001, SEC 001). Its output is a
signed offline package that the desktop engine imports.

## Delivered in v1.4.0

| SRS requirement | Implementation |
| --- | --- |
| FR TRNPIPE 001 — provenance & rights; unapproved items quarantined | `datasets.rights_status` (quarantined → approved → withdrawn), `source_ref`, `permitted_use`, `rights_ref`, `consent_required/consent_ref`. `PUT /api/engine/datasets/{id}/rights` with `approve: true`. |
| FR TRNPIPE 002 — reproducible runs | Kit writes `lineage.json`; `training_jobs` stores dataset manifest/split hashes, config hash, seed. Post-run `base_digest`, `code_commit`, `container_digest`, `hardware`, `output_digest` via `PUT /api/engine/training/{id}/lineage`. |
| FR TRNPIPE 003 — leakage control | `POST /api/engine/datasets/{id}/freeze` assigns train/dev/test **by group** (`dataset_items.group_key` = speaker / document / session), deterministic per dataset. The **test split never leaves** the server: kits export `dataset.jsonl` (train) + `dev.jsonl` only. |
| FR TRNPIPE 005 — promotion gates | `EngineGateService` encodes §17.2 thresholds per task plus §17.3 regression (≤2 points) and stratum (≥100 utterances, ≤5 points WER) rules. Missing metrics = *insufficient evidence*, never pass. |
| FR TRNPIPE 006 — withdrawal | `POST /api/engine/datasets/{id}/withdraw` blocks new kits and lists affected runs; no automatic-unlearning claim. |
| §17.4 — evaluation report & approvals | `engine_evaluations`; release owner **and** language lead approvals from different people (`POST /api/engine/evaluations/{id}/approve`, `role: release|language`). |
| §10.1 — package lifecycle | `engine_packages.state`: candidate → quarantined → verified → evaluated → approved → staged → active → retired; revoked from any state (reason required). History kept content-free in `state_history`. |
| FR ADM 002 / SEC 007 — safe import checks | Artifact validation: relative paths only, no traversal, no duplicates, no executables, sha256 + measured size required; CC-BY-NC / unknown licences blocked; rights approval required before `verified`. |
| §14.5 — signed manifest | On `→ staged` the canonical manifest is serialised once and signed with **Ed25519** (detached, over exact UTF-8 bytes). `GET /api/engine/packages/{id}/manifest` returns manifest, exact bytes (base64), sha256, signature and verification result. |
| FR ADM 003 / 004 — activation & rollback | Activation atomically retires the previously active package for the task; `POST /api/engine/packages/rollback` re-activates the latest retained signed version. |
| SEC 002 — scoped permissions | New permissions `engine.view`, `engine.manage`, `engine.sign` (signing, activation, revocation). Falls back to `models.view` / `models.manage` for existing roles. |

### Signing key

Set `ENGINE_SIGNING_SECRET` (base64 of a 64-byte libsodium Ed25519 secret key) in `.env` for production. If unset, a
key is generated once at `writable/engine-keys/publisher.sk` (mode 600, git-ignored). The public key and key id are
returned by `GET /api/engine/overview`; ship that public key with the signed desktop installer (SRS §14.5).

Generate one: `php -r 'echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;'`

### Typical flow (WF05)

1. Upload a dataset (`/datasets`), set `group_key` per row where speakers/documents repeat.
2. `PUT /api/engine/datasets/{id}/rights` → `approve: true`.
3. `POST /api/engine/datasets/{id}/freeze`.
4. Create a training job, generate the kit, run it on the GPU worker, then `PUT /api/engine/training/{id}/lineage`.
5. Score on the locked suite, `POST /api/engine/evaluations` (gate computed), two approvals.
6. `POST /api/engine/packages` → transition `quarantined` → `verified` → `evaluated` → `approved` → `staged` (signed) → `active`.

## Not in this repo / still open

- On-device engine, `/v1` gateway, SDKs, adapters (FR CORE, ASR, TTS, CAP, TRN, FNT, WR, MT, INT) — desktop/office repos.
- Artifact upload & hash re-verification on the server (currently digests are declared by the GPU worker).
- Signed revocation list export for offline devices (§10.1) and fleet manifest export (FR ADM 007).
- Frontend screens: the React source is not in this repo (`public/app` is a build). The API above is ready for an
  **Engine Releases** page (overview, evaluations, packages) when the source is restored.
- ASR/TTS training recipes (§16.4, §16.5) — current kit covers text LoRA/QLoRA only.
