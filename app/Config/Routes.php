<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

// Lightweight unauthenticated health probe for the control plane itself.
$routes->get('api/ping', 'Api\HealthController::ping');

/*
 * Auth (guest entry points + token-protected profile/logout).
 */
$routes->group('api/auth', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    $routes->post('login', 'AuthController::login');
    $routes->post('refresh', 'AuthController::refresh');
    $routes->get('profile', 'AuthController::profile', ['filter' => 'jwt']);
    $routes->post('logout', 'AuthController::logout', ['filter' => 'jwt']);
});

/*
 * Admin / dashboard API — all routes require a valid Bearer access token.
 * Finer-grained permission checks happen inside controllers; the filter does
 * coarse role gating where a route group is admin-only (jwt:admin).
 * Module route groups are appended here as each phase lands.
 */
$routes->group('api', ['namespace' => 'App\Controllers\Api', 'filter' => 'jwt'], static function ($routes) {
    // --- Server Manager (Phase 2) ---
    $routes->get('servers', 'ServersController::index');
    $routes->post('servers', 'ServersController::create');
    $routes->get('servers/(:num)', 'ServersController::show/$1');
    $routes->put('servers/(:num)', 'ServersController::update/$1');
    $routes->delete('servers/(:num)', 'ServersController::delete/$1');
    $routes->post('servers/(:num)/health', 'ServersController::health/$1');
    $routes->get('servers/(:num)/models', 'ServersController::models/$1');
    $routes->post('servers/(:num)/(restart|shutdown|update)', 'ServersController::control/$1/$2');

    // --- Model Manager (Phase 3) ---
    $routes->get('models', 'ModelsController::index');
    $routes->post('models/sync', 'ModelsController::sync');
    $routes->post('models/pull', 'ModelsController::pull');
    $routes->delete('models/(:num)', 'ModelsController::delete/$1');
    $routes->post('models/(:num)/load', 'ModelsController::load/$1');
    $routes->post('models/(:num)/unload', 'ModelsController::unload/$1');
    $routes->post('models/(:num)/default', 'ModelsController::setDefault/$1');
    $routes->get('models/(:num)/config', 'ModelsController::getConfig/$1');
    $routes->put('models/(:num)/config', 'ModelsController::saveConfig/$1');

    // --- Chat Playground (Phase 4) ---
    $routes->get('chat/sessions', 'ChatController::sessions_list');
    $routes->post('chat/sessions', 'ChatController::create');
    $routes->get('chat/sessions/(:num)', 'ChatController::show/$1');
    $routes->put('chat/sessions/(:num)', 'ChatController::rename/$1');
    $routes->delete('chat/sessions/(:num)', 'ChatController::destroy/$1');
    $routes->post('chat', 'ChatController::chat');
    $routes->post('generate', 'ChatController::generate');

    // --- Dashboard + Monitoring (Phase 5) ---
    $routes->get('dashboard', 'DashboardController::index');
    $routes->get('dashboard/stats', 'DashboardController::stats');
    $routes->get('dashboard/charts', 'DashboardController::charts');
    $routes->get('metrics', 'MetricsController::index');
    $routes->get('health', 'MetricsController::health');
    $routes->post('metrics/refresh', 'MetricsController::refresh');

    // --- API Gateway management + logs (Phase 6) ---
    $routes->get('apikeys', 'ApiKeysController::index');
    $routes->post('apikeys', 'ApiKeysController::create');
    $routes->put('apikeys/(:num)', 'ApiKeysController::update/$1');
    $routes->post('apikeys/(:num)/rotate', 'ApiKeysController::rotate/$1');
    $routes->delete('apikeys/(:num)', 'ApiKeysController::delete/$1');
    $routes->get('apilogs', 'ApiLogsController::index');

    // --- Users / Roles / Audit / Notifications / Settings (Phase 7) ---
    $routes->get('users', 'UsersController::index');
    $routes->post('users', 'UsersController::create');
    $routes->put('users/(:num)', 'UsersController::update/$1');
    $routes->delete('users/(:num)', 'UsersController::delete/$1');

    $routes->get('roles', 'RolesController::index');
    $routes->get('roles/permissions', 'RolesController::permissions');
    $routes->post('roles', 'RolesController::create');
    $routes->put('roles/(:num)', 'RolesController::update/$1');
    $routes->delete('roles/(:num)', 'RolesController::delete/$1');

    $routes->get('audit', 'AuditController::index');

    $routes->get('notifications', 'NotificationsController::index');
    $routes->post('notifications/read', 'NotificationsController::read');
    $routes->post('notifications/broadcast', 'NotificationsController::broadcast');
    $routes->post('notifications/test', 'NotificationsController::test');

    $routes->get('settings/([a-z]+)', 'SettingsController::show/$1');
    $routes->put('settings/([a-z]+)', 'SettingsController::save/$1');

    // --- Prompt Library / Knowledge Base / Agents (Phase 8) ---
    $routes->get('prompts', 'PromptsController::index');
    $routes->post('prompts', 'PromptsController::create');
    $routes->put('prompts/(:num)', 'PromptsController::update/$1');
    $routes->delete('prompts/(:num)', 'PromptsController::delete/$1');

    $routes->get('knowledge', 'KnowledgeController::index');
    $routes->post('knowledge', 'KnowledgeController::create');
    $routes->get('knowledge/(:num)', 'KnowledgeController::show/$1');
    $routes->delete('knowledge/(:num)', 'KnowledgeController::delete/$1');
    $routes->post('knowledge/(:num)/documents', 'KnowledgeController::addDocument/$1');
    $routes->delete('knowledge/documents/(:num)', 'KnowledgeController::deleteDocument/$1');
    $routes->post('knowledge/(:num)/search', 'KnowledgeController::search/$1');
    $routes->post('knowledge/(:num)/reindex', 'KnowledgeController::reindex/$1');

    $routes->get('agents', 'AgentsController::index');
    $routes->post('agents', 'AgentsController::create');
    $routes->put('agents/(:num)', 'AgentsController::update/$1');
    $routes->delete('agents/(:num)', 'AgentsController::delete/$1');

    // --- Queue / Scheduler / Analytics (Phase 9) ---
    $routes->get('queue', 'QueueController::index');
    $routes->post('queue', 'QueueController::create');
    $routes->post('queue/work', 'QueueController::work');
    $routes->post('queue/(:num)/cancel', 'QueueController::cancel/$1');
    $routes->post('queue/(:num)/retry', 'QueueController::retry/$1');

    $routes->get('cron', 'CronController::index');
    $routes->put('cron/(:num)', 'CronController::update/$1');
    $routes->post('cron/(:num)/run', 'CronController::run/$1');

    $routes->get('analytics', 'AnalyticsController::index');

    // --- SinLama: Model Studio / Datasets / Training (v1.3) ---
    $routes->get('studio/presets', 'ModelStudioController::presets');
    $routes->post('studio/upload', 'ModelStudioController::upload');
    $routes->post('studio/create', 'ModelStudioController::create');
    $routes->post('studio/copy', 'ModelStudioController::copyModel');

    $routes->get('datasets', 'DatasetsController::index');
    $routes->post('datasets', 'DatasetsController::create');
    $routes->get('datasets/(:num)', 'DatasetsController::show/$1');
    $routes->post('datasets/(:num)/upload', 'DatasetsController::upload/$1');
    $routes->post('datasets/(:num)/dedupe', 'DatasetsController::dedupe/$1');
    $routes->post('datasets/(:num)/clean', 'DatasetsController::clean/$1');
    $routes->get('datasets/(:num)/export', 'DatasetsController::export/$1');
    $routes->delete('datasets/(:num)', 'DatasetsController::delete/$1');
    $routes->delete('datasets/items/(:num)', 'DatasetsController::deleteItem/$1');

    $routes->get('training', 'TrainingController::index');
    $routes->post('training', 'TrainingController::create');
    $routes->put('training/(:num)', 'TrainingController::update/$1');
    $routes->delete('training/(:num)', 'TrainingController::delete/$1');
    $routes->post('training/(:num)/kit', 'TrainingController::generateKit/$1');
    $routes->get('training/(:num)/kit', 'TrainingController::downloadKit/$1');

    // --- Speech to text (Whisper Sinhala via the local aistudio-asr service) ---
    $routes->get('speech/status', 'SpeechController::status');
    $routes->post('speech/transcribe', 'SpeechController::transcribe');

    // --- WorkSuite Sinhala AI Engine: build & release zone (v1.5, docs/sinhala-ai-engine) ---
    $routes->get('engine/overview', 'EngineController::overview');
    $routes->put('engine/datasets/(:num)/rights', 'EngineController::datasetRights/$1');
    $routes->post('engine/datasets/(:num)/freeze', 'EngineController::datasetFreeze/$1');
    $routes->post('engine/datasets/(:num)/withdraw', 'EngineController::datasetWithdraw/$1');
    $routes->put('engine/training/(:num)/lineage', 'EngineController::trainingLineage/$1');
    $routes->get('engine/evaluations', 'EngineController::evaluations');
    $routes->post('engine/evaluations', 'EngineController::createEvaluation');
    $routes->post('engine/evaluations/(:num)/approve', 'EngineController::approveEvaluation/$1');
    $routes->get('engine/packages', 'EngineController::packages');
    $routes->post('engine/packages', 'EngineController::createPackage');
    $routes->post('engine/packages/rollback', 'EngineController::rollback');
    $routes->post('engine/packages/(:num)/transition', 'EngineController::transition/$1');
    $routes->get('engine/packages/(:num)/manifest', 'EngineController::manifest/$1');
    $routes->get('engine/evaluations/(:num)', 'EngineController::evaluation/$1');
    $routes->get('engine/packages/(:num)', 'EngineController::package/$1');
    $routes->post('engine/packages/(:num)/artifacts', 'EngineController::uploadArtifact/$1');
    $routes->delete('engine/packages/(:num)/artifacts', 'EngineController::deleteArtifact/$1');
    $routes->get('engine/approvals', 'EngineController::approvals');
    $routes->get('engine/bindings', 'EngineController::bindings');
    $routes->put('engine/bindings', 'EngineController::bind');
    $routes->post('engine/bindings/rollback', 'EngineController::bindingRollback');
});

/*
 * External API gateway (key/secret auth) — Phase 6.
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Gateway', 'filter' => 'apikey'], static function ($routes) {
    $routes->get('models', 'GatewayController::models');
    $routes->post('chat', 'GatewayController::chat');
    $routes->post('generate', 'GatewayController::generate');
    $routes->post('embeddings', 'GatewayController::embeddings');
    $routes->post('translate', 'GatewayController::translate');   // SinLama (v1.3)
    $routes->post('summarize', 'GatewayController::summarize');   // SinLama (v1.3)
    $routes->get('catalog', 'DownloadsController::catalog');                      // downloads (v1.14)
    $routes->get('packages/(:segment)/files', 'DownloadsController::file/$1');
    $routes->get('datasets/(:num)/download', 'DownloadsController::dataset/$1');
});
