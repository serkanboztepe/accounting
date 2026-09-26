#!/bin/bash
# Yeni Yapı (İnşaat Yönetimi) — tek komutluk deploy
# Kullanım:  ./deploy-yenyapi.sh "commit notu"
#   1) frontend build   2) commit + push   3) build rsync
#   4) sunucuda deploy   5) sağlık kontrolü
set -euo pipefail

SERVER=root@49.13.68.63
APP_REMOTE=/home/boztepeler.com/yenyapi
URL=https://yenyapi.boztepeler.com
MSG="${1:-deploy: $(date '+%Y-%m-%d %H:%M')}"

cd "$(dirname "$0")"

echo "==> [1/5] Frontend build"
if [ ! -d node_modules ]; then
  echo "    node_modules yok, npm ci çalışıyor..."
  npm ci
fi
npm run build

echo "==> [2/5] Commit + push"
git add -A
if git diff --cached --quiet; then
  echo "    Commit'lenecek değişiklik yok, mevcut HEAD push ediliyor"
else
  git commit -m "$MSG"
fi
git push origin main

echo "==> [3/5] Build dosyaları sunucuya kopyalanıyor (rsync)"
rsync -az --delete public/build/ "$SERVER:$APP_REMOTE/public/build/"

echo "==> [4/5] Sunucuda deploy tetikleniyor"
ssh -o BatchMode=yes "$SERVER" '/root/deploy-yenyapi.sh'

echo "==> [5/5] Sağlık kontrolü"
code=$(curl -sk -o /dev/null -w '%{http_code}' "$URL/admin/login" --max-time 20)
if [ "$code" = "200" ]; then
  echo "    ✓ CANLI — $URL (HTTP $code)"
else
  echo "    ✗ DİKKAT: beklenmeyen yanıt HTTP $code — $URL"
  exit 1
fi
