#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
exec php artisan queue:work --sleep=3 --queue="$(php artisan task:queue-shard-list)"
