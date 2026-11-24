<?php
/**
 * Sensitive-value redaction for the live-query viewer.
 *
 * The viewer can dump arbitrary rows (options, meta, posts, users, and full
 * `SELECT *` table dumps). This redacts anything that looks like a credential
 * before it reaches the browser. The matching logic is pure so it can be
 * unit-tested without WordPress.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Substrings that mark a column / option / meta name as holding a secret.
 *
 * @return string[]
 */
function wpoptimizing_sensitive_patterns() {
	$patterns = array(
		'password',
		'passwd',
		'pwd',
		'user_pass',
		'secret',
		'token',
		'api_key',
		'apikey',
		'auth_key',
		'auth_salt',
		'secure_auth',
		'logged_in',
		'nonce_key',
		'nonce_salt',
		'private_key',
		'client_secret',
		'access_token',
		'refresh_token',
		'bearer',
		'webhook_secret',
		'smtp_pass',
		'session',
		'salt',
		'cvv',
		'card_number',
		'ccnumber',
		// Account recovery / second factor. user_activation_key in particular is a password
		// reset in a single column: leaking it is equivalent to leaking the account.
		'activation_key',
		'recovery',
		'reset_key',
		'confirm_key',
		'totp',
		'2fa',
		'otp',
		'license',
		'signature',
		'credential',
	);

	/**
	 * Filters the substrings that flag a name as sensitive (case-insensitive).
	 *
	 * @since 0.1.0
	 * @param string[] $patterns Default patterns.
	 */
	$patterns = apply_filters( 'wpoptimizing_sensitive_patterns', $patterns );

	return array_values( array_filter( array_map( 'strval', (array) $patterns ) ) );
}

/**
 * Whether a name contains any sensitive substring. Pure.
 *
 * @param string   $name     Column / option / meta name.
 * @param string[] $patterns Sensitive substrings (lower-case compared).
 * @return bool
 */
function wpoptimizing_name_is_sensitive( $name, array $patterns ) {
	$name = strtolower( (string) $name );
	if ( '' === $name ) {
		return false;
	}
	foreach ( $patterns as $pattern ) {
		$pattern = strtolower( (string) $pattern );
		if ( '' !== $pattern && false !== strpos( $name, $pattern ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a value looks like a long random key/secret (≥ 32 key-ish chars). Pure.
 *
 * @param mixed $value Value.
 * @return bool
 */
function wpoptimizing_value_looks_secret( $value ) {
	$val = (string) $value;
	if ( strlen( $val ) >= 32 && 1 === preg_match( '/^[A-Za-z0-9\/+_\-]{32,}$/', $val ) ) {
		// A long identifier is not a key: «action_scheduler_lock_async-request-runner» has the
		// same characters as a token, and every option name over 32 characters came out as
		// [REDACTED]. Words joined by _ or - in one case are a name; a key is hex (a UUID too),
		// mixes upper case, lower case and digits, or has no separators at all.
		if ( 1 !== preg_match( '/[_\-]/', $val ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^[0-9a-f\-]+$/i', $val ) ) {
			return true;
		}
		if ( 1 === preg_match( '/[A-Z]/', $val ) && 1 === preg_match( '/[a-z]/', $val ) && 1 === preg_match( '/[0-9]/', $val ) ) {
			return true;
		}
	}
	// Password hashes and WordPress reset keys ("<timestamp>:<phpass hash>").
	if ( 1 === preg_match( '/^(?:[0-9]{9,12}:)?\$(?:P|wp|2[aby]|argon2)/', $val ) ) {
		return true;
	}
	return false;
}

/**
 * Redact sensitive values from live-query result rows.
 *
 * Three layers:
 *  1. Any column whose own NAME is sensitive (password, token, secret, user_pass,
 *     activation_key…) is redacted in every row — this covers `SELECT *` table dumps.
 *  2. For option/meta result sets, the value is also redacted when the option/meta
 *     NAME is sensitive or the value itself looks like a long random key.
 *  3. In EVERY result set, a value that looks like a key or a password hash is redacted
 *     whatever its column is called — the safety net for columns nobody listed.
 *
 * @param string[] $columns Column names for the result set.
 * @param array[]  $rows    Result rows (associative arrays).
 * @return array[]
 */
function wpoptimizing_redact_sensitive_rows( array $columns, array $rows ) {
	if ( empty( $rows ) ) {
		return $rows;
	}

	$patterns = wpoptimizing_sensitive_patterns();

	// Name/value pairs for option/meta result sets (enables the value heuristic).
	$name_col  = '';
	$value_col = '';
	if ( in_array( 'option_name', $columns, true ) && in_array( 'option_value', $columns, true ) ) {
		$name_col  = 'option_name';
		$value_col = 'option_value';
	} elseif ( in_array( 'meta_key', $columns, true ) && in_array( 'meta_value', $columns, true ) ) {
		$name_col  = 'meta_key';
		$value_col = 'meta_value';
	}

	// Any column whose own name is sensitive (table dumps, user lookups, …).
	$sensitive_cols = array();
	foreach ( $columns as $column ) {
		if ( wpoptimizing_name_is_sensitive( $column, $patterns ) ) {
			$sensitive_cols[] = $column;
		}
	}

	foreach ( $rows as &$row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		// Layer 3 (all result sets, including SELECT * dumps): a value that LOOKS like a
		// long random key is redacted whatever its column is called. Column names we do not
		// recognise are exactly the case this catches.
		foreach ( $row as $rk => $rv ) {
			// The record's own name (option_name, meta_key) is never hidden: it is what the list
			// and the viewer's title already show, and hiding it only hides which row this is.
			if ( '' !== $name_col && $rk === $name_col ) {
				continue;
			}
			if ( is_scalar( $rv ) && wpoptimizing_value_looks_secret( $rv ) ) {
				$row[ $rk ] = '[REDACTED]';
			}
		}

		foreach ( $sensitive_cols as $column ) {
			if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
				$row[ $column ] = '[REDACTED]';
			}
		}

		if ( '' !== $name_col && isset( $row[ $value_col ] ) && '[REDACTED]' !== $row[ $value_col ] ) {
			$name = (string) ( $row[ $name_col ] ?? '' );
			if ( wpoptimizing_name_is_sensitive( $name, $patterns ) || wpoptimizing_value_looks_secret( $row[ $value_col ] ) ) {
				$row[ $value_col ] = '[REDACTED]';
			}
		}
	}
	unset( $row );

	return $rows;
}
