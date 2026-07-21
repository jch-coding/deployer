#!/usr/bin/env bash
# Optional: compile official Central protos if protoc + php plugin are installed.
# Deployer ships a hand-written decoder compatible with these field numbers.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/app/Protobuf/Central"
mkdir -p "$OUT"
if ! command -v protoc >/dev/null 2>&1; then
  echo "protoc not found; skipping generation (runtime uses ClassicMonitoringStreamDecoder)."
  exit 0
fi
protoc \
  --php_out="$OUT" \
  -I "$ROOT/proto/central" \
  "$ROOT/proto/central/streaming.proto" \
  "$ROOT/proto/central/monitoring.proto"
echo "Generated PHP into $OUT"
