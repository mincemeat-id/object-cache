<?php
/**
 * Coverage verification script.
 *
 * Parses build/logs/clover.xml and asserts that:
 * - Overall statement/line coverage is >= 90%.
 * - Critical files have 100% statement/line coverage.
 */

declare(strict_types=1);

$clover_file = __DIR__ . '/../build/logs/clover.xml';

if (!file_exists($clover_file)) {
    fwrite(STDERR, "Error: Clover XML file not found at: {$clover_file}\n");
    fwrite(STDERR, "Please run phpunit with code coverage active first.\n");
    exit(1);
}

$xml = simplexml_load_file($clover_file);
if (!$xml) {
    fwrite(STDERR, "Error: Failed to parse Clover XML.\n");
    exit(1);
}

// 1. Check overall statement coverage
$project_metrics = $xml->project->metrics;
$total_statements = (int) $project_metrics['statements'];
$covered_statements = (int) $project_metrics['coveredstatements'];

if ($total_statements === 0) {
    fwrite(STDERR, "Error: No statements found in coverage report.\n");
    exit(1);
}

$overall_coverage = ($covered_statements / $total_statements) * 100;
echo sprintf("Overall line/statement coverage: %.2f%% (%d/%d)\n", $overall_coverage, $covered_statements, $total_statements);

if ($overall_coverage < 90.0) {
    fwrite(STDERR, sprintf("Error: Overall coverage of %.2f%% is below the required 90.0%% threshold!\n", $overall_coverage));
    exit(1);
}

// 2. Check critical component coverage (must be 100%)
$critical_files = array(
    'src/KeySpace.php',
    'src/ValueCodec.php',
    'src/Config.php',
    'src/Lifecycle.php',
);

$missing_files = array();
$failed_files = array();

foreach ($critical_files as $rel_path) {
    $abs_path = realpath(__DIR__ . '/../' . $rel_path);
    if (!$abs_path) {
        $missing_files[] = $rel_path;
        continue;
    }

    // Find the file in clover XML
    $found = false;
    foreach ($xml->xpath("//file[@name='{$abs_path}']") as $file_node) {
        $found = true;
        $file_metrics = $file_node->metrics;
        $statements = (int) $file_metrics['statements'];
        $covered = (int) $file_metrics['coveredstatements'];

        $coverage = $statements > 0 ? ($covered / $statements) * 100 : 100.0;
        echo sprintf("Critical file %s coverage: %.2f%% (%d/%d)\n", $rel_path, $coverage, $covered, $statements);

        if ($coverage < 100.0) {
            $failed_files[] = array(
                'path' => $rel_path,
                'coverage' => $coverage,
                'covered' => $covered,
                'statements' => $statements,
            );
        }
    }

    if (!$found) {
        $missing_files[] = $rel_path;
    }
}

if (!empty($missing_files)) {
    fwrite(STDERR, "Error: Some critical files were not found in the coverage report:\n");
    foreach ($missing_files as $f) {
        fwrite(STDERR, " - {$f}\n");
    }
    exit(1);
}

if (!empty($failed_files)) {
    fwrite(STDERR, "Error: Some critical files do not have 100% statement/line coverage:\n");
    foreach ($failed_files as $f) {
        fwrite(STDERR, sprintf(" - %s: %.2f%% (%d/%d)\n", $f['path'], $f['coverage'], $f['covered'], $f['statements']));
    }
    exit(1);
}

echo "Coverage thresholds successfully met! All checks green.\n";
exit(0);
