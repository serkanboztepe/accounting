#!/bin/bash
# panel.hesapasistanim.com sunucu deploy (local ./deploy-panel.sh tetikler)
# Sunucudaki yeri: /root/deploy-panel.sh — bu dosya onun repodaki kopyası.
# Kurulum: scp deploy-panel.server.sh root@49.13.68.63:/root/deploy-panel.sh
set -euo pipefail
PHP=/usr/local/lsws/lsphp82/bin/php
APP=/home/hesapasistanim.com/panel
OWNER=hesap7898
cd "$APP"
OLD_LOCK=$(md5sum composer.lock | cut -d' ' -f1)
git fetch -q origin
# Yeni migration geliyorsa: kod değişmeden ÖNCE merkez + her firma veritabanı yedeği.
# Yedek alınamazsa set -e burada durdurur; kod ve veritabanı eski haliyle kalır.
NEW_MIGRATIONS=$(git diff --name-only --diff-filter=AM HEAD origin/main -- database/migrations | wc -l)
if [ "$NEW_MIGRATIONS" -gt 0 ]; then
  echo "==> $NEW_MIGRATIONS yeni migration: önce yedek (merkez + firmalar)"
  $PHP artisan backup:run --only-db --disable-notifications
  $PHP artisan tenants:run "backup:run --only-db --disable-notifications"
  echo "==> yedek tamam"
else
  echo "==> yeni migration yok, deploy öncesi yedek atlandı"
fi
git reset -q --hard origin/main
chown -R "$OWNER:$OWNER" "$APP"          # artisan'dan ÖNCE (filament:assets yazabilsin)
[ "$OLD_LOCK" = "$(md5sum composer.lock | cut -d' ' -f1)" ] || $PHP /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction -q
$PHP artisan migrate --database=central --force
$PHP artisan tenants:migrate
$PHP artisan filament:assets
[ -L public/storage ] || $PHP artisan storage:link
$PHP artisan optimize:clear >/dev/null
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
chown -R "$OWNER:$OWNER" "$APP"
echo "panel deploy OK: $(git log --oneline -1)"
