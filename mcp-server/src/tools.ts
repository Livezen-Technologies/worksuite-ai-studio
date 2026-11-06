import { z, type ZodTypeAny } from 'zod';

/**
 * Every AI Studio admin endpoint as an MCP tool. One table so a new route is one line here.
 *
 *  - `path` uses {placeholders}; each one becomes a required tool argument (ids are positive ints
 *    unless `params` overrides the schema).
 *  - `query: true` adds an optional `query` object (filters, search, page, per_page…).
 *  - `body` is a hint naming the fields the endpoint reads; it adds a `data` object argument.
 *  - `kind` drives MCP annotations and read-only mode: read | write | destructive.
 *
 * The server enforces permissions; the tools act as the signed-in AI Studio user.
 */
export type Kind = 'read' | 'write' | 'destructive';
export interface ToolDef {
  name: string;
  title: string;
  description: string;
  method: 'GET' | 'POST' | 'PUT' | 'DELETE';
  path: string;
  kind: Kind;
  query?: boolean;
  body?: string;
  params?: Record<string, ZodTypeAny>;
  /** NDJSON streaming endpoint: collect the reply. */
  stream?: boolean;
}

const id = (what: string) => z.number().int().positive().describe(`${what} id`);
const r = (name: string, title: string, path: string, description: string, extra: Partial<ToolDef> = {}): ToolDef =>
  ({ name, title, description, method: 'GET', path, kind: 'read', ...extra });
const w = (name: string, title: string, method: ToolDef['method'], path: string, description: string, extra: Partial<ToolDef> = {}): ToolDef =>
  ({ name, title, description, method, path, kind: method === 'DELETE' ? 'destructive' : 'write', ...extra });

const SETTINGS_GROUP = z.enum(['general', 'ai', 'smtp', 'storage', 'notifications', 'gateway']).describe('Settings group');

export const TOOLS: ToolDef[] = [
  // --- Account / overview ---
  r('whoami', 'Who am I', '/auth/profile', 'The AI Studio user this MCP server acts as, with role and permissions.'),
  r('dashboard_get', 'Dashboard', '/dashboard', 'Dashboard overview: servers, models, requests, tokens, users and recent activity.'),
  r('dashboard_stats', 'Dashboard stats', '/dashboard/stats', 'Headline counters for the dashboard.'),
  r('dashboard_charts', 'Dashboard charts', '/dashboard/charts', 'Time series behind the dashboard charts (requests per hour etc.).', { query: true }),
  r('metrics_get', 'Live metrics', '/metrics', 'Live CPU, memory, GPU and status for every node.', { query: true }),
  r('health_get', 'Health', '/health', 'Platform health checks (database, nodes, queue, storage).'),
  w('metrics_refresh', 'Refresh metrics', 'POST', '/metrics/refresh', 'Poll every node now and refresh the live metrics.'),
  r('analytics_get', 'Analytics', '/analytics', 'Token, latency and usage trends. Query: range (e.g. 24h, 7d, 30d), model, user.', { query: true }),

  // --- Servers (Ollama nodes / providers) ---
  r('servers_list', 'List servers', '/servers', 'All Ollama nodes and providers with status.', { query: true }),
  r('servers_summary', 'Servers summary', '/servers/summary', 'Counts of servers by status.'),
  r('servers_get', 'Get server', '/servers/{id}', 'One server with details.', { params: { id: id('Server') } }),
  r('servers_usage', 'Server usage', '/servers/{id}/usage', 'Usage history for one server.', { params: { id: id('Server') }, query: true }),
  r('servers_models', 'Server models', '/servers/{id}/models', 'Models installed on one server.', { params: { id: id('Server') } }),
  w('servers_create', 'Add server', 'POST', '/servers', 'Register a server.', { body: 'name (required), description, kind, hostname, ip_address, api_url, port, auth_token, gpu_model, cuda_version, cpu_cores, ram_gb, storage_gb, os, is_local' }),
  w('servers_update', 'Update server', 'PUT', '/servers/{id}', 'Edit a server (send only fields to change; blank auth_token keeps the existing key).', { params: { id: id('Server') }, body: 'Same fields as servers_create' }),
  w('servers_delete', 'Delete server', 'DELETE', '/servers/{id}', 'Remove a server.', { params: { id: id('Server') } }),
  w('servers_health_check', 'Check server health', 'POST', '/servers/{id}/health', 'Run a health check on a server now.', { params: { id: id('Server') } }),
  w('servers_disable', 'Disable server', 'POST', '/servers/{id}/disable', 'Take a server out of rotation.', { params: { id: id('Server') }, body: 'reason' }),
  w('servers_enable', 'Enable server', 'POST', '/servers/{id}/enable', 'Put a server back into rotation.', { params: { id: id('Server') } }),
  w('servers_control', 'Control server', 'POST', '/servers/{id}/{action}', 'Restart, shut down or update the node agent on a server.', {
    params: { id: id('Server'), action: z.enum(['restart', 'shutdown', 'update']).describe('What to do') }, kind: 'destructive',
  }),
  w('servers_enrolment_create', 'Issue enrolment', 'POST', '/servers/enrolments', 'Issue an enrolment token so a new node can join with the node app.', { body: 'name, kind and other node fields' }),
  w('servers_agent_token', 'Agent token', 'POST', '/servers/{id}/agent-token', 'Issue a training agent token for a server.', { params: { id: id('Server') } }),

  // --- Models ---
  r('models_list', 'List models', '/models', 'All models across servers. Query: server_id, search.', { query: true }),
  w('models_sync', 'Sync models', 'POST', '/models/sync', 'Re-read installed models from servers.', { body: 'server_id (optional; all servers when omitted)' }),
  w('models_pull', 'Pull model', 'POST', '/models/pull', 'Download a model onto a server (e.g. "llama3.1:8b").', { body: 'server_id, name' }),
  w('models_delete', 'Delete model', 'DELETE', '/models/{id}', 'Remove a model from its server.', { params: { id: id('Model') } }),
  w('models_load', 'Load model', 'POST', '/models/{id}/load', 'Load a model into memory.', { params: { id: id('Model') } }),
  w('models_unload', 'Unload model', 'POST', '/models/{id}/unload', 'Unload a model from memory.', { params: { id: id('Model') } }),
  w('models_set_default', 'Set default model', 'POST', '/models/{id}/default', 'Make a model the default.', { params: { id: id('Model') } }),
  r('models_config_get', 'Model config', '/models/{id}/config', 'A model\'s parameters (temperature, context, system prompt…).', { params: { id: id('Model') } }),
  w('models_config_save', 'Save model config', 'PUT', '/models/{id}/config', 'Save a model\'s parameters.', { params: { id: id('Model') }, body: 'Config fields as returned by models_config_get' }),

  // --- Chat ---
  w('chat_send', 'Chat with a model', 'POST', '/chat', 'Send a message to a model on a server and get the full reply. Continues a saved session when session_id is given, otherwise starts one.', {
    stream: true, body: 'server_id (required), model (required), content (required), session_id, system_prompt, images (base64 array for vision models)',
  }),
  w('generate', 'One-shot generate', 'POST', '/generate', 'Run a single prompt without saving a session.', { stream: true, body: 'server_id, model, prompt, system_prompt' }),
  r('chat_sessions_list', 'Chat sessions', '/chat/sessions', 'Saved chat sessions.', { query: true }),
  r('chat_sessions_get', 'Get chat session', '/chat/sessions/{id}', 'A chat session with its messages.', { params: { id: id('Session') } }),
  w('chat_sessions_create', 'New chat session', 'POST', '/chat/sessions', 'Create an empty chat session.', { body: 'title, server_id, model, system_prompt' }),
  w('chat_sessions_rename', 'Rename chat session', 'PUT', '/chat/sessions/{id}', 'Rename a chat session.', { params: { id: id('Session') }, body: 'title' }),
  w('chat_sessions_delete', 'Delete chat session', 'DELETE', '/chat/sessions/{id}', 'Delete a chat session.', { params: { id: id('Session') } }),

  // --- Speech ---
  r('speech_status', 'Speech status', '/speech/status', 'Whether the Sinhala speech-to-text service is ready.'),
  w('speech_transcribe', 'Transcribe audio', 'POST', '/speech/transcribe', 'Transcribe audio to text.', { body: 'audio (base64) and options as the API expects' }),

  // --- Gateway: API keys + logs ---
  r('apikeys_list', 'List API keys', '/apikeys', 'Keys for the /api/v1 gateway.', { query: true }),
  w('apikeys_create', 'Create API key', 'POST', '/apikeys', 'Create a gateway key. The secret is shown once in the result.', { body: 'name (required), scopes, rate limits, allowed models, expires_at' }),
  w('apikeys_update', 'Update API key', 'PUT', '/apikeys/{id}', 'Edit a gateway key.', { params: { id: id('API key') }, body: 'Fields to change' }),
  w('apikeys_rotate', 'Rotate API key', 'POST', '/apikeys/{id}/rotate', 'Issue a new secret for a key; the old one stops working.', { params: { id: id('API key') }, kind: 'destructive' }),
  w('apikeys_delete', 'Delete API key', 'DELETE', '/apikeys/{id}', 'Revoke and delete a gateway key.', { params: { id: id('API key') } }),
  r('apilogs_list', 'API logs', '/apilogs', 'Gateway request log. Query: page, per_page, status, model, key_id, search.', { query: true }),

  // --- Users / roles / audit ---
  r('users_list', 'List users', '/users', 'People who can sign in. Query: search, role, status, page.', { query: true }),
  w('users_create', 'Create user', 'POST', '/users', 'Add a user.', { body: 'name, email, password (min 8), role_id (all required); status' }),
  w('users_update', 'Update user', 'PUT', '/users/{id}', 'Edit a user (role, status, name, password…).', { params: { id: id('User') }, body: 'Fields to change' }),
  w('users_delete', 'Delete user', 'DELETE', '/users/{id}', 'Delete a user.', { params: { id: id('User') } }),
  r('roles_list', 'List roles', '/roles', 'Roles with their permissions.'),
  r('roles_permissions', 'All permissions', '/roles/permissions', 'Every permission key that can be granted.'),
  w('roles_create', 'Create role', 'POST', '/roles', 'Create a role.', { body: 'label (required), permissions (array of keys), description' }),
  w('roles_update', 'Update role', 'PUT', '/roles/{id}', 'Edit a role.', { params: { id: id('Role') }, body: 'label, permissions, description' }),
  w('roles_delete', 'Delete role', 'DELETE', '/roles/{id}', 'Delete a role.', { params: { id: id('Role') } }),
  r('audit_list', 'Audit log', '/audit', 'Who changed what. Query: page, user_id, action, search.', { query: true }),

  // --- Notifications / settings ---
  r('notifications_list', 'Notifications', '/notifications', 'Notifications for the signed-in user.', { query: true }),
  w('notifications_mark_read', 'Mark read', 'POST', '/notifications/read', 'Mark notifications read (all, or one by id).', { body: 'id (optional)' }),
  w('notifications_broadcast', 'Broadcast', 'POST', '/notifications/broadcast', 'Send a notification to users.', { body: 'title (required), body, level, channels' }),
  w('notifications_test', 'Test notification', 'POST', '/notifications/test', 'Send a test notification through the configured channels.', { body: 'channels' }),
  r('settings_get', 'Get settings', '/settings/{group}', 'Platform settings for one group.', { params: { group: SETTINGS_GROUP } }),
  w('settings_save', 'Save settings', 'PUT', '/settings/{group}', 'Save platform settings for one group.', { params: { group: SETTINGS_GROUP }, body: 'Key/value settings as returned by settings_get' }),

  // --- Prompts / knowledge / agents ---
  r('prompts_list', 'Prompt library', '/prompts', 'Reusable prompts and templates.', { query: true }),
  w('prompts_create', 'Create prompt', 'POST', '/prompts', 'Save a prompt.', { body: 'name, content (required); description, category, tags' }),
  w('prompts_update', 'Update prompt', 'PUT', '/prompts/{id}', 'Edit a prompt.', { params: { id: id('Prompt') }, body: 'Fields to change' }),
  w('prompts_delete', 'Delete prompt', 'DELETE', '/prompts/{id}', 'Delete a prompt.', { params: { id: id('Prompt') } }),
  r('knowledge_list', 'Knowledge bases', '/knowledge', 'Knowledge bases for retrieval.', { query: true }),
  r('knowledge_get', 'Get knowledge base', '/knowledge/{id}', 'A knowledge base with its documents.', { params: { id: id('Knowledge base') } }),
  w('knowledge_create', 'Create knowledge base', 'POST', '/knowledge', 'Create a knowledge base.', { body: 'name (required), description, server_id, embed_model, chunk_size' }),
  w('knowledge_delete', 'Delete knowledge base', 'DELETE', '/knowledge/{id}', 'Delete a knowledge base.', { params: { id: id('Knowledge base') } }),
  w('knowledge_add_document', 'Add document', 'POST', '/knowledge/{id}/documents', 'Add a text document to a knowledge base.', { params: { id: id('Knowledge base') }, body: 'title, content' }),
  w('knowledge_delete_document', 'Delete document', 'DELETE', '/knowledge/documents/{id}', 'Remove a document from its knowledge base.', { params: { id: id('Document') } }),
  w('knowledge_search', 'Search knowledge base', 'POST', '/knowledge/{id}/search', 'Semantic search in a knowledge base.', { params: { id: id('Knowledge base') }, body: 'query, limit', kind: 'read' }),
  w('knowledge_reindex', 'Reindex', 'POST', '/knowledge/{id}/reindex', 'Rebuild embeddings for a knowledge base.', { params: { id: id('Knowledge base') } }),
  r('agents_list', 'AI agents', '/agents', 'Configured assistants.', { query: true }),
  w('agents_create', 'Create agent', 'POST', '/agents', 'Create an assistant.', { body: 'name (required), model, server_id, system_prompt, tools, knowledge_base_id' }),
  w('agents_update', 'Update agent', 'PUT', '/agents/{id}', 'Edit an assistant.', { params: { id: id('Agent') }, body: 'Fields to change' }),
  w('agents_delete', 'Delete agent', 'DELETE', '/agents/{id}', 'Delete an assistant.', { params: { id: id('Agent') } }),

  // --- Queue / cron ---
  r('queue_list', 'Job queue', '/queue', 'Background jobs. Query: status, type, page.', { query: true }),
  w('queue_create', 'Queue job', 'POST', '/queue', 'Queue a background job.', { body: 'type, payload, priority' }),
  w('queue_work', 'Run queue', 'POST', '/queue/work', 'Process pending jobs now.'),
  w('queue_cancel', 'Cancel job', 'POST', '/queue/{id}/cancel', 'Cancel a job.', { params: { id: id('Job') }, kind: 'destructive' }),
  w('queue_retry', 'Retry job', 'POST', '/queue/{id}/retry', 'Retry a failed job.', { params: { id: id('Job') } }),
  r('cron_list', 'Schedules', '/cron', 'Scheduled tasks.'),
  w('cron_update', 'Update schedule', 'PUT', '/cron/{id}', 'Edit a scheduled task (enable, expression).', { params: { id: id('Schedule') }, body: 'enabled, expression' }),
  w('cron_run', 'Run schedule now', 'POST', '/cron/{id}/run', 'Run a scheduled task immediately.', { params: { id: id('Schedule') } }),

  // --- SinLama: studio, datasets, training ---
  r('studio_presets', 'Studio presets', '/studio/presets', 'Model Studio presets and base models.'),
  w('studio_create', 'Create custom model', 'POST', '/studio/create', 'Build a custom model from a base (Modelfile-style).', { body: 'name, base model, system prompt, parameters, server_id' }),
  w('studio_copy', 'Copy model', 'POST', '/studio/copy', 'Copy a model to another name or server.', { body: 'source, destination, server_id' }),
  r('datasets_list', 'Datasets', '/datasets', 'Instruction datasets for fine-tuning.', { query: true }),
  r('datasets_get', 'Get dataset', '/datasets/{id}', 'A dataset with its items.', { params: { id: id('Dataset') }, query: true }),
  w('datasets_create', 'Create dataset', 'POST', '/datasets', 'Create a dataset.', { body: 'name, description, language' }),
  w('datasets_upload', 'Upload dataset items', 'POST', '/datasets/{id}/upload', 'Add items to a dataset.', { params: { id: id('Dataset') }, body: 'items or content as the API expects (JSONL rows)' }),
  w('datasets_dedupe', 'Dedupe dataset', 'POST', '/datasets/{id}/dedupe', 'Remove duplicate items.', { params: { id: id('Dataset') } }),
  w('datasets_clean', 'Clean dataset', 'POST', '/datasets/{id}/clean', 'Clean and normalise items.', { params: { id: id('Dataset') } }),
  r('datasets_export', 'Export dataset', '/datasets/{id}/export', 'Export a dataset (JSONL).', { params: { id: id('Dataset') }, query: true }),
  w('datasets_delete', 'Delete dataset', 'DELETE', '/datasets/{id}', 'Delete a dataset.', { params: { id: id('Dataset') } }),
  w('datasets_delete_item', 'Delete dataset item', 'DELETE', '/datasets/items/{id}', 'Delete one dataset item.', { params: { id: id('Item') } }),
  r('training_list', 'Training jobs', '/training', 'LoRA/QLoRA training jobs.', { query: true }),
  w('training_create', 'Create training job', 'POST', '/training', 'Create a training job.', { body: 'name, base_model, dataset_id, method (lora|qlora), hyperparams, notes' }),
  w('training_update', 'Update training job', 'PUT', '/training/{id}', 'Edit a training job.', { params: { id: id('Training job') }, body: 'name, status, hyperparams, notes' }),
  w('training_delete', 'Delete training job', 'DELETE', '/training/{id}', 'Delete a training job.', { params: { id: id('Training job') } }),
  w('training_generate_kit', 'Generate training kit', 'POST', '/training/{id}/kit', 'Build the downloadable training kit.', { params: { id: id('Training job') } }),
  r('training_live', 'Training progress', '/training/{id}/live', 'Live progress (loss, step, ETA) for a training job.', { params: { id: id('Training job') } }),
  w('training_cancel', 'Cancel training', 'POST', '/training/{id}/cancel', 'Cancel a running training job.', { params: { id: id('Training job') }, kind: 'destructive' }),
  w('training_retry', 'Retry training', 'POST', '/training/{id}/retry', 'Retry a failed training job.', { params: { id: id('Training job') } }),
  w('training_report_token', 'Report token', 'POST', '/training/{id}/report-token', 'Issue a progress-report token for a training run.', { params: { id: id('Training job') } }),
  r('pipelines_list', 'Training pipelines', '/training/pipelines', 'Automated training pipelines.'),
  w('pipelines_create', 'Create pipeline', 'POST', '/training/pipelines', 'Create a training pipeline.', { body: 'Pipeline fields as the API expects' }),
  w('pipelines_update', 'Update pipeline', 'PUT', '/training/pipelines/{id}', 'Edit a pipeline.', { params: { id: id('Pipeline') }, body: 'Fields to change' }),
  w('pipelines_delete', 'Delete pipeline', 'DELETE', '/training/pipelines/{id}', 'Delete a pipeline.', { params: { id: id('Pipeline') } }),
  w('pipelines_run', 'Run pipeline', 'POST', '/training/pipelines/{id}/run', 'Start a pipeline run.', { params: { id: id('Pipeline') } }),
  r('ml_models_list', 'Table ML models', '/ml-models', 'Tabular classifiers/regressors.'),
  w('ml_models_create', 'Train table model', 'POST', '/ml-models/table', 'Train a model on tabular data.', { body: 'Fields as the API expects' }),
  w('ml_models_predict', 'Predict', 'POST', '/ml-models/{id}/predict', 'Run a prediction with a table model.', { params: { id: id('ML model') }, body: 'rows / features', kind: 'read' }),
  w('ml_models_delete', 'Delete table model', 'DELETE', '/ml-models/{id}', 'Delete a table model.', { params: { id: id('ML model') } }),

  // --- Storage ---
  r('storage_overview', 'Storage overview', '/storage', 'Storage nodes, mounts, buckets, rules and alerts.'),
  r('storage_node_get', 'Storage node', '/storage/nodes/{id}', 'One storage node.', { params: { id: id('Storage node') } }),
  w('storage_node_create', 'Add storage node', 'POST', '/storage/nodes', 'Register a storage node (disk, NFS, S3…).', { body: 'Node fields as the API expects' }),
  w('storage_node_update', 'Update storage node', 'PUT', '/storage/nodes/{id}', 'Edit a storage node.', { params: { id: id('Storage node') }, body: 'Fields to change' }),
  w('storage_node_delete', 'Delete storage node', 'DELETE', '/storage/nodes/{id}', 'Remove a storage node.', { params: { id: id('Storage node') } }),
  w('storage_node_test', 'Test storage node', 'POST', '/storage/nodes/{id}/test', 'Test connectivity to a storage node.', { params: { id: id('Storage node') } }),
  w('storage_node_status', 'Set storage status', 'POST', '/storage/nodes/{id}/status', 'Change a storage node\'s status.', { params: { id: id('Storage node') }, body: 'status' }),
  w('storage_allocate', 'Allocate storage', 'POST', '/storage/nodes/{id}/allocations', 'Allocate space on a node.', { params: { id: id('Storage node') }, body: 'Allocation fields' }),
  w('storage_unallocate', 'Remove allocation', 'DELETE', '/storage/allocations/{id}', 'Remove a storage allocation.', { params: { id: id('Allocation') } }),
  w('storage_rule_create', 'Create storage rule', 'POST', '/storage/rules', 'Create a storage rule.', { body: 'Rule fields' }),
  w('storage_rule_update', 'Update storage rule', 'PUT', '/storage/rules/{id}', 'Edit a storage rule.', { params: { id: id('Rule') }, body: 'Fields to change' }),
  w('storage_rule_delete', 'Delete storage rule', 'DELETE', '/storage/rules/{id}', 'Delete a storage rule.', { params: { id: id('Rule') } }),
  w('storage_alert_ack', 'Acknowledge alert', 'POST', '/storage/alerts/{id}/ack', 'Acknowledge a storage alert.', { params: { id: id('Alert') } }),
  r('storage_files_list', 'Files', '/storage/files', 'Stored files. Query: search, node, page.', { query: true }),
  r('storage_files_get', 'File', '/storage/files/{id}', 'One stored file.', { params: { id: id('File') } }),
  w('storage_files_rename', 'Rename file', 'PUT', '/storage/files/{id}', 'Rename a stored file.', { params: { id: id('File') }, body: 'name' }),
  w('storage_files_delete', 'Delete file', 'DELETE', '/storage/files/{id}', 'Delete a stored file.', { params: { id: id('File') } }),
  w('storage_files_transfer', 'Transfer file', 'POST', '/storage/files/{id}/transfer', 'Move a file to another storage node.', { params: { id: id('File') }, body: 'node_id' }),
  r('storage_policies_list', 'Storage policies', '/storage/policies', 'Retention and placement policies.'),
  w('storage_policies_create', 'Create policy', 'POST', '/storage/policies', 'Create a storage policy.', { body: 'Policy fields' }),
  w('storage_policies_update', 'Update policy', 'PUT', '/storage/policies/{id}', 'Edit a storage policy.', { params: { id: id('Policy') }, body: 'Fields to change' }),
  w('storage_policies_delete', 'Delete policy', 'DELETE', '/storage/policies/{id}', 'Delete a storage policy.', { params: { id: id('Policy') } }),
  r('storage_tasks_list', 'Storage tasks', '/storage/tasks', 'Background storage tasks.', { query: true }),
  w('storage_tasks_retry', 'Retry storage task', 'POST', '/storage/tasks/{id}/retry', 'Retry a failed storage task.', { params: { id: id('Task') } }),
  r('backups_list', 'Backups', '/storage/backups', 'Backup jobs and recent runs.'),
  w('backups_update', 'Update backup', 'PUT', '/storage/backups/{id}', 'Edit a backup job.', { params: { id: id('Backup') }, body: 'Fields to change' }),
  w('backups_run', 'Run backup', 'POST', '/storage/backups/{id}/run', 'Run a backup now.', { params: { id: id('Backup') } }),
  r('restore_status', 'Restore status', '/storage/restore', 'Status of the latest database restore.'),
  w('backups_restore', 'Restore backup', 'POST', '/storage/backups/runs/{id}/restore', 'Restore the database from a backup run (Super Admin only; a safety backup is taken first). Overwrites current data.', { params: { id: id('Backup run') }, kind: 'destructive' }),

  // --- User portal ---
  r('portal_overview', 'My workspace', '/portal/overview', 'Your allocations, datasets and jobs.'),
  r('portal_nodes', 'My nodes', '/portal/nodes', 'Nodes you are allocated on.'),
  r('portal_datasets_list', 'My datasets', '/portal/datasets', 'Your training datasets.'),
  r('portal_datasets_get', 'My dataset', '/portal/datasets/{id}', 'One of your datasets.', { params: { id: id('Dataset') } }),
  w('portal_datasets_create', 'Create my dataset', 'POST', '/portal/datasets', 'Create a personal dataset.', { body: 'name, description' }),
  w('portal_datasets_upload', 'Upload to my dataset', 'POST', '/portal/datasets/{id}/upload', 'Add data to a personal dataset.', { params: { id: id('Dataset') }, body: 'Content as the API expects' }),
  w('portal_datasets_delete', 'Delete my dataset', 'DELETE', '/portal/datasets/{id}', 'Delete a personal dataset.', { params: { id: id('Dataset') } }),
  r('portal_jobs_list', 'My training jobs', '/portal/jobs', 'Your training jobs.'),
  r('portal_jobs_get', 'My training job', '/portal/jobs/{id}', 'One of your training jobs.', { params: { id: id('Job') } }),
  w('portal_jobs_create', 'Create my training job', 'POST', '/portal/jobs', 'Start a training job on your allocated nodes.', { body: 'Job fields as the API expects' }),
  w('portal_jobs_set_nodes', 'Set job nodes', 'PUT', '/portal/jobs/{id}/nodes', 'Choose which nodes a job runs on.', { params: { id: id('Job') }, body: 'nodes' }),
  w('portal_jobs_cancel', 'Cancel my job', 'POST', '/portal/jobs/{id}/cancel', 'Cancel one of your jobs.', { params: { id: id('Job') }, kind: 'destructive' }),
  w('portal_jobs_kit', 'Build my job kit', 'POST', '/portal/jobs/{id}/kit', 'Build the training kit for a job.', { params: { id: id('Job') } }),

  // --- Compute allocations ---
  r('compute_overview', 'Compute overview', '/compute/overview', 'Nodes open for training and capacity allocations.'),
  r('compute_usage', 'Compute usage', '/compute/usage', 'Who uses which capacity.', { query: true }),
  r('compute_jobs', 'Compute jobs', '/compute/jobs', 'Job placements across nodes.', { query: true }),
  w('compute_allocation_create', 'Allocate compute', 'POST', '/compute/allocations', 'Give a user CPU/GPU capacity on a node.', { body: 'user_id, server_id, cpu_cores, gpu_count, gpu_vram_gb, allow_training, expires_at, notes' }),
  w('compute_allocation_update', 'Update allocation', 'PUT', '/compute/allocations/{id}', 'Edit a compute allocation.', { params: { id: id('Allocation') }, body: 'Fields to change' }),
  w('compute_allocation_delete', 'Remove allocation', 'DELETE', '/compute/allocations/{id}', 'Remove a compute allocation.', { params: { id: id('Allocation') } }),
  w('compute_node_update', 'Update compute node', 'PUT', '/compute/nodes/{id}', 'Set a node\'s training capacity.', { params: { id: id('Node') }, body: 'Capacity fields' }),
  w('compute_job_placement', 'Set job placement', 'PUT', '/compute/jobs/{id}/placement', 'Place a job on nodes.', { params: { id: id('Job') }, body: 'nodes, override' }),
  w('compute_job_node_status', 'Set job node status', 'PUT', '/compute/jobs/{id}/nodes/{node_id}', 'Update a job\'s status on one node.', { params: { id: id('Job'), node_id: id('Node') }, body: 'status, progress, message' }),

  // --- Sinhala engine ---
  r('engine_overview', 'Engine overview', '/engine/overview', 'Active packs, pipeline and release gates.'),
  w('engine_dataset_rights', 'Set dataset rights', 'PUT', '/engine/datasets/{id}/rights', 'Record licence/rights for a dataset.', { params: { id: id('Dataset') }, body: 'license_id, rights_approval_id, notes' }),
  w('engine_dataset_freeze', 'Freeze dataset', 'POST', '/engine/datasets/{id}/freeze', 'Freeze a dataset version for training.', { params: { id: id('Dataset') } }),
  w('engine_dataset_withdraw', 'Withdraw dataset', 'POST', '/engine/datasets/{id}/withdraw', 'Withdraw a dataset.', { params: { id: id('Dataset') }, body: 'reason', kind: 'destructive' }),
  w('engine_training_lineage', 'Set training lineage', 'PUT', '/engine/training/{id}/lineage', 'Record lineage for a training job.', { params: { id: id('Training job') }, body: 'Lineage fields' }),
  r('engine_evaluations_list', 'Evaluations', '/engine/evaluations', 'Evaluation reports.', { query: true }),
  r('engine_evaluations_get', 'Evaluation', '/engine/evaluations/{id}', 'One evaluation report.', { params: { id: id('Evaluation') } }),
  w('engine_evaluations_create', 'Create evaluation', 'POST', '/engine/evaluations', 'Record an evaluation.', { body: 'candidate_digest, baseline_digest, metrics, baseline_metrics, profile, notes' }),
  w('engine_evaluations_approve', 'Approve evaluation', 'POST', '/engine/evaluations/{id}/approve', 'Approve an evaluation for release.', { params: { id: id('Evaluation') }, body: 'notes' }),
  r('engine_packages_list', 'Packages', '/engine/packages', 'Signed model packages and lifecycle.', { query: true }),
  r('engine_packages_get', 'Package', '/engine/packages/{id}', 'One package.', { params: { id: id('Package') } }),
  r('engine_packages_manifest', 'Package manifest', '/engine/packages/{id}/manifest', 'A package\'s manifest.', { params: { id: id('Package') } }),
  w('engine_packages_create', 'Create package', 'POST', '/engine/packages', 'Create a model package.', { body: 'evaluation_uuid, base_model, quantization, runtime_name, runtime_lock, languages, hardware, min_ram_gib, min_disk_gib, notes' }),
  w('engine_packages_transition', 'Transition package', 'POST', '/engine/packages/{id}/transition', 'Move a package through its lifecycle (e.g. candidate -> released).', { params: { id: id('Package') }, body: 'to (target state), reason' }),
  w('engine_packages_rollback', 'Roll back package', 'POST', '/engine/packages/rollback', 'Roll back to a previous package.', { body: 'package_id, reason', kind: 'destructive' }),
  w('engine_packages_delete_artifact', 'Delete artifact', 'DELETE', '/engine/packages/{id}/artifacts', 'Remove an artifact from a package.', { params: { id: id('Package') }, body: 'Artifact identifier' }),
  r('engine_approvals', 'Approvals', '/engine/approvals', 'Items waiting for your approval.'),
  r('engine_bindings', 'Bindings', '/engine/bindings', 'Active pack per task and device profile.'),
  w('engine_bind', 'Bind package', 'PUT', '/engine/bindings', 'Bind a package to a task/profile.', { body: 'package_id, task, profile' }),
  w('engine_bindings_rollback', 'Roll back binding', 'POST', '/engine/bindings/rollback', 'Roll a binding back to its previous package.', { body: 'task, profile, reason', kind: 'destructive' }),
];

/** Escape hatch for anything not covered above (and future routes). */
export const API_REQUEST_TOOL = {
  name: 'api_request',
  title: 'Raw AI Studio API request',
  description:
    'Call any AI Studio admin endpoint directly. `path` is relative to /api (e.g. "/servers/3/usage"). ' +
    'Prefer the specific tools; use this for endpoints they do not cover.',
};
