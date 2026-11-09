<?php
// A tiny OpenAI-compatible inference engine for gateway tests (run with `php -S`).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$in   = json_decode((string) file_get_contents('php://input'), true) ?: [];
if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => [['id' => 'fake-chat'], ['id' => 'fake-embed']]]);
} elseif ($path === '/v1/chat/completions') {
    if (($in['model'] ?? '') === 'broken') { http_response_code(500); echo json_encode(['error' => ['message' => 'engine exploded']]); return; }
    header('Content-Type: text/event-stream');
    // Echo what the gateway passed so tests can check parameter mapping.
    $echo = 'Hello ' . json_encode(['t' => $in['temperature'] ?? null, 'max' => $in['max_tokens'] ?? null, 'json' => $in['response_format']['type'] ?? null,
        'last' => end($in['messages'])['content'] ?? null]);
    foreach (str_split($echo, 8) as $piece) echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $piece]]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 5]]) . "\n\n";
    echo "data: [DONE]\n\n";
} elseif ($path === '/v1/embeddings') {
    header('Content-Type: application/json');
    $inputs = (array) ($in['input'] ?? []);
    echo json_encode(['data' => array_map(static fn ($i) => ['embedding' => [0.5, -1.0, (float) $i]], array_keys($inputs))]);
} else {
    http_response_code(404);
}
