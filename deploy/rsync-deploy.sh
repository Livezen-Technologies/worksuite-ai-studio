#!/usr/bin/env bash
#
# Local → server deploy for WorkSuite AI Studio. Run from anywhere on your machine:
#
#   bash deploy/rsync-deploy.sh                 # uses the defaults below
#   DEPLOY_HOST=ubuntu@aistudio.example.com bash deploy/rsync-deploy.sh
#
# Steps: build the frontend → back up the database on the server → sync code (never touching
# server-only files) → migrate → restart workers. If the migration fails, the script stops and
# prints the restore command for the backup it just took.
#
set -euo pipefail

HOST="${DEPLOY_HOST:-ubuntu@aistudio.livezentech.com}"
DEST="${DEPLOY_DIR:-/var/www/ollamahub}"
BACKUP_DIR="${DEPLOY_BACKUP_DIR:-/var/backups/aistudio}"
URL="${DEPLOY_URL:-https://aistudio.livezentech.com}"

LOCAL="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Building frontend (frontend/ -> public/app)"
( cd "${LOCAL}/frontend" && npm ci --no-audit --no-fund && npm run build )

echo "==> Backing up the database on ${HOST}"
STAMP="$(date +%Y%m%d-%H%M%S)"
# shellcheck disable=SC2029  # local values are passed to the remote on purpose
ssh "${HOST}" "DEST='${DEST}' BACKUP_DIR='${BACKUP_DIR}' STAMP='${STAMP}' bash -s" <<'REMOTE'
set -euo pipefail
sudo mkdir -p "${BACKUP_DIR}" && sudo chown "$(id -un)" "${BACKUP_DIR}" && chmod 700 "${BACKUP_DIR}"
env_get() { grep -E "^\s*$1\s*=" "${DEST}/.env" | head -1 | sed -E 's/^[^=]+=\s*//; s/^"(.*)"$/\1/; s/\s+$//'; }
DB=$(env_get database.default.database); USER=$(env_get database.default.username); PASS=$(env_get database.default.password)
HOSTDB=$(env_get database.default.hostname); HOSTDB=${HOSTDB:-127.0.0.1}
MYSQL_PWD="${PASS}" mysqldump --single-transaction --routines --no-tablespaces -h "${HOSTDB}" -u "${USER}" "${DB}" \
  | gzip > "${BACKUP_DIR}/predeploy-${STAMP}.sql.gz"
ls -1t "${BACKUP_DIR}"/predeploy-*.sql.gz | tail -n +11 | xargs -r rm -f   # keep the last 10
echo "    backup: ${BACKUP_DIR}/predeploy-${STAMP}.sql.gz"
REMOTE

echo "==> Syncing to ${HOST}:${DEST}"
# Never delete or overwrite what only exists on the server: config, user data, uploads,
# node installers, logs. --delete only prunes code that was removed from the repo.
rsync -az --delete \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.env' \
  --exclude 'writable/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'node-app/' \
  --exclude 'mcp-server/node_modules/' \
  --exclude 'mcp-server/dist/' \
  --exclude 'public/node/downloads/' \
  --exclude 'public/uploads/' \
  --exclude 'tests/' \
  "${LOCAL}/" "${HOST}:${DEST}/"

echo "==> Installing PHP dependencies, migrating and restarting"
# shellcheck disable=SC2029  # DEST is expanded locally on purpose
if ! ssh "${HOST}" "set -e; cd '${DEST}' && composer install --no-dev --no-interaction --optimize-autoloader -q \
      && php8.3 spark migrate --all \
      && php8.3 spark cache:clear \
      && sudo systemctl restart ollamahub-queue php8.3-fpm"; then
  echo "!! Deploy step failed. The database backup from before this deploy is:" >&2
  echo "   ${HOST}:${BACKUP_DIR}/predeploy-${STAMP}.sql.gz" >&2
  echo "   Restore: see docs/runbook.md (\"Restore the database\")." >&2
  exit 1
fi

echo "==> Health check"
if ! curl -fsS --retry 3 --retry-delay 5 --retry-connrefused "${URL}/api/ping" >/dev/null; then
  echo "!! Health check failed: ${URL}/api/ping did not answer 200. The database backup from before this deploy is:" >&2
  echo "   ${HOST}:${BACKUP_DIR}/predeploy-${STAMP}.sql.gz" >&2
  echo "   Restore: see docs/runbook.md (\"Restore the database\")." >&2
  exit 1
fi
echo "    ok"

echo "==> Deployed. ${URL}/app/"
