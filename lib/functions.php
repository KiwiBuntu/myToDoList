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
    $client = $category === 'work' ? trim((string) $client) : '';
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

function add_note(int $taskId, string $note, ?int $minutes = null): void
{
    $note = trim($note);
    if ($note === '' || get_task($taskId) === null) {
        return;
    }

    if (!in_array($minutes, TIME_INCREMENTS, true)) {
        $minutes = null;
    }

    $stmt = get_db()->prepare('INSERT INTO task_notes (task_id, note, minutes) VALUES (:task_id, :note, :minutes)');
    $stmt->execute(['task_id' => $taskId, 'note' => $note, 'minutes' => $minutes]);
}

function get_task_notes(int $taskId): array
{
    $stmt = get_db()->prepare('SELECT * FROM task_notes WHERE task_id = :task_id ORDER BY created_at DESC');
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function format_datetime(string $dateTime): string
{
    return (new DateTime($dateTime))->format('j M, g:ia');
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
