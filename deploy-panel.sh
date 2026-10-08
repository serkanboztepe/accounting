#!/bin/bash
# Tek panel (panel.hesapasistanim.com) — tek komutluk deploy
# Kullanım:  ./deploy-panel.sh
#   Commit'lenmiş HEAD'i gönderir (çalışma alanındaki commit'lenmemiş değişikliklere DOKUNMAZ —
#   eski deploy*.sh'lerdeki `git add -A` tuzağı yok).
#   1) frontend build   2) push   3) build rsync   4) sunucuda deploy   5) sağlık kontrolü
set -euo pipefail

SERVER=root@49.13.68.63
APP_REMOTE=/home/hesapasistanim.com/panel
URL=https://panel.hesapasistanim.com

cd "$(dirname "$0")"

echo "==> [1/5] Frontend build"
if [ ! -d node_modules ]; then
  npm ci
fi
npm run build

echo "==> [2/5] Push (HEAD: $(git log --oneline -1))"
git push origin main

echo "==> [3/5] Build dosyaları sunucuya kopyalanıyor (rsync)"
rsync -az --delete public/build/ "$SERVER:$APP_REMOTE/public/build/"

echo "==> [4/5] Sunucuda deploy (merkez + tüm firma veritabanları migrate)"
ssh -o BatchMode=yes "$SERVER" '/root/deploy-panel.sh'

echo "==> [5/5] Sağlık kontrolü"
for path in /admin/login /hub/login; do
  code=$(curl -sk -o /dev/null -w '%{http_code}' "$URL$path" --max-time 20)
  if [ "$code" = "200" ]; then
    echo "    ✓ $URL$path (HTTP $code)"
  else
    echo "    ✗ DİKKAT: $URL$path HTTP $code"
    exit 1
  fi
done
