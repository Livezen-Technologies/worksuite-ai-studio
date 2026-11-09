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
        $f = \Config\Database::forge($this->DBGroup);
        foreach (['users', 'roles', 'api_keys', 'api_logs', 'servers', 'models', 'model_registry', 'model_assignments', 'model_defaults', 'settings'] as $t) $f->dropTable($t, true);
        $id = ['id' => ['type' => 'INTEGER', 'auto_increment' => true]];
        $null = static fn (string $type = 'VARCHAR') => ['type' => $type, 'null' => true];
        $f->addField($id + ['name' => $null(), 'status' => $null(), 'role_id' => $null('INTEGER'), 'deleted_at' => $null('DATETIME')])->addKey('id', true)->createTable('users');
        $f->addField($id + ['name' => $null()])->addKey('id', true)->createTable('roles');
        $f->addField($id + ['name' => $null(), 'user_id' => $null('INTEGER'), 'api_key' => $null(), 'secret_hash' => $null(), 'scopes' => $null(), 'allowed_models' => $null('TEXT'),
            'allowed_ips' => $null('TEXT'), 'rate_limit_per_min' => ['type' => 'INTEGER', 'default' => 60], 'status' => $null(), 'daily_token_limit' => $null('INTEGER'),
            'monthly_token_limit' => $null('INTEGER'), 'expires_at' => $null('DATETIME'), 'last_used_at' => $null('DATETIME'), 'created_at' => $null('DATETIME'),
            'updated_at' => $null('DATETIME'), 'revoked_at' => $null('DATETIME'), 'token_version' => ['type' => 'INTEGER', 'default' => 0], 'application' => $null()])
            ->addKey('id', true)->createTable('api_keys');
        $f->addField($id + ['api_key_id' => $null('INTEGER'), 'user_id' => $null('INTEGER'), 'endpoint' => $null(), 'model' => $null(), 'server_id' => $null('INTEGER'),
            'method' => $null(), 'prompt_tokens' => $null('INTEGER'), 'completion_tokens' => $null('INTEGER'), 'total_tokens' => $null('INTEGER'),
            'processing_ms' => $null('INTEGER'), 'status' => $null('INTEGER'), 'client_ip' => $null(), 'country' => $null(), 'error' => $null(), 'created_at' => $null('DATETIME')])
            ->addKey('id', true)->createTable('api_logs');
        $f->addField($id + ['name' => $null(), 'kind' => $null(), 'api_url' => $null(), 'auth_token' => $null(), 'status' => $null(), 'health_score' => ['type' => 'INTEGER', 'default' => 100],
            'is_enabled' => ['type' => 'INTEGER', 'default' => 1], 'deleted_at' => $null('DATETIME'), 'gpu_vram_gb' => $null('REAL'), 'vram_used_gb' => $null('REAL')])->addKey('id', true)->createTable('servers');
        $f->addField($id + ['server_id' => $null('INTEGER'), 'name' => $null(), 'parameter_size' => $null(), 'quantization' => $null(), 'size_bytes' => $null('INTEGER'), 'status' => $null()])->addKey('id', true)->createTable('models');
        $f->addField($id + ['name' => $null(), 'display_name' => $null(), 'type' => $null(), 'version' => $null(), 'family' => $null(), 'parameter_size' => $null(),
            'quantization' => $null(), 'vram_gb' => $null('REAL'), 'context_length' => $null('INTEGER'), 'capabilities' => $null('TEXT'), 'engine' => $null(),
            'source' => $null(), 'description' => $null(), 'access_roles' => $null('TEXT'), 'keep_loaded' => ['type' => 'INTEGER', 'default' => 0],
            'idle_unload_minutes' => $null('INTEGER'), 'enabled' => ['type' => 'INTEGER', 'default' => 1], 'created_by' => $null('INTEGER'),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_registry');
        $f->addField($id + ['registry_id' => $null('INTEGER'), 'server_id' => $null('INTEGER'), 'state' => $null(), 'job_id' => $null('INTEGER'), 'error' => $null(),
            'created_at' => $null('DATETIME'), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_assignments');
        $f->addField($id + ['task' => $null(), 'application' => ['type' => 'VARCHAR', 'default' => ''], 'model_name' => $null(), 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('model_defaults');
        $f->addField($id + ['group' => $null(), 'key' => $null(), 'value' => $null('TEXT'), 'is_secret' => ['type' => 'INTEGER', 'default' => 0], 'updated_at' => $null('DATETIME')])->addKey('id', true)->createTable('settings');

        $this->db->table('roles')->insertBatch([['name' => 'customer'], ['name' => 'developer']]);
        $this->db->table('users')->insert(['name' => 'Ann', 'status' => 'active', 'role_id' => 1]);
        $this->db->table('api_keys')->insert(['name' => 'k', 'user_id' => 1, 'api_key' => 'ohub_k1', 'secret_hash' => password_hash('sk_s', PASSWORD_DEFAULT),
            'scopes' => 'chat,generate,embeddings,models', 'status' => 'active', 'application' => 'HR portal']);
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
}
