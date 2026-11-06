# WorkSuite AI Studio API reference

Generated from `app/Config/Routes.php` (regenerate with `python3 docs/gen-api-reference.py`). Every JSON
endpoint answers `{ status, message, data }`; errors carry `status: "error"` and a readable `message`
(422 adds field errors in `data`).

| Group | Auth |
|---|---|
| `/api/auth/*` | public sign-in steps; `profile` and `logout` need a token |
| `/api/*` (admin) | `Authorization: Bearer <access token>` from `/api/auth/login`; per-route permissions are checked in the controller |
| `/api/v1/*` (gateway) | API key and secret (API Keys page) |
| `/api/agent/*` | node agent token |
| `/api/ping` | public liveness |

| Method | Path | Handler |
|---|---|---|
| GET | `/` | Home::index |
| GET | `/api/ping` | HealthController::ping |
| POST | `/api/nodes/enrol` | NodesController::enrol |
| POST | `/api/training/{id}/progress` | TrainingProgressController::progress/$1 |
| GET | `/api/training/{id}/files/{name}` | TrainingProgressController::file/$1/$2 |
| PUT | `/api/training/{id}/output/{name}` | TrainingProgressController::output/$1/$2 |
| POST | `/api/agent/heartbeat` | AgentController::heartbeat |
| GET | `/api/agent/asr/manifest` | AgentController::asrManifest |
| GET | `/api/agent/asr/file` | AgentController::asrFile |

## `/api/auth`

| Method | Path | Handler |
|---|---|---|
| POST | `/api/auth/login` | AuthController::login |
| POST | `/api/auth/refresh` | AuthController::refresh |
| GET | `/api/auth/profile` | AuthController::profile |
| POST | `/api/auth/logout` | AuthController::logout |
| POST | `/api/auth/mfa` | AuthController::mfa |
| POST | `/api/auth/mfa/setup` | AuthController::mfaSetup |
| POST | `/api/auth/mfa/setup/confirm` | AuthController::mfaSetupConfirm |
| POST | `/api/auth/passkey/options` | AuthController::passkeyOptions |
| POST | `/api/auth/passkey` | AuthController::passkey |

## `/api/account/security`

| Method | Path | Handler |
|---|---|---|
| GET | `/api/account/security` | SecurityController::index |
| POST | `/api/account/security/totp/setup` | SecurityController::totpSetup |
| POST | `/api/account/security/totp/enable` | SecurityController::totpEnable |
| POST | `/api/account/security/totp/disable` | SecurityController::totpDisable |
| POST | `/api/account/security/recovery-codes` | SecurityController::recoveryCodes |
| POST | `/api/account/security/passkeys/options` | SecurityController::passkeyOptions |
| POST | `/api/account/security/passkeys` | SecurityController::passkeyCreate |
| PUT | `/api/account/security/passkeys/{id}` | SecurityController::passkeyRename/$1 |
| DELETE | `/api/account/security/passkeys/{id}` | SecurityController::passkeyDelete/$1 |

## `/api`

### Server Manager (Phase 2)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/servers` | ServersController::index |
| POST | `/api/servers` | ServersController::create |
| POST | `/api/servers/enrolments` | NodesController::issue |
| GET | `/api/servers/summary` | ServersController::summary |
| GET | `/api/servers/{id}` | ServersController::show/$1 |
| GET | `/api/servers/{id}/usage` | ServersController::usage/$1 |
| PUT | `/api/servers/{id}` | ServersController::update/$1 |
| DELETE | `/api/servers/{id}` | ServersController::delete/$1 |
| POST | `/api/servers/{id}/health` | ServersController::health/$1 |
| POST | `/api/servers/{id}/disable` | ServersController::disable/$1 |
| POST | `/api/servers/{id}/enable` | ServersController::enable/$1 |
| GET | `/api/servers/{id}/models` | ServersController::models/$1 |
| POST | `/api/servers/{id}/{restart|shutdown|update}` | ServersController::control/$1/$2 |

### Model Manager (Phase 3)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/models` | ModelsController::index |
| POST | `/api/models/sync` | ModelsController::sync |
| POST | `/api/models/pull` | ModelsController::pull |
| DELETE | `/api/models/{id}` | ModelsController::delete/$1 |
| POST | `/api/models/{id}/load` | ModelsController::load/$1 |
| POST | `/api/models/{id}/unload` | ModelsController::unload/$1 |
| POST | `/api/models/{id}/default` | ModelsController::setDefault/$1 |
| GET | `/api/models/{id}/config` | ModelsController::getConfig/$1 |
| PUT | `/api/models/{id}/config` | ModelsController::saveConfig/$1 |

### Chat Playground (Phase 4)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/chat/sessions` | ChatController::sessions_list |
| POST | `/api/chat/sessions` | ChatController::create |
| GET | `/api/chat/sessions/{id}` | ChatController::show/$1 |
| PUT | `/api/chat/sessions/{id}` | ChatController::rename/$1 |
| DELETE | `/api/chat/sessions/{id}` | ChatController::destroy/$1 |
| POST | `/api/chat` | ChatController::chat |
| POST | `/api/generate` | ChatController::generate |

### Dashboard + Monitoring (Phase 5)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/dashboard` | DashboardController::index |
| GET | `/api/dashboard/stats` | DashboardController::stats |
| GET | `/api/dashboard/charts` | DashboardController::charts |
| GET | `/api/metrics` | MetricsController::index |
| GET | `/api/health` | MetricsController::health |
| POST | `/api/metrics/refresh` | MetricsController::refresh |

### API Gateway management + logs (Phase 6)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/apikeys` | ApiKeysController::index |
| POST | `/api/apikeys` | ApiKeysController::create |
| PUT | `/api/apikeys/{id}` | ApiKeysController::update/$1 |
| POST | `/api/apikeys/{id}/rotate` | ApiKeysController::rotate/$1 |
| DELETE | `/api/apikeys/{id}` | ApiKeysController::delete/$1 |
| GET | `/api/apilogs` | ApiLogsController::index |

### Users / Roles / Audit / Notifications / Settings (Phase 7)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/users` | UsersController::index |
| POST | `/api/users` | UsersController::create |
| PUT | `/api/users/{id}` | UsersController::update/$1 |
| DELETE | `/api/users/{id}` | UsersController::delete/$1 |
| GET | `/api/roles` | RolesController::index |
| GET | `/api/roles/permissions` | RolesController::permissions |
| POST | `/api/roles` | RolesController::create |
| PUT | `/api/roles/{id}` | RolesController::update/$1 |
| DELETE | `/api/roles/{id}` | RolesController::delete/$1 |
| GET | `/api/audit` | AuditController::index |
| GET | `/api/notifications` | NotificationsController::index |
| POST | `/api/notifications/read` | NotificationsController::read |
| POST | `/api/notifications/broadcast` | NotificationsController::broadcast |
| POST | `/api/notifications/test` | NotificationsController::test |
| GET | `/api/settings/{group}` | SettingsController::show/$1 |
| PUT | `/api/settings/{group}` | SettingsController::save/$1 |

### Prompt Library / Knowledge Base / Agents (Phase 8)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/prompts` | PromptsController::index |
| POST | `/api/prompts` | PromptsController::create |
| PUT | `/api/prompts/{id}` | PromptsController::update/$1 |
| DELETE | `/api/prompts/{id}` | PromptsController::delete/$1 |
| GET | `/api/knowledge` | KnowledgeController::index |
| POST | `/api/knowledge` | KnowledgeController::create |
| GET | `/api/knowledge/{id}` | KnowledgeController::show/$1 |
| DELETE | `/api/knowledge/{id}` | KnowledgeController::delete/$1 |
| POST | `/api/knowledge/{id}/documents` | KnowledgeController::addDocument/$1 |
| DELETE | `/api/knowledge/documents/{id}` | KnowledgeController::deleteDocument/$1 |
| POST | `/api/knowledge/{id}/search` | KnowledgeController::search/$1 |
| POST | `/api/knowledge/{id}/reindex` | KnowledgeController::reindex/$1 |
| GET | `/api/agents` | AgentsController::index |
| POST | `/api/agents` | AgentsController::create |
| PUT | `/api/agents/{id}` | AgentsController::update/$1 |
| DELETE | `/api/agents/{id}` | AgentsController::delete/$1 |

### Queue / Scheduler / Analytics (Phase 9)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/queue` | QueueController::index |
| POST | `/api/queue` | QueueController::create |
| POST | `/api/queue/work` | QueueController::work |
| POST | `/api/queue/{id}/cancel` | QueueController::cancel/$1 |
| POST | `/api/queue/{id}/retry` | QueueController::retry/$1 |
| GET | `/api/cron` | CronController::index |
| PUT | `/api/cron/{id}` | CronController::update/$1 |
| POST | `/api/cron/{id}/run` | CronController::run/$1 |
| GET | `/api/analytics` | AnalyticsController::index |

### SinLama: Model Studio / Datasets / Training (v1.3)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/studio/presets` | ModelStudioController::presets |
| POST | `/api/studio/upload` | ModelStudioController::upload |
| POST | `/api/studio/create` | ModelStudioController::create |
| POST | `/api/studio/copy` | ModelStudioController::copyModel |
| GET | `/api/datasets` | DatasetsController::index |
| POST | `/api/datasets` | DatasetsController::create |
| GET | `/api/datasets/{id}` | DatasetsController::show/$1 |
| POST | `/api/datasets/{id}/upload` | DatasetsController::upload/$1 |
| POST | `/api/datasets/{id}/speech` | DatasetsController::speechUpload/$1 |
| POST | `/api/datasets/{id}/dedupe` | DatasetsController::dedupe/$1 |
| POST | `/api/datasets/{id}/clean` | DatasetsController::clean/$1 |
| GET | `/api/datasets/{id}/export` | DatasetsController::export/$1 |
| DELETE | `/api/datasets/{id}` | DatasetsController::delete/$1 |
| DELETE | `/api/datasets/items/{id}` | DatasetsController::deleteItem/$1 |
| GET | `/api/training` | TrainingController::index |
| POST | `/api/training` | TrainingController::create |
| PUT | `/api/training/{id}` | TrainingController::update/$1 |
| DELETE | `/api/training/{id}` | TrainingController::delete/$1 |
| POST | `/api/training/{id}/kit` | TrainingController::generateKit/$1 |
| GET | `/api/training/{id}/kit` | TrainingController::downloadKit/$1 |
| POST | `/api/training/{id}/report-token` | TrainingProgressController::reportToken/$1 |
| GET | `/api/training/{id}/live` | TrainingProgressController::live/$1 |
| POST | `/api/training/{id}/cancel` | TrainingPipelinesController::cancel/$1 |
| POST | `/api/training/{id}/retry` | TrainingPipelinesController::retry/$1 |
| GET | `/api/training/pipelines` | TrainingPipelinesController::index |
| GET | `/api/storage` | StorageController::index |
| GET | `/api/storage/nodes/{id}` | StorageController::show/$1 |
| POST | `/api/storage/nodes` | StorageController::create |
| PUT | `/api/storage/nodes/{id}` | StorageController::update/$1 |
| DELETE | `/api/storage/nodes/{id}` | StorageController::delete/$1 |
| POST | `/api/storage/nodes/{id}/test` | StorageController::test/$1 |
| POST | `/api/storage/nodes/{id}/status` | StorageController::status/$1 |
| POST | `/api/storage/nodes/{id}/allocations` | StorageController::allocate/$1 |
| DELETE | `/api/storage/allocations/{id}` | StorageController::unallocate/$1 |
| POST | `/api/storage/rules` | StorageController::ruleCreate |
| PUT | `/api/storage/rules/{id}` | StorageController::ruleUpdate/$1 |
| DELETE | `/api/storage/rules/{id}` | StorageController::ruleDelete/$1 |
| POST | `/api/storage/alerts/{id}/ack` | StorageController::ack/$1 |
| GET | `/api/storage/files` | StorageFilesController::index |
| POST | `/api/storage/files` | StorageFilesController::upload |
| GET | `/api/storage/files/{id}` | StorageFilesController::show/$1 |
| GET | `/api/storage/files/{id}/download` | StorageFilesController::download/$1 |
| PUT | `/api/storage/files/{id}` | StorageFilesController::rename/$1 |
| DELETE | `/api/storage/files/{id}` | StorageFilesController::delete/$1 |
| POST | `/api/storage/files/{id}/transfer` | StorageFilesController::transfer/$1 |
| GET | `/api/storage/policies` | StorageFilesController::policies |
| POST | `/api/storage/policies` | StorageFilesController::policySave |
| PUT | `/api/storage/policies/{id}` | StorageFilesController::policySave/$1 |
| DELETE | `/api/storage/policies/{id}` | StorageFilesController::policyDelete/$1 |
| GET | `/api/storage/tasks` | StorageFilesController::tasks |
| POST | `/api/storage/tasks/{id}/retry` | StorageFilesController::retry/$1 |
| GET | `/api/storage/backups` | StorageFilesController::backups |
| PUT | `/api/storage/backups/{id}` | StorageFilesController::backupSave/$1 |
| POST | `/api/storage/backups/{id}/run` | StorageFilesController::backupRun/$1 |
| GET | `/api/storage/restore` | StorageFilesController::restoreStatus |
| POST | `/api/storage/backups/runs/{id}/restore` | StorageFilesController::restore/$1 |
| GET | `/api/ml-models` | MlModelsController::index |
| POST | `/api/ml-models/table` | MlModelsController::createTable |
| POST | `/api/ml-models/{id}/predict` | MlModelsController::predict/$1 |
| DELETE | `/api/ml-models/{id}` | MlModelsController::delete/$1 |
| POST | `/api/training/pipelines` | TrainingPipelinesController::create |
| PUT | `/api/training/pipelines/{id}` | TrainingPipelinesController::update/$1 |
| DELETE | `/api/training/pipelines/{id}` | TrainingPipelinesController::delete/$1 |
| POST | `/api/training/pipelines/{id}/run` | TrainingPipelinesController::run/$1 |
| POST | `/api/servers/{id}/agent-token` | TrainingPipelinesController::agentToken/$1 |

### User portal + compute allocations

| Method | Path | Handler |
|---|---|---|
| GET | `/api/portal/overview` | PortalController::overview |
| GET | `/api/portal/nodes` | PortalController::nodes |
| GET | `/api/portal/datasets` | PortalController::datasetsIndex |
| POST | `/api/portal/datasets` | PortalController::datasetCreate |
| GET | `/api/portal/datasets/{id}` | PortalController::datasetShow/$1 |
| POST | `/api/portal/datasets/{id}/upload` | PortalController::datasetUpload/$1 |
| DELETE | `/api/portal/datasets/{id}` | PortalController::datasetDelete/$1 |
| GET | `/api/portal/jobs` | PortalController::jobsIndex |
| POST | `/api/portal/jobs` | PortalController::jobCreate |
| GET | `/api/portal/jobs/{id}` | PortalController::jobShow/$1 |
| PUT | `/api/portal/jobs/{id}/nodes` | PortalController::jobNodes/$1 |
| POST | `/api/portal/jobs/{id}/cancel` | PortalController::jobCancel/$1 |
| POST | `/api/portal/jobs/{id}/kit` | PortalController::jobKit/$1 |
| GET | `/api/portal/jobs/{id}/kit` | PortalController::jobKitDownload/$1 |
| GET | `/api/compute/overview` | ComputeController::overview |
| POST | `/api/compute/allocations` | ComputeController::allocationSave |
| PUT | `/api/compute/allocations/{id}` | ComputeController::allocationUpdate/$1 |
| DELETE | `/api/compute/allocations/{id}` | ComputeController::allocationDelete/$1 |
| PUT | `/api/compute/nodes/{id}` | ComputeController::nodeUpdate/$1 |
| GET | `/api/compute/usage` | ComputeController::usage |
| GET | `/api/compute/jobs` | ComputeController::jobs |
| PUT | `/api/compute/jobs/{id}/placement` | ComputeController::placement/$1 |
| PUT | `/api/compute/jobs/{id}/nodes/{id}` | ComputeController::nodeStatus/$1/$2 |

### Speech to text (Whisper Sinhala via the local aistudio-asr service)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/speech/status` | SpeechController::status |
| POST | `/api/speech/transcribe` | SpeechController::transcribe |

### WorkSuite Sinhala AI Engine: build & release zone (v1.5, docs/sinhala-ai-engine)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/engine/overview` | EngineController::overview |
| PUT | `/api/engine/datasets/{id}/rights` | EngineController::datasetRights/$1 |
| POST | `/api/engine/datasets/{id}/freeze` | EngineController::datasetFreeze/$1 |
| POST | `/api/engine/datasets/{id}/withdraw` | EngineController::datasetWithdraw/$1 |
| PUT | `/api/engine/training/{id}/lineage` | EngineController::trainingLineage/$1 |
| GET | `/api/engine/evaluations` | EngineController::evaluations |
| POST | `/api/engine/evaluations` | EngineController::createEvaluation |
| POST | `/api/engine/evaluations/{id}/approve` | EngineController::approveEvaluation/$1 |
| GET | `/api/engine/packages` | EngineController::packages |
| POST | `/api/engine/packages` | EngineController::createPackage |
| POST | `/api/engine/packages/rollback` | EngineController::rollback |
| POST | `/api/engine/packages/{id}/transition` | EngineController::transition/$1 |
| GET | `/api/engine/packages/{id}/manifest` | EngineController::manifest/$1 |
| GET | `/api/engine/evaluations/{id}` | EngineController::evaluation/$1 |
| GET | `/api/engine/packages/{id}` | EngineController::package/$1 |
| POST | `/api/engine/packages/{id}/artifacts` | EngineController::uploadArtifact/$1 |
| DELETE | `/api/engine/packages/{id}/artifacts` | EngineController::deleteArtifact/$1 |
| GET | `/api/engine/approvals` | EngineController::approvals |
| GET | `/api/engine/bindings` | EngineController::bindings |
| PUT | `/api/engine/bindings` | EngineController::bind |
| POST | `/api/engine/bindings/rollback` | EngineController::bindingRollback |

## `/api/v1`

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/models` | GatewayController::models |
| POST | `/api/v1/chat` | GatewayController::chat |
| POST | `/api/v1/generate` | GatewayController::generate |
| POST | `/api/v1/embeddings` | GatewayController::embeddings |
| POST | `/api/v1/translate` | GatewayController::translate |
| POST | `/api/v1/summarize` | GatewayController::summarize |
| GET | `/api/v1/catalog` | DownloadsController::catalog |
| GET | `/api/v1/classifiers` | ClassifyController::index |
| POST | `/api/v1/classify` | ClassifyController::classify |
| POST | `/api/v1/predict` | ClassifyController::predict |
| GET | `/api/v1/packages/{name}/files` | DownloadsController::file/$1 |
| GET | `/api/v1/datasets/{id}/download` | DownloadsController::dataset/$1 |

