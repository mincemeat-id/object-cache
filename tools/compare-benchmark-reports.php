<?php
/**
 * Compares controlled benchmark artifacts for repeatability or release drift.
 *
 * Usage: php tools/compare-benchmark-reports.php baseline.json current.json
 *        --mode=repeatability|release --output=comparison.json
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

const MINCEMEAT_COMPARISON_SCHEMA_VERSION = 1;
const MINCEMEAT_COMPARISON_REPORT_SCHEMA  = 2;
const MINCEMEAT_COMPARISON_SUITE_VERSION  = 3;
const MINCEMEAT_COMPARISON_TOLERANCE_PCT  = 25.0;
const MINCEMEAT_COMPARISON_NOISE_FLOOR_MS = 2.0;

/**
 * @return array<string,mixed>
 */
function mincemeat_load_benchmark_report( string $path ): array {
	if ( ! is_readable( $path )) {
		throw new RuntimeException( 'Benchmark report is not readable: ' . basename( $path ) );
	}
	$report = json_decode( (string) file_get_contents( $path ), true );
	if (
		! is_array( $report )
		|| ( $report['schema_version'] ?? null ) !== MINCEMEAT_COMPARISON_REPORT_SCHEMA
		|| ( $report['suite_version'] ?? null ) !== MINCEMEAT_COMPARISON_SUITE_VERSION
	) {
		throw new RuntimeException( 'Benchmark report schema or suite is incompatible.' );
	}

	return $report;
}

/**
 * @param mixed $value
 */
function mincemeat_count_value( $value ): ?int {
	return is_int( $value ) ? $value : null;
}

$mode        = 'repeatability';
$output_file = '';
$positionals = array();
foreach (array_slice( $argv, 1 ) as $argument) {
	if (strpos( $argument, '--mode=' ) === 0) {
		$mode = substr( $argument, strlen( '--mode=' ) );
	} elseif (strpos( $argument, '--output=' ) === 0) {
		$output_file = substr( $argument, strlen( '--output=' ) );
	} else {
		$positionals[] = $argument;
	}
}

if (count( $positionals ) !== 2 || ! in_array( $mode, array( 'repeatability', 'release' ), true ) || $output_file === '') {
	fwrite( STDERR, "Usage: php tools/compare-benchmark-reports.php baseline.json current.json --mode=repeatability|release --output=comparison.json\n" );
	exit( 2 );
}

try {
	$baseline = mincemeat_load_benchmark_report( $positionals[0] );
	$current  = mincemeat_load_benchmark_report( $positionals[1] );

	if (( $baseline['environment'] ?? null ) !== ( $current['environment'] ?? null )) {
		throw new RuntimeException( 'Controlled-runner environments differ.' );
	}
	if (( $baseline['configuration'] ?? null ) !== ( $current['configuration'] ?? null )) {
		throw new RuntimeException( 'Benchmark sampling configuration differs.' );
	}
	if (( $baseline['artifact']['harness_sha256'] ?? null ) !== ( $current['artifact']['harness_sha256'] ?? null )) {
		throw new RuntimeException( 'Benchmark harness hashes differ.' );
	}
	if (array_keys( $baseline['benchmarks'] ?? array() ) !== array_keys( $current['benchmarks'] ?? array() )) {
		throw new RuntimeException( 'Benchmark workload sets differ.' );
	}

	$failures = array();
	$metrics  = array();
	foreach ($current['benchmarks'] as $key => $current_metric) {
		$baseline_metric = $baseline['benchmarks'][ $key ];
		if (
			( $baseline_metric['label'] ?? null ) !== ( $current_metric['label'] ?? null )
			|| ( $baseline_metric['iterations'] ?? null ) !== ( $current_metric['iterations'] ?? null )
		) {
			throw new RuntimeException( 'Workload metadata differs for ' . $key . '.' );
		}

		$baseline_ms = (float) ( $baseline_metric['median_ms'] ?? 0.0 );
		$current_ms  = (float) ( $current_metric['median_ms'] ?? 0.0 );
		if ( ! is_finite( $baseline_ms ) || ! is_finite( $current_ms ) || $baseline_ms <= 0.0 || $current_ms <= 0.0) {
			throw new RuntimeException( 'Latency sample is not finite and positive for ' . $key . '.' );
		}
		$delta_ms = $current_ms - $baseline_ms;
		$delta_pct = ( $delta_ms / $baseline_ms ) * 100;
		$latency_outside_tolerance = abs( $delta_pct ) > MINCEMEAT_COMPARISON_TOLERANCE_PCT
			&& abs( $delta_ms ) > MINCEMEAT_COMPARISON_NOISE_FLOOR_MS;
		$latency_failed = $mode === 'repeatability'
			? $latency_outside_tolerance
			: $delta_pct > MINCEMEAT_COMPARISON_TOLERANCE_PCT && $delta_ms > MINCEMEAT_COMPARISON_NOISE_FLOOR_MS;

		$count_comparisons = array();
		foreach (array(
			'backend_commands',
			'backend_round_trips',
			'connections',
		) as $field) {
			$baseline_count = mincemeat_count_value( $baseline_metric[ $field ] ?? null );
			$current_count  = mincemeat_count_value( $current_metric[ $field ] ?? null );
			$count_failed   = false;
			if ($baseline_count !== null || $current_count !== null) {
				if ($baseline_count === null || $current_count === null) {
					$count_failed = true;
				} else {
					$count_failed = $mode === 'repeatability'
						? $baseline_count !== $current_count
						: $current_count > $baseline_count;
				}
			}
			$count_comparisons[ $field ] = array(
				'baseline' => $baseline_count,
				'current'  => $current_count,
				'delta'    => $baseline_count !== null && $current_count !== null ? $current_count - $baseline_count : null,
				'status'   => $count_failed ? 'fail' : 'pass',
			);
			if ($count_failed) {
				$failures[] = sprintf( '%s %s changed from %s to %s.', $key, $field, var_export( $baseline_count, true ), var_export( $current_count, true ) );
			}
		}

		if ($latency_failed) {
			$failures[] = sprintf( '%s latency changed by %+.1f%% (%+.3f ms).', $key, $delta_pct, $delta_ms );
		}
		$metrics[ $key ] = array(
			'label'   => $current_metric['label'],
			'latency' => array(
				'baseline_median_ms' => $baseline_ms,
				'current_median_ms'  => $current_ms,
				'delta_ms'           => round( $delta_ms, 3 ),
				'delta_pct'          => round( $delta_pct, 1 ),
				'status'             => $latency_failed ? 'fail' : 'pass',
			),
			'counts'  => $count_comparisons,
		);
	}

	$comparison = array(
		'schema_version' => MINCEMEAT_COMPARISON_SCHEMA_VERSION,
		'mode'           => $mode,
		'status'         => count( $failures ) === 0 ? 'pass' : 'fail',
		'tolerance'      => array(
			'percent'        => MINCEMEAT_COMPARISON_TOLERANCE_PCT,
			'noise_floor_ms' => MINCEMEAT_COMPARISON_NOISE_FLOOR_MS,
			'rule'           => $mode === 'repeatability' ? 'absolute-change-exceeds-both' : 'regression-exceeds-both',
		),
		'baseline'       => array(
			'label'  => $baseline['artifact']['label'] ?? 'unknown',
			'commit' => $baseline['artifact']['source_commit'] ?? 'unknown',
		),
		'current'        => array(
			'label'  => $current['artifact']['label'] ?? 'unknown',
			'commit' => $current['artifact']['source_commit'] ?? 'unknown',
		),
		'failures'       => $failures,
		'metrics'        => $metrics,
	);
	$encoded = json_encode( $comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	if ( ! is_string( $encoded ) || ! is_dir( dirname( $output_file ) ) || file_put_contents( $output_file, $encoded . "\n" ) === false) {
		throw new RuntimeException( 'Unable to write benchmark comparison artifact.' );
	}

	echo sprintf( "%s comparison: %s (%d failures)\n", ucfirst( $mode ), strtoupper( $comparison['status'] ), count( $failures ) );
	if (count( $failures ) > 0) {
		foreach ($failures as $failure) {
			fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
		}
		exit( 1 );
	}
} catch (Throwable $e) {
	fwrite( STDERR, 'Comparison error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
