<?php
/**
 * Value codec exception carrying a stable reason category.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use RuntimeException;

/**
 * Thrown when a value cannot be encoded (unsupported type, serialization failure).
 *
 * Decode never throws: corrupt/unknown payloads are reported as a miss with a
 * sanitized error category by ValueCodec::decode().
 */
final class ValueCodecException extends RuntimeException {

	/**
	 * @var string
	 */
	private $category;

	public function __construct( string $category, string $message = '' ) {
		parent::__construct( '' === $message ? $category : $message );
		$this->category = $category;
	}

	/**
	 * The stable error category for diagnostics.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}
}
