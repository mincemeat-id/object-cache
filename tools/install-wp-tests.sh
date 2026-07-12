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
$content = str_replace("localhost", "127.0.0.1", $content);

// Ensure we define object cache constants or configurations
// We will define MINCEMEAT_OBJECT_CACHE_CONFIG constant
$config_injection = "\n" .
"define( \"MINCEMEAT_OBJECT_CACHE_CONFIG\", array(\n" .
"    \"scheme\" => \"tcp\",\n" .
"    \"host\" => \"127.0.0.1\",\n" .
"    \"port\" => 6379,\n" .
"    \"database\" => 0,\n" .
"    \"connect_timeout\" => 1.0,\n" .
"    \"read_timeout\" => 1.0,\n" .
"    \"namespace\" => \"wp-tests-ns\",\n" .
"    \"debug\" => true,\n" .
") );\n";

$content = str_replace("/* Only change the main database connection settings if you are using multiple servers. */", $config_injection . "\n/* Only change the main database connection settings if you are using multiple servers. */", $content);

file_put_contents($file, $content);
'

# 5. Create plugin directories for smoke testing
echo "Creating plugins directory for WooCommerce, Yoast, and EDD..."
mkdir -p "$TARGET_DIR/src/wp-content/plugins"

# Download pinned or recent stable versions of WooCommerce, Yoast SEO, and EDD for compatibility smoke testing
PLUGINS_DIR="$TARGET_DIR/src/wp-content/plugins"

# Helper to download and unzip a plugin
download_plugin() {
    local name=$1
    if [ ! -d "$PLUGINS_DIR/$name" ]; then
        echo "Downloading plugin $name..."
        curl -sL "https://downloads.wordpress.org/plugin/$name.zip" -o "/tmp/$name.zip"
        unzip -q "/tmp/$name.zip" -d "$PLUGINS_DIR"
        rm -f "/tmp/$name.zip"
    else
        echo "Plugin $name already downloaded."
    fi
}

# We use latest stable versions for smoke testing
download_plugin "woocommerce"
download_plugin "wordpress-seo"
download_plugin "easy-digital-downloads"

echo "WordPress $WP_VERSION test infrastructure set up successfully!"
