<?php
/**
 * Backend exception carrying a stable reason category.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use RuntimeException;

/**
 * Thrown when the persistent backend fails to connect, authenticate, or
 * execute a command. The reason code is stable for Site Health; the message
 * never carries credentials, keys, or cached values.
 */
final class BackendException extends RuntimeException {

	/**
	 * @var string
	 */
	private $reason;

	public function __construct( string $reason, string $message = '', int $code = 0, ?\Throwable $previous = null) {
		parent::__construct( $message === '' ? $reason : $message, $code, $previous );
		$this->reason = $reason;
	}

	/**
	 * The stable reason code for diagnostics.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
