#!/usr/bin/env bash
#
# Nightly database backup for WorkSuite AI Studio (installed as a cron job by install-server.sh).
#
#   APP_DIR=/var/www/ollamahub BACKUP_DIR=/var/backups/aistudio bash deploy/backup-db.sh
#
# Keeps KEEP_DAYS days locally. Set BACKUP_RSYNC_TARGET (e.g. backup@backup-host:/srv/aistudio/)
# to also copy each dump off this server: a backup on the same disk does not survive losing it.
#
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/ollamahub}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/aistudio}"
KEEP_DAYS="${KEEP_DAYS:-14}"

# shellcheck source-path=SCRIPTDIR source=lib-env.sh
. "$(dirname "$0")/lib-env.sh"
DB=$(env_get database.default.database "${APP_DIR}/.env"); USER=$(env_get database.default.username "${APP_DIR}/.env"); PASS=$(env_get database.default.password "${APP_DIR}/.env")
HOSTDB=$(env_get database.default.hostname "${APP_DIR}/.env"); HOSTDB=${HOSTDB:-127.0.0.1}

mkdir -p "${BACKUP_DIR}" && chmod 700 "${BACKUP_DIR}"
OUT="${BACKUP_DIR}/nightly-$(date +%Y%m%d-%H%M%S).sql.gz"
MYSQL_PWD="${PASS}" mysqldump --single-transaction --routines --no-tablespaces -h "${HOSTDB}" -u "${USER}" "${DB}" | gzip > "${OUT}.part"
mv "${OUT}.part" "${OUT}"
gzip -t "${OUT}"
find "${BACKUP_DIR}" -name 'nightly-*.sql.gz' -mtime "+${KEEP_DAYS}" -delete

if [ -n "${BACKUP_RSYNC_TARGET:-}" ]; then
  rsync -a "${OUT}" "${BACKUP_RSYNC_TARGET}"
fi
# Freshness metric for the BackupStale alert (node_exporter textfile collector, if present).
TEXTFILE_DIR="${TEXTFILE_DIR:-/var/lib/prometheus/node-exporter}"
if [ -d "${TEXTFILE_DIR}" ]; then
  echo "aistudio_backup_last_success_seconds $(date +%s)" > "${TEXTFILE_DIR}/aistudio_backup.prom.$$"
  mv "${TEXTFILE_DIR}/aistudio_backup.prom.$$" "${TEXTFILE_DIR}/aistudio_backup.prom"
fi
echo "backup ok: ${OUT}"
