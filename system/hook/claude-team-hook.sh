#!/usr/bin/env bash
# Claude Code hook: hadisəni komanda dashboard-una göndərir. Heç vaxt Claude-u bloklamır.
CONF="${CLAUDE_TEAM_CONF:-$HOME/.claude-team-dash.conf}"
[ -f "$CONF" ] || exit 0
. "$CONF"
[ -n "$URL" ] && [ -n "$TOKEN" ] || exit 0
payload=$(cat)

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
