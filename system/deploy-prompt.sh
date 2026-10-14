#!/usr/bin/env bash
# Saytı serverə yükləyir. Sən özün terminalda işlət:  bash deploy-prompt.sh
# Hər dəfə təmiz müvəqqəti qovluq istifadə edir, PHP sintaksisini yoxlayır (xəta olsa heç nəyi əvəz etmir),
# köhnə faylları serverdə ~/.tmp-dash/*.prev kimi saxlayır və sonda lokal ilə serveri müqayisə edir.
set -euo pipefail
cd "$(dirname "$0")"
H=u131695581@77.37.71.174
D=domains/development.chaitech.info/public_html
KEY=$HOME/.ssh/hostinger
PHPFILES="index feed db command agent ingest admin kit tasks report daily control message sound plans world remote jarvis cmdimg mission mission_lib"
SSH="ssh -i $KEY -p 65002 $H"
SCP="scp -i $KEY -P 65002 -q"
RUN=".tmp-dash/run-$(date +%s)"

echo "1/4 PHP faylları yüklənir və yoxlanır..."
$SSH "mkdir -p ~/$RUN"
for f in $PHPFILES; do $SCP public_html/$f.php $H:$RUN/; done
$SSH "cd ~/$RUN && for f in $PHPFILES; do if ! php -l \$f.php >/dev/null 2>&1; then echo \"SINTAKSIS XƏTASI: \$f.php\"; php -l \$f.php; exit 1; fi; done && echo '   sintaksis: hamısı təmizdir' && for f in $PHPFILES; do [ -f ~/$D/\$f.php ] && cp ~/$D/\$f.php ~/.tmp-dash/\$f.php.prev; done; cp ~/$RUN/*.php ~/$D/ && cd ~ && rm -rf ~/$RUN && echo '   PHP faylları əvəz olundu'"

echo "2/4 3D kainat..."
$SSH "cp ~/$D/kainat.js ~/.tmp-dash/kainat.js.prev 2>/dev/null || true"
$SCP public_html/kainat.js $H:$D/kainat.js
$SCP public_html/jarvis.js $H:$D/jarvis.js
# Jarvis modulları: brauzer (jarvis-*.js) və alət deklarasiyaları (jarvis_modules/*.php, sintaksis yoxlanır)
$SCP public_html/jarvis-*.js $H:$D/
$SSH "mkdir -p ~/$D/jarvis_modules"
$SCP public_html/jarvis_modules/*.php $H:$D/jarvis_modules/
$SSH "cd ~/$D/jarvis_modules && for f in *.php; do php -l \$f >/dev/null || { echo \"SINTAKSIS XƏTASI: jarvis_modules/\$f\"; exit 1; }; done"

echo "3/4 quraşdırma paketi və səs faylları..."
$SSH "mkdir -p ~/$D/kit/hook ~/$D/kit/agent ~/$D/media"
$SCP hook/claude-team-hook.sh hook/install.sh $H:$D/kit/hook/
$SCP agent/claude-team-agent.py agent/install-launcher.sh $H:$D/kit/agent/
$SCP public_html/kit/BASLA.template.md public_html/kit/.htaccess $H:$D/kit/
$SCP public_html/media/*.mp3 public_html/media/.htaccess $H:$D/media/

echo "4/4 yoxlama (lokal ilə server müqayisəsi)..."
CHECK=""; for p in $PHPFILES; do CHECK="$CHECK $p.php"; done; CHECK="$CHECK kainat.js jarvis.js $(cd public_html && ls jarvis-*.js jarvis_modules/*.php | tr '\n' ' ') media/askar.mp3 media/emil.mp3 kit/BASLA.template.md"
LOCAL=$(cd public_html && for f in $CHECK; do echo "$f $(md5 -q "$f")"; done)
SERVER=$($SSH "cd ~/$D && for f in $CHECK; do echo \"\$f \$(md5sum \"\$f\" | cut -d' ' -f1)\"; done")
if [ "$LOCAL" = "$SERVER" ]; then echo "   hər şey serverdə lokal ilə eynidir"; else echo "   XƏBƏRDARLIQ: fərq var:"; diff <(echo "$LOCAL") <(echo "$SERVER") || true; fi
echo DEPLOYED
