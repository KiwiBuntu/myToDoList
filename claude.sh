#!/usr/bin/env bash
# Resumes the most recent Claude Code session for this project.
cd "$(dirname "${BASH_SOURCE[0]}")"
exec claude --continue
