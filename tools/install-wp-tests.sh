#!/bin/bash
set -e

# Define variables
WP_VERSION="6.9.4"
TARGET_DIR="tests/wp-tests"
TEMP_TAR="/tmp/wp-$WP_VERSION.tar.gz"

echo "Installing WordPress $WP_VERSION test infrastructure..."

# 1. Download WordPress develop source
if [ ! -f "$TEMP_TAR" ]; then
    echo "Downloading WordPress $WP_VERSION..."
    curl -sL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.tar.gz" -o "$TEMP_TAR"
fi

# 2. Extract
echo "Extracting to $TARGET_DIR..."
mkdir -p "$TARGET_DIR"
tar -xzf "$TEMP_TAR" -C "$TARGET_DIR" --strip-components=1

# 3. Clean up temp tar
rm -f "$TEMP_TAR"

# 4. Copy config template
echo "Configuring wp-tests-config.php..."
cp "$TARGET_DIR/wp-tests-config-sample.php" "$TARGET_DIR/wp-tests-config.php"

# Edit config values in wp-tests-config.php using inline php (to be clean and robust)
php -r '
$file = "tests/wp-tests/wp-tests-config.php";
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
"    define( \"MINCEMEAT_OBJECT_CACHE_CONFIG\", unserialize( getenv( \"MINCEMEAT_OBJECT_CACHE_CONFIG\" ) ) );\n" .
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
"}\n";

$content .= "\n" . $config_injection;

file_put_contents($file, $content);
'

# Adjust WordPress core cache tests to respect wp_cache_supports('flush_group')
php -r '
$file = "tests/wp-tests/tests/phpunit/tests/cache.php";
if (file_exists($file)) {
    $content = file_get_contents($file);
    
    // Modify the expected incorrect usage assertion
    $content = preg_replace(
        "/if\s*\(\s*wp_using_ext_object_cache\(\)\s*\)\s*\{\s*\\\$this->setExpectedIncorrectUsage\(\s*[\x27\x22]wp_cache_flush_group[\x27\x22]\s*\);\s*\}/",
        "if ( wp_using_ext_object_cache() && ! wp_cache_supports( \x27flush_group\x27 ) ) { \$this->setExpectedIncorrectUsage( \x27wp_cache_flush_group\x27 ); }",
        $content
    );

    // Modify the assertion on return value
    $content = preg_replace(
        "/if\s*\(\s*wp_using_ext_object_cache\(\)\s*\)\s*\{\s*\\\$this->assertFalse\(\s*\\\$results\s*\);\s*\}\s*else\s*\{/",
        "if ( wp_using_ext_object_cache() && ! wp_cache_supports( \x27flush_group\x27 ) ) { \$this->assertFalse( \$results ); } else {",
        $content
    );

    file_put_contents($file, $content);
}
'


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
        curl -sL "https://downloads.wordpress.org/plugin/$name.$version.zip" -o "/tmp/$name.zip"
        unzip -q "/tmp/$name.zip" -d "$PLUGINS_DIR"
        rm -f "/tmp/$name.zip"
    else
        echo "Plugin $name already downloaded."
    fi
}

# We use pinned stable versions for smoke testing
download_plugin "woocommerce" "8.9.1"
download_plugin "wordpress-seo" "22.8"
download_plugin "easy-digital-downloads" "3.6.9"

echo "WordPress $WP_VERSION test infrastructure set up successfully!"
