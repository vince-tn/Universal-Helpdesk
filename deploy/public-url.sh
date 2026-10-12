#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

url=$(docker compose logs tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1 || true)

if [ -z "$url" ]; then
    echo "No public address yet - the tunnel may still be starting, or is not part of this copy."
    echo "Check: docker compose logs --tail=30 tunnel"
    exit 1
fi

echo "$url"
