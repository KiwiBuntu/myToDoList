<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/functions.php';

$noteId = (int) ($_GET['note_id'] ?? 0);
$note = get_note($noteId);

if ($note === null || empty($note['attachment_path'])) {
    http_response_code(404);
    exit('Not found');
}

$config = require __DIR__ . '/../lib/config.php';
$path = $config['uploads_dir'] . '/' . $note['attachment_path'];

if (!is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$mime = (string) ($note['attachment_mime'] ?: 'application/octet-stream');
$displayName = str_replace(['"', "\r", "\n"], '', (string) $note['attachment_name']);
$disposition = str_starts_with($mime, 'image/') || $mime === 'application/pdf' ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $displayName . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
