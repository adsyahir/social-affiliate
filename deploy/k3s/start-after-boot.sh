#!/usr/bin/env bash
# Run from cron at boot:  @reboot /home/<user>/apps/social-affiliate/deploy/k3s/start-after-boot.sh
#
# Docker starts before k3s, so the app container's port (bound to the cluster bridge)
# cannot be published yet and the container stays down. Wait for the bridge, then start.

set -euo pipefail

cd "$(dirname "$0")/../.."
set -a && . ./.env && set +a
APP_HOST_IP="${APP_BIND:-10.42.0.1:8090}" && APP_HOST_IP="${APP_HOST_IP%:*}"

for _ in $(seq 1 120); do ip -4 addr show | grep -q "inet ${APP_HOST_IP}/" && break; sleep 5; done

docker compose --env-file .env -f deploy/k3s/compose.yaml up -d
