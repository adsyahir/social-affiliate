#!/usr/bin/env bash
# Deploy (or redeploy) on the k3s server, from the repo checkout:
#
#   ./deploy/k3s/deploy.sh
#
# Pulls main, rebuilds the image, restarts the stack (migrations run on app start)
# and applies the Traefik routing. Safe to run repeatedly.

set -euo pipefail

cd "$(dirname "$0")/../.."

[ -f .env ] || { echo ".env is missing; see deploy/k3s/README.md"; exit 1; }

set -a && . ./.env && set +a
: "${DOMAIN:?set DOMAIN in .env}" "${K8S_NAMESPACE:?set K8S_NAMESPACE in .env}"

APP_BIND="${APP_BIND:-10.42.0.1:8090}"
export APP_HOST_IP="${APP_BIND%:*}" APP_HOST_PORT="${APP_BIND##*:}"

echo "==> pulling main"
git pull --ff-only

# The app binds to the cluster bridge, which only exists once k3s is up (matters after a reboot).
for _ in $(seq 1 60); do ip -4 addr show | grep -q "inet ${APP_HOST_IP}/" && break; sleep 5; done
ip -4 addr show | grep -q "inet ${APP_HOST_IP}/" || { echo "${APP_HOST_IP} is not on this host; is k3s running?"; exit 1; }

echo "==> building and starting"
docker compose --env-file .env -f deploy/k3s/compose.yaml up -d --build --wait

echo "==> routing ${DOMAIN}"
envsubst < deploy/k3s/ingress.yaml | kubectl apply -f -

docker image prune -f >/dev/null
echo "==> deployed $(git rev-parse --short HEAD) at https://${DOMAIN}"
