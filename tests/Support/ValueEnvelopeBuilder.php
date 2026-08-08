<?php
/**
 * Test-side builder for the value envelope wire format.
 *
 * The runtime `ValueCodec` deliberately exposes only `encode()`/`decode()`.
 * Tests that need to construct specific envelopes (including corrupt or
 * malformed ones) build them here using the public `MAGIC`/`VERSION`
 * constants and the documented 10-byte header layout: 4 magic + 1 version +
 * 1 tag + 4-byte big-endian length.
 *
 * @package Mincemeat\ObjectCache\Tests
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Support;

use Mincemeat\ObjectCache\ValueCodec;

/**
 * Builds raw value envelopes for fixture and corruption tests.
 */
final class ValueEnvelopeBuilder {

	private function __construct() {
	}

	/**
	 * Builds a raw envelope from a pre-serialized payload.
	 *
	 * @param int    $tag     Type tag.
	 * @param string $payload Raw payload bytes.
	 * @return string
	 */
	public static function inline( int $tag, string $payload ): string {
		return self::header( $tag, strlen( $payload ) ) . $payload;
	}

	/**
	 * Builds the 10-byte envelope header, mirroring the private runtime layout.
	 *
	 * @param int $tag    Type tag.
	 * @param int $length Payload length.
	 * @return string
	 */
	private static function header( int $tag, int $length ): string {
		return ValueCodec::MAGIC
			. chr( ValueCodec::VERSION )
			. chr( $tag )
			. pack( 'N', $length );
	}
}