#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}"
