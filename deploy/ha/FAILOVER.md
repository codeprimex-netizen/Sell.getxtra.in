# Database Replication & Failover Runbook (Req 17.3)

## Topology
- **Primary** (`mysql-primary`): accepts writes + transactions. GTID + ROW binlog.
- **Replica** (`mysql-replica`): `read_only=ON`, replicates via GTID. The app
  routes reads here through `DB_READ_HOST`; inside a transaction reads stay on
  the primary for consistency (see `ConnectionManager::read`).

## Setup (one-time)
1. Create a replication user on the primary:
   `CREATE USER 'repl'@'%' IDENTIFIED BY '***'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';`
2. Point the replica at the primary and start replication:
   `CHANGE REPLICATION SOURCE TO SOURCE_HOST='mysql-primary', SOURCE_AUTO_POSITION=1, SOURCE_USER='repl', SOURCE_PASSWORD='***'; START REPLICA;`
3. Verify: `SHOW REPLICA STATUS\G` → `Replica_IO_Running=Yes`, `Replica_SQL_Running=Yes`, `Seconds_Behind_Source≈0`.

## Monitoring
- Alert on replica lag (`Seconds_Behind_Source > 30`) and on IO/SQL threads stopped.
- The `/readyz` probe marks the app unavailable if the primary is unreachable.

## Planned failover (maintenance)
1. Stop writes (enable maintenance mode / scale web to read-only).
2. Ensure replica caught up: `Seconds_Behind_Source = 0`.
3. Promote replica: `STOP REPLICA; RESET REPLICA ALL; SET GLOBAL read_only=OFF;`
4. Repoint `DB_HOST` (writes) to the promoted node; update `DB_READ_HOST`.
5. Rebuild the old primary as a replica of the new primary (step 2 above).

## Unplanned failover (primary down)
1. Confirm the primary is truly down (avoid split-brain).
2. Promote the most up-to-date replica (highest GTID) as above.
3. Roll the app config (`DB_HOST`) and restart web + worker tiers.
4. Fence the old primary; when recovered, reintroduce it as a replica.

## Targets
- Detection + promotion automated via orchestrator (e.g. Orchestrator/MHA) to
  meet **RTO ≤ 1h**; GTID + semi-sync keeps data loss within **RPO ≤ 15m**.
