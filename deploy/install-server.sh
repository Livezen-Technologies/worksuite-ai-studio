#!/usr/bin/env bash
#
# WorkSuite AI Studio — one-time server provisioning for Ubuntu 24.04.
# Installs the runtime, creates the database, configures nginx + php-fpm, runs
# migrations/seeds, installs Ollama (CPU-only), Prometheus + node_exporter, and a
# systemd queue worker + cron. Idempotent where practical. Run as the ubuntu user
# with sudo:
#   OLLAMAHUB_DB_PASS=... AISTUDIO_DOMAIN=aistudio.example.com AISTUDIO_CERT_EMAIL=ops@example.com bash install-server.sh
#
set -euo pipefail

APP_DIR=/var/www/ollamahub
DB_NAME=ollamahub
DB_USER=ollamahub
PHP=php8.3

echo "==> Updating apt and installing packages"
sudo apt-get update -y
sudo apt-get install -y nginx mysql-server redis-server \
  ${PHP}-fpm ${PHP}-cli ${PHP}-mysql ${PHP}-mbstring ${PHP}-xml ${PHP}-curl ${PHP}-intl ${PHP}-zip \
  prometheus prometheus-node-exporter curl unzip git

echo "==> Creating database + app DB user"
DB_PASS="${OLLAMAHUB_DB_PASS:?set OLLAMAHUB_DB_PASS before running}"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
-- Least privilege: data access plus the DDL migrations need. No routines, triggers, events,
-- FILE or GRANT. (Existing installs: see docs/runbook.md "Tighten database privileges".)
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE TEMPORARY TABLES
  ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Installing Ollama (CPU-only on this node)"
if ! command -v ollama >/dev/null 2>&1; then
  curl -fsSL https://ollama.com/install.sh | sh
fi
sudo systemctl enable --now ollama

echo "==> nginx vhost"
DOMAIN="${AISTUDIO_DOMAIN:?set AISTUDIO_DOMAIN (e.g. aistudio.example.com) before running}"
CERT_EMAIL="${AISTUDIO_CERT_EMAIL:?set AISTUDIO_CERT_EMAIL for certificate expiry notices}"
sudo cp "${APP_DIR}/deploy/nginx-security-headers.conf" /etc/nginx/snippets/aistudio-security.conf
echo 'limit_req_zone $binary_remote_addr zone=aistudio_auth:10m rate=30r/m;' | sudo tee /etc/nginx/conf.d/aistudio-ratelimit.conf >/dev/null
sed "s/__DOMAIN__/${DOMAIN}/" "${APP_DIR}/deploy/nginx-ollamahub.conf" | sudo tee /etc/nginx/sites-available/ollamahub >/dev/null
sudo ln -sf /etc/nginx/sites-available/ollamahub /etc/nginx/sites-enabled/ollamahub
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

echo "==> HTTPS (Let's Encrypt) + redirect HTTP -> HTTPS"
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d "${DOMAIN}" --redirect --non-interactive --agree-tos -m "${CERT_EMAIL}"

echo "==> App configuration (.env, only if missing)"
cd "${APP_DIR}"
if [ ! -f .env ]; then
  # Fresh secrets every install: the app refuses placeholder or default secrets in production.
  sed -e "s|^app.baseURL = .*|app.baseURL = 'https://${DOMAIN}/'|" \
      -e "s|^database.default.password = .*|database.default.password = ${DB_PASS}|" \
      -e "s|^JWT_SECRET = .*|JWT_SECRET = $(openssl rand -hex 32)|" \
      -e "s|^app.forceGlobalSecureRequests = .*|app.forceGlobalSecureRequests = true|" \
      deploy/env.production.example > .env
  chmod 640 .env && sudo chown "$(id -un)":www-data .env
  echo "    wrote .env (JWT_SECRET generated)"
fi

echo "==> App: composer install (no-dev), migrate, seed"
if [ ! -f vendor/autoload.php ]; then
  curl -sS https://getcomposer.org/installer | ${PHP} -- --install-dir=/usr/local/bin --filename=composer
  composer install --no-dev --optimize-autoloader
fi
${PHP} spark migrate --all
${PHP} spark db:seed AuthSeeder
sudo chown -R www-data:www-data "${APP_DIR}/writable"

echo "==> Prometheus scrape config (this node's node_exporter)"
sudo cp "${APP_DIR}/deploy/prometheus-ollamahub.yml" /etc/prometheus/prometheus.yml
sudo cp "${APP_DIR}/deploy/prometheus-alerts.yml" /etc/prometheus/aistudio-alerts.yml
sudo systemctl restart prometheus prometheus-node-exporter

echo "==> systemd queue worker + schedule cron"
sudo cp "${APP_DIR}/deploy/ollamahub-queue.service" /etc/systemd/system/ollamahub-queue.service
sudo systemctl daemon-reload
sudo systemctl enable --now ollamahub-queue
( crontab -l 2>/dev/null | grep -v 'spark schedule:run' ; \
  echo "* * * * * cd ${APP_DIR} && ${PHP} spark schedule:run >/dev/null 2>&1" ) | crontab -

echo "==> Nightly database backup (03:17) -> /var/backups/aistudio"
sudo mkdir -p /var/backups/aistudio && sudo chmod 700 /var/backups/aistudio
( sudo crontab -l 2>/dev/null | grep -v 'deploy/backup-db.sh' ; \
  echo "17 3 * * * APP_DIR=${APP_DIR} bash ${APP_DIR}/deploy/backup-db.sh >> /var/log/aistudio-backup.log 2>&1" ) | sudo crontab -
echo "    Set BACKUP_RSYNC_TARGET in that cron line to copy backups off this server (see docs/runbook.md)."

echo "==> Log housekeeping: app logs kept 30 days, backup log rotated weekly"
( sudo crontab -l 2>/dev/null | grep -v 'writable/logs' ; \
  echo "41 4 * * * find ${APP_DIR}/writable/logs -name 'log-*.log' -mtime +30 -delete" ) | sudo crontab -
printf '/var/log/aistudio-backup.log {\n  weekly\n  rotate 8\n  compress\n  missingok\n  notifempty\n}\n' | sudo tee /etc/logrotate.d/aistudio >/dev/null

echo "==> Firewall: SSH, HTTP, HTTPS only"
if command -v ufw >/dev/null 2>&1; then
  sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
fi

echo "==> Done. Pull a baseline model:  ollama pull llama3.2"
echo "    Then register this node in WorkSuite AI Studio at  https://${DOMAIN}/app/  (api_url http://127.0.0.1:11434)"
