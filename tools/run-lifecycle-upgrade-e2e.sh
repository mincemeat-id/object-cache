#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/tests/e2e/docker-compose.yml"
WORDPRESS_VERSION=${MINCEMEAT_E2E_WORDPRESS_VERSION:-7.0.2}
E2E_PORT=${MINCEMEAT_E2E_PORT:-8091}
ADMIN_PASSWORD=${MINCEMEAT_E2E_ADMIN_PASSWORD:-admin-e2e-only}
KEEP_ENV=${MINCEMEAT_E2E_KEEP_ENV:-0}
RC1_TAG=0.1.0-rc1
TEMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mincemeat-lifecycle-e2e.XXXXXX")
ACTIVE_PLUGIN_DIR="$TEMP_DIR/active-plugin"
RC1_SOURCE_DIR="$TEMP_DIR/rc1-source"
RC1_PACKAGE="$TEMP_DIR/rc1.zip"
CANDIDATE_PACKAGE="$TEMP_DIR/candidate.zip"

compose() {
	MINCEMEAT_E2E_PLUGIN_DIR="$ACTIVE_PLUGIN_DIR" \
	MINCEMEAT_E2E_WORDPRESS_VERSION="$WORDPRESS_VERSION" \
		docker compose -f "$COMPOSE_FILE" "$@"
}

wp() {
	compose exec -T --user www-data wordpress wp \
		--path=/var/www/html --url="http://host.docker.internal:$E2E_PORT" "$@"
}

fail() {
	printf 'Lifecycle E2E failure: %s\n' "$1" >&2
	exit 1
}

cleanup() {
	if [[ "$KEEP_ENV" != "1" ]]; then
		compose down --volumes --remove-orphans >/dev/null 2>&1 || true
		rm -rf "$TEMP_DIR"
	else
		printf 'Lifecycle E2E environment retained; staged packages are in %s.\n' "$TEMP_DIR"
	fi
}

install_package_files() {
	local package=$1
	local unpack_dir="$TEMP_DIR/unpack"

	rm -rf "$unpack_dir"
	mkdir -p "$unpack_dir" "$ACTIVE_PLUGIN_DIR"
	find "$ACTIVE_PLUGIN_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
	unzip -q "$package" -d "$unpack_dir"
	cp -a "$unpack_dir/mincemeat-object-cache/." "$ACTIVE_PLUGIN_DIR/"
}

dropin_hash() {
	compose exec -T wordpress sha256sum /var/www/html/wp-content/object-cache.php | awk '{print $1}'
}

trap cleanup EXIT

git -C "$ROOT_DIR" rev-parse --verify "refs/tags/$RC1_TAG" >/dev/null \
	|| fail "Required immutable tag $RC1_TAG is unavailable."

mkdir -p "$RC1_SOURCE_DIR" "$ACTIVE_PLUGIN_DIR"
git -C "$ROOT_DIR" archive "$RC1_TAG" | tar -x -C "$RC1_SOURCE_DIR"
(
	cd "$RC1_SOURCE_DIR"
	php tools/build-package.php >/dev/null
)
cp "$RC1_SOURCE_DIR/mincemeat-object-cache.zip" "$RC1_PACKAGE"

php "$ROOT_DIR/tools/build-package.php" >/dev/null
cp "$ROOT_DIR/mincemeat-object-cache.zip" "$CANDIDATE_PACKAGE"

install_package_files "$RC1_PACKAGE"
RC1_HASH=$(tr -d '[:space:]' < "$ACTIVE_PLUGIN_DIR/stubs/object-cache.php.sha256")

compose down --volumes --remove-orphans >/dev/null 2>&1 || true
compose up -d --build database redis wordpress

printf 'Waiting for WordPress %s lifecycle environment...\n' "$WORDPRESS_VERSION"
for _ in $(seq 1 60); do
	if wp core version >/dev/null 2>&1; then
		break
	fi
	sleep 2
done
wp core version >/dev/null 2>&1 || fail 'WordPress did not become ready.'
test "$(wp core version)" = "$WORDPRESS_VERSION" || fail 'Unexpected WordPress version.'

wp core install \
	--url="http://host.docker.internal:$E2E_PORT" \
	--title='Mincemeat Lifecycle E2E' \
	--admin_user=admin \
	--admin_password="$ADMIN_PASSWORD" \
	--admin_email=admin@example.test \
	--skip-email >/dev/null

printf 'Installing the packaged RC1 drop-in...\n'
wp plugin activate mincemeat-object-cache >/dev/null
test "$(dropin_hash)" = "$RC1_HASH" || fail 'RC1 package did not install its exact drop-in.'
wp mincemeat-cache status | grep -F 'Drop-in Status: owned-current' >/dev/null

printf 'Replacing companion files with the candidate package...\n'
install_package_files "$CANDIDATE_PACKAGE"
CANDIDATE_HASH=$(tr -d '[:space:]' < "$ACTIVE_PLUGIN_DIR/stubs/object-cache.php.sha256")
test "$CANDIDATE_HASH" != "$RC1_HASH" || fail 'Candidate and RC1 drop-ins are identical.'
wp mincemeat-cache status | grep -F 'Drop-in Status: owned-stale' >/dev/null
wp mincemeat-cache update-dropin | grep -F 'updated successfully' >/dev/null
test "$(dropin_hash)" = "$CANDIDATE_HASH" || fail 'Candidate update did not install exact bytes.'
wp mincemeat-cache status | grep -F 'Drop-in Status: owned-current' >/dev/null
compose exec -T wordpress sh -c '! find /var/www/html/wp-content -maxdepth 1 -name "object-cache.tmp.*.php" | grep -q .' \
	|| fail 'Atomic update left a temporary drop-in behind.'

printf 'Checking failed-update preservation...\n'
compose cp "$RC1_SOURCE_DIR/stubs/object-cache.php" wordpress:/var/www/html/wp-content/object-cache.php >/dev/null
test "$(dropin_hash)" = "$RC1_HASH" || fail 'Could not seed the trusted stale RC1 drop-in.'
compose exec -T wordpress chown www-data:www-data /var/www/html/wp-content/object-cache.php
compose exec -T wordpress chmod 0555 /var/www/html/wp-content
if wp mincemeat-cache update-dropin >/dev/null 2>&1; then
	fail 'Update unexpectedly succeeded without writable content access.'
fi
test "$(dropin_hash)" = "$RC1_HASH" || fail 'Failed update changed the installed drop-in.'
compose exec -T wordpress chmod 0755 /var/www/html/wp-content
wp mincemeat-cache update-dropin >/dev/null
test "$(dropin_hash)" = "$CANDIDATE_HASH" || fail 'Recovery update did not install candidate bytes.'

printf 'Checking foreign-file preservation...\n'
wp mincemeat-cache remove-dropin >/dev/null
compose exec -T wordpress sh -c "printf '%s\n' '<?php // foreign lifecycle E2E drop-in' > /var/www/html/wp-content/object-cache.php"
FOREIGN_HASH=$(dropin_hash)
if wp mincemeat-cache install-dropin >/dev/null 2>&1; then
	fail 'Install overwrote a foreign drop-in.'
fi
if wp mincemeat-cache remove-dropin >/dev/null 2>&1; then
	fail 'Removal deleted a foreign drop-in.'
fi
test "$(dropin_hash)" = "$FOREIGN_HASH" || fail 'Foreign drop-in bytes changed.'
compose exec -T wordpress rm /var/www/html/wp-content/object-cache.php
wp mincemeat-cache install-dropin >/dev/null

printf 'Checking deliberate candidate-to-RC1 rollback...\n'
wp mincemeat-cache remove-dropin >/dev/null
install_package_files "$RC1_PACKAGE"
wp mincemeat-cache install-dropin >/dev/null
test "$(dropin_hash)" = "$RC1_HASH" || fail 'Deliberate rollback did not restore RC1 bytes.'
wp mincemeat-cache status | grep -F 'Drop-in Status: owned-current' >/dev/null

printf 'Checking companion deactivation cleanup...\n'
wp plugin deactivate mincemeat-object-cache >/dev/null
compose exec -T wordpress test ! -e /var/www/html/wp-content/object-cache.php \
	|| fail 'Deactivation left an owned drop-in installed.'
wp plugin activate mincemeat-object-cache >/dev/null
test "$(dropin_hash)" = "$RC1_HASH" || fail 'Reactivation did not restore RC1 bytes.'

printf 'Packaged RC1-to-candidate lifecycle E2E suite passed.\n'
