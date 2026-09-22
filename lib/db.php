<?php
declare(strict_types=1);

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $dbPath = $config['db_path'];
    $dataDir = dirname($dbPath);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            description TEXT NOT NULL,
            category TEXT NOT NULL CHECK (category IN (\'personal\', \'work\')),
            client TEXT,
            priority TEXT NOT NULL DEFAULT \'normal\' CHECK (priority IN (\'urgent\', \'normal\', \'relaxed\')),
            due_at TEXT,
            recurrence TEXT,
            done INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )
    ');

    // Migrate older databases created before priority/due_at/recurrence existed.
    $existingColumns = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $migrations = [
        'priority' => "ALTER TABLE tasks ADD COLUMN priority TEXT NOT NULL DEFAULT 'normal'",
        'due_at' => 'ALTER TABLE tasks ADD COLUMN due_at TEXT',
        'recurrence' => 'ALTER TABLE tasks ADD COLUMN recurrence TEXT',
    ];
    foreach ($migrations as $column => $sql) {
        if (!in_array($column, $existingColumns, true)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS task_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            note TEXT NOT NULL,
            minutes INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )
    ');

    // Migrate older databases created before minutes existed.
    $existingNoteColumns = array_column($pdo->query('PRAGMA table_info(task_notes)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('minutes', $existingNoteColumns, true)) {
        $pdo->exec('ALTER TABLE task_notes ADD COLUMN minutes INTEGER');
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS task_chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK (role IN (\'user\', \'model\')),
            content TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )
    ');

    return $pdo;
}
