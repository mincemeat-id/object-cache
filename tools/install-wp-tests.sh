#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
WP_VERSION=${WP_VERSION:-6.9.5}
TARGET_DIR=${WP_TESTS_DIR:-$ROOT_DIR/tests/wp-tests}
PATCH_DIR="$ROOT_DIR/tests/patches/wordpress"
CACHE_PATCH="$PATCH_DIR/cache-flush-group-support.patch"
CACHE_HASHES="$PATCH_DIR/cache-test-sources.sha256"
TEMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mincemeat-wp-tests.XXXXXX")
TEMP_TAR="$TEMP_DIR/wordpress-develop.tar.gz"

cleanup() {
    rm -rf "$TEMP_DIR"
}

trap cleanup EXIT

case "$TARGET_DIR" in
    "$ROOT_DIR"/tests/wp-tests*) ;;
    *)
        echo "Refusing to replace WordPress test directory outside $ROOT_DIR/tests/." >&2
        exit 1
        ;;
esac

echo "Installing WordPress $WP_VERSION test infrastructure..."

# 1. Download WordPress develop source
echo "Downloading WordPress $WP_VERSION..."
curl -fL --retry 3 --retry-all-errors \
    "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.tar.gz" \
    -o "$TEMP_TAR"

# 2. Extract
echo "Extracting to $TARGET_DIR..."
mkdir -p "$TEMP_DIR/source"
tar -xzf "$TEMP_TAR" -C "$TEMP_DIR/source" --strip-components=1
rm -rf "$TARGET_DIR"
mv "$TEMP_DIR/source" "$TARGET_DIR"

# 4. Copy config template
echo "Configuring wp-tests-config.php..."
cp "$TARGET_DIR/wp-tests-config-sample.php" "$TARGET_DIR/wp-tests-config.php"

# Edit config values in wp-tests-config.php using inline php (to be clean and robust)
MINCEMEAT_WP_TESTS_DIR="$TARGET_DIR" php -r '
$file = getenv( "MINCEMEAT_WP_TESTS_DIR" ) . "/wp-tests-config.php";
$content = file_get_contents($file);

// Replace DB details
$content = str_replace("youremptytestdbnamehere", "wordpress_test", $content);
$content = str_replace("yourusernamehere", "root", $content);
$content = str_replace("yourpasswordhere", "root", $content);
$content = str_replace(chr(39)."localhost".chr(39), "getenv( ".chr(39)."DB_HOST".chr(39)." ) ?: ".chr(39)."127.0.0.1:33076".chr(39), $content);

// Ensure we define object cache constants or configurations
// We will define MINCEMEAT_OBJECT_CACHE_CONFIG constant dynamically if present in env
$config_injection = "\n" .
"if ( getenv( \"MINCEMEAT_OBJECT_CACHE_CONFIG\" ) ) {\n" .
"    \$raw_config = getenv( \"MINCEMEAT_OBJECT_CACHE_CONFIG\" );\n" .
"    \$decoded = json_decode( \$raw_config, true );\n" .
"    if ( ! is_array( \$decoded ) ) {\n" .
"        throw new Exception( \"Invalid configuration injection: not an array.\" );\n" .
"    }\n" .
"    \$allowed_types = array(\n" .
"        \"namespace\" => \"string\",\n" .
"        \"scheme\" => \"string\",\n" .
"        \"host\" => \"string\",\n" .
"        \"port\" => \"integer\",\n" .
"        \"path\" => \"string\",\n" .
"        \"database\" => \"integer\",\n" .
"        \"username\" => \"string\",\n" .
"        \"password\" => \"string\",\n" .
"        \"connect_timeout\" => \"double\",\n" .
"        \"read_timeout\" => \"double\",\n" .
"        \"persistent\" => \"boolean\",\n" .
"        \"max_ttl\" => \"integer\",\n" .
"        \"tls\" => \"array\",\n" .
"        \"debug\" => \"boolean\",\n" .
"    );\n" .
"    foreach ( \$decoded as \$key => \$value ) {\n" .
"        if ( ! isset( \$allowed_types[\$key] ) ) {\n" .
"            throw new Exception( \"Invalid configuration injection: unknown key \" . \$key );\n" .
"        }\n" .
"        if ( \$value !== null ) {\n" .
"            \$type = gettype( \$value );\n" .
"            \$expected = \$allowed_types[\$key];\n" .
"            if ( \$expected === \"double\" && ( \$type === \"integer\" || \$type === \"double\" ) ) {\n" .
"                continue;\n" .
"            }\n" .
"            if ( \$type !== \$expected ) {\n" .
"                throw new Exception( \"Invalid configuration injection: key \" . \$key . \" expected \" . \$expected . \", got \" . \$type );\n" .
"            }\n" .
"        }\n" .
"    }\n" .
"    define( \"MINCEMEAT_OBJECT_CACHE_CONFIG\", \$decoded );\n" .
"} else {\n" .
"    define( \"MINCEMEAT_OBJECT_CACHE_CONFIG\", array(\n" .
"        \"scheme\" => \"tcp\",\n" .
"        \"host\" => \"127.0.0.1\",\n" .
"        \"port\" => 6383,\n" .
"        \"database\" => 0,\n" .
"        \"connect_timeout\" => 1.0,\n" .
"        \"read_timeout\" => 1.0,\n" .
"        \"namespace\" => \"wp-tests-ns\",\n" .
"        \"debug\" => true,\n" .
"    ) );\n" .
"}";

$content .= "\n" . $config_injection;

file_put_contents($file, $content);
'

# WordPress core assumes every external cache lacks flush_group support. Keep
# that adaptation explicit, checksum-gated, and reviewable instead of silently
# rewriting whatever source happened to download.
CACHE_TEST="$TARGET_DIR/tests/phpunit/tests/cache.php"
EXPECTED_CACHE_HASH=$(awk -v version="$WP_VERSION" '$1 == version { print $2 }' "$CACHE_HASHES")
if [[ -z "$EXPECTED_CACHE_HASH" ]]; then
    echo "No reviewed WordPress cache-test checksum exists for $WP_VERSION." >&2
    exit 1
fi

ACTUAL_CACHE_HASH=$(sha256sum "$CACHE_TEST" | awk '{print $1}')
if [[ "$ACTUAL_CACHE_HASH" != "$EXPECTED_CACHE_HASH" ]]; then
    echo "WordPress cache-test provenance mismatch for $WP_VERSION." >&2
    echo "Expected $EXPECTED_CACHE_HASH, got $ACTUAL_CACHE_HASH." >&2
    exit 1
fi

patch --silent --dry-run -d "$TARGET_DIR" -p1 < "$CACHE_PATCH"
patch --silent -d "$TARGET_DIR" -p1 < "$CACHE_PATCH"
PATCHED_CACHE_HASH=$(sha256sum "$CACHE_TEST" | awk '{print $1}')
PATCH_HASH=$(sha256sum "$CACHE_PATCH" | awk '{print $1}')
printf '%s\n' \
    "wordpress_version=$WP_VERSION" \
    "upstream_cache_sha256=$EXPECTED_CACHE_HASH" \
    "patch_sha256=$PATCH_HASH" \
    "patched_cache_sha256=$PATCHED_CACHE_HASH" \
    > "$TARGET_DIR/.mincemeat-test-provenance"

echo "Applied reviewed cache-test patch to exact upstream source $EXPECTED_CACHE_HASH."


# 5. Create plugin directories for smoke testing
echo "Creating plugins directory for WooCommerce, Yoast, and EDD..."
mkdir -p "$TARGET_DIR/src/wp-content/plugins"

# Download pinned or recent stable versions of WooCommerce, Yoast SEO, and EDD for compatibility smoke testing
PLUGINS_DIR="$TARGET_DIR/src/wp-content/plugins"

# Helper to download and unzip a plugin with exact version pinning
download_plugin() {
    local name=$1
    local version=$2
    if [ ! -d "$PLUGINS_DIR/$name" ]; then
        echo "Downloading plugin $name $version..."
        curl -fL --retry 3 --retry-all-errors \
            "https://downloads.wordpress.org/plugin/$name.$version.zip" \
            -o "$TEMP_DIR/$name.zip"
        unzip -q "$TEMP_DIR/$name.zip" -d "$PLUGINS_DIR"
    else
        echo "Plugin $name already downloaded."
    fi
}

# We use pinned stable versions for smoke testing
download_plugin "woocommerce" "8.9.1"
download_plugin "wordpress-seo" "22.8"
download_plugin "easy-digital-downloads" "3.6.9"

echo "WordPress $WP_VERSION test infrastructure set up successfully!"
