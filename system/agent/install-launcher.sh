#!/usr/bin/env bash
# Bir dəfə işlət:  bash agent/install-launcher.sh
# ~/Applications/ClaudeTeamAgent.app yaradır və claudeteam://start ünvanını ona bağlayır.
# Saytdakı "Agenti işə sal" düyməsi bu ünvanı açır. Proqram yalnız agent skriptini işə salır, parametr qəbul etmir.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
AGENT="$HERE/claude-team-agent.py"
APP="${LAUNCHER_APP:-$HOME/Applications/ClaudeTeamAgent.app}"
LOG="$HOME/.claude-team-agent.log"
[ -f "$AGENT" ] || { echo "agent tapılmadı: $AGENT"; exit 1; }
mkdir -p "$(dirname "$APP")"
TMP="$(mktemp -d)"
cat > "$TMP/launcher.applescript" <<APPLESCRIPT
on startAgent()
    do shell script "/usr/bin/nohup /usr/bin/python3 '$AGENT' >> '$LOG' 2>&1 &"
end startAgent

on open location theURL
    if theURL is "claudeteam://start" then my startAgent()
end open location

on run
    my startAgent()
end run
APPLESCRIPT
rm -rf "$APP"
osacompile -o "$APP" "$TMP/launcher.applescript"
PL="$APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c "Add :LSUIElement bool true" "$PL"
/usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes array" "$PL"
/usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes:0 dict" "$PL"
/usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes:0:CFBundleURLName string ClaudeTeamAgent" "$PL"
/usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes:0:CFBundleURLSchemes array" "$PL"
/usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string claudeteam" "$PL"
codesign --force --deep --sign - "$APP" >/dev/null 2>&1 || true
if [ -z "${LAUNCHER_APP:-}" ]; then
  /System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -f "$APP"
fi
rm -rf "$TMP"
echo "Hazırdır: $APP"
echo "Test: open claudeteam://start   (sonra saytda 'agent onlayn' görünməlidir; log: $LOG)"
