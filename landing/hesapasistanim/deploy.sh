#!/bin/bash
# hesapasistanim.com yayını: sektör sayfalarını üretir, siteyi sunucuya kopyalar.
# Kullanım: ./landing/hesapasistanim/deploy.sh
set -euo pipefail

cd "$(dirname "$0")"
SERVER=root@49.13.68.63
DOCROOT=/home/hesapasistanim.com/public_html

python3 -I build_sectors.py "$PWD" >/dev/null

# Sadece yayınlanan dosyalar; *-source.html, build_sectors.py, deploy.sh sunucuya gitmez.
FILES=(index.html gizlilik.html muteahhit.html mimar.html toptanci.html alacak-verecek.html
       site.css sitemap.xml robots.txt og.png icon-180.png .htaccess)

scp -q "${FILES[@]}" "$SERVER:$DOCROOT/"
ssh "$SERVER" "cd $DOCROOT && chown hesap7898:hesap7898 ${FILES[*]} && chmod 644 ${FILES[*]}"

# .htaccess değiştiyse LiteSpeed yeniden okusun
if ! git diff --quiet HEAD -- .htaccess 2>/dev/null; then
  ssh "$SERVER" '/usr/local/lsws/bin/lswsctrl restart >/dev/null'
fi

code=$(curl -s -o /dev/null -w '%{http_code}' https://hesapasistanim.com/)
echo "Yayınlandı — https://hesapasistanim.com/ → $code"
