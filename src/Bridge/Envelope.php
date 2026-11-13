<?php
/**
 * Verifies command envelopes from PressHelm Cloud (ADR-001): Ed25519 signature over the exact
 * JSON bytes, audience (site id), expiry and an increasing sequence number. Replay protection
 * by nonce is done by the caller, which owns persistent storage.
 *
 * Pure PHP (libsodium or WordPress's sodium_compat); no WordPress calls, so it is unit-tested
 * against the shared contract fixtures.
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Envelope verifier.
 */
final class Envelope {

	const OK               = 'ok';
	const MALFORMED        = 'malformed';
	const BAD_SIGNATURE    = 'bad_signature';
	const WRONG_AUDIENCE   = 'wrong_audience';
	const EXPIRED          = 'expired';
	const SEQ_REWIND       = 'seq_rewind';
	const SIGNATURE_LENGTH = 64;
	const PUBLIC_KEY_BYTES = 32;

	/**
	 * Opens an envelope.
	 *
	 * @param array  $envelope        Array with base64 'data' and 'sig'.
	 * @param string $cloud_public    Raw 32-byte cloud public key.
	 * @param string $site_id         This site's id.
	 * @param int    $last_seq        Highest sequence already executed.
	 * @param int    $now             Current Unix time.
	 * @return array{0:string,1:array|null} [status, command|null]
	 */
	public static function open( array $envelope, $cloud_public, $site_id, $last_seq, $now ) {
		$data = isset( $envelope['data'] ) ? base64_decode( (string) $envelope['data'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding of signed bytes, not code.
		$sig  = isset( $envelope['sig'] ) ? base64_decode( (string) $envelope['sig'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature bytes.

		if ( false === $data || '' === $data || false === $sig || self::SIGNATURE_LENGTH !== strlen( $sig ) || self::PUBLIC_KEY_BYTES !== strlen( (string) $cloud_public ) ) {
			return array( self::MALFORMED, null );
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $data, $cloud_public ) ) {
			return array( self::BAD_SIGNATURE, null );
		}
		$command = json_decode( $data, true );
		if ( ! is_array( $command ) || ! isset( $command['site_id'], $command['seq'], $command['expires_at'], $command['nonce'], $command['ability'] ) ) {
			return array( self::MALFORMED, null );
		}
		if ( ! hash_equals( (string) $site_id, (string) $command['site_id'] ) ) {
			return array( self::WRONG_AUDIENCE, null );
		}
		if ( (int) $command['expires_at'] < (int) $now ) {
			return array( self::EXPIRED, null );
		}
		if ( (int) $command['seq'] <= (int) $last_seq ) {
			return array( self::SEQ_REWIND, null );
		}
		return array( self::OK, $command );
	}
}
