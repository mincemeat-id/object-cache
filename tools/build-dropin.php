<?php
// phpcs:ignoreFile
/**
 * Deterministic build script for Mincemeat Object Cache drop-in.
 *
 * Compiles all files under `src/` into a single standalone PHP 7.4-compatible
 * drop-in at `stubs/object-cache.php` with appropriate ownership/build markers.
 */

declare(strict_types=1);

$plugin_dir = dirname( __DIR__ );
$src_dir    = $plugin_dir . '/src';
$output_file = $plugin_dir . '/stubs/object-cache.php';

// 1. Get version from plugin file.
$plugin_file = $plugin_dir . '/mincemeat-object-cache.php';
$plugin_content = file_get_contents( $plugin_file );
if ( ! preg_match( '/Version:\s*([^\s\n\r]+)/', $plugin_content, $matches ) ) {
	fwrite( STDERR, "Error: Could not parse version from mincemeat-object-cache.php\n" );
	exit( 1 );
}
$version = $matches[1];

// 2. Read schema version from Api.php.
$api_file = $src_dir . '/Api.php';
$api_content = file_get_contents( $api_file );
if ( ! preg_match( '/SCHEMA_VERSION = \'([^\']+)\'/', $api_content, $schema_matches ) ) {
	fwrite( STDERR, "Error: Could not parse schema version from Api.php\n" );
	exit( 1 );
}
$schema_version = $schema_matches[1];

// 3. Find and sort all class files under src/.
$files = glob( $src_dir . '/*.php' );
if ( ! $files ) {
	fwrite( STDERR, "Error: No PHP source files found in src/\n" );
	exit( 1 );
}

sort( $files );

$class_contents = array();
$unique_imports = array();

foreach ( $files as $file ) {
	$filename = basename( $file );
	if ( 'functions.php' === $filename ) {
		continue;
	}

	$content = file_get_contents( $file );
	$content = str_replace( "\r\n", "\n", $content );

	// Strip everything before and including the namespace declaration.
	$namespace_pos = strpos( $content, 'namespace Mincemeat\\ObjectCache;' );
	if ( false === $namespace_pos ) {
		fwrite( STDERR, "Error: Namespace declaration not found in {$filename}\n" );
		exit( 1 );
	}
	$content = substr( $content, $namespace_pos + strlen( 'namespace Mincemeat\\ObjectCache;' ) );

	// Extract and remove any use statements.
	if ( preg_match_all( '/^\s*use\s+([^;]+);\s*/m', $content, $use_matches ) ) {
		foreach ( $use_matches[1] as $import ) {
			$import = trim( $import );
			// Skip imports of the same namespace.
			if ( strpos( $import, 'Mincemeat\\ObjectCache\\' ) === 0 ) {
				continue;
			}
			$unique_imports[] = $import;
		}
		$content = preg_replace( '/^\s*use\s+([^;]+);\s*/m', '', $content );
	}

	$content = trim( $content );
	if ( '' !== $content ) {
		$class_contents[] = "// --- {$filename} ---\n" . $content;
	}
}

// Format class imports.
$unique_imports = array_unique( $unique_imports );
sort( $unique_imports );
$imports_string = '';
foreach ( $unique_imports as $import ) {
	$imports_string .= "\tuse {$import};\n";
}

// 4. Process global functions.php.
$functions_file = $src_dir . '/functions.php';
if ( ! file_exists( $functions_file ) ) {
	fwrite( STDERR, "Error: functions.php not found\n" );
	exit( 1 );
}

$functions_content = file_get_contents( $functions_file );
$functions_content = str_replace( "\r\n", "\n", $functions_content );

$declare_pos = strpos( $functions_content, 'declare(strict_types=1);' );
if ( false === $declare_pos ) {
	fwrite( STDERR, "Error: declare(strict_types=1); not found in functions.php\n" );
	exit( 1 );
}
$functions_body = substr( $functions_content, $declare_pos + strlen( 'declare(strict_types=1);' ) );
$functions_body = preg_replace( '/^\s*use\s+([^;]+);\s*/m', '', $functions_body );
$functions_body = trim( $functions_body );

// 5. Assemble template.
$template = <<<'PHP'
<?php
// phpcs:ignoreFile
/**
 * Mincemeat Object Cache Drop-In
 *
 * Owner: mincemeat-object-cache
 * Version: {{VERSION}}
 * Drop-in Version: {{VERSION}}
 * Schema Version: {{SCHEMA_VERSION}}
 * Build Hash: {{BUILD_HASH}}
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache {
{{IMPORTS}}
{{CLASSES}}
}

namespace {
	use Mincemeat\ObjectCache\ObjectCache;

{{FUNCTIONS}}
}
PHP;

// Indent lines to look clean.
$classes_formatted = implode( "\n\n", $class_contents );
$classes_lines = explode( "\n", $classes_formatted );
foreach ( $classes_lines as &$line ) {
	if ( '' !== $line ) {
		$line = "\t" . $line;
	}
}
$classes_formatted = implode( "\n", $classes_lines );

$functions_lines = explode( "\n", $functions_body );
foreach ( $functions_lines as &$line ) {
	if ( '' !== $line ) {
		$line = "\t" . $line;
	}
}
$functions_formatted = implode( "\n", $functions_lines );

// Compute deterministic build hash of the code body only.
$hash_payload = $imports_string . "\n" . $classes_formatted . "\n" . $functions_formatted;
$build_hash   = hash( 'sha256', $hash_payload );

// Replace variables.
$output = str_replace(
	array( '{{VERSION}}', '{{SCHEMA_VERSION}}', '{{BUILD_HASH}}', '{{IMPORTS}}', '{{CLASSES}}', '{{FUNCTIONS}}' ),
	array( $version, $schema_version, $build_hash, $imports_string, $classes_formatted, $functions_formatted ),
	$template
);

// Write to stubs/object-cache.php.
if ( ! is_dir( dirname( $output_file ) ) ) {
	mkdir( dirname( $output_file ), 0755, true );
}

file_put_contents( $output_file, $output );
echo "Successfully built drop-in at stubs/object-cache.php (Hash: {$build_hash})\n";
