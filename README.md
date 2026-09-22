# myToDoList

A simple PHP + SQLite to-do list for personal and work tasks, with optional
Gemini AI guidance on each item. Built to stay out of the way — no accounts,
no sync service, just a local app on your network.

## Features

- Tag each task **personal** or **work**; work tasks can carry a client name
- Optional due date/time and recurrence (daily/weekly/monthly/yearly) —
  completing a recurring task rolls it to the next date instead of piling up
  duplicates
- Priority: urgent / normal / relaxed
- Timestamped notes per task, with optional time-expended logging (15/30/45/60 min)
- "Last activity" shown per task
- Filter chips for category, priority, and client — multi-select, combinable
- **Get help with this** opens a saved, multi-turn chat with Gemini AI about
  the task, starting from an editable prompt

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `curl` extensions
- (Optional) a Gemini API key for the AI chat feature — get one at
  https://aistudio.google.com/apikey

## Setup

1. Clone the repo.
2. (Optional) Create `lib/.env` to enable the AI chat feature:
   ```
   GEMINI_API_KEY=your-key-here
   ```
   The app works fine without this — "Get help with this" will just say the
   key isn't configured.
3. Start the server:
   ```
   ./start.sh [port]
   ```
   Defaults to port 8931, bound to this machine's LAN IP (auto-detected) so
   it's reachable from other devices on your network, not just localhost.
   Open the printed URL in a browser.

## Project layout

- `lib/` — never served over HTTP. Config, database access, business logic,
  and the Gemini integration all live here, along with the SQLite database
  itself (`lib/data/todo.sqlite`, created automatically on first run) and
  your `.env` file.
- `web/` — the document root. Point any web server at this folder, or just
  use `start.sh`.

## Data

Everything lives in `lib/data/todo.sqlite`. Nothing under `lib/data/` or
`lib/.env` is committed to git (see `.gitignore`) — both are local to your
machine.
