#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

PORT="${1:-8931}"
HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"

if [ -z "$HOST" ]; then
    echo "Could not detect a LAN IP, falling back to 127.0.0.1 (only reachable from this machine)." >&2
    HOST="127.0.0.1"
fi

if [ ! -f "lib/.env" ]; then
    echo "Note: lib/.env not found — the 'Get help with this' AI chat will show a" >&2
    echo "'not configured' message until you add GEMINI_API_KEY there." >&2
fi

echo "Starting To Do server at http://${HOST}:${PORT}/"
exec php -S "${HOST}:${PORT}" -t web
