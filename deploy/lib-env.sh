# shellcheck shell=bash
# Shared helper, sourced by backup-db.sh and verify-backup.sh.
#
# env_get KEY FILE: print the value of KEY from a CodeIgniter .env file. The key is compared
# literally (dots in "database.default.password" are not regex wildcards); surrounding spaces
# and one pair of double or single quotes around the value are stripped.
env_get() {
  awk -F= -v k="$1" '{key=$1; gsub(/^[ \t]+|[ \t]+$/, "", key)} key==k {sub(/^[^=]*=[ \t]*/, ""); sub(/[ \t\r]+$/, ""); if ($0 ~ /^".*"$/ || $0 ~ /^\x27.*\x27$/) $0 = substr($0, 2, length($0) - 2); print; exit}' "$2"
}
