<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PRIORITIES = ['urgent', 'normal', 'relaxed'];
const RECURRENCES = ['daily', 'weekly', 'monthly', 'yearly'];

function add_task(
    string $description,
    string $category,
    ?string $client,
    string $priority = 'normal',
    ?string $dueAt = null,
    ?string $recurrence = null
): void {
    $description = trim($description);
    if ($description === '') {
        return;
    }
    if (!in_array($category, ['personal', 'work'], true)) {
        $category = 'personal';
    }
    $client = trim((string) $client);
    $client = $client === '' ? null : $client;

    if (!in_array($priority, PRIORITIES, true)) {
        $priority = 'normal';
    }

    $dueAt = trim((string) $dueAt);
    $dueAt = $dueAt === '' ? null : $dueAt;

    // Recurrence only makes sense with a due date to count forward from.
    $recurrence = $dueAt !== null && in_array($recurrence, RECURRENCES, true) ? $recurrence : null;

    $stmt = get_db()->prepare(
        'INSERT INTO tasks (description, category, client, priority, due_at, recurrence)
         VALUES (:description, :category, :client, :priority, :due_at, :recurrence)'
    );
    $stmt->execute([
        'description' => $description,
        'category' => $category,
        'client' => $client,
        'priority' => $priority,
        'due_at' => $dueAt,
        'recurrence' => $recurrence,
    ]);
}

// Builds a SQL "IN (...)" placeholder list and adds the values to $params by reference.
function in_clause(array $values, string $prefix, array &$params): string
{
    $placeholders = [];
    foreach (array_values($values) as $i => $value) {
        $key = "{$prefix}{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $value;
    }
    return implode(', ', $placeholders);
}

function get_tasks(string $filter = 'open', array $categories = [], array $priorities = [], array $clients = []): array
{
    $sql = "
        SELECT
            tasks.*,
            (SELECT COUNT(*) FROM task_notes WHERE task_notes.task_id = tasks.id) AS note_count,
            COALESCE(
                (SELECT MAX(created_at) FROM task_notes WHERE task_notes.task_id = tasks.id),
                tasks.created_at
            ) AS last_activity
        FROM tasks
    ";

    $where = [];
    $params = [];

    if ($filter === 'open') {
        $where[] = 'done = 0';
    } elseif ($filter === 'done') {
        $where[] = 'done = 1';
    }
    if ($categories) {
        $where[] = 'category IN (' . in_clause($categories, 'cat', $params) . ')';
    }
    if ($priorities) {
        $where[] = 'priority IN (' . in_clause($priorities, 'pri', $params) . ')';
    }
    if ($clients) {
        $where[] = 'client IN (' . in_clause($clients, 'cli', $params) . ')';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= "
        ORDER BY
            done ASC,
            CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 WHEN 'relaxed' THEN 2 ELSE 1 END ASC,
            (due_at IS NULL) ASC,
            due_at ASC,
            created_at DESC
    ";

    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_distinct_clients(): array
{
    return get_db()
        ->query('SELECT DISTINCT client FROM tasks WHERE client IS NOT NULL ORDER BY client')
        ->fetchAll(PDO::FETCH_COLUMN);
}

function get_task(int $id): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM tasks WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    return $task === false ? null : $task;
}

// Completing a recurring task rolls its due date forward and leaves it open,
// so recurring items stay a single row instead of piling up history.
function toggle_task(int $id): void
{
    $task = get_task($id);
    if ($task === null) {
        return;
    }

    $completing = (int) $task['done'] === 0;

    if ($completing && $task['recurrence'] && $task['due_at']) {
        $nextDue = compute_next_due((string) $task['due_at'], (string) $task['recurrence']);
        get_db()
            ->prepare('UPDATE tasks SET due_at = :due_at WHERE id = :id')
            ->execute(['due_at' => $nextDue, 'id' => $id]);
        return;
    }

    get_db()->prepare('UPDATE tasks SET done = 1 - done WHERE id = :id')->execute(['id' => $id]);
}

function compute_next_due(string $dueAt, string $recurrence): string
{
    $intervals = [
        'daily' => '+1 day',
        'weekly' => '+1 week',
        'monthly' => '+1 month',
        'yearly' => '+1 year',
    ];

    $date = new DateTime($dueAt);
    $date->modify($intervals[$recurrence] ?? '+1 day');

    // Preserve whether the original due date carried a time component.
    $hasTime = str_contains($dueAt, 'T');
    return $date->format($hasTime ? 'Y-m-d\TH:i' : 'Y-m-d');
}

function delete_task(int $id): void
{
    foreach (get_task_notes($id) as $note) {
        if (!empty($note['attachment_path'])) {
            delete_attachment_file((string) $note['attachment_path']);
        }
    }

    get_db()->prepare('DELETE FROM task_notes WHERE task_id = :id')->execute(['id' => $id]);
    get_db()->prepare('DELETE FROM task_chats WHERE task_id = :id')->execute(['id' => $id]);
    get_db()->prepare('DELETE FROM tasks WHERE id = :id')->execute(['id' => $id]);
}

function add_chat_message(int $taskId, string $role, string $content): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO task_chats (task_id, role, content) VALUES (:task_id, :role, :content)'
    );
    $stmt->execute(['task_id' => $taskId, 'role' => $role, 'content' => $content]);
}

function get_chat_messages(int $taskId): array
{
    $stmt = get_db()->prepare('SELECT * FROM task_chats WHERE task_id = :task_id ORDER BY created_at ASC, id ASC');
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

const TIME_INCREMENTS = [15, 30, 45, 60];
const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024; // 15MB, under PHP's upload_max_filesize/post_max_size

function add_note(int $taskId, string $note, ?int $minutes = null): ?int
{
    $note = trim($note);
    if ($note === '' || get_task($taskId) === null) {
        return null;
    }

    if (!in_array($minutes, TIME_INCREMENTS, true)) {
        $minutes = null;
    }

    $stmt = get_db()->prepare('INSERT INTO task_notes (task_id, note, minutes) VALUES (:task_id, :note, :minutes)');
    $stmt->execute(['task_id' => $taskId, 'note' => $note, 'minutes' => $minutes]);

    return (int) get_db()->lastInsertId();
}

// $file is one entry from $_FILES. Silently skips on any problem (missing
// file, too large, upload error) so the note text itself is never lost.
function attach_file_to_note(int $noteId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return;
    }
    if (!is_uploaded_file($file['tmp_name']) || (int) $file['size'] > MAX_ATTACHMENT_BYTES) {
        return;
    }

    $config = require __DIR__ . '/config.php';
    $uploadDir = $config['uploads_dir'];
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0770, true);
    }

    $originalName = basename((string) $file['name']);
    $extension = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($originalName, PATHINFO_EXTENSION));
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? ".{$extension}" : '');
    $destination = $uploadDir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return;
    }

    $mime = @mime_content_type($destination) ?: 'application/octet-stream';

    $stmt = get_db()->prepare(
        'UPDATE task_notes
         SET attachment_path = :path, attachment_name = :name, attachment_size = :size, attachment_mime = :mime
         WHERE id = :id'
    );
    $stmt->execute([
        'path' => $storedName,
        'name' => $originalName,
        'size' => (int) $file['size'],
        'mime' => $mime,
        'id' => $noteId,
    ]);
}

function delete_attachment_file(string $storedName): void
{
    $config = require __DIR__ . '/config.php';
    $path = $config['uploads_dir'] . '/' . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function get_note(int $id): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM task_notes WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    return $note === false ? null : $note;
}

function get_task_notes(int $taskId): array
{
    $stmt = get_db()->prepare('SELECT * FROM task_notes WHERE task_id = :task_id ORDER BY created_at DESC');
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

function format_datetime(string $dateTime): string
{
    return (new DateTime($dateTime))->format('j M, g:ia');
}

// Renders a practical subset of Markdown (headings, bold/italic, inline and
// fenced code, links, bullet/numbered lists, blockquotes, paragraphs) from an
// AI reply into safe HTML. Escapes first, then only ever inserts our own
// fixed tags around already-escaped text — never trusts the model's output
// to already be safe HTML.
function format_chat_markdown(string $content): string
{
    // Pull out fenced code blocks before anything else touches the text, so
    // their contents are escaped but never reinterpreted as markdown.
    $codeBlocks = [];
    $content = preg_replace_callback(
        '/```[a-zA-Z0-9]*\n?(.*?)```/s',
        function (array $match) use (&$codeBlocks) {
            $codeBlocks[] = '<pre><code>' . htmlspecialchars(trim($match[1], "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            return "\x01CODEBLOCK" . (count($codeBlocks) - 1) . "\x01";
        },
        $content
    );

    $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $escaped);
    $escaped = preg_replace(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
        '<a href="$2" target="_blank" rel="noopener">$1</a>',
        $escaped
    );

    $html = '';
    $paragraph = [];
    $listType = null;
    $listItems = [];

    $flushParagraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        }
    };
    $flushList = function () use (&$listType, &$listItems, &$html) {
        if ($listType === 'ul') {
            $html .= '<ul>' . implode('', array_map(fn ($item) => "<li>{$item}</li>", $listItems)) . '</ul>';
        } elseif ($listType === 'ol') {
            $html .= '<ol>' . implode('', array_map(fn ($item) => "<li>{$item}</li>", $listItems)) . '</ol>';
        } elseif ($listType === 'blockquote') {
            $html .= '<blockquote>' . implode('<br>', $listItems) . '</blockquote>';
        }
        $listType = null;
        $listItems = [];
    };

    foreach (explode("\n", $escaped) as $line) {
        $line = trim($line);

        if ($line === '') {
            $flushList();
            $flushParagraph();
        } elseif (preg_match('/^\x01CODEBLOCK(\d+)\x01$/', $line, $m)) {
            $flushList();
            $flushParagraph();
            $html .= $codeBlocks[(int) $m[1]];
        } elseif (preg_match('/^#{1,6}\s+(.*)/', $line, $m)) {
            $flushList();
            $flushParagraph();
            $html .= '<p><strong>' . $m[1] . '</strong></p>';
        } elseif (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $line)) {
            $flushList();
            $flushParagraph();
        } elseif (preg_match('/^[-*]\s+(.*)/', $line, $m)) {
            $flushParagraph();
            if ($listType !== 'ul') {
                $flushList();
                $listType = 'ul';
            }
            $listItems[] = $m[1];
        } elseif (preg_match('/^\d+\.\s+(.*)/', $line, $m)) {
            $flushParagraph();
            if ($listType !== 'ol') {
                $flushList();
                $listType = 'ol';
            }
            $listItems[] = $m[1];
        } elseif (preg_match('/^&gt;\s?(.*)/', $line, $m)) {
            $flushParagraph();
            if ($listType !== 'blockquote') {
                $flushList();
                $listType = 'blockquote';
            }
            $listItems[] = $m[1];
        } else {
            $flushList();
            $paragraph[] = $line;
        }
    }
    $flushList();
    $flushParagraph();

    return $html;
}

function format_minutes(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;

    if ($hours > 0 && $remainder > 0) {
        return "{$hours}h {$remainder}m";
    }
    if ($hours > 0) {
        return "{$hours}h";
    }
    return "{$remainder}m";
}

function format_due(string $dueAt): string
{
    $hasTime = str_contains($dueAt, 'T');
    $date = new DateTime($dueAt);
    return $date->format($hasTime ? 'D j M, g:ia' : 'D j M');
}

function due_status(string $dueAt): string
{
    $due = new DateTime($dueAt);
    $now = new DateTime();

    if (!str_contains($dueAt, 'T')) {
        $due->setTime(23, 59, 59);
    }

    if ($due < $now) {
        return 'overdue';
    }
    if ($due->format('Y-m-d') === $now->format('Y-m-d')) {
        return 'today';
    }
    return 'upcoming';
}

// Open tasks that are overdue or due today, soonest first — for the reminder banner.
function get_due_reminders(): array
{
    $stmt = get_db()->query(
        "SELECT * FROM tasks WHERE done = 0 AND due_at IS NOT NULL ORDER BY due_at ASC"
    );
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_values(array_filter(
        $tasks,
        fn (array $task) => due_status((string) $task['due_at']) !== 'upcoming'
    ));
}
