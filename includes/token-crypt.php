<?php
/**
 * Stored API tokens: encrypted while they sit in the database.
 *
 * THE PROBLEM: some features need a third-party credential kept between requests — the
 * Cloudflare API token the user enters, for instance. Written in clear text it is readable by
 * anyone with the dashboard, the database, or a backup of that site.
 *
 * WHAT THIS FIXES AND WHAT IT DOES NOT. The key is derived from the site's salts, which do
 * not travel inside a SQL dump. Against the case that actually happens — a stray `.sql` in
 * a public directory, a backup that ends up somewhere it should not, a copy sent by e-mail
 * — the attacker gets a useless blob. Against somebody who already has the filesystem it
 * is worth nothing: they have the salts and they decrypt. It does not claim otherwise.
 *
 * WHAT REALLY BOUNDS THE DAMAGE is the token's own scope, which is not decided here: give
 * each token the narrowest permissions its feature needs.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Envelope prefix. Lets a reader tell an encrypted value from a plain one.
 */
const WPOPT_TOKEN_ENVELOPE = 'wpopt1|';

/**
 * Encryption key, derived from this site's salts.
 *
 * @since 0.1.0
 * @return string 32 raw bytes, or '' when there are no salts.
 */
function wpoptimizing_token_key() {
	if ( ! function_exists( 'wp_salt' ) ) {
		return '';
	}

	$salt = (string) wp_salt( 'auth' );

	return '' === $salt ? '' : hash( 'sha256', $salt . '|wpoptimizing-update-token', true );
}

/**
 * Can this server encrypt?
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_token_can_encrypt() {
	static $can = null;

	if ( null !== $can ) {
		return $can;
	}

	$can = function_exists( 'openssl_encrypt' )
		&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true )
		&& '' !== wpoptimizing_token_key();

	return $can;
}

/**
 * Wrap a token for storage.
 *
 * @since 0.1.0
 * @param string $token Plain token.
 * @return string Envelope, or the token unchanged when the server cannot encrypt.
 */
function wpoptimizing_token_encrypt( $token ) {
	$token = (string) $token;

	if ( '' === $token || ! wpoptimizing_token_can_encrypt() ) {
		return $token;
	}

	$iv  = random_bytes( 12 );
	$tag = '';

	$cipher = openssl_encrypt( $token, 'aes-256-gcm', wpoptimizing_token_key(), OPENSSL_RAW_DATA, $iv, $tag );

	if ( false === $cipher ) {
		return $token;
	}

	return WPOPT_TOKEN_ENVELOPE . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope, not obfuscation.
}

/**
 * Unwrap a stored token.
 *
 * Anything unexpected returns '' instead of guessing: the caller treats that as an empty
 * cache and fetches again, which is the right outcome. A half-decoded token sent to GitLab
 * would be a silent update failure.
 *
 * @since 0.1.0
 * @param mixed $stored Stored value.
 * @return string Plain token, or ''.
 */
function wpoptimizing_token_decrypt( $stored ) {
	$stored = (string) $stored;

	if ( '' === $stored ) {
		return '';
	}

	// No envelope: a plain value from an earlier version. Accepted once; it is rewritten
	// encrypted as soon as it expires.
	if ( 0 !== strpos( $stored, WPOPT_TOKEN_ENVELOPE ) ) {
		return $stored;
	}

	if ( ! wpoptimizing_token_can_encrypt() ) {
		return '';
	}

	$raw = base64_decode( substr( $stored, strlen( WPOPT_TOKEN_ENVELOPE ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope, not obfuscation.

	if ( false === $raw || strlen( $raw ) <= 28 ) {
		return '';
	}

	$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', wpoptimizing_token_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

	return is_string( $plain ) ? $plain : '';
}
