document.addEventListener('DOMContentLoaded', function () {
    var categorySelect = document.getElementById('category');
    var clientInput = document.getElementById('client');

    function syncClientField() {
        var isWork = categorySelect.value === 'work';
        clientInput.classList.toggle('hidden', !isWork);
        if (!isWork) {
            clientInput.value = '';
        }
    }

    if (categorySelect && clientInput) {
        categorySelect.addEventListener('change', syncClientField);
        syncClientField();
    }

    var dueInput = document.getElementById('due_at');
    var recurrenceSelect = document.getElementById('recurrence');

    function syncRecurrenceField() {
        var hasDue = dueInput.value !== '';
        recurrenceSelect.classList.toggle('hidden', !hasDue);
        if (!hasDue) {
            recurrenceSelect.value = '';
        }
    }

    if (dueInput && recurrenceSelect) {
        dueInput.addEventListener('change', syncRecurrenceField);
        syncRecurrenceField();
    }

    function openDialog(dialog) {
        dialog.showModal();
        var textarea = dialog.querySelector('textarea');
        if (textarea) {
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }
    }

    document.querySelectorAll('.notes-btn, .help-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dialog = document.getElementById(btn.getAttribute('data-dialog'));
            if (dialog) {
                openDialog(dialog);
            }
        });
    });

    document.querySelectorAll('dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    // Reopen the relevant dialog after a note was just added (redirected back with ?notes=ID).
    var notesId = new URLSearchParams(window.location.search).get('notes');
    if (notesId) {
        var reopenDialog = document.getElementById('notes-dialog-' + notesId);
        if (reopenDialog) {
            openDialog(reopenDialog);
        }
        var url = new URL(window.location.href);
        url.searchParams.delete('notes');
        window.history.replaceState({}, '', url);
    }

    function appendChatMessage(log, role, content, time, extraClass) {
        var div = document.createElement('div');
        div.className = 'chat-message chat-' + role + (extraClass ? ' ' + extraClass : '');
        var timeEl = document.createElement('time');
        timeEl.textContent = time;
        var p = document.createElement('p');
        p.textContent = content;
        div.appendChild(timeEl);
        div.appendChild(p);
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    document.querySelectorAll('.chat-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var taskId = form.getAttribute('data-task-id');
            var textarea = form.querySelector('textarea');
            var button = form.querySelector('button');
            var message = textarea.value.trim();
            if (!message) {
                return;
            }

            var log = document.getElementById('chat-log-' + taskId);
            var userBubble = appendChatMessage(log, 'user', message, 'Just now');
            textarea.value = '';
            textarea.placeholder = 'Reply...';
            var replyBubble = appendChatMessage(log, 'model', 'Thinking...', '', 'thinking');

            button.disabled = true;
            textarea.disabled = true;

            var body = new URLSearchParams();
            body.set('task_id', taskId);
            body.set('message', message);

            fetch('chat.php', { method: 'POST', body: body })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    replyBubble.classList.remove('thinking');
                    if (result.ok) {
                        userBubble.querySelector('time').textContent = result.data.user.time;
                        replyBubble.querySelector('time').textContent = result.data.reply.time;
                        replyBubble.querySelector('p').textContent = result.data.reply.content;
                    } else {
                        replyBubble.classList.add('chat-error');
                        replyBubble.querySelector('p').textContent = result.data.error || 'Something went wrong.';
                    }
                })
                .catch(function () {
                    replyBubble.classList.remove('thinking');
                    replyBubble.classList.add('chat-error');
                    replyBubble.querySelector('p').textContent = 'Could not reach the server.';
                })
                .finally(function () {
                    button.disabled = false;
                    textarea.disabled = false;
                    textarea.focus();
                    log.scrollTop = log.scrollHeight;
                });
        });
    });
});
