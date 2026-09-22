<?php
declare(strict_types=1);

function build_help_starter_prompt(array $task): string
{
    $context = '';
    if (!empty($task['due_at'])) {
        $context .= ' It is due ' . format_due((string) $task['due_at']) . '.';
    }
    if (!empty($task['priority']) && $task['priority'] !== 'normal') {
        $context .= ' Priority: ' . $task['priority'] . '.';
    }

    return 'I have a to-do item: "' . $task['description'] . '".' . $context . ' '
        . 'Give me brief, practical guidance on how to approach or break down this task.';
}

// $history is a list of ['role' => 'user'|'model', 'content' => string], oldest first.
function get_gemini_reply(array $history): string
{
    $config = require __DIR__ . '/config.php';
    $apiKey = $config['gemini_api_key'];

    if ($apiKey === '') {
        return 'No Gemini API key configured. Set the GEMINI_API_KEY environment variable to enable this feature.';
    }

    $model = $config['gemini_model'];
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $contents = array_map(
        fn (array $message) => [
            'role' => $message['role'],
            'parts' => [['text' => $message['content']]],
        ],
        $history
    );

    $payload = json_encode(['contents' => $contents]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return "Couldn't reach Gemini: {$curlError}";
    }

    $data = json_decode($response, true);

    if ($status !== 200) {
        $message = $data['error']['message'] ?? "HTTP {$status}";
        return "Gemini error: {$message}";
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    return $text !== null ? trim($text) : 'Gemini returned no guidance for this task.';
}
