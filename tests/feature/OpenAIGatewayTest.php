<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The OpenAI-compatible gateway at /api/openai/v1, end to end against a fake OpenAI-compatible
 * engine (a vLLM-style node) served by PHP's built-in web server.
 */
final class OpenAIGatewayTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private static $proc;
    private static int $port = 0;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$port = random_int(20000, 40000);
        self::$log  = tempnam(sys_get_temp_dir(), 'fake-engine-');
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../_support/fake_openai_engine.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_ENGINE_LOG' => self::$log] + getenv());
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) usleep(100000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) { proc_terminate(self::$proc); proc_close(self::$proc); }
        @unlink(self::$log);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        unset($_SERVER['ohub_api_key']);
        putenv('ASR_SERVICE_URL=http://127.0.0.1:' . self::$port); $_ENV['ASR_SERVICE_URL'] = $_SERVER['ASR_SERVICE_URL'] = 'http://127.0.0.1:' . self::$port;
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['users', 'roles', 'api_keys', 'api_logs', 'servers', 'models', 'model_registry', 'model_assignments', 'model_defaults', 'settings', 'queues', 'notifications'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true] + ($type === 'VARCHAR' ? ['constraint' => 191] : []);
        $f->addField($id + ['name' => $null(), 'status' => $null(), 'role_id' => $null('INTEGER'), 'deleted_at' => $null('DATETIME')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => $null(), 'user_id' => $null('INTEGER'), 'api_key' => $null(), 'secret_hash' => $null(), 'scopes' => $null(), 'allowed_models' => $null('TEXT'),
            'allowed_ips' => $null('TEXT'), 'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60], 'status' => $null(), 'daily_token_limit' => $null('INTEGER'),
            'monthly_token_limit' => $null('INTEGER'), 'expires_at' => $null('DATETIME'), 'last_used_at' => $null('DATETIME'), 'created_at' => $null('DATETIME'),
            'updated_at' => $null('DATETIME'), 'revoked_at' => $null('DATETIME'), 'token_version' => ['type' => 'INTEGER', 'default' => 0], 'application' => $null(),
            'previous_secret_hash' => $null(), 'previous_secret_expires_at' => $null('DATETIME'), 'max_output_tokens' => $null('INTEGER')])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['api_key_id' => $null('INTEGER'), 'user_id' => $null('INTEGER'), 'endpoint' => $null(), 'model' => $null(), 'server_id' => $null('INTEGER'),
            'method' => $null(), 'prompt_tokens' => $null('INTEGER'), 'completion_tokens' => $null('INTEGER'), 'total_tokens' => $null('INTEGER'),
            'processing_ms' => $null('INTEGER'), 'status' => $null('INTEGER'), 'client_ip' => $null(), 'country' => $null(), 'error' => $null(), 'created_at' => $null('DATETIME'), 'end_user' => $null()])
            ->addKey('id', true)->createTable('api_logs');
        $f->addField($id + ['name' => $null(), 'kind' => $null(), 'api_url' => $null(), 'auth_token' => $null(), 'status' => $null(), 'health_score' => ['type' => 'INTEGER', 'default' => 100],
            'is_enabled' => ['type' => 'INTEGER', 'default' => 1], 'deleted_at' => $null('DATETIME'), 'gpu_vram_gb' => $null('REAL'), 'vram_used_gb' => $null('REAL'),
            'asr_enabled' => ['type' => 'INTEGER', 'default' => 1], 'asr_port' => $null('INTEGER'), 'asr_device' => $null(), 'asr_seen_at' => $null('DATETIME'),
            'gpu_model' => $null(), 'gpu_count' => $null('INTEGER')])->addKey('id', true)->createTable('servers');
        $f->addField($id + ['server_id' => $null('INTEGER'), 'name' => $null(), 'parameter_size' => $null(), 'quantization' => $null(), 'size_bytes' => $null('BIGINT'), 'status' => $null()])->addKey('id', true)->createTable('models');
        $f->addField($id + ['name' => $null(), 'display_name' => $null(), 'type' => $null(), 'version' => $null(), 'family' => $null(), 'parameter_size' => $null(),
            'quantization' => $null(), 'vram_gb' => $null('REAL'), 'context_length' => $null('INTEGER'), 'capabilities' => $null('TEXT'), 'engine' => $null(),
            'source' => $null(), 'description' => $null(), 'access_roles' => $null('TEXT'), 'keep_loaded' => ['type' => 'INTEGER', 'default' => 0],
            'idle_unload_minutes' => $null('INTEGER'), 'enabled' => ['type' => 'INTEGER', 'default' => 1], 'created_by' => $null('INTEGER'),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_registry');
        $f->addField($id + ['registry_id' => $null('INTEGER'), 'server_id' => $null('INTEGER'), 'state' => $null(), 'job_id' => $null('INTEGER'), 'error' => $null(),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_assignments');
        $f->addField($id + ['task' => $null(), 'application' => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => ''], 'model_name' => $null(), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_defaults');
        $f->addField($id + ['group' => $null(), 'key' => $null(), 'value' => $null('TEXT'), 'is_secret' => ['type' => 'INTEGER', 'default' => 0], 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('settings');

        $f->addField($id + ['type' => $null(), 'status' => $null(), 'error' => $null()])->addKey('id', true)->createTable('queues');
        $f->addField($id + ['user_id' => $null('INTEGER'), 'type' => $null(), 'title' => $null(), 'body' => $null('TEXT'), 'level' => $null(), 'read_at' => $null('DATETIME'), 'created_at' => $null('DATETIME')])->addKey('id', true)->createTable('notifications');
        $this->db->table('roles')->insertBatch([['name' => 'customer'], ['name' => 'developer']]);
        $this->db->table('users')->insert(['name' => 'Ann', 'status' => 'active', 'role_id' => 1]);
        $this->db->table('api_keys')->insert(['name' => 'k', 'user_id' => 1, 'api_key' => 'ohub_k1', 'secret_hash' => password_hash('sk_s', PASSWORD_DEFAULT),
            'scopes' => 'chat,generate,embeddings,models,transcribe', 'status' => 'active', 'application' => 'HR portal']);
        $this->db->table('servers')->insertBatch([
            ['name' => 'dead', 'kind' => 'vllm', 'api_url' => 'http://127.0.0.1:1', 'status' => 'online', 'health_score' => 100],
            ['name' => 'fake', 'kind' => 'vllm', 'api_url' => 'http://127.0.0.1:' . self::$port, 'status' => 'online', 'health_score' => 90],
            ['name' => 'ollama', 'kind' => 'ollama', 'api_url' => 'http://127.0.0.1:' . self::$port, 'status' => 'online', 'health_score' => 90],
            ['name' => 'claude', 'kind' => 'anthropic', 'api_url' => 'http://127.0.0.1:' . self::$port . '/v1', 'status' => 'online', 'health_score' => 90],
            ['name' => 'gemini', 'kind' => 'gemini', 'api_url' => 'http://127.0.0.1:' . self::$port . '/v1beta', 'status' => 'online', 'health_score' => 90],
        ]);
        foreach ([3 => 'tool-ollama', 4 => 'tool-claude', 5 => 'tool-gemini'] as $sid => $m) $this->db->table('models')->insert(['server_id' => $sid, 'name' => $m, 'status' => 'available']);
        foreach (['fake-chat', 'fake-embed', 'secret', 'broken'] as $m) {
            $this->db->table('models')->insert(['server_id' => 2, 'name' => $m, 'status' => 'available']);
            $this->db->table('models')->insert(['server_id' => 1, 'name' => $m, 'status' => 'available']); // the dead node is tried first: failover
        }
        $this->db->table('model_registry')->insert(['name' => 'secret', 'type' => 'llm', 'access_roles' => json_encode(['developer']), 'enabled' => 1]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ohub_k1:sk_s'];
    }

    private function api(string $method, string $path, array $body = [])
    {
        $req = $this->withHeaders($this->auth());
        return $method === 'get' ? $req->get('api/openai/v1/' . $path) : $req->withBodyFormat('json')->post('api/openai/v1/' . $path, $body);
    }

    private function body($res): array
    {
        return json_decode($res->getJSON() ?: (string) $res->response()->getBody(), true) ?? [];
    }

    public function testAuthErrorsUseTheOpenAiShape(): void
    {
        $res = $this->get('api/openai/v1/models');
        $res->assertStatus(401);
        $b = $this->body($res);
        $this->assertSame('authentication_error', $b['error']['type']);
        $this->assertSame('invalid_api_key', $b['error']['code']);
    }

    public function testModelsListHidesModelsTheOwnerCannotUse(): void
    {
        $res = $this->api('get', 'models');
        $res->assertStatus(200);
        $b = $this->body($res);
        $this->assertSame('list', $b['object']);
        $ids = array_column($b['data'], 'id');
        $this->assertContains('fake-chat', $ids);
        $this->assertNotContains('secret', $ids);
        $this->assertSame('embedding', array_column($b['data'], 'type', 'id')['fake-embed']);
        $this->api('get', 'models/fake-chat')->assertStatus(200);
        $this->api('get', 'models/secret')->assertStatus(404);
    }

    public function testChatCompletionFailsOverAndMapsParameters(): void
    {
        $res = $this->api('post', 'chat/completions', ['model' => 'fake-chat', 'temperature' => 0.2, 'max_tokens' => 50,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'developer', 'content' => 'Be brief.'], ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi there']]]]]);
        $res->assertStatus(200);
        $b = $this->body($res);
        $this->assertSame('chat.completion', $b['object']);
        $this->assertStringStartsWith('chatcmpl-', $b['id']);
        $content = $b['choices'][0]['message']['content'];
        $this->assertStringStartsWith('Hello ', $content);
        $echo = json_decode(substr($content, 6), true);
        $this->assertEquals(['t' => 0.2, 'max' => 50, 'json' => 'json_object', 'last' => 'Hi there'], $echo);
        $this->assertSame(['prompt_tokens' => 7, 'completion_tokens' => 5, 'total_tokens' => 12], $b['usage']);
        $this->assertNotEmpty($res->response()->getHeaderLine('x-request-id'));
        $log = $this->db->table('api_logs')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('openai/chat.completions', $log['endpoint']);
        $this->assertEquals(2, $log['server_id'], 'served by the healthy node after the first failed');
        $this->assertEquals(12, $log['total_tokens']);
    }

    public function testDefaultModelAndLegacyCompletions(): void
    {
        $this->api('post', 'chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]])->assertStatus(400);
        $this->db->table('model_defaults')->insert(['task' => 'chat', 'application' => 'HR portal', 'model_name' => 'fake-chat']);
        $b = $this->body($this->api('post', 'chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]]));
        $this->assertSame('fake-chat', $b['model']);
        $b = $this->body($this->api('post', 'completions', ['model' => 'default', 'prompt' => 'Say hi']));
        $this->assertSame('text_completion', $b['object']);
        $this->assertStringStartsWith('Hello ', $b['choices'][0]['text']);
    }

    public function testEmbeddings(): void
    {
        $b = $this->body($this->api('post', 'embeddings', ['model' => 'fake-embed', 'input' => ['a', 'b']]));
        $this->assertSame('list', $b['object']);
        $this->assertCount(2, $b['data']);
        $this->assertEquals([0.5, -1.0, 1.0], $b['data'][1]['embedding']);
        $b64 = $this->body($this->api('post', 'embeddings', ['model' => 'fake-embed', 'input' => 'a', 'encoding_format' => 'base64']));
        $this->assertEqualsWithDelta([0.5, -1.0, 0.0], array_values(unpack('g*', base64_decode($b64['data'][0]['embedding']))), 1e-6);
    }

    public function testRequestErrors(): void
    {
        $e = fn ($res) => $this->body($res)['error'];
        $this->assertSame('tools', $e($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'messages' => [['role' => 'user', 'content' => 'x']], 'tools' => [['type' => 'function']]]))['param']);
        $this->assertSame('messages', $e($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'messages' => []]))['param']);
        $this->assertSame('invalid_value', $e($this->api('post', 'chat/completions', ['model' => 'fake-chat',
            'messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'http://169.254.169.254/x.png']]]]]]))['code']);
        $res = $this->api('post', 'chat/completions', ['model' => 'secret', 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $res->assertStatus(403);
        $this->assertSame('permission_error', $e($res)['type']);
        $res = $this->api('post', 'chat/completions', ['model' => 'nowhere', 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $res->assertStatus(503);
        $res = $this->api('post', 'chat/completions', ['model' => 'broken', 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $res->assertStatus(502);
        $this->assertSame('api_error', $e($res)['type']);
        $this->db->table('api_keys')->update(['scopes' => 'chat']);
        $this->api('post', 'embeddings', ['model' => 'fake-embed', 'input' => 'a'])->assertStatus(403);
    }

    // ---- Tool calling --------------------------------------------------------------------------

    private const TOOLS = [['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Weather for a city',
        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => false]]]];

    private function sent(): array
    {
        return json_decode((string) file_get_contents(self::$log), true);
    }

    /** First turn: the model asks for a tool; the reply is an OpenAI tool call whatever the engine. */
    public function testToolCallsComeBackInOpenAiFormatFromEveryEngine(): void
    {
        $cases = ['fake-chat' => ['Colombo', null], 'tool-ollama' => ['Kandy', null], 'tool-claude' => ['Galle', 'Checking.'], 'tool-gemini' => ['Jaffna', null]];
        foreach ($cases as $model => [$city, $text]) {
            $b = $this->body($this->api('post', 'chat/completions', ['model' => $model, 'tools' => self::TOOLS, 'tool_choice' => 'auto',
                'messages' => [['role' => 'user', 'content' => 'Weather?']]]));
            $choice = $b['choices'][0] ?? null;
            $this->assertSame('tool_calls', $choice['finish_reason'] ?? null, $model . ': ' . json_encode($b));
            $call = $choice['message']['tool_calls'][0];
            $this->assertSame('function', $call['type']);
            $this->assertSame('get_weather', $call['function']['name']);
            $this->assertSame(['city' => $city], json_decode($call['function']['arguments'], true), $model);
            $this->assertNotEmpty($call['id']);
            $this->assertSame($text, $choice['message']['content'], $model);
        }
        $this->assertSame('call_abc', $this->body($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'tools' => self::TOOLS,
            'messages' => [['role' => 'user', 'content' => 'x']]]))['choices'][0]['message']['tool_calls'][0]['id'], 'the engine\'s own call id is kept');
    }

    /** Tool definitions and tool_choice reach each engine in its own format. */
    public function testToolsAreTranslatedPerEngine(): void
    {
        $ask = fn (string $model, $choice = 'auto') => $this->api('post', 'chat/completions', ['model' => $model, 'tools' => self::TOOLS, 'tool_choice' => $choice,
            'messages' => [['role' => 'user', 'content' => 'Weather?']]]);

        $ask('fake-chat', ['type' => 'function', 'function' => ['name' => 'get_weather']]);
        $body = $this->sent()['body'];
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'get_weather']], $body['tool_choice']);

        $ask('tool-ollama', 'required');
        $body = $this->sent()['body'];
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
        $this->assertSame('system', $body['messages'][0]['role'], 'Ollama has no tool_choice; it is asked in a system message');

        $ask('tool-claude', 'required');
        $body = $this->sent()['body'];
        $this->assertSame(['name', 'description', 'input_schema'], array_keys($body['tools'][0]));
        $this->assertSame(['type' => 'any'], $body['tool_choice']);

        $ask('tool-gemini', ['type' => 'function', 'function' => ['name' => 'get_weather']]);
        $body = $this->sent()['body'];
        $decl = $body['tools'][0]['functionDeclarations'][0];
        $this->assertSame('get_weather', $decl['name']);
        $this->assertArrayNotHasKey('additionalProperties', $decl['parameters'], 'keywords Gemini rejects are dropped');
        $this->assertSame(['mode' => 'ANY', 'allowedFunctionNames' => ['get_weather']], $body['toolConfig']['functionCallingConfig']);

        $ask('fake-chat', 'none');
        $this->assertArrayNotHasKey('tools', $this->sent()['body'], 'tool_choice none sends no tools');
    }

    /** Second turn: the assistant's tool call and the tool result are sent back in each engine's format. */
    public function testToolResultsAreTranslatedPerEngine(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Weather in Colombo and Kandy?'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Colombo"}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Kandy"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temp":31}'],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"temp":24}'],
        ];
        $send = fn (string $model) => $this->body($this->api('post', 'chat/completions', ['model' => $model, 'tools' => self::TOOLS, 'messages' => $history]));

        $send('tool-ollama');
        $m = $this->sent()['body']['messages'];
        $this->assertSame(['city' => 'Colombo'], $m[1]['tool_calls'][0]['function']['arguments'], 'Ollama gets arguments as an object');
        $this->assertSame(['role' => 'tool', 'content' => '{"temp":24}', 'tool_name' => 'get_weather'], $m[3]);

        $send('tool-claude');
        $m = $this->sent()['body']['messages'];
        $this->assertCount(3, $m, 'both tool results merge into one user turn');
        $this->assertSame('tool_use', $m[1]['content'][0]['type']);
        $this->assertSame(['city' => 'Kandy'], $m[1]['content'][1]['input']);
        $this->assertSame('user', $m[2]['role']);
        $this->assertSame(['tool_result', 'tool_result'], array_column($m[2]['content'], 'type'));
        $this->assertSame('call_2', $m[2]['content'][1]['tool_use_id']);

        $send('tool-gemini');
        $c = $this->sent()['body']['contents'];
        $this->assertSame('model', $c[1]['role']);
        $this->assertSame('get_weather', $c[1]['parts'][0]['functionCall']['name']);
        $this->assertSame(['name' => 'get_weather', 'response' => ['temp' => 24]], $c[2]['parts'][1]['functionResponse'], 'name looked up from the call id');

        $this->db->table('models')->where('name', 'fake-chat')->where('server_id', 1)->delete();
        $this->db->table('models')->insert(['server_id' => 2, 'name' => 'echo', 'status' => 'available']);
        $this->api('post', 'chat/completions', ['model' => 'echo', 'messages' => $history]);
        $m = $this->sent()['body']['messages'];
        $this->assertSame('call_2', $m[1]['tool_calls'][1]['id']);
        $this->assertNull($m[1]['content']);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temp":31}'], $m[2]);
    }

    public function testToolValidation(): void
    {
        $err = fn (array $extra) => $this->body($this->api('post', 'chat/completions', $extra + ['model' => 'fake-chat', 'messages' => [['role' => 'user', 'content' => 'x']]]))['error'] ?? null;
        $this->assertSame('tools', $err(['tools' => [['type' => 'function', 'function' => ['name' => 'bad name!']]]])['param']);
        $this->assertSame('tool_choice', $err(['tools' => self::TOOLS, 'tool_choice' => ['type' => 'function', 'function' => ['name' => 'other']]])['param']);
        $this->assertSame('functions', $err(['functions' => [['name' => 'f']]])['param']);
        $this->assertSame('invalid_value', $err(['messages' => [['role' => 'tool', 'content' => 'x']]])['code'], 'a tool message needs tool_call_id');
    }

    // ---- Speech to text ------------------------------------------------------------------------

    private function transcribe(array $fields)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aud');
        file_put_contents($tmp, str_repeat("\0", 2048));
        service('superglobals')->setFilesArray(['file' => ['name' => 'a.wav', 'type' => 'audio/wav', 'tmp_name' => $tmp, 'error' => 0, 'size' => 2048]]);
        try {
            return $this->withHeaders($this->auth())->post('api/openai/v1/audio/transcriptions', $fields);
        } finally {
            service('superglobals')->setFilesArray([]);
            @unlink($tmp);
        }
    }

    public function testTranscriptionsInEveryFormat(): void
    {
        $res = $this->transcribe(["model" => "whisper-1", "language" => "si-LK"]);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame(['text' => 'ආයුබෝවන් ලංකා'], $this->body($res));
        $sent = $this->sent();
        $this->assertSame(['sinhala', 'si', 2048], [$sent['query']['model'], $sent['query']['language'], $sent['bytes']], 'whisper-1 → the default speech model; the audio is passed through');

        $v = $this->body($this->transcribe(['model' => 'whisper:clinic', 'response_format' => 'verbose_json', 'timestamp_granularities' => ['word', 'segment']]));
        $this->assertSame('clinic', $this->sent()['query']['model']);
        $this->assertSame('1', (string) $this->sent()['query']['words']);
        $this->assertSame('auto', $this->sent()['query']['language'], 'no language: detect it');
        $this->assertSame(2.5, $v['duration']);
        $this->assertSame([0, 1], array_column($v['segments'], 'id'));
        $this->assertSame('ආයුබෝවන්', $v['words'][0]['word']);

        $srt = (string) $this->transcribe(['response_format' => 'srt'])->response()->getBody();
        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:01,200\nආයුබෝවන්\n\n2\n00:00:01,200 --> 00:00:02,500\nලංකා", $srt);
        $vtt = (string) $this->transcribe(['response_format' => 'vtt'])->response()->getBody();
        $this->assertStringStartsWith("WEBVTT\n\n00:00:00.000 --> 00:00:01.200", $vtt);
        $this->assertSame("ආයුබෝවන් ලංකා\n", (string) $this->transcribe(['response_format' => 'text'])->response()->getBody());

        $log = $this->db->table('api_logs')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame(['openai/audio.transcriptions', 'whisper:sinhala', 200], [$log['endpoint'], $log['model'], (int) $log['status']]);
    }

    public function testTranscriptionAccessAndDefaults(): void
    {
        $this->db->table('model_defaults')->insert(['task' => 'transcription', 'application' => '', 'model_name' => 'whisper:clinic']);
        $this->transcribe([]);
        $this->assertSame('clinic', $this->sent()['query']['model'], 'no model: the registry default');

        $this->db->table('model_registry')->insert(['name' => 'whisper:clinic', 'type' => 'stt', 'access_roles' => json_encode(['developer']), 'enabled' => 1]);
        $this->transcribe(['model' => 'whisper:clinic'])->assertStatus(403);
        $this->assertSame('response_format', $this->body($this->transcribe(['model' => 'whisper:sinhala', 'response_format' => 'bad']))['error']['param']);
        $this->transcribe(['model' => '../etc'])->assertStatus(400);

        $ids = array_column($this->body($this->api('get', 'models'))['data'], 'id');
        $this->assertContains('whisper:sinhala', $ids);
        $this->assertNotContains('whisper:turbo', $ids, 'not installed on the speech service');
        $this->assertNotContains('whisper:tiny', $ids, 'a built-in the running service does not offer');
        $this->assertNotContains('whisper:clinic', $ids, 'restricted to another role');

        $this->db->table('api_keys')->update(['scopes' => 'chat']);
        $this->transcribe([])->assertStatus(403);
    }

    // ---- Model load policy (audit B2) -------------------------------------------------------------

    public function testAutoloadUsesWhatIsReallyInMemory(): void
    {
        @unlink(self::$log . '.generate');
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['pinned-model' => [1, null], 'idle-model' => [0, 10], 'vllm-pinned' => [1, null]] as $name => [$keep, $idle]) {
            $sid = $name === 'vllm-pinned' ? 2 : 3;
            $this->db->table('models')->insert(['server_id' => $sid, 'name' => $name, 'status' => 'available']);
            $this->db->table('model_registry')->insert(['name' => $name, 'keep_loaded' => $keep, 'idle_unload_minutes' => $idle, 'enabled' => 1]);
            $this->db->table('model_assignments')->insert(['registry_id' => $this->db->insertID(), 'server_id' => $sid, 'state' => 'deployed']);
        }
        $this->db->table('servers')->where('id', 3)->update(['kind' => 'ollama']);
        $this->db->table('servers')->where('id', 2)->update(['kind' => 'vllm']);
        $this->db->table('servers')->update(['is_enabled' => 1]);

        $out = (new \App\Services\ModelRegistryService())->autoload();
        $this->assertSame(['pinned-model@ollama'], $out['loaded'], 'pinned and not in memory: loaded; the vLLM node is left alone');
        $this->assertSame(['idle-model@ollama'], $out['unloaded'], 'in memory (per /api/ps) and unused for 10 minutes: unloaded');
        $calls = array_map('json_decode', array_filter(explode("\n", (string) @file_get_contents(self::$log . '.generate'))));
        $this->assertEquals([['pinned-model', -1], ['idle-model', 0]], array_map(static fn ($c) => [$c->model, $c->keep_alive], $calls));
        \App\Services\ModelRegistryService::forgetPinned();
        $this->assertTrue(\App\Services\ModelRegistryService::isPinned('pinned-model'));
    }

    /** Audit B4, B10, B11. */
    public function testUsageEstimatesFinishReasonAndEmbeddingOptions(): void
    {
        $this->db->table('models')->insert(['server_id' => 2, 'name' => 'nousage', 'status' => 'available']);
        $b = $this->body($this->api('post', 'chat/completions', ['model' => 'nousage', 'messages' => [['role' => 'user', 'content' => str_repeat('a', 40)]]]));
        $this->assertGreaterThan(0, $b['usage']['prompt_tokens'], 'no usage from the engine: estimated, not free');
        $this->assertGreaterThan(0, $b['usage']['completion_tokens']);
        $this->assertGreaterThan(0, (int) $this->db->table('api_logs')->orderBy('id', 'DESC')->get()->getRow()->total_tokens);

        $b = $this->body($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'max_tokens' => 3, 'messages' => [['role' => 'user', 'content' => 'x']]]));
        $this->assertSame('length', $b['choices'][0]['finish_reason'], 'the engine said it hit the limit');

        $e = $this->body($this->api('post', 'embeddings', ['model' => 'fake-embed', 'input' => ['a', 'b'], 'dimensions' => 2]));
        $v = $e['data'][1]['embedding'];
        $this->assertCount(2, $v);
        $this->assertEqualsWithDelta(1.0, sqrt($v[0] ** 2 + $v[1] ** 2), 1e-9, 'shortened and re-normalised');
        $err = $this->body($this->api('post', 'embeddings', ['model' => 'fake-embed', 'input' => [[101, 202]]]))['error'];
        $this->assertSame('unsupported_value', $err['code']);
    }

    /** Gap 3: quota headers, the per-key reply cap, and a heads-up at 80 %. */
    public function testQuotaHeadersCapAndWarning(): void
    {
        $this->db->table('api_keys')->update(['daily_token_limit' => 100, 'max_output_tokens' => 64]);
        $this->db->table('api_logs')->insert(['api_key_id' => 1, 'endpoint' => 'chat', 'total_tokens' => 85, 'status' => 200, 'created_at' => date('Y-m-d H:i:s')]);

        $res = $this->api('post', 'chat/completions', ['model' => 'fake-chat', 'max_tokens' => 500, 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $echo = json_decode(substr($this->body($res)['choices'][0]['message']['content'], 6), true);
        $this->assertSame(64, $echo['max'], 'the key caps reply length');
        $r = $res->response();
        $this->assertSame('100', $r->getHeaderLine('x-ratelimit-limit-tokens'));
        $this->assertSame('3', $r->getHeaderLine('x-ratelimit-remaining-tokens'), 'what is left after this call (85 + 12 of 100)');
        $this->assertSame('60', $r->getHeaderLine('x-ratelimit-limit-requests'));
        $this->assertStringEndsWith('s', $r->getHeaderLine('x-ratelimit-reset-tokens'));

        $n = $this->db->table('notifications')->get()->getResultArray();
        $this->assertCount(1, $n, 'one heads-up at 80 % of the daily quota');
        $this->assertStringContainsString('80%', $n[0]['title']);
        $this->api('post', 'chat/completions', ['model' => 'fake-chat', 'messages' => [['role' => 'user', 'content' => 'x']]]);
        $this->assertSame(1, $this->db->table('notifications')->countAllResults(), 'not repeated in the same day');
    }

    /** Gap 5: n > 1, the user field, translations, and clear answers for what is not served. */
    public function testMoreOpenAiCompatibility(): void
    {
        $b = $this->body($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'n' => 3, 'user' => 'end-user-42', 'messages' => [['role' => 'user', 'content' => 'x']]]));
        $this->assertSame([0, 1, 2], array_column($b['choices'], 'index'));
        $this->assertSame(36, $b['usage']['total_tokens'], 'usage covers every answer');
        $this->assertSame('end-user-42', $this->db->table('api_logs')->orderBy('id', 'DESC')->get()->getRow()->end_user);

        $this->assertSame('n', $this->body($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'n' => 2, 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'x']]]))['error']['param']);
        $this->assertSame('logprobs', $this->body($this->api('post', 'chat/completions', ['model' => 'fake-chat', 'logprobs' => true, 'messages' => [['role' => 'user', 'content' => 'x']]]))['error']['param']);
        $this->api('post', 'audio/speech', ['model' => 'tts-1', 'input' => 'hi', 'voice' => 'alloy'])->assertStatus(501);

        $res = $this->transcribe(['model' => 'whisper-1', 'language' => 'si']);
        $res->assertStatus(200);
        $tmp = tempnam(sys_get_temp_dir(), 'aud');
        file_put_contents($tmp, str_repeat("\0", 2048));
        service('superglobals')->setFilesArray(['file' => ['name' => 'a.wav', 'type' => 'audio/wav', 'tmp_name' => $tmp, 'error' => 0, 'size' => 2048]]);
        $this->withHeaders($this->auth())->post('api/openai/v1/audio/translations', ['model' => 'whisper-1'])->assertStatus(200);
        service('superglobals')->setFilesArray([]);
        @unlink($tmp);
        $q = $this->sent()['query'];
        $this->assertSame(['translate', 'small', 'auto'], [$q['task'], $q['model'], $q['language']], 'translation: multilingual model, any language in, English out');
    }
}
