<?php
// phpcs:ignoreFile
/**
 * Deterministic build/packaging script for Mincemeat Object Cache.
 *
 * Compiles the drop-in first, then packages all release files into a clean,
 * reproducible plugin ZIP at `mincemeat-object-cache.zip` with checksums and manifest.
 */

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);
$src_dir    = $plugin_dir . '/src';
$stubs_dir  = $plugin_dir . '/stubs';
$zip_file   = $plugin_dir . '/mincemeat-object-cache.zip';
$sha_file   = $zip_file . '.sha256';
$manifest_file = $plugin_dir . '/manifest.json';

// 1. Run build-dropin.php first
echo "Compiling cache drop-in...\n";
require_once $plugin_dir . '/tools/build-dropin.php';

// 2. Identify version
$plugin_file = $plugin_dir . '/mincemeat-object-cache.php';
$plugin_content = file_get_contents($plugin_file);
if (!preg_match('/Version:\s*([^\s\n\r]+)/', $plugin_content, $matches)) {
    fwrite(STDERR, "Error: Could not parse version from mincemeat-object-cache.php\n");
    exit(1);
}
$version = $matches[1];

// 3. Find files to include
$files_to_pack = array(
    'mincemeat-object-cache.php',
    'readme.txt',
    'README.md',
    'LICENSE',
    'CHANGELOG.md',
);

// Add stubs
$files_to_pack[] = 'stubs/object-cache.php';
$files_to_pack[] = 'stubs/object-cache.php.sha256';

// Add src files
$src_files = glob($src_dir . '/*.php');
if (!$src_files) {
    fwrite(STDERR, "Error: No source files found in src/\n");
    exit(1);
}
foreach ($src_files as $f) {
    $files_to_pack[] = 'src/' . basename($f);
}

sort($files_to_pack);

// 4. Build ZIP file deterministically
if (file_exists($zip_file)) {
    unlink($zip_file);
}

$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Error: Could not open/create ZIP file at {$zip_file}\n");
    exit(1);
}

$manifest_files = array();

foreach ($files_to_pack as $rel_path) {
    $full_path = $plugin_dir . '/' . $rel_path;
    if (!file_exists($full_path)) {
        fwrite(STDERR, "Error: Pack file missing: {$full_path}\n");
        exit(1);
    }

    $content = file_get_contents($full_path);
    $content = str_replace("\r\n", "\n", $content); // Normalize line endings for determinism

    $zip_path = 'mincemeat-object-cache/' . $rel_path;
    $zip->addFromString($zip_path, $content);

    // Compute checksum
    $manifest_files[$rel_path] = array(
        'sha256' => hash('sha256', $content),
        'size'   => strlen($content),
    );
}

$zip->close();

// 5. Compute ZIP hash and write sidecar
$zip_hash = hash_file('sha256', $zip_file);
file_put_contents($sha_file, $zip_hash);

// 6. Generate manifest.json
$manifest = array(
    'name'       => 'mincemeat-object-cache',
    'version'    => $version,
    'zip_sha256' => $zip_hash,
    'files'      => $manifest_files,
);

file_put_contents($manifest_file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Successfully built plugin package at mincemeat-object-cache.zip (SHA-256: {$zip_hash})\n";
echo "Manifest written to manifest.json\n";
