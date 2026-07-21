#!/usr/bin/env bash
# Produce repeatability and immutable-RC1 comparison artifacts on one runner.

set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
HOST=${1:-127.0.0.1}
PORT=${2:-6383}
OUTPUT_DIR=${3:-"$ROOT_DIR/build/benchmarks"}
REFERENCE_TAG=0.1.0-rc1

mkdir -p "$OUTPUT_DIR"

BENCHMARK_TMP=$(mktemp -d)
cleanup() {
    rm -rf "$BENCHMARK_TMP"
}
trap cleanup EXIT

REFERENCE_ROOT="$BENCHMARK_TMP/reference"
mkdir -p "$REFERENCE_ROOT"
git -C "$ROOT_DIR" archive "$REFERENCE_TAG" | tar -x -C "$REFERENCE_ROOT"

IMAGE_DIGEST=${MINCEMEAT_BENCHMARK_BACKEND_IMAGE_DIGEST:-}
if [[ -z "$IMAGE_DIGEST" ]] && command -v docker >/dev/null 2>&1; then
    REDIS_CONTAINER=$(docker compose -f "$ROOT_DIR/docker-compose.yml" ps -q redis8 2>/dev/null || true)
    if [[ -n "$REDIS_CONTAINER" ]]; then
        IMAGE_DIGEST=$(docker inspect --format '{{.Image}}' "$REDIS_CONTAINER")
    fi
fi
if [[ -z "$IMAGE_DIGEST" ]]; then
    echo "Controlled benchmark requires MINCEMEAT_BENCHMARK_BACKEND_IMAGE_DIGEST or the local redis8 Compose service." >&2
    exit 2
fi

RUNNER_ID=${MINCEMEAT_BENCHMARK_RUNNER:-local-controlled-$(uname -m)}
CURRENT_COMMIT=$(git -C "$ROOT_DIR" rev-parse HEAD)
if ! git -C "$ROOT_DIR" diff --quiet -- .; then
    CURRENT_COMMIT="${CURRENT_COMMIT}+working-tree"
fi
REFERENCE_COMMIT=$(git -C "$ROOT_DIR" rev-list -n 1 "$REFERENCE_TAG")

run_report() {
    local label=$1
    local commit=$2
    local runtime_root=$3
    local output=$4
    shift 4

    MINCEMEAT_BENCHMARK_RUNNER="$RUNNER_ID" \
    MINCEMEAT_BENCHMARK_BACKEND_IMAGE_DIGEST="$IMAGE_DIGEST" \
    MINCEMEAT_BENCHMARK_COMMIT="$commit" \
        php -d xdebug.mode=off -d pcov.enabled=0 -d opcache.enable_cli=0 \
        "$ROOT_DIR/tools/benchmark-soak.php" "$HOST" "$PORT" \
        --json --label="$label" --runtime-root="$runtime_root" --output="$output" "$@" >/dev/null
}

run_report rc1-reference "$REFERENCE_COMMIT" "$REFERENCE_ROOT" "$OUTPUT_DIR/rc1-reference.json" --skip-guardrails
run_report rc2-run-1 "$CURRENT_COMMIT" "$ROOT_DIR" "$OUTPUT_DIR/rc2-run-1.json"
run_report rc2-run-2 "$CURRENT_COMMIT" "$ROOT_DIR" "$OUTPUT_DIR/rc2-run-2.json"

php "$ROOT_DIR/tools/compare-benchmark-reports.php" \
    "$OUTPUT_DIR/rc2-run-1.json" "$OUTPUT_DIR/rc2-run-2.json" \
    --mode=repeatability --output="$OUTPUT_DIR/repeatability-comparison.json"
php "$ROOT_DIR/tools/compare-benchmark-reports.php" \
    "$OUTPUT_DIR/rc1-reference.json" "$OUTPUT_DIR/rc2-run-1.json" \
    --mode=release --output="$OUTPUT_DIR/rc1-to-rc2-comparison.json"

echo "Controlled benchmark artifacts written to $OUTPUT_DIR"
