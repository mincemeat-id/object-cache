#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/tests/e2e/docker-compose.yml"
E2E_DIR="$ROOT_DIR/tests/e2e"
E2E_PORT=${MINCEMEAT_E2E_PORT:-8091}
E2E_URL="http://host.docker.internal:$E2E_PORT"
ADMIN_PASSWORD=${MINCEMEAT_E2E_ADMIN_PASSWORD:-admin-e2e-only}
KEEP_ENV=${MINCEMEAT_E2E_KEEP_ENV:-0}

compose() {
	docker compose -f "$COMPOSE_FILE" "$@"
}

wp() {
	compose exec -T --user www-data wordpress wp --path=/var/www/html --url="http://host.docker.internal:$E2E_PORT" "$@"
}

fail() {
	printf 'E2E failure: %s\n' "$1" >&2
	exit 1
}

cleanup() {
	if [[ "$KEEP_ENV" != "1" ]]; then
		compose down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}

run_browser_phase() {
	local phase=$1
	mkdir -p "$E2E_DIR/node_modules" "$E2E_DIR/test-results"
	docker run --rm --add-host=host.docker.internal:host-gateway \
		--volume "$E2E_DIR:/work" --workdir /work \
		--mount type=volume,destination=/work/node_modules \
		--env "MINCEMEAT_E2E_URL=$E2E_URL" \
		--env "MINCEMEAT_E2E_PHASE=$phase" \
		--env "MINCEMEAT_E2E_ADMIN_PASSWORD=$ADMIN_PASSWORD" \
		mcr.microsoft.com/playwright:v1.55.0-noble \
		bash -lc 'npm ci --no-audit --no-fund --silent && npm test; status=$?; chown -R --reference=/work /work/test-results 2>/dev/null || true; exit $status'
}

trap cleanup EXIT
compose down --volumes --remove-orphans >/dev/null 2>&1 || true
compose up -d --build database redis wordpress

printf 'Waiting for the isolated WordPress container...\n'
for _ in $(seq 1 60); do
	if wp core version >/dev/null 2>&1; then
		break
	fi
	sleep 2
done
wp core version >/dev/null 2>&1 || fail 'WordPress did not become ready.'

wp core install \
	--url="http://host.docker.internal:$E2E_PORT" \
	--title='Mincemeat E2E' \
	--admin_user=admin \
	--admin_password="$ADMIN_PASSWORD" \
	--admin_email=admin@example.test \
	--skip-email >/dev/null

printf 'Running browser activation, admin, frontend, and Site Health checks...\n'
run_browser_phase activate

printf 'Running WP-CLI lifecycle and ownership checks...\n'
wp mincemeat-cache remove-dropin | grep -F 'removed successfully' >/dev/null
wp mincemeat-cache install-dropin | grep -F 'installed successfully' >/dev/null
wp eval 'exit( is_file( WP_CONTENT_DIR . "/object-cache.php" ) ? 0 : 1 );'
status=$(wp mincemeat-cache status)
grep -F 'Drop-in Status: owned-current' <<<"$status" >/dev/null
grep -F 'Cache Status:   persistent' <<<"$status" >/dev/null
if grep -Eq 'redis-e2e-only|mincemeat-e2e|redis:6379' <<<"$status"; then
	fail 'WP-CLI diagnostics exposed a configured secret or internal endpoint.'
fi

wp mincemeat-cache remove-dropin >/dev/null
compose exec -T --user www-data wordpress sh -c "printf '%s\n' '<?php // foreign E2E drop-in' > /var/www/html/wp-content/object-cache.php"
foreign_hash=$(compose exec -T wordpress sha256sum /var/www/html/wp-content/object-cache.php | awk '{print $1}')
if wp mincemeat-cache install-dropin >/dev/null 2>&1; then
	fail 'WP-CLI overwrote a foreign drop-in.'
fi
if wp mincemeat-cache remove-dropin >/dev/null 2>&1; then
	fail 'WP-CLI removed a foreign drop-in.'
fi
test "$foreign_hash" = "$(compose exec -T wordpress sha256sum /var/www/html/wp-content/object-cache.php | awk '{print $1}')" \
	|| fail 'Foreign drop-in contents changed.'
wp plugin deactivate mincemeat-object-cache >/dev/null
test "$foreign_hash" = "$(compose exec -T wordpress sha256sum /var/www/html/wp-content/object-cache.php | awk '{print $1}')" \
	|| fail 'Plugin deactivation removed or changed a foreign drop-in.'
wp plugin activate mincemeat-object-cache >/dev/null
compose exec -T wordpress rm /var/www/html/wp-content/object-cache.php
wp mincemeat-cache install-dropin >/dev/null
wp plugin deactivate mincemeat-object-cache >/dev/null
wp eval 'exit( file_exists( WP_CONTENT_DIR . "/object-cache.php" ) ? 1 : 0 );'
wp plugin activate mincemeat-object-cache >/dev/null

printf 'Running backend outage checks...\n'
compose stop redis >/dev/null
run_browser_phase outage

printf 'Running backend recovery checks...\n'
compose start redis >/dev/null
for _ in $(seq 1 30); do
	if compose exec -T redis redis-cli -a redis-e2e-only --no-auth-warning ping 2>/dev/null | grep -q PONG; then
		break
	fi
	sleep 1
done
run_browser_phase recovery

printf 'Running multisite network activation checks...\n'
wp plugin deactivate mincemeat-object-cache >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp core multisite-convert --title='Mincemeat E2E Network' >/dev/null
run_browser_phase multisite
wp plugin is-active mincemeat-object-cache --network
wp mincemeat-cache status | grep -F 'Drop-in Status: owned-current' >/dev/null

printf 'Mincemeat E2E suite passed.\n'
