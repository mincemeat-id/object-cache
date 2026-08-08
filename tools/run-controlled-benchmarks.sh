#!/usr/bin/env bash
# Produce repeatability and immutable-RC3 comparison artifacts on one runner.

set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
HOST=${1:-127.0.0.1}
PORT=${2:-6383}
OUTPUT_DIR=${3:-"$ROOT_DIR/build/benchmarks"}
REFERENCE_TAG=0.1.0-rc3

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

run_memory() {
    local label=$1
    local commit=$2
    local runtime_root=$3
    local output=$4

    MINCEMEAT_BENCHMARK_RUNNER="$RUNNER_ID" \
    MINCEMEAT_BENCHMARK_BACKEND_IMAGE_DIGEST="$IMAGE_DIGEST" \
    MINCEMEAT_BENCHMARK_COMMIT="$commit" \
        php -d xdebug.mode=off -d pcov.enabled=0 -d opcache.enable_cli=0 \
        "$ROOT_DIR/tools/benchmark-memory.php" "$HOST" "$PORT" \
        --label="$label" --runtime-root="$runtime_root" --output="$output" >/dev/null
}

run_report rc3-reference "$REFERENCE_COMMIT" "$REFERENCE_ROOT" "$OUTPUT_DIR/rc3-reference.json" --skip-guardrails
run_report rc4-run-1 "$CURRENT_COMMIT" "$ROOT_DIR" "$OUTPUT_DIR/rc4-run-1.json"
run_report rc4-run-2 "$CURRENT_COMMIT" "$ROOT_DIR" "$OUTPUT_DIR/rc4-run-2.json"

run_memory rc3-memory "$REFERENCE_COMMIT" "$REFERENCE_ROOT" "$OUTPUT_DIR/rc3-memory.json"
run_memory rc4-memory "$CURRENT_COMMIT" "$ROOT_DIR" "$OUTPUT_DIR/rc4-memory.json"

# Comparisons write their artifacts even when a metric fails (e.g. a noisy soak
# workload), so run both and record the aggregate gate rather than aborting on
# the first failure. Hard drift (environment/harness/workload) still aborts
# inside the tool before any artifact is written.
COMPARISON_FAILURES=0
if php "$ROOT_DIR/tools/compare-benchmark-reports.php" \
    "$OUTPUT_DIR/rc4-run-1.json" "$OUTPUT_DIR/rc4-run-2.json" \
    --mode=repeatability --output="$OUTPUT_DIR/repeatability-comparison.json"; then
    :
else
    COMPARISON_FAILURES=$(( COMPARISON_FAILURES + 1 ))
fi
if php "$ROOT_DIR/tools/compare-benchmark-reports.php" \
    "$OUTPUT_DIR/rc3-reference.json" "$OUTPUT_DIR/rc4-run-1.json" \
    --mode=release --output="$OUTPUT_DIR/rc3-to-rc4-comparison.json"; then
    :
else
    COMPARISON_FAILURES=$(( COMPARISON_FAILURES + 1 ))
fi

echo "Controlled benchmark artifacts written to $OUTPUT_DIR"
if [[ "$COMPARISON_FAILURES" -gt 0 ]]; then
    echo "WARNING: $COMPARISON_FAILURES comparison(s) reported metric failures; inspect the JSON artifacts." >&2
    exit 1
fi
