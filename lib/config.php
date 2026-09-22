<?php
declare(strict_types=1);

// GEMINI_API_KEY can come from a real environment variable, or from lib/.env
// (not web-accessible, kept out of version control).
require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/.env');

return [
    'db_path' => __DIR__ . '/data/todo.sqlite',
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
    'gemini_model' => 'gemini-flash-latest',
];
