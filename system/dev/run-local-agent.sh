#!/usr/bin/env bash
# Lokal test üçün ayrıca Claude Code agenti: lokal panelə (127.0.0.1:8090) qoşulur.
# Əsas (production) agentin faylarına toxunmur: öz konfiqi, proyekt siyahısı və PID faylı system/dev/agent-local/<istifadəçi>/ içindədir.
# İstifadə: bash system/dev/run-local-agent.sh [İSTİFADƏÇİ] [PROYEKT=QOVLUQ ...]
#   məs.   bash system/dev/run-local-agent.sh Askar neo=/Users/jarvis/Documents/neo/neo
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
USER_NAME="${1:-Askar}"; shift || true
DIR="$HERE/agent-local/$USER_NAME"; mkdir -p "$DIR"; chmod 700 "$HERE/agent-local" "$DIR"   # hər istifadəçi üçün ayrıca (bir neçə lokal agent eyni anda)
# Token: AGENT_TOKEN mühit dəyişənindən (məs. seed-in Emil-i: local-dev-emil-token) və ya config.php-dən (ekrana çıxmır)
TOKEN="${AGENT_TOKEN:-}"
[ -n "$TOKEN" ] || TOKEN="$(php -r '$c = require $argv[1]; foreach ($c["users"] as $t => $n) if ($n === $argv[2]) { echo $t; exit; } exit(1);' "$ROOT/public_html/config.php" "$USER_NAME")" \
  || { echo "config.php-də '$USER_NAME' istifadəçisi yoxdur"; exit 1; }
printf 'URL=%q\nTOKEN=%q\n' "http://127.0.0.1:8090/ingest.php" "$TOKEN" > "$DIR/dash.conf"; chmod 600 "$DIR/dash.conf"
if [ "$#" -gt 0 ]; then : > "$DIR/projects"; for kv in "$@"; do printf '%s\t%s\n' "${kv%%=*}" "${kv#*=}" >> "$DIR/projects"; done; fi
[ -s "$DIR/projects" ] || { echo "Proyekt ver: neo=/yol/qovluq"; exit 1; }
echo "Lokal agent ($USER_NAME): $(cut -f1 "$DIR/projects" | tr '\n' ' ')"
CLAUDE_TEAM_CONF="$DIR/dash.conf" CLAUDE_TEAM_PROJECTS="$DIR/projects" CLAUDE_TEAM_PID="$DIR/agent.pid" \
  exec python3 "$ROOT/agent/claude-team-agent.py"
