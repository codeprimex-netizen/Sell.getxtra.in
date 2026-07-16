#!/usr/bin/env bash
# Restore Code.getxtra.in from a logical dump, then optionally replay binlogs
# to a point in time (Req 17.5 / 22.2). Target RTO <= 1h.
#
#   ./restore.sh /var/backups/sell-getxtra/db-20250101T000000Z.sql.gz ["2025-01-01 12:34:56"]
set -euo pipefail

DUMP="${1:?usage: restore.sh <dump.sql.gz> [pitr-datetime]}"
PITR="${2:-}"
: "${DB_HOST:?}"; : "${DB_DATABASE:?}"; : "${DB_USERNAME:?}"; : "${DB_PASSWORD:?}"

echo "[restore] restoring ${DUMP} -> ${DB_DATABASE} @ ${DB_HOST}"
gunzip -c "${DUMP}" | mysql --host="${DB_HOST}" --user="${DB_USERNAME}" --password="${DB_PASSWORD}" "${DB_DATABASE}"

if [[ -n "${PITR}" ]]; then
  echo "[restore] replaying binlogs up to ${PITR}"
  # Adjust binlog paths as needed; --stop-datetime bounds PITR to the RPO window.
  mysqlbinlog --stop-datetime="${PITR}" /var/lib/mysql/binlogs/mysql-bin.* \
    | mysql --host="${DB_HOST}" --user="${DB_USERNAME}" --password="${DB_PASSWORD}" "${DB_DATABASE}"
fi

echo "[restore] running migrations to reconcile schema"
php bin/console migrate || true
echo "[restore] complete — verify /readyz and a smoke test before routing traffic"
