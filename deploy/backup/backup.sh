#!/usr/bin/env bash
# Database + object-storage backup for Sell.getxtra.in (Req 17.5 / 22.2).
#
# Runs every 15 minutes via cron to meet RPO <= 15m:
#   */15 * * * * /app/deploy/backup/backup.sh >> /var/log/backup.log 2>&1
#
# Combines a consistent logical dump with continuous binlog shipping (PITR).
set -euo pipefail

: "${DB_HOST:?}"; : "${DB_DATABASE:?}"; : "${DB_USERNAME:?}"; : "${DB_PASSWORD:?}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/sell-getxtra}"
S3_BUCKET="${BACKUP_S3_BUCKET:-}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DUMP="${BACKUP_DIR}/db-${STAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

echo "[backup] dumping ${DB_DATABASE} @ ${DB_HOST} -> ${DUMP}"
mysqldump \
  --host="${DB_HOST}" --user="${DB_USERNAME}" --password="${DB_PASSWORD}" \
  --single-transaction --quick --routines --triggers --set-gtid-purged=ON \
  "${DB_DATABASE}" | gzip -9 > "${DUMP}"

# Off-site copy (encrypted, versioned bucket) for durability.
if [[ -n "${S3_BUCKET}" ]]; then
  echo "[backup] uploading to s3://${S3_BUCKET}/db/"
  aws s3 cp "${DUMP}" "s3://${S3_BUCKET}/db/" --sse aws:kms
  # Ship binlogs for point-in-time recovery within the RPO window.
  aws s3 sync /var/lib/mysql/binlogs "s3://${S3_BUCKET}/binlogs/" --sse aws:kms || true
fi

# Retain 7 days locally; lifecycle policy handles long-term retention in S3.
find "${BACKUP_DIR}" -name 'db-*.sql.gz' -mtime +7 -delete
echo "[backup] done ${STAMP}"
