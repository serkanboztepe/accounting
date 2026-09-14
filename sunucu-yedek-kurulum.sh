#!/usr/bin/env bash
#
# Otomatik yedekleme — SUNUCU kurulum yardımcısı (tek seferlik çalıştır).
# Ne yapar:
#   1) rclone kurar (yoksa) ve Google Drive bağlantısını yapılandırmana yardım eder
#   2) Laravel scheduler cron'unu ekler  -> yedekleri tetikleyen asıl satır
#   3) Gece rclone kopyasını (sunucu -> Drive) cron'a ekler
#   4) Bir test yedeği alıp Drive'a kopyalar
#
# NOT: Mail ayarı (hata bildirimi) bu script'te YOK — onu .env'de elle yaparsın
#      (MAIL_MAILER=smtp + Gmail Uygulama Şifresi). Detay README/konuşmada.
#
# Idempotent: tekrar çalıştırmak güvenlidir, cron satırlarını çift eklemez.

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# AYARLAR — kendi sunucuna göre düzenle
# ─────────────────────────────────────────────────────────────
APP_PATH="/home/boztepeler.com/muhasebe"                   # Laravel kök dizini (deploy hedefiyle aynı)
PHP_BIN="/usr/local/lsws/lsphp82/bin/php"                  # CyberPanel lsphp82
RCLONE_REMOTE="gdrive"                                     # rclone config'te vereceğin ad
DRIVE_FOLDER="insaat-yedek"                                # Drive'daki hedef klasör
BACKUP_DIR="$APP_PATH/storage/app/private/insaat-yonetimi" # spatie yedek klasörü
# ─────────────────────────────────────────────────────────────

echo "==> 1/4  rclone kontrol ediliyor..."
if ! command -v rclone >/dev/null 2>&1; then
  echo "    rclone yok, kuruluyor..."
  curl -fsSL https://rclone.org/install.sh | sudo bash
else
  echo "    rclone zaten kurulu: $(rclone version | head -1)"
fi

echo
echo "==> 2/4  Google Drive bağlantısı (rclone remote: '$RCLONE_REMOTE')"
if rclone listremotes 2>/dev/null | grep -q "^${RCLONE_REMOTE}:"; then
  echo "    '$RCLONE_REMOTE' remote'u zaten var, atlanıyor."
else
  echo "    Şimdi rclone config açılacak. Sırasıyla:"
  echo "      n (new) -> ad: $RCLONE_REMOTE -> storage: drive -> "
  echo "      client_id/secret boş bırak (Enter) -> scope: 1 (full) -> "
  echo "      geri kalanı Enter -> 'Use auto config?' sunucuda HAYIR (n) -> "
  echo "      verilen komutu KENDİ bilgisayarında çalıştır, çıkan token'ı yapıştır."
  read -r -p "    Devam etmek için Enter'a bas..."
  rclone config
fi

echo
echo "==> 3/4  Cron satırları ekleniyor..."
CRON_SCHEDULE="* * * * * cd $APP_PATH && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
CRON_RCLONE="30 3 * * * rclone copy $BACKUP_DIR ${RCLONE_REMOTE}:${DRIVE_FOLDER} >> /dev/null 2>&1"

CURRENT_CRON="$(crontab -l 2>/dev/null || true)"

add_cron() {
  local line="$1"; local tag="$2"
  if printf '%s\n' "$CURRENT_CRON" | grep -Fq "$tag"; then
    echo "    [zaten var] $tag"
  else
    CURRENT_CRON="$(printf '%s\n%s\n' "$CURRENT_CRON" "$line")"
    echo "    [eklendi]   $line"
  fi
}

add_cron "$CRON_SCHEDULE" "artisan schedule:run"
add_cron "$CRON_RCLONE"   "rclone copy $BACKUP_DIR"
printf '%s\n' "$CURRENT_CRON" | crontab -

echo
echo "==> 4/4  Test yedeği alınıyor ve Drive'a kopyalanıyor..."
cd "$APP_PATH"
$PHP_BIN artisan backup:run
rclone copy "$BACKUP_DIR" "${RCLONE_REMOTE}:${DRIVE_FOLDER}" --progress

echo
echo "✅ Bitti. Kontrol:"
echo "   - Sunucu yedeği : ls -lah $BACKUP_DIR"
echo "   - Drive yedeği  : rclone ls ${RCLONE_REMOTE}:${DRIVE_FOLDER}"
echo "   - Sağlık        : $PHP_BIN artisan backup:monitor"
echo
echo "⚠ Hata bildirimi için .env'de gerçek SMTP kurmayı unutma (MAIL_MAILER=smtp)."
