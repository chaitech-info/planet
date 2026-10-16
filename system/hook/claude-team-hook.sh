#!/usr/bin/env bash
# Claude Code hook: hadisəni komanda dashboard-una göndərir. Heç vaxt Claude-u bloklamır
# (istisna: PermissionRequest panelin qərarını ≤ 50 san gözləyir, aşağıda approval()).
CONF="${CLAUDE_TEAM_CONF:-$HOME/.claude-team-dash.conf}"
[ -f "$CONF" ] || exit 0
. "$CONF"
[ -n "$URL" ] && [ -n "$TOKEN" ] || exit 0
payload=$(cat)

# İcazə relesi (PermissionRequest): sorğunu panelə/Jarvis-ə göndər, qərarı ≤ 50 san gözlə.
# Qərar gəlsə Claude Code-un gözlədiyi JSON-u çap edir; vaxt keçsə və ya xəta olsa heç nə çap etmir (adi dialoq açılır).
# Söndürmək: CLAUDE_TEAM_APPROVALS=0 (mühitdə və ya ~/.claude-team-agent.env-də). Agentin -p işləri (CLAUDE_TEAM_RUN) relesiz qalır.
approval() {
  [ -z "$CLAUDE_TEAM_RUN" ] || return 0
  local opt="${CLAUDE_TEAM_APPROVALS:-}"
  [ -n "$opt" ] || opt=$(sed -n 's/^[[:space:]]*\(export[[:space:]]\{1,\}\)\{0,1\}CLAUDE_TEAM_APPROVALS=["'"'"']\{0,1\}\([^"'"'"' ]*\).*/\2/p' "$HOME/.claude-team-agent.env" 2>/dev/null | tail -1)
  case "$opt" in 0|off|no|false) return 0 ;; esac
  command -v python3 >/dev/null 2>&1 || return 0
  local AURL="${APPROVAL_URL:-${URL%/*}/approval.php}" W="${CLAUDE_TEAM_APPROVAL_WAIT:-25}" body res id st end left w
  call() { printf '%s' "$2" | curl -sS -m "$1" --connect-timeout 3 -X POST -H 'Content-Type: application/json' \
      -H "X-Team-Token: $TOKEN" --data-binary @- "$AURL" 2>/dev/null; }
  body=$(printf '%s' "$payload" | python3 -c '
import json, os, sys
p = json.load(sys.stdin)
ti = p.get("tool_input") or {}
s = json.dumps(ti, ensure_ascii=False)
if len(s.encode()) > 4096:   # 4 KB: uzun sətirləri kəs, yenə böyükdürsə mətn kimi
    ti = {k: (v[:1000] + "…" if isinstance(v, str) and len(v) > 1000 else v) for k, v in ti.items()} if isinstance(ti, dict) else {}
    s = json.dumps(ti, ensure_ascii=False)
    if len(s.encode()) > 4096:
        ti = {"_truncated": s.encode()[:4000].decode("utf-8", "ignore")}
cwd = p.get("cwd") or ""
print(json.dumps({"action": "request", "session_id": p.get("session_id", ""), "project": os.path.basename(cwd.rstrip("/")) or "naməlum",
                  "cwd": cwd, "tool": p.get("tool_name", ""), "input": ti}, ensure_ascii=False))' 2>/dev/null) || return 0
  res=$(call 6 "$body") || return 0
  id=$(printf '%s' "$res" | sed -n 's/.*"id" *: *\([0-9][0-9]*\).*/\1/p' | head -1)
  [ -n "$id" ] || return 0
  end=$(( SECONDS + ${CLAUDE_TEAM_APPROVAL_BUDGET:-50} ))   # test: kiçik BUDGET/WAIT
  while :; do
    left=$(( end - SECONDS - 3 ))
    [ "$left" -gt 0 ] || break
    w=$W; [ "$w" -le "$left" ] || w=$left
    res=$(call $(( w + 3 )) "{\"action\":\"wait\",\"id\":$id,\"wait\":$w}") || return 0   # şəbəkə xətası: adi dialoq
    st=$(printf '%s' "$res" | sed -n 's/.*"status" *: *"\([a-z]*\)".*/\1/p' | head -1)
    case "$st" in
      allow|deny)
        printf '%s' "$res" | python3 -c '
import json, sys
r = json.load(sys.stdin)
d = {"behavior": r["status"]}
if r["status"] == "deny":
    d["message"] = "Komanda paneli (Jarvis) rədd etdi" + (": " + r["reason"] if r.get("reason") else "")
print(json.dumps({"hookSpecificOutput": {"hookEventName": "PermissionRequest", "decision": d}}, ensure_ascii=False))' 2>/dev/null
        return 0 ;;
      pending) ;;
      *) return 0 ;;
    esac
  done
  call 4 "{\"action\":\"cancel\",\"id\":$id}" >/dev/null || true
  return 0
}

post() {   # $1 = JSON
  ( printf '%s' "$1" | curl -sS -m 5 --connect-timeout 3 -X POST \
      -H 'Content-Type: application/json' -H "X-Team-Token: $TOKEN" ${CLAUDE_TEAM_RUN:+-H "X-Team-Run: $CLAUDE_TEAM_RUN"} \
      --data-binary @- "$URL" >/dev/null 2>&1 & )
}
post "$payload"

# Esc ilə dayandırma: Claude Code bu halda Stop hook-unu çağırmır, sayt "işləyir" göstərməkdə qalır.
# Əvəzində transkriptdə "[Request interrupted by user]" yazılır. Prompt başlayanda kiçik arxa plan
# izləyicisi transkripti izləyir və bunu görəndə panelə "Interrupt" hadisəsi göndərir.
jget() { printf '%s' "$payload" | sed -n 's/.*"'"$1"'" *: *"\([^"]*\)".*/\1/p' | head -1; }
ev=$(jget hook_event_name)
sid=$(jget session_id)
case "$sid" in ''|*[!A-Za-z0-9_-]*) exit 0 ;; esac
WD="$HOME/.claude-team-watch"

case "$ev" in
  PermissionRequest) approval ;;
  Stop|SessionEnd) rm -f "$WD/$sid.pid" 2>/dev/null ;;
  UserPromptSubmit)
    [ -z "$CLAUDE_TEAM_RUN" ] || exit 0          # agentin işlərini agent özü dayandırır
    tp=$(jget transcript_path); cwd=$(jget cwd)
    [ -f "$tp" ] || exit 0
    mkdir -p "$WD" 2>/dev/null || exit 0
    tok="$$.$RANDOM$RANDOM"
    printf '%s' "$tok" > "$WD/$sid.pid"          # yeni prompt köhnə izləyicini əvəz edir
    (
      pos=$(wc -c < "$tp" 2>/dev/null | tr -d ' ')
      [ -n "$pos" ] || exit 0
      end=$(( $(date +%s) + 14400 ))
      while [ "$(date +%s)" -lt "$end" ]; do
        sleep 2
        [ "$(cat "$WD/$sid.pid" 2>/dev/null)" = "$tok" ] || exit 0
        [ -f "$tp" ] || exit 0
        size=$(wc -c < "$tp" | tr -d ' ')
        if [ "$size" -gt "$pos" ]; then
          if tail -c +"$((pos + 1))" "$tp" | grep -q '"text":"\[Request interrupted by user'; then
            post "{\"hook_event_name\":\"Interrupt\",\"session_id\":\"$sid\",\"cwd\":\"$cwd\"}"
            rm -f "$WD/$sid.pid"
            exit 0
          fi
          pos=$size
        fi
      done
    ) </dev/null >/dev/null 2>&1 &
    ;;
esac
exit 0
