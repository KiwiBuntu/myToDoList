<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/gemini.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        add_task(
            (string) ($_POST['description'] ?? ''),
            (string) ($_POST['category'] ?? 'personal'),
            $_POST['client'] ?? null,
            (string) ($_POST['priority'] ?? 'normal'),
            $_POST['due_at'] ?? null,
            $_POST['recurrence'] ?? null
        );
    } elseif ($action === 'toggle') {
        toggle_task((int) ($_POST['id'] ?? 0));
    } elseif ($action === 'delete') {
        delete_task((int) ($_POST['id'] ?? 0));
    } elseif ($action === 'add_note') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $minutes = $_POST['minutes'] ?? '';
        $noteId = add_note($taskId, (string) ($_POST['note'] ?? ''), $minutes === '' ? null : (int) $minutes);
        if ($noteId !== null && isset($_FILES['attachment'])) {
            attach_file_to_note($noteId, $_FILES['attachment']);
        }
    }

    $params = [];
    if (isset($_GET['filter'])) {
        $params['filter'] = (string) $_GET['filter'];
    }
    if (!empty($_GET['cat'])) {
        $params['cat'] = (array) $_GET['cat'];
    }
    if (!empty($_GET['pri'])) {
        $params['pri'] = (array) $_GET['pri'];
    }
    if (!empty($_GET['client'])) {
        $params['client'] = (array) $_GET['client'];
    }
    if ($action === 'add_note') {
        $params['notes'] = (int) ($_POST['task_id'] ?? 0);
    }
    $redirect = 'index.php' . ($params ? '?' . http_build_query($params) : '');
    header('Location: ' . $redirect);
    exit;
}

$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open', 'done', 'all'], true)) {
    $filter = 'open';
}

$knownClients = get_distinct_clients();
$selectedCategories = array_values(array_intersect((array) ($_GET['cat'] ?? []), ['personal', 'work']));
$selectedPriorities = array_values(array_intersect((array) ($_GET['pri'] ?? []), PRIORITIES));
$selectedClients = array_values(array_intersect((array) ($_GET['client'] ?? []), $knownClients));
$hasTagFilters = $selectedCategories || $selectedPriorities || $selectedClients;

$tasks = get_tasks($filter, $selectedCategories, $selectedPriorities, $selectedClients);

function toggled(array $values, string $value): array
{
    return in_array($value, $values, true)
        ? array_values(array_diff($values, [$value]))
        : array_merge($values, [$value]);
}

function state_url(string $filter, array $categories, array $priorities, array $clients): string
{
    $params = ['filter' => $filter];
    if ($categories) {
        $params['cat'] = $categories;
    }
    if ($priorities) {
        $params['pri'] = $priorities;
    }
    if ($clients) {
        $params['client'] = $clients;
    }
    return 'index.php?' . http_build_query($params);
}

$actionUrl = state_url($filter, $selectedCategories, $selectedPriorities, $selectedClients);
$reminders = get_due_reminders();
$overdueCount = count(array_filter($reminders, fn (array $t) => due_status((string) $t['due_at']) === 'overdue'));
$todayCount = count($reminders) - $overdueCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>To Do</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<main>
    <h1>To Do</h1>

    <?php if ($reminders): ?>
        <div class="reminder-banner">
            <strong>
                <?php if ($overdueCount > 0): ?>
                    ⏰ <?= $overdueCount ?> overdue<?= $todayCount > 0 ? ', ' . $todayCount . ' due today' : '' ?>
                <?php else: ?>
                    ⏰ <?= $todayCount ?> due today
                <?php endif; ?>
            </strong>
            <ul>
                <?php foreach ($reminders as $reminder): ?>
                    <li>
                        <a href="index.php?filter=open#task-<?= (int) $reminder['id'] ?>"><?= htmlspecialchars($reminder['description']) ?></a>
                        &middot;
                        <span class="reminder-status reminder-<?= due_status((string) $reminder['due_at']) ?>">
                            <?= htmlspecialchars(format_due((string) $reminder['due_at'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="add-form" method="post" action="<?= htmlspecialchars($actionUrl) ?>">
        <input type="hidden" name="action" value="add">
        <input
            type="text"
            name="description"
            placeholder="What needs doing?"
            required
            autofocus
        >
        <div class="add-form-row">
            <select name="category" id="category">
                <option value="personal">Personal</option>
                <option value="work">Work</option>
            </select>
            <input
                type="text"
                name="client"
                id="client"
                placeholder="Client (optional)"
            >
        </div>
        <div class="add-form-row">
            <select name="priority" id="priority">
                <option value="urgent">🔴 Urgent</option>
                <option value="normal" selected>⚪ Normal</option>
                <option value="relaxed">🟢 Relaxed</option>
            </select>
            <input type="datetime-local" name="due_at" id="due_at">
            <select name="recurrence" id="recurrence" class="hidden">
                <option value="">Doesn't repeat</option>
                <option value="daily">Repeats daily</option>
                <option value="weekly">Repeats weekly</option>
                <option value="monthly">Repeats monthly</option>
                <option value="yearly">Repeats yearly</option>
            </select>
        </div>
        <div class="add-form-row add-form-submit">
            <button type="submit">Add</button>
        </div>
    </form>

    <nav class="filters">
        <a href="<?= htmlspecialchars(state_url('open', $selectedCategories, $selectedPriorities, $selectedClients)) ?>" class="<?= $filter === 'open' ? 'active' : '' ?>">Open</a>
        <a href="<?= htmlspecialchars(state_url('done', $selectedCategories, $selectedPriorities, $selectedClients)) ?>" class="<?= $filter === 'done' ? 'active' : '' ?>">Done</a>
        <a href="<?= htmlspecialchars(state_url('all', $selectedCategories, $selectedPriorities, $selectedClients)) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
    </nav>

    <nav class="tag-filters">
        <a
            href="<?= htmlspecialchars(state_url($filter, toggled($selectedCategories, 'personal'), $selectedPriorities, $selectedClients)) ?>"
            class="chip chip-personal <?= in_array('personal', $selectedCategories, true) ? 'active' : '' ?>"
        >personal</a>
        <a
            href="<?= htmlspecialchars(state_url($filter, toggled($selectedCategories, 'work'), $selectedPriorities, $selectedClients)) ?>"
            class="chip chip-work <?= in_array('work', $selectedCategories, true) ? 'active' : '' ?>"
        >work</a>

        <?php foreach (['urgent', 'relaxed'] as $priority): ?>
            <a
                href="<?= htmlspecialchars(state_url($filter, $selectedCategories, toggled($selectedPriorities, $priority), $selectedClients)) ?>"
                class="chip chip-priority-<?= $priority ?> <?= in_array($priority, $selectedPriorities, true) ? 'active' : '' ?>"
            ><?= $priority ?></a>
        <?php endforeach; ?>

        <?php foreach ($knownClients as $client): ?>
            <a
                href="<?= htmlspecialchars(state_url($filter, $selectedCategories, $selectedPriorities, toggled($selectedClients, $client))) ?>"
                class="chip chip-client <?= in_array($client, $selectedClients, true) ? 'active' : '' ?>"
            ><?= htmlspecialchars($client) ?></a>
        <?php endforeach; ?>

        <?php if ($hasTagFilters): ?>
            <a href="<?= htmlspecialchars(state_url($filter, [], [], [])) ?>" class="chip-clear">Clear tags &times;</a>
        <?php endif; ?>
    </nav>

    <ul class="task-list">
        <?php if (!$tasks): ?>
            <li class="empty"><?= $hasTagFilters ? 'No tasks match these tags.' : 'Nothing here. Nice.' ?></li>
        <?php endif; ?>

        <?php foreach ($tasks as $task): ?>
            <li id="task-<?= (int) $task['id'] ?>" class="task <?= $task['done'] ? 'done' : '' ?>">
                <form method="post" class="toggle-form" action="<?= htmlspecialchars($actionUrl) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                    <button type="submit" class="checkbox" aria-label="Toggle done"><?= $task['done'] ? '✓' : '' ?></button>
                </form>

                <div class="task-body">
                    <span class="description"><?= htmlspecialchars($task['description']) ?></span>
                    <span class="tags">
                        <span class="tag tag-<?= htmlspecialchars($task['category']) ?>"><?= htmlspecialchars($task['category']) ?></span>
                        <?php if ($task['client']): ?>
                            <span class="tag <?= $task['category'] === 'personal' ? 'tag-client-personal' : 'tag-client' ?>"><?= htmlspecialchars($task['client']) ?></span>
                        <?php endif; ?>
                        <?php if ($task['priority'] !== 'normal'): ?>
                            <span class="tag tag-priority-<?= htmlspecialchars($task['priority']) ?>"><?= htmlspecialchars($task['priority']) ?></span>
                        <?php endif; ?>
                        <?php if ($task['due_at']): ?>
                            <span class="tag tag-due tag-due-<?= due_status((string) $task['due_at']) ?>">
                                <?= $task['recurrence'] ? '↻ ' : '' ?><?= htmlspecialchars(format_due((string) $task['due_at'])) ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <div class="last-activity">Last activity: <?= htmlspecialchars(format_datetime((string) $task['last_activity'])) ?></div>
                </div>

                <div class="task-actions">
                    <?php $chatCount = count(get_chat_messages((int) $task['id'])); ?>
                    <button type="button" class="help-btn" data-dialog="help-dialog-<?= (int) $task['id'] ?>">
                        Get help with this<?= $chatCount > 0 ? ' (' . $chatCount . ')' : '' ?>
                    </button>
                    <button type="button" class="notes-btn" data-dialog="notes-dialog-<?= (int) $task['id'] ?>">
                        Notes<?= $task['note_count'] > 0 ? ' (' . (int) $task['note_count'] . ')' : '' ?>
                    </button>
                    <form method="post" class="delete-form" action="<?= htmlspecialchars($actionUrl) ?>" onsubmit="return confirm('Delete this task?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                        <button type="submit" class="delete-btn" aria-label="Delete">&times;</button>
                    </form>
                </div>

                <dialog id="notes-dialog-<?= (int) $task['id'] ?>" class="notes-dialog">
                    <form method="dialog" class="dialog-close-form">
                        <button type="submit" class="dialog-close" aria-label="Close">&times;</button>
                    </form>
                    <h2><?= htmlspecialchars($task['description']) ?></h2>
                    <p class="last-activity">
                        Last activity: <?= htmlspecialchars(format_datetime((string) $task['last_activity'])) ?>
                        <?php
                            $notes = get_task_notes((int) $task['id']);
                            $totalMinutes = array_sum(array_column($notes, 'minutes'));
                        ?>
                        <?php if ($totalMinutes > 0): ?>
                            &middot; Time logged: <?= htmlspecialchars(format_minutes((int) $totalMinutes)) ?>
                        <?php endif; ?>
                    </p>

                    <div class="notes-list">
                        <?php if (!$notes): ?>
                            <p class="empty">No notes yet.</p>
                        <?php endif; ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="note">
                                <time>
                                    <?= htmlspecialchars(format_datetime((string) $note['created_at'])) ?>
                                    <?php if ($note['minutes']): ?>
                                        &middot; <?= htmlspecialchars(format_minutes((int) $note['minutes'])) ?>
                                    <?php endif; ?>
                                </time>
                                <p><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                                <?php if ($note['attachment_path']): ?>
                                    <a class="note-attachment" href="attachment.php?note_id=<?= (int) $note['id'] ?>" target="_blank" rel="noopener">
                                        📎 <?= htmlspecialchars((string) $note['attachment_name']) ?>
                                        <span class="note-attachment-size">(<?= htmlspecialchars(format_bytes((int) $note['attachment_size'])) ?>)</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="post" class="note-form" action="<?= htmlspecialchars($actionUrl) ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <textarea name="note" placeholder="Add a note..." required></textarea>
                        <select name="minutes">
                            <option value="">Time expended (optional)</option>
                            <option value="15">15 min</option>
                            <option value="30">30 min</option>
                            <option value="45">45 min</option>
                            <option value="60">1 hour</option>
                        </select>
                        <input type="file" name="attachment">
                        <button type="submit">Add note</button>
                    </form>
                </dialog>

                <dialog id="help-dialog-<?= (int) $task['id'] ?>" class="help-dialog">
                    <form method="dialog" class="dialog-close-form">
                        <button type="submit" class="dialog-close" aria-label="Close">&times;</button>
                    </form>
                    <h2><?= htmlspecialchars($task['description']) ?></h2>

                    <?php $chatMessages = get_chat_messages((int) $task['id']); ?>
                    <div class="chat-log" id="chat-log-<?= (int) $task['id'] ?>">
                        <?php foreach ($chatMessages as $i => $message): ?>
                            <?php $collapsed = $message['role'] === 'model' && $i !== count($chatMessages) - 1; ?>
                            <div class="chat-message chat-<?= htmlspecialchars($message['role']) ?> <?= $collapsed ? 'collapsed' : '' ?>">
                                <time><?= htmlspecialchars(format_datetime((string) $message['created_at'])) ?></time>
                                <div class="chat-text">
                                    <?php if ($message['role'] === 'model'): ?>
                                        <?= format_chat_markdown($message['content']) ?>
                                    <?php else: ?>
                                        <?= nl2br(htmlspecialchars($message['content'])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($message['role'] === 'model'): ?>
                                    <button type="button" class="chat-toggle"><?= $collapsed ? 'Show more' : 'Show less' ?></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form class="chat-form" data-task-id="<?= (int) $task['id'] ?>">
                        <textarea
                            name="message"
                            <?= $chatMessages ? 'placeholder="Reply..."' : '' ?>
                        ><?= $chatMessages ? '' : htmlspecialchars(build_help_starter_prompt($task)) ?></textarea>
                        <button type="submit">Send</button>
                    </form>
                </dialog>
            </li>
        <?php endforeach; ?>
    </ul>
</main>
<script src="app.js"></script>
</body>
</html>
