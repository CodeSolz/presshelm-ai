<?php
/**
 * Refusal from the file layer (M3.2). `code` is stable and machine-readable; the ability layer
 * turns it into a WP_Error for the agent.
 *
 * @package PressHelm
 */

namespace PressHelm\Files;

defined( 'ABSPATH' ) || exit;

/**
 * File operation refused.
 */
final class FileError extends \RuntimeException {

	/**
	 * Machine-readable reason.
	 *
	 * @var string
	 */
	public $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason  e.g. path_denied, conflict, not_found, ambiguous, php_syntax.
	 * @param string $message Human-readable detail (never contains file contents).
	 */
	public function __construct( $reason, $message ) {
		parent::__construct( $message );
		$this->reason = (string) $reason;
	}
}
