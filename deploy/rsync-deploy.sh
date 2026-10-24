#!/usr/bin/env bash
#
# Local → server deploy. Builds the React bundle, then rsyncs the CI4 app to
# 40.160.57.17 (excluding local-only files). Run from the project root on your Mac.
#
#   bash deploy/rsync-deploy.sh
#
set -euo pipefail

HOST=ubuntu@40.160.57.17
DEST=/var/www/ollamahub
LOCAL="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Building React bundle"
( cd "${LOCAL}/react-app" && npm run build )

echo "==> Syncing to ${HOST}:${DEST}"
rsync -avz --delete \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'writable/' \
  --exclude 'node_modules' \
  --exclude 'react-app/node_modules' \
  --exclude 'react-app/dist' \
  "${LOCAL}/" "${HOST}:${DEST}/"

echo "==> Running migrations on server"
ssh "${HOST}" "cd ${DEST} && php8.3 spark migrate --all && sudo systemctl restart ollamahub-queue php8.3-fpm"

echo "==> Deployed. Visit http://40.160.57.17/app/"
