<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/gemini.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

$task = get_task($taskId);
if ($task === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
    exit;
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message is empty']);
    exit;
}

add_chat_message($taskId, 'user', $message);
$history = get_chat_messages($taskId);
$reply = get_gemini_reply($history);
add_chat_message($taskId, 'model', $reply);

$updated = get_chat_messages($taskId);
$userEntry = $updated[count($updated) - 2];
$replyEntry = $updated[count($updated) - 1];

echo json_encode([
    'user' => ['content' => $userEntry['content'], 'time' => format_datetime((string) $userEntry['created_at'])],
    'reply' => ['content' => $replyEntry['content'], 'time' => format_datetime((string) $replyEntry['created_at'])],
]);
