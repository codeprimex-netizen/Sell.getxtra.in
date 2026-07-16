# Rolling Deploy & Rollback Runbook (Req 23.4)

## Deploy model
- **Zero-downtime rolling update**: `maxUnavailable=0`, `maxSurge=1` on the web
  Deployment. Kubernetes brings up a new pod, waits for its `/readyz` probe,
  then retires an old one — so full capacity is maintained throughout.
- **Traffic gating**: the Service only routes to pods passing `/readyz`, which
  checks DB/cache/queue. A pod that can't reach its dependencies never receives
  traffic.
- **Graceful drain**: a `preStop` sleep + 30s termination grace lets in-flight
  requests finish before SIGTERM.

## Migrations (expand/contract)
Migrations are **expand-only** and run as a gated Job *before* the app rollout,
so old and new app versions coexist safely during the rollout. Destructive
"contract" changes (dropping columns) ship in a *later* release, after all pods
run the new code.

## Rollback

### Automatic
The CD pipeline runs `kubectl rollout status`; if it times out or fails, it
issues `kubectl rollout undo` and re-checks status. A failed production rollout
self-heals to the previous ReplicaSet.

### Manual
```bash
# Inspect history
kubectl rollout history deployment/sell-web

# Roll back to the previous revision
kubectl rollout undo deployment/sell-web
kubectl rollout status deployment/sell-web --timeout=300s

# Or pin a specific known-good revision
kubectl rollout undo deployment/sell-web --to-revision=<N>
```
`revisionHistoryLimit: 10` retains enough history to roll back several releases.

### Image pinning
Re-deploy a specific known-good image without a new build via the CD
`workflow_dispatch` input `image_tag`, or:
```bash
kubectl set image deployment/sell-web web=ghcr.io/codeprimex-netizen/code.getxtra.in:sha-<good>
```

## Post-rollback
1. Confirm `/readyz` is green and error rate/latency have recovered (Grafana).
2. If a migration must be reverted, run the paired down-migration only after
   all pods are on the compatible code version.
3. File an incident note and a follow-up to fix the root cause forward.
