#!/usr/bin/env bash
# Helper for a gated, zero-downtime rollout with auto-rollback (Req 23.4).
#
#   ./rollout.sh <image-tag>
#
# Runs expand-only migrations, rolls web + worker, and undoes the web rollout
# if it fails to become ready.
set -euo pipefail

TAG="${1:?usage: rollout.sh <image-tag>}"
IMAGE="${IMAGE:-ghcr.io/codeprimex-netizen/sell.getxtra.in}"

echo "[rollout] running gated migrations @ ${TAG}"
kubectl set image job/sell-migrate migrate="${IMAGE}:${TAG}" --local -o yaml | kubectl apply -f -
kubectl wait --for=condition=complete --timeout=600s job/sell-migrate

echo "[rollout] deploying web + worker @ ${TAG}"
kubectl set image deployment/sell-web web="${IMAGE}:${TAG}"
kubectl set image deployment/sell-worker worker="${IMAGE}:${TAG}"

if ! kubectl rollout status deployment/sell-web --timeout=300s; then
  echo "[rollout] web rollout FAILED — rolling back"
  kubectl rollout undo deployment/sell-web
  kubectl rollout status deployment/sell-web --timeout=300s
  exit 1
fi

kubectl rollout status deployment/sell-worker --timeout=300s || kubectl rollout undo deployment/sell-worker
echo "[rollout] complete @ ${TAG}"
