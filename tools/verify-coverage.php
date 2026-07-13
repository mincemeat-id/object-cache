<?php
/**
 * Coverage verification script.
 *
 * Parses build/logs/clover.xml and asserts the current project coverage
 * baseline so CI catches regressions without overstating release confidence.
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

$overall_threshold = (float) (getenv('MINCEMEAT_COVERAGE_MIN') ?: '75.0');

if ($overall_coverage < $overall_threshold) {
    fwrite(STDERR, sprintf("Error: Overall coverage of %.2f%% is below the required %.2f%% threshold!\n", $overall_coverage, $overall_threshold));
    exit(1);
}

// 2. Check critical component coverage against current baselines.
$critical_files = array(
    'src/KeySpace.php'   => 100.0,
    'src/ValueCodec.php' => 90.0,
    'src/Config.php'     => 95.0,
    'src/Lifecycle.php'  => 65.0,
);

$missing_files = array();
$failed_files = array();

foreach ($critical_files as $rel_path => $threshold) {
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

        if ($coverage < $threshold) {
            $failed_files[] = array(
                'path' => $rel_path,
                'coverage' => $coverage,
                'covered' => $covered,
                'statements' => $statements,
                'threshold' => $threshold,
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
    fwrite(STDERR, "Error: Some critical files are below their coverage baseline:\n");
    foreach ($failed_files as $f) {
        fwrite(STDERR, sprintf(" - %s: %.2f%% (%d/%d), required %.2f%%\n", $f['path'], $f['coverage'], $f['covered'], $f['statements'], $f['threshold']));
    }
    exit(1);
}

echo "Coverage thresholds successfully met! All checks green.\n";
exit(0);
