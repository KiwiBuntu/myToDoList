<?php
declare(strict_types=1);

// GEMINI_API_KEY can come from a real environment variable, or from lib/.env
// (not web-accessible, kept out of version control).
require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/.env');

// PHP defaults to UTC regardless of the system clock. Match the server's
// actual timezone so due-date comparisons (and SQLite's 'localtime' values)
// agree on what "today" and "now" mean.
$systemTimezone = trim((string) @file_get_contents('/etc/timezone'));
date_default_timezone_set($systemTimezone !== '' ? $systemTimezone : 'UTC');

return [
    'db_path' => __DIR__ . '/data/todo.sqlite',
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
    'gemini_model' => 'gemini-flash-latest',
];
