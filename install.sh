#!/usr/bin/env bash
# Usage: sudo ./install.sh   (add --with-timer to enable the systemd timer)
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
command -v php >/dev/null || { echo "PHP CLI not found"; exit 1; }

DEST=/opt/php-server-health
SRC="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$DEST"
cp -r "$SRC/bin" "$SRC/src" "$SRC/composer.json" "$DEST/"
chmod +x "$DEST/bin/php-server-health"
ln -sf "$DEST/bin/php-server-health" /usr/local/bin/php-server-health

[ -f /etc/php-server-health.json ] || cp "$SRC/config/health.example.json" /etc/php-server-health.json

if [ "${1:-}" = "--with-timer" ]; then
    cp "$SRC"/systemd/php-server-health.{service,timer} /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now php-server-health.timer
fi
echo "Installed. Try: php-server-health"
