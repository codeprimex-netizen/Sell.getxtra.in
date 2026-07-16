# Disaster Recovery Plan — Code.getxtra.in (Req 17.5 / 22.2)

## Objectives
- **RPO ≤ 15 minutes** — max acceptable data loss.
- **RTO ≤ 1 hour** — max acceptable time to restore service.

## Backup strategy
| Asset            | Method                                   | Frequency | Retention |
|------------------|------------------------------------------|-----------|-----------|
| MySQL (logical)  | `mysqldump --single-transaction` (GTID)  | 15 min    | 7d local / 35d S3 |
| MySQL (PITR)     | Binlog shipping to S3                     | continuous| within RPO |
| Private uploads  | Versioned, encrypted S3 bucket + CRR      | continuous| 35d |
| Secrets          | Vault snapshots                           | daily     | 30d |

All off-site copies are encrypted (SSE-KMS) and stored in a versioned bucket
with cross-region replication.

## Restore drill (run quarterly)
1. Provision a clean environment (DB + app) from IaC.
2. Restore the latest dump: `deploy/backup/restore.sh <latest-dump>`.
3. For PITR, pass the target timestamp to replay binlogs within the RPO window.
4. Restore private uploads from the versioned bucket.
5. Run `php bin/console migrate`, then verify `/readyz` + a checkout smoke test.
6. Record actual RPO/RTO achieved and file gaps as action items.

## Regional failover
- Infra is defined as code (Terraform); a standby region can be stood up from
  the latest backups + replicated storage.
- DNS is switched to the standby LB after `/readyz` passes in the new region.

## Roles
- **Incident commander** coordinates; **DBA** runs restore; **SRE** validates
  probes/metrics; **Comms** updates the status page.
