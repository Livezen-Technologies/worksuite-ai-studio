<?php
// A tiny multi-protocol inference engine for gateway tests (run with `php -S`). Speaks the OpenAI
// API (/v1/*), Ollama (/api/chat), Anthropic (/v1/messages) and Gemini (/v1beta/models/*), and
// writes each request body to $FAKE_ENGINE_LOG so tests can check what the gateway sent.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw  = (string) file_get_contents('php://input');
$in   = json_decode($raw, true) ?: [];
if ($log = getenv('FAKE_ENGINE_LOG')) file_put_contents($log, json_encode(['path' => $path, 'body' => $in]));
$sse = static function (array $events): void {
    header('Content-Type: text/event-stream');
    foreach ($events as $e) echo 'data: ' . json_encode($e) . "\n\n";
};

if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => [['id' => 'fake-chat'], ['id' => 'fake-embed']]]);
} elseif ($path === '/v1/chat/completions') {
    if (($in['model'] ?? '') === 'broken') { http_response_code(500); echo json_encode(['error' => ['message' => 'engine exploded']]); return; }
    if (! empty($in['tools'])) { // a streamed tool call, split across deltas the way OpenAI does
        $sse([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"city":']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"Colombo"}']]]]]]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 9]],
        ]);
        echo "data: [DONE]\n\n";
        return;
    }
    $echo = 'Hello ' . json_encode(['t' => $in['temperature'] ?? null, 'max' => $in['max_tokens'] ?? null, 'json' => $in['response_format']['type'] ?? null,
        'last' => end($in['messages'])['content'] ?? null]);
    $events = array_map(static fn ($p) => ['choices' => [['delta' => ['content' => $p]]]], str_split($echo, 8));
    if (($in['max_tokens'] ?? null) === 3) $events[] = ['choices' => [['delta' => new stdClass(), 'finish_reason' => 'length']]];
    if (($in['model'] ?? '') !== 'nousage') $events[] = ['choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 5]];
    $sse($events);
    echo "data: [DONE]\n\n";
} elseif ($path === '/v1/embeddings') {
    header('Content-Type: application/json');
    $inputs = (array) ($in['input'] ?? []);
    echo json_encode(['data' => array_map(static fn ($i) => ['embedding' => [0.5, -1.0, (float) $i]], array_keys($inputs))]);
} elseif ($path === '/api/chat') { // Ollama, NDJSON
    header('Content-Type: application/x-ndjson');
    if (! empty($in['tools'])) {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Kandy']]]]], 'done' => false]) . "\n";
    } else {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'It is sunny.'], 'done' => false]) . "\n";
    }
    echo json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 11, 'eval_count' => 4]) . "\n";
} elseif ($path === '/api/ps') { // Ollama: what is in memory
    header('Content-Type: application/json');
    echo json_encode(['models' => [['name' => 'idle-model', 'model' => 'idle-model']]]);
} elseif ($path === '/api/generate') { // Ollama load / unload (keep_alive) — appended to the log
    if ($log) file_put_contents($log . '.generate', json_encode($in) . "\n", FILE_APPEND);
    header('Content-Type: application/json');
    echo json_encode(['done' => true]);
} elseif ($path === '/v1/messages') { // Anthropic
    $events = [['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 30]]]];
    if (! empty($in['tools'])) {
        $events[] = ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']];
        $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Checking.']];
        $events[] = ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => new stdClass()]];
        $events[] = ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city": "Ga']];
        $events[] = ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'lle"}']];
    } else {
        $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Done.']];
    }
    $events[] = ['type' => 'message_delta', 'usage' => ['output_tokens' => 12]];
    $sse($events);
} elseif (str_starts_with($path, '/v1beta/models/')) { // Gemini
    $parts = ! empty($in['tools']) ? [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Jaffna']]]] : [['text' => 'Noted.']];
    $sse([['candidates' => [['content' => ['parts' => $parts]]], 'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 3]]]);
} elseif ($path === '/api/models') { // Hugging Face hub search
    if ($log) file_put_contents($log, json_encode(['path' => $path, 'query' => $_GET]));
    header('Content-Type: application/json');
    echo json_encode([
        ['id' => 'Qwen/Qwen2.5-7B-Instruct', 'downloads' => 900000, 'likes' => 800, 'tags' => ['transformers', 'license:apache-2.0'], 'pipeline_tag' => 'text-generation'],
        ['id' => 'bartowski/Qwen2.5-7B-Instruct-GGUF', 'downloads' => 300000, 'likes' => 90, 'tags' => ['gguf', 'license:apache-2.0'], 'gated' => false],
        ['id' => '../../etc/passwd', 'downloads' => 1],
    ]);
} elseif ($path === '/health') { // the speech service (deploy/asr/asr_service.py)
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'model' => 'Whisper Small · Sinhala', 'models' => [
        ['value' => 'sinhala', 'label' => 'Whisper Small · Sinhala', 'note' => 'Default.', 'installed' => true],
        ['value' => 'turbo', 'label' => 'Whisper Large v3 Turbo', 'note' => '', 'installed' => false],
        ['value' => 'clinic', 'label' => 'Clinic dictation', 'note' => 'Custom.', 'installed' => true],
    ]]);
} elseif ($path === '/transcribe') {
    if ($log) file_put_contents($log, json_encode(['path' => $path, 'query' => $_GET, 'bytes' => strlen($raw)]));
    header('Content-Type: application/json');
    $w = ! empty($_GET['words']);
    echo json_encode(['text' => 'ආයුබෝවන් ලංකා', 'language' => 'si', 'duration' => 2.5, 'segments' => [
        ['start' => 0.0, 'end' => 1.2, 'text' => 'ආයුබෝවන්'] + ($w ? ['words' => [['start' => 0.0, 'end' => 1.2, 'word' => 'ආයුබෝවන්']]] : []),
        ['start' => 1.2, 'end' => 2.5, 'text' => 'ලංකා'],
    ]]);
} else {
    http_response_code(404);
}
