<?php
/**
 * Verifies, records and executes one command envelope (ADR-001):
 * signature + audience + expiry → ledger (idempotency / redelivery) → seq check → ack →
 * run as PressHelm's task user (content permissions only) with WordPress capability checks → journal + health gate
 * for changes (automatic rollback) → signed result.
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

use PressHelm\Abilities\Executor;
use PressHelm\Abilities\Registry;
use PressHelm\Connect\Cloud;
use PressHelm\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Command dispatcher.
 */
final class Dispatcher {

	/**
	 * Handles one envelope. Returns a short status for logs/tests.
	 *
	 * @param array $envelope {data, sig}.
	 * @return string
	 */
	public static function handle( array $envelope ) {
		if ( \PressHelm\Recovery\EmergencyStop::active() ) {
			return 'emergency_stop'; // Nothing runs, nothing is acknowledged.
		}
		$conn = Connection::get();
		$pub  = isset( $conn['cloud_public_key'] ) ? base64_decode( (string) $conn['cloud_public_key'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key bytes.
		if ( false === $pub || empty( $conn['site_id'] ) ) {
			return 'not_connected';
		}
		// Seq is checked below (after the ledger), so redeliveries can be answered.
		list( $status, $cmd ) = Envelope::open( $envelope, $pub, (string) $conn['site_id'], -1, time() );
		if ( Envelope::OK !== $status ) {
			return $status; // Never report on unverified input: it may not come from the cloud.
		}

		$known = Ledger::get( (string) $cmd['command_id'] );
		if ( $known ) {
			// Redelivery: never execute twice. Re-send the stored result, or report an interruption.
			$stored = json_decode( (string) $known['result'], true );
			if ( 'running' === $known['status'] ) {
				Ledger::finish(
					(string) $cmd['command_id'],
					'failed',
					array(
						'error'   => 'interrupted',
						'message' => 'The site stopped while running this step; it was not repeated.',
					)
				);
				$stored = array(
					'status' => 'failed',
					'result' => array( 'error' => 'interrupted' ),
				);
			}
			self::report( (string) $cmd['command_id'], isset( $stored['status'] ) ? $stored['status'] : $known['status'], isset( $stored['result'] ) ? $stored['result'] : null );
			return 'redelivered';
		}
		if ( (int) $cmd['seq'] <= (int) ( $conn['last_seq'] ?? 0 ) ) {
			return Envelope::SEQ_REWIND;
		}
		if ( ! Ledger::start( (string) $cmd['command_id'], (int) $cmd['seq'], (string) $cmd['ability'] ) ) {
			return 'duplicate';
		}
		Connection::update( array( 'last_seq' => (int) $cmd['seq'] ) );
		Cloud::post( '/v1/connector/ack', array( 'command_id' => $cmd['command_id'] ) );

		$outcome             = self::execute( $cmd );
		$outcome['approved'] = ! empty( $cmd['approval_id'] ); // Shown in Task History.
		Ledger::finish( (string) $cmd['command_id'], $outcome['status'], $outcome );
		self::report( (string) $cmd['command_id'], $outcome['status'], $outcome['result'] );
		/**
		 * A command from PressHelm Cloud has run (after its result was reported).
		 *
		 * @param string $ability Ability id.
		 * @param string $status  succeeded, failed or rejected.
		 * @param array  $outcome Outcome (status, result).
		 * @param array  $payload The command's input.
		 */
		do_action( 'presshelm_command_finished', (string) $cmd['ability'], (string) $outcome['status'], $outcome, (array) ( $cmd['payload'] ?? array() ) );
		return $outcome['status'];
	}

	/**
	 * Whether an ability may only run with the owner's approval: it changes the site and its risk is
	 * medium or higher, or it always asks.
	 *
	 * @param array $def Registry entry.
	 * @return bool
	 */
	public static function needs_approval( array $def ) {
		if ( ! empty( $def['approval_required'] ) ) {
			return true;
		}
		return ! empty( $def['mutates'] ) && in_array( (string) ( $def['risk'] ?? '' ), array( 'MEDIUM', 'HIGH', 'CRITICAL' ), true );
	}

	/**
	 * Abilities that change one post's content, which may run without approval on a staged draft
	 * copy (visitors see nothing until it is published with approval).
	 */
	const STAGE_EDITS = array( 'content.update', 'blocks.patch', 'content.text_edit', 'builder.update', 'builder.change', 'media.insert', 'seo.yoast.update', 'post_meta.update' );

	/**
	 * Whether a change only touches a staged draft copy (and cannot publish it).
	 *
	 * @param string $ability Ability id.
	 * @param array  $payload Payload.
	 * @return bool
	 */
	public static function edits_staged_copy( $ability, array $payload ) {
		if ( ! in_array( $ability, self::STAGE_EDITS, true ) || isset( $payload['status'] ) || isset( $payload['post_status'] ) ) {
			return false;
		}
		// Text edits: only the post itself (or its meta).
		if ( 'content.text_edit' === $ability && ! in_array( (string) ( $payload['target_type'] ?? 'post' ), array( 'post', 'meta' ), true ) ) {
			return false;
		}
		$id = (int) ( $payload['id'] ?? ( $payload['post_id'] ?? 0 ) );
		return $id > 0 && \PressHelm\Abilities\Stage::original_of( $id ) > 0;
	}

	/**
	 * Runs the ability.
	 *
	 * @param array $cmd Verified command.
	 * @return array{status:string,result:mixed}
	 */
	private static function execute( array $cmd ) {
		if ( ! isset( Registry::all()[ $cmd['ability'] ] ) ) {
			return array(
				'status' => 'rejected',
				'result' => array( 'error' => 'unknown_ability' ),
			);
		}
		// The owner's own limits (Permissions tab) apply to everything the cloud sends.
		$refusal = \PressHelm\Safety\LocalPolicy::refusal( (string) $cmd['ability'], ! empty( Registry::all()[ $cmd['ability'] ]['mutates'] ), ! empty( $cmd['approval_id'] ) );
		if ( '' !== $refusal ) {
			return array(
				'status' => 'rejected',
				'result' => array(
					'error'   => $refusal,
					'message' => 'local_policy_off' === $refusal
						? 'The site owner turned this off in WordPress → PressHelm → Permissions.'
						: 'The site owner wants to approve every change of this kind (WordPress → PressHelm → Permissions); ask for approval.',
				),
			);
		}
		// Changes of medium risk and above never run without the owner's approval, whatever the
		// cloud sends (defense in depth: the site checks too, by its own risk list). The same for
		// abilities that always need it (e.g. other plugins' abilities that change the site).
		$def = Registry::all()[ $cmd['ability'] ];
		if ( self::needs_approval( $def ) && empty( $cmd['approval_id'] ) && ! self::edits_staged_copy( (string) $cmd['ability'], (array) ( $cmd['payload'] ?? array() ) ) ) {
			return array(
				'status' => 'rejected',
				'result' => array(
					'error'   => 'approval_required',
					'message' => 'This change needs the site owner\'s approval.',
				),
			);
		}
		// Run as PressHelm's own task user, never an administrator: it has content permissions only,
		// and WordPress refuses anything else.
		$user = TaskUser::id();
		if ( ! $user ) {
			return array(
				'status' => 'rejected',
				'result' => array(
					'error'   => 'task_user_missing',
					'message' => 'The site owner needs to open WordPress → PressHelm once to finish setting up.',
				),
			);
		}
		$token = TaskUser::begin( $user );
		try {
			return Executor::run( (string) $cmd['ability'], (array) ( $cmd['payload'] ?? array() ), (string) $cmd['command_id'] );
		} finally {
			TaskUser::end( $token );
		}
	}

	/**
	 * Signed result to the cloud.
	 *
	 * @param string $command_id Command id.
	 * @param string $status     Status.
	 * @param mixed  $result     Result data.
	 */
	private static function report( $command_id, $status, $result ) {
		Cloud::post(
			'/v1/connector/results',
			array(
				'command_id' => $command_id,
				'status'     => $status,
				'result'     => $result,
			)
		);
	}
}
