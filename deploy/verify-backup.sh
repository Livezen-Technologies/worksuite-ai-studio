#!/usr/bin/env bash
#
# Weekly restore test for WorkSuite AI Studio backups (installed as a root cron job by
# install-server.sh). Restores the newest nightly dump into a scratch database, checks that key
# tables exist and hold rows, drops the scratch database and writes a freshness metric for the
# BackupRestoreStale alert. Exits non-zero on any failure. Run as root (uses `mysql` via the
# root unix socket, like `sudo mysql`):
#
#   sudo APP_DIR=/var/www/ollamahub BACKUP_DIR=/var/backups/aistudio bash deploy/verify-backup.sh
#
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/ollamahub}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/aistudio}"
TEXTFILE_DIR="${TEXTFILE_DIR:-/var/lib/prometheus/node-exporter}"
CHECK_TABLES="${CHECK_TABLES:-users roles settings migrations}"   # must exist and hold rows
EXIST_TABLES="${EXIST_TABLES:-api_keys servers model_registry}"      # must exist (may be empty)
# Encrypted dumps (BACKUP_AGE_RECIPIENT in backup-db.sh) need the matching private key here.
AGE_IDENTITY="${BACKUP_AGE_IDENTITY:-}"

if [ "$(id -u)" -ne 0 ]; then
  echo "verify-backup: run as root (sudo)" >&2
  exit 1
fi

# shellcheck source-path=SCRIPTDIR source=lib-env.sh
. "$(dirname "$0")/lib-env.sh"
DB=$(env_get database.default.database "${APP_DIR}/.env"); DB=${DB:-ollamahub}
if ! [[ "${DB}" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "verify-backup: unexpected database name '${DB}'" >&2
  exit 1
fi
SCRATCH="${DB}_restore_check"

# shellcheck disable=SC2012  # file names are ours (nightly-YYYYMMDD-HHMMSS.sql.gz), ls -t is fine
DUMP=$(ls -1t "${BACKUP_DIR}"/nightly-*.sql.gz "${BACKUP_DIR}"/nightly-*.sql.gz.age 2>/dev/null | head -1 || true)
if [ -z "${DUMP}" ]; then
  echo "verify-backup: no nightly-*.sql.gz(.age) in ${BACKUP_DIR}" >&2
  exit 1
fi
if [[ "${DUMP}" == *.age ]] && [ -z "${AGE_IDENTITY}" ]; then
  echo "verify-backup: ${DUMP} is encrypted; set BACKUP_AGE_IDENTITY to the age key file to test restores" >&2
  exit 1
fi
read_dump() { if [[ "${DUMP}" == *.age ]]; then age -d -i "${AGE_IDENTITY}" "${DUMP}" | gunzip -c; else gunzip -c "${DUMP}"; fi; }

cleanup() { mysql -e "DROP DATABASE IF EXISTS \`${SCRATCH}\`" || true; }
trap cleanup EXIT

echo "verify-backup: restoring ${DUMP} into ${SCRATCH}"
mysql -e "DROP DATABASE IF EXISTS \`${SCRATCH}\`; CREATE DATABASE \`${SCRATCH}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
read_dump | mysql "${SCRATCH}"

for t in ${EXIST_TABLES}; do
  mysql -N -B -e "SELECT 1 FROM \`${SCRATCH}\`.\`${t}\` LIMIT 1" >/dev/null || { echo "verify-backup: table ${t} missing" >&2; exit 1; }
done
for t in ${CHECK_TABLES}; do
  n=$(mysql -N -B -e "SELECT COUNT(*) FROM \`${SCRATCH}\`.\`${t}\`") || { echo "verify-backup: table ${t} missing" >&2; exit 1; }
  if [ "${n}" -le 0 ]; then
    echo "verify-backup: table ${t} is empty" >&2
    exit 1
  fi
  echo "    ${t}: ${n} rows"
done

if [ -d "${TEXTFILE_DIR}" ]; then
  echo "aistudio_backup_restore_ok_seconds $(date +%s)" > "${TEXTFILE_DIR}/aistudio_backup_restore.prom.$$"
  mv "${TEXTFILE_DIR}/aistudio_backup_restore.prom.$$" "${TEXTFILE_DIR}/aistudio_backup_restore.prom"
fi
echo "verify-backup ok: ${DUMP}"
