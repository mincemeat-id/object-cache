<?php
/**
 * Minimal cachegrind (Xdebug 3) analyzer.
 *
 * Reports top functions by EXCLUSIVE (self) time and per-function call counts,
 * which is the robust signal for where CPU is spent. The cost line immediately
 * following a `calls=` record is the callee's cost (call-site cost) and is NOT
 * counted toward the caller's exclusive time.
 *
 * Usage: php tools/rc4-analyze-cachegrind.php <file> [topN]
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$file = $argv[1] ?? '';
if ( $file === '' || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php tools/rc4-analyze-cachegrind.php <cachegrind-file> [topN]\n" );
	exit( 2 );
}
$top_n = (int) ( $argv[2] ?? 25 );

$lines = file( $file, FILE_IGNORE_NEW_LINES );
if ( $lines === false ) {
	fwrite( STDERR, "Unable to read file.\n" );
	exit( 2 );
}

$fn_names = array();
// fn_id -> exclusive cost (10ns units)
$exclusive = array();
// fn_id -> call count (how many times this function was invoked)
$call_counts = array();

$current_fn = null;
$pending_callee = null;
$in_call_sequence = false;

foreach ( $lines as $line ) {
	if ( $line === '' || $line[0] === '#' ) {
		continue;
	}

	if ( strpos( $line, 'fl=' ) === 0 || strpos( $line, 'fn=' ) === 0 ) {
		if ( preg_match( '/^fn=\((\d+)\) (.*)$/', $line, $m ) ) {
			$fn_names[ (int) $m[1] ] = $m[2];
			$current_fn = (int) $m[1];
			$in_call_sequence = false;
		}
		continue;
	}

	if ( strpos( $line, 'cfl=' ) === 0 || strpos( $line, 'cfn=' ) === 0 ) {
		if ( preg_match( '/^cfn=\((\d+)\)/', $line, $m ) ) {
			$pending_callee = (int) $m[1];
		}
		continue;
	}

	if ( strpos( $line, 'calls=' ) === 0 ) {
		// calls=COUNT COST MEM
		if ( preg_match( '/^calls=(\d+)/', $line, $m ) ) {
			$count = (int) $m[1];
			if ( $pending_callee !== null ) {
				$call_counts[ $pending_callee ] = ( $call_counts[ $pending_callee ] ?? 0 ) + $count;
			}
		}
		$in_call_sequence = true; // the NEXT cost line is the callee's cost
		continue;
	}

	// A cost line: "<line> <cost> <mem>"
	if ( preg_match( '/^(\d+)\s+(\d+)\s+(\d+)$/', $line, $m ) ) {
		$cost = (int) $m[2];
		if ( $current_fn !== null && ! $in_call_sequence ) {
			$exclusive[ $current_fn ] = ( $exclusive[ $current_fn ] ?? 0 ) + $cost;
		}
		$in_call_sequence = false;
		continue;
	}

	// A bare "fn id" line switches the current function (no cost).
	if ( preg_match( '/^(\d+)$/', $line, $m ) ) {
		$current_fn = (int) $m[1];
		$in_call_sequence = false;
		continue;
	}
}

$to_ms = static function ( $cost ) {
	return $cost * 10 / 1000000.0; // 10ns units -> ms
};

echo "Top functions by EXCLUSIVE (self) time:\n";
$items = array();
foreach ( $exclusive as $id => $cost ) {
	$items[] = array( $fn_names[ $id ] ?? "fn#$id", $cost );
}
usort( $items, static function ( $a, $b ) {
	return $b[1] <=> $a[1];
} );
$i = 0;
foreach ( $items as $item ) {
	if ( $i++ >= $top_n ) {
		break;
	}
	$count = $call_counts[ array_search( $item[0], $fn_names, true ) ] ?? 0;
	printf( "%8.3f ms  %6d calls  %s\n", $to_ms( $item[1] ), $count, $item[0] );
}

echo "\nMincemeat hot-path functions (relevant rows):\n";
$interesting = array(
	'KeySpace->group_digest',
	'KeySpace->key_digest',
	'KeySpace->item_key',
	'KeySpace->group_control_key',
	'Backend->generation_tokens',
	'ObjectCache->persistent_set',
	'ObjectCache->persistent_get',
	'ObjectCache->set',
	'ObjectCache->get',
	'ObjectCache->exists',
	'ObjectCache->set_in_memory',
	'Backend->set',
	'Backend->get',
	'php::hash',
);
foreach ( $interesting as $needle ) {
	foreach ( $fn_names as $id => $name ) {
		if ( strpos( $name, $needle ) !== false ) {
			$cost = $exclusive[ $id ] ?? 0;
			printf(
				"%8.3f ms  %6d calls  %s\n",
				$to_ms( $cost ),
				$call_counts[ $id ] ?? 0,
				$name
			);
			break;
		}
	}
}
