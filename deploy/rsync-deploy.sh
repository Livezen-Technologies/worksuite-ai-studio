#!/usr/bin/env bash
#
# Local → server deploy for WorkSuite AI Studio. Run from anywhere on your machine:
#
#   bash deploy/rsync-deploy.sh                 # uses the defaults below
#   DEPLOY_HOST=ubuntu@aistudio.example.com bash deploy/rsync-deploy.sh
#
# Steps: build the frontend → back up the database on the server → stop the queue worker → sync
# code (never touching server-only files; files are swapped in at the end of the transfer) →
# migrate → restart workers. Migrations only add tables and columns, so the old code keeps
# working against the new schema; the window where new code meets the old schema is the few
# seconds between the file swap and the migration. If the migration fails, the queue worker is
# left stopped and the script prints the restore command for the backup it just took.
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
ssh "${HOST}" "sudo DEST='${DEST}' BACKUP_DIR='${BACKUP_DIR}' STAMP='${STAMP}' bash -s" <<'REMOTE'
set -euo pipefail
# Runs as root (sudo bash -s below): the backup dir stays root:root 0700, like the nightly backups.
install -d -o root -g root -m 700 "${BACKUP_DIR}"
# Same exact-key lookup as deploy/lib-env.sh (inlined: the server may not have it before the first sync).
env_get() {
  awk -F= -v k="$1" '{key=$1; gsub(/^[ \t]+|[ \t]+$/, "", key)} key==k {sub(/^[^=]*=[ \t]*/, ""); sub(/[ \t\r]+$/, ""); if ($0 ~ /^".*"$/ || $0 ~ /^\x27.*\x27$/) $0 = substr($0, 2, length($0) - 2); print; exit}' "${DEST}/.env"
}
DB=$(env_get database.default.database); USER=$(env_get database.default.username); PASS=$(env_get database.default.password)
HOSTDB=$(env_get database.default.hostname); HOSTDB=${HOSTDB:-127.0.0.1}
MYSQL_PWD="${PASS}" mysqldump --single-transaction --routines --no-tablespaces -h "${HOSTDB}" -u "${USER}" "${DB}" \
  | gzip > "${BACKUP_DIR}/predeploy-${STAMP}.sql.gz"
ls -1t "${BACKUP_DIR}"/predeploy-*.sql.gz | tail -n +11 | xargs -r rm -f   # keep the last 10
echo "    backup: ${BACKUP_DIR}/predeploy-${STAMP}.sql.gz"
REMOTE

echo "==> Stopping the queue worker (no jobs run on half-deployed code)"
ssh "${HOST}" "sudo systemctl stop ollamahub-queue"

echo "==> Syncing to ${HOST}:${DEST}"
# --delay-updates stages every changed file and renames them all at the end, so the live tree
# is never a mix of old and new files for the length of the transfer.
# Never delete or overwrite what only exists on the server: config, user data, uploads,
# node installers, logs. --delete only prunes code that was removed from the repo.
rsync -az --delete --delay-updates \
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
  echo "!! Deploy step failed. The queue worker is stopped; start it with: sudo systemctl start ollamahub-queue" >&2
  echo "   The database backup from before this deploy is:" >&2
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
