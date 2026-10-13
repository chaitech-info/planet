#!/usr/bin/env bash
# İstifadə: ./install.sh URL TOKEN [PROYEKT_QOVLUĞU]
# PROYEKT_QOVLUĞU verilsə, hook yalnız o proyektdə işləyir (PROYEKT/.claude/settings.local.json).
# Verilməsə, bütün proyektlər üçün ~/.claude/settings.json-a yazılır. Köhnə ayarın ehtiyat nüsxəsi saxlanır.
set -euo pipefail
URL="${1:?URL lazımdır (…/ingest.php)}"
TOKEN="${2:?token lazımdır}"
HERE="$(cd "$(dirname "$0")" && pwd)"
CLAUDE_DIR="${CLAUDE_DIR:-$HOME/.claude}"
PROJECT="${3:-}"
if [ -n "$PROJECT" ]; then
  SETTINGS="${CLAUDE_SETTINGS:-$(cd "$PROJECT" && pwd)/.claude/settings.local.json}"
else
  SETTINGS="${CLAUDE_SETTINGS:-$CLAUDE_DIR/settings.json}"
fi
CONF="${CLAUDE_TEAM_CONF:-$HOME/.claude-team-dash.conf}"

mkdir -p "$CLAUDE_DIR/hooks"
cp "$HERE/claude-team-hook.sh" "$CLAUDE_DIR/hooks/claude-team-hook.sh"
chmod +x "$CLAUDE_DIR/hooks/claude-team-hook.sh"

printf 'URL=%q\nTOKEN=%q\n' "$URL" "$TOKEN" > "$CONF"
chmod 600 "$CONF"

[ -f "$SETTINGS" ] && cp "$SETTINGS" "$SETTINGS.bak-teamdash"
python3 - "$SETTINGS" "$CLAUDE_DIR/hooks/claude-team-hook.sh" <<'PY'
import json, os, sys
path, cmd = sys.argv[1], sys.argv[2]
cfg = json.load(open(path)) if os.path.exists(path) else {}
hooks = cfg.setdefault("hooks", {})
for ev in ["SessionStart", "UserPromptSubmit", "PostToolUse", "Notification", "Stop", "SessionEnd"]:
    groups = hooks.setdefault(ev, [])
    if any("claude-team-hook.sh" in h.get("command", "") for g in groups for h in g.get("hooks", [])):
        continue
    entry = {"hooks": [{"type": "command", "command": cmd}]}
    if ev == "PostToolUse":
        entry["matcher"] = "*"
    groups.append(entry)
os.makedirs(os.path.dirname(path), exist_ok=True)
json.dump(cfg, open(path, "w"), indent=2, ensure_ascii=False)
PY
if [ -n "$PROJECT" ]; then
  ABS="$(cd "$PROJECT" && pwd)"
  LIST="${CLAUDE_TEAM_PROJECTS:-$HOME/.claude-team-projects}"
  touch "$LIST"
  grep -vF "$(printf '%s\t%s' "$(basename "$ABS")" "$ABS")" "$LIST" > "$LIST.tmp" || true
  printf '%s\t%s\n' "$(basename "$ABS")" "$ABS" >> "$LIST.tmp" && mv "$LIST.tmp" "$LIST"
  chmod 600 "$LIST"
fi
echo "Hazırdır. Claude Code-u yenidən başlat (və ya /hooks ilə yoxla)."
