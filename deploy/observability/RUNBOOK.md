# On-call Runbook — Code.getxtra.in

Observability stack (Req 15): structured JSON logs (Loki), RED + KPI metrics
(Prometheus/Grafana), distributed traces (OpenTelemetry / `traceparent`), and
health probes (`/healthz`, `/readyz`). Alerts route to on-call via
Alertmanager; event-driven alerts are also emitted by the app's `AlertService`.

## Dashboards & endpoints
- Grafana: import `grafana-dashboard.json` (uid `sell-getxtra-red`).
- Metrics scrape: `GET /metrics` (Prometheus text; optional bearer `METRICS_TOKEN`).
- Liveness: `GET /healthz` — process up.
- Readiness: `GET /readyz` — DB, cache, queue (critical) + search (degrades only).
- Traces: correlate by `trace_id` / `traceparent` across web → queue → jobs.

## Alerts

### high-error-rate
5xx ratio > 5% for 5m. Check recent deploys, `/readyz`, DB/gateway health, and
error logs filtered by `level=error` and the offending `request_id`/`trace_id`.

### high-latency
P95 > 1s for 10m. Inspect slow spans by `trace_id`, DB slow query log, cache hit
ratio, and upstream gateway latency.

### queue-backlog
`queue_depth` > 1000 for 10m. Scale workers (`bin/console queue:work`), check for
a poison message, and confirm the DB queue driver is healthy.

### dead-letter
Jobs entering `failed_jobs`. Inspect the failure in the dead-letter table, fix
the root cause, and replay. `AlertService::jobDeadLettered` fires immediately.

### payment-failures
Spike in `alerts_fired_total{alert="payment_failure"}`. Check gateway status,
webhook signature verification, and the ledger for stuck orders.

## Escalation
1. Acknowledge in Alertmanager.
2. Triage with the linked dashboard panel + logs by `trace_id`.
3. If customer-impacting > 15m, escalate to the secondary on-call.
