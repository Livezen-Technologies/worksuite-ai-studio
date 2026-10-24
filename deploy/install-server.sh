#!/usr/bin/env bash
#
# OllamaHub — one-time server provisioning for Ubuntu 24.04 (target: 40.160.57.17).
# Installs the runtime, creates the database, configures nginx + php-fpm, runs
# migrations/seeds, installs Ollama (CPU-only), Prometheus + node_exporter, and a
# systemd queue worker + cron. Idempotent where practical. Run as the ubuntu user
# with sudo:   bash install-server.sh
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
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Installing Ollama (CPU-only on this node)"
if ! command -v ollama >/dev/null 2>&1; then
  curl -fsSL https://ollama.com/install.sh | sh
fi
sudo systemctl enable --now ollama

echo "==> nginx vhost"
sudo cp "${APP_DIR}/deploy/nginx-ollamahub.conf" /etc/nginx/sites-available/ollamahub
sudo ln -sf /etc/nginx/sites-available/ollamahub /etc/nginx/sites-enabled/ollamahub
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

echo "==> App: composer install (no-dev), migrate, seed"
cd "${APP_DIR}"
if [ ! -f vendor/autoload.php ]; then
  curl -sS https://getcomposer.org/installer | ${PHP} -- --install-dir=/usr/local/bin --filename=composer
  composer install --no-dev --optimize-autoloader
fi
${PHP} spark migrate --all
${PHP} spark db:seed AuthSeeder
sudo chown -R www-data:www-data "${APP_DIR}/writable"

echo "==> Prometheus scrape config (this node's node_exporter)"
sudo cp "${APP_DIR}/deploy/prometheus-ollamahub.yml" /etc/prometheus/prometheus.yml
sudo systemctl restart prometheus prometheus-node-exporter

echo "==> systemd queue worker + schedule cron"
sudo cp "${APP_DIR}/deploy/ollamahub-queue.service" /etc/systemd/system/ollamahub-queue.service
sudo systemctl daemon-reload
sudo systemctl enable --now ollamahub-queue
( crontab -l 2>/dev/null | grep -v 'spark schedule:run' ; \
  echo "* * * * * cd ${APP_DIR} && ${PHP} spark schedule:run >/dev/null 2>&1" ) | crontab -

echo "==> Done. Pull a baseline model:  ollama pull llama3.2"
echo "    Then register this node in OllamaHub at  http://<host>/app/  (api_url http://127.0.0.1:11434)"
