<?php
/**
 * Versioned value envelope codec.
 *
 * Every value begins with a plugin magic/version and a type tag so that
 * numeric strings never become integers, cached false/null are unambiguous,
 * unknown versions/tags are treated as corrupt (never passed blindly to
 * unserialize), and arrays/objects use native PHP serialization. The exact
 * binary bytes are implementation-owned and fixture-tested.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Encodes and decodes the plugin-owned wire format for cached values.
 *
 * @internal This class is an implementation detail of the persistent adapter.
 */
final class ValueCodec {

	/** Plugin magic bytes. */
	public const MAGIC = 'MCOC';

	/** Envelope schema version. */
	public const VERSION = 0x01;

	/** Type tags. */
	public const TAG_INT        = 0x01;
	public const TAG_DOUBLE     = 0x02;
	public const TAG_STRING     = 0x03;
	public const TAG_BOOL       = 0x04;
	public const TAG_NULL       = 0x05;
	public const TAG_SERIALIZED = 0x06;

	/** The PHP serialization of boolean false, used to disambiguate failure. */
	private const SERIALIZED_FALSE = 'b:0;';

	/** Envelope header size: 4 magic + 1 version + 1 tag + 4 length. */
	private const HEADER_SIZE = 10;

	private function __construct() {
	}

	/**
	 * Builds a raw envelope from a pre-serialized payload. Useful for fixtures
	 * and tests that need to construct specific envelopes (including corrupt
	 * ones) without going through encode().
	 *
	 * @param int    $tag     Type tag.
	 * @param string $payload Raw payload bytes.
	 * @return string
	 */
	public static function header_inline( int $tag, string $payload): string {
		return self::header( $tag, strlen( $payload ) ) . $payload;
	}

	/**
	 * Encodes a value into the versioned envelope.
	 *
	 * @param mixed $value The value to encode.
	 * @return string
	 * @throws ValueCodecException On an unsupported type or serialization failure.
	 */
	public static function encode( $value): string {
		if ($value === null) {
			return self::header( self::TAG_NULL, 0 );
		}

		if (is_bool( $value )) {
			return self::header( self::TAG_BOOL, 1 ) . ( $value ? "\x01" : "\x00" );
		}

		if (is_int( $value )) {
			$payload = self::int_to_payload( $value );
			return self::header( self::TAG_INT, strlen( $payload ) ) . $payload;
		}

		if (is_float( $value )) {
			$payload = pack( 'e', $value );
			return self::header( self::TAG_DOUBLE, strlen( $payload ) ) . $payload;
		}

		if (is_string( $value )) {
			return self::header( self::TAG_STRING, strlen( $value ) ) . $value;
		}

		if (is_array( $value ) || is_object( $value )) {
			return self::encode_serialized( $value );
		}

		throw new ValueCodecException( 'encode-unsupported', sprintf( 'Unsupported value type: %s.', gettype( $value ) ) );
	}

	/**
	 * Decodes the versioned envelope.
	 *
	 * @param string $bytes The encoded envelope.
	 * @return array{0:bool,1:mixed,2:?string} Tuple [found, value, error_category].
	 *         found is false for a miss/corruption (value is false, error set).
	 *         found is true on success (error is null).
	 */
	public static function decode( string $bytes): array {
		if ($bytes === '') {
			return array( false, false, 'decode-empty' );
		}

		if (strlen( $bytes ) < self::HEADER_SIZE) {
			return array( false, false, 'decode-truncated' );
		}

		if (substr( $bytes, 0, 4 ) !== self::MAGIC) {
			return array( false, false, 'decode-magic' );
		}

		$version = ord( $bytes[4] );
		if ($version !== self::VERSION) {
			return array( false, false, 'decode-version' );
		}

		$tag     = ord( $bytes[5] );
		$length  = self::read_length( $bytes, 6 );
		$payload = substr( $bytes, self::HEADER_SIZE );

		if (strlen( $payload ) !== $length) {
			return array( false, false, 'decode-length' );
		}

		switch ($tag) {
			case self::TAG_NULL:
				if ($length !== 0) {
					return array( false, false, 'decode-null-payload' );
				}
				return array( true, null, null );

			case self::TAG_BOOL:
				if ($length !== 1) {
					return array( false, false, 'decode-bool-payload' );
				}
				return array( true, $payload !== "\x00", null );

			case self::TAG_INT:
				return self::decode_int( $payload );

			case self::TAG_DOUBLE:
				return self::decode_double( $payload );

			case self::TAG_STRING:
				return array( true, $payload, null );

			case self::TAG_SERIALIZED:
				return self::decode_serialized( $payload );

			default:
				return array( false, false, 'decode-unknown-tag' );
		}
	}

	/**
	 * Encodes an integer as its decimal-string payload, round-tripping any
	 * integer supported by the running PHP build.
	 *
	 * @param int $value
	 * @return string
	 */
	private static function int_to_payload( int $value): string {
		return (string) $value;
	}

	/**
	 * @param string $payload
	 * @return array{0:bool,1:int|false,2:string|null}
	 */
	private static function decode_int( string $payload): array {
		if ($payload === '') {
			return array( false, false, 'decode-int-empty' );
		}

		$str = $payload;
		$abs = $str[0] === '-' ? substr( $str, 1 ) : $str;

		if ($abs === '' || ! ctype_digit( $abs )) {
			return array( false, false, 'decode-int-invalid' );
		}

		return array( true, (int) $payload, null );
	}

	/**
	 * @param string $payload
	 * @return array{0:bool,1:float|false,2:string|null}
	 */
	private static function decode_double( string $payload): array {
		if (strlen( $payload ) !== 8) {
			return array( false, false, 'decode-double-length' );
		}
		$unpacked = unpack( 'e', $payload );
		if ($unpacked === false) {
			return array( false, false, 'decode-double-invalid' );
		}
		return array( true, $unpacked[1], null );
	}

	/**
	 * @param mixed $value
	 * @return string
	 * @throws ValueCodecException
	 */
	private static function encode_serialized( $value): string {
		$payload = null;
		$prev    = error_reporting( 0 );

		try {
			$payload = serialize( $value );
		} catch (\Throwable $e) {
			$payload = false;
		}

		error_reporting( $prev );

		if ($payload === false) {
			throw new ValueCodecException( 'encode-serialize-failed', 'Failed to serialize value.' );
		}

		return self::header( self::TAG_SERIALIZED, strlen( $payload ) ) . $payload;
	}

	/**
	 * @param string $payload
	 * @return array{0:bool,1:mixed,2:string|null}
	 */
	private static function decode_serialized( string $payload): array {
		if ($payload === '') {
			return array( false, false, 'decode-serialized-empty' );
		}

		$value = null;
		$prev  = error_reporting( 0 );

		set_error_handler(
			static function () {
				return true;
			}
		);

		try {
			$value = @unserialize( $payload );
		} catch (\Throwable $e) {
			$value = false;
		}

		restore_error_handler();
		error_reporting( $prev );

		// unserialize returns false both on failure and for a legitimate
		// boolean false. Disambiguate by comparing the payload to the known
		// serialization of false. Any other non-false result is a success.
		if ($value === false && $payload !== self::SERIALIZED_FALSE) {
			return array( false, false, 'decode-serialized-failed' );
		}

		return array( true, $value, null );
	}

	/**
	 * Builds the 10-byte envelope header.
	 *
	 * @param int $tag
	 * @param int $length
	 * @return string
	 */
	private static function header( int $tag, int $length): string {
		return self::MAGIC
			. chr( self::VERSION )
			. chr( $tag )
			. self::write_length( $length );
	}

	/**
	 * Reads the 4-byte big-endian length field starting at offset.
	 *
	 * @param string $bytes
	 * @param int    $offset
	 * @return int
	 */
	private static function read_length( string $bytes, int $offset): int {
		$unpacked = unpack( 'N', substr( $bytes, $offset, 4 ) );
		if ($unpacked === false) {
			return 0;
		}
		return (int) $unpacked[1];
	}

	/**
	 * Writes a 4-byte big-endian length field.
	 *
	 * @param int $length
	 * @return string
	 */
	private static function write_length( int $length): string {
		return pack( 'N', $length );
	}
}
