#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
TARGET_DIR=${WP_TESTS_DIR:-$ROOT_DIR/tests/wp-tests}
PATCH_FILE="$ROOT_DIR/tests/patches/wordpress/cache-flush-group-support.patch"
PROVENANCE_FILE="$TARGET_DIR/.mincemeat-test-provenance"

if [[ ! -f "$PROVENANCE_FILE" ]]; then
    echo "WordPress test provenance record is missing: $PROVENANCE_FILE" >&2
    exit 1
fi

wordpress_version=$(awk -F= '$1 == "wordpress_version" { print substr($0, index($0, "=") + 1) }' "$PROVENANCE_FILE")
upstream_cache_sha256=$(awk -F= '$1 == "upstream_cache_sha256" { print $2 }' "$PROVENANCE_FILE")
patch_sha256=$(awk -F= '$1 == "patch_sha256" { print $2 }' "$PROVENANCE_FILE")
patched_cache_sha256=$(awk -F= '$1 == "patched_cache_sha256" { print $2 }' "$PROVENANCE_FILE")

if [[ ! "$wordpress_version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?([.-][A-Za-z0-9]+)*$ ]] \
    || [[ ! "$upstream_cache_sha256" =~ ^[a-f0-9]{64}$ ]] \
    || [[ ! "$patch_sha256" =~ ^[a-f0-9]{64}$ ]] \
    || [[ ! "$patched_cache_sha256" =~ ^[a-f0-9]{64}$ ]]; then
    echo "WordPress test provenance record contains invalid values." >&2
    exit 1
fi

CACHE_TEST="$TARGET_DIR/tests/phpunit/tests/cache.php"
ACTUAL_PATCH_HASH=$(sha256sum "$PATCH_FILE" | awk '{print $1}')
ACTUAL_PATCHED_HASH=$(sha256sum "$CACHE_TEST" | awk '{print $1}')

if [[ "$ACTUAL_PATCH_HASH" != "$patch_sha256" ]]; then
    echo "The reviewed WordPress cache-test patch changed after installation." >&2
    exit 1
fi

if [[ "$ACTUAL_PATCHED_HASH" != "$patched_cache_sha256" ]]; then
    echo "The patched WordPress cache test changed after installation." >&2
    exit 1
fi

VERIFY_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mincemeat-wp-provenance.XXXXXX")
cleanup() {
    rm -rf "$VERIFY_DIR"
}
trap cleanup EXIT

mkdir -p "$VERIFY_DIR/tests/phpunit/tests"
cp "$CACHE_TEST" "$VERIFY_DIR/tests/phpunit/tests/cache.php"
patch --silent --reverse -d "$VERIFY_DIR" -p1 < "$PATCH_FILE"

RECOVERED_HASH=$(sha256sum "$VERIFY_DIR/tests/phpunit/tests/cache.php" | awk '{print $1}')
if [[ "$RECOVERED_HASH" != "$upstream_cache_sha256" ]]; then
    echo "The installed cache test does not reverse to the reviewed upstream source." >&2
    exit 1
fi

patch --silent -d "$VERIFY_DIR" -p1 < "$PATCH_FILE"
cmp --silent "$CACHE_TEST" "$VERIFY_DIR/tests/phpunit/tests/cache.php"

echo "WordPress $wordpress_version test provenance verified: upstream $upstream_cache_sha256, patch $patch_sha256."
