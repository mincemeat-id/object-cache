<?php
/**
 * Configuration exception carrying a stable reason code.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use InvalidArgumentException;

/**
 * Thrown when MINCEMEAT_OBJECT_CACHE_CONFIG fails validation.
 *
 * The reason code is stable for Site Health; the message is redacted and
 * never contains credentials, the source namespace, or DSN strings.
 */
final class ConfigException extends InvalidArgumentException {

	/**
	 * @var string
	 */
	private $reason;

	public function __construct( string $reason, string $message = '' ) {
		parent::__construct( $message === '' ? $reason : $message );
		$this->reason = $reason;
	}

	/**
	 * The stable reason code for Site Health.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
