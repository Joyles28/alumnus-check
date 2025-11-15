<?php
/**
 * Custom session and auth helpers for the Alumnus plugin.
 *
 * This provides lightweight session state for the plugin's own login system,
 * without relying on WordPress user authentication.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Cookie name for the signed "remember me" token
const ALUMNUS_AUTH_COOKIE = 'alumnus_auth';

/**
 * Ensure a PHP session is started early with safe cookie parameters.
 */
function alumnus_session_start() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}

	// Secure-ish defaults; SameSite=Lax so redirects and admin-ajax work well
	$secure   = is_ssl();
	$httponly = true;

	// Respect WP's cookie host and path
	$cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
	$cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

	// PHP >= 7.3 supports options array
	$opts = array(
		'lifetime' => 0, // Session cookie by default
		'path'     => $cookie_path,
		'domain'   => $cookie_domain,
		'secure'   => $secure,
		'httponly' => $httponly,
		'samesite' => 'Lax',
	);

	if ( function_exists('session_set_cookie_params') ) {
		// Use array signature if available (7.3+); falls back silently on older versions
		@session_set_cookie_params( $opts );
	}

	@session_start();
}
add_action( 'init', 'alumnus_session_start', 1 );

/**
 * Create a signed, time-limited token for remember-me cookie.
 */
function alumnus_build_auth_token( $username, $expires ) {
	$username = (string) $username;
	$expires  = (int) $expires;
	$payload  = json_encode( array( 'u' => $username, 'e' => $expires ), JSON_UNESCAPED_SLASHES );
	$payload_b64 = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
	$secret  = wp_salt( 'auth' );
	$mac     = hash_hmac( 'sha256', $payload_b64, $secret );
	return $payload_b64 . '.' . $mac;
}

/**
 * Validate a remember-me cookie and return username if valid; otherwise ''.
 */
function alumnus_validate_auth_cookie( $cookie_value ) {
	if ( ! is_string( $cookie_value ) || $cookie_value === '' ) {
		return '';
	}
	$parts = explode( '.', $cookie_value );
	if ( count( $parts ) !== 2 ) { return ''; }
	list( $payload_b64, $mac ) = $parts;
	$secret   = wp_salt( 'auth' );
	$expected = hash_hmac( 'sha256', $payload_b64, $secret );
	if ( ! hash_equals( $expected, $mac ) ) { return ''; }
	$payload_json = base64_decode( strtr( $payload_b64, '-_', '+/' ) );
	$data = json_decode( $payload_json, true );
	if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['e'] ) ) { return ''; }
	if ( time() > (int) $data['e'] ) { return ''; }
	return (string) $data['u'];
}

/**
 * Set the current alumni login state into session and optional remember cookie.
 *
 * @param object $user_row   Row from custom `user` table (expects ->user, ->course_id, ->year)
 * @param bool   $remember  If true, set a signed cookie to restore session for ~14 days.
 */
function alumnus_set_login_state( $user_row, $remember = false ) {
	alumnus_session_start();
	$_SESSION['alumnus_logged_in'] = true;
	$_SESSION['alumnus_user']      = (string) $user_row->user;
	$_SESSION['alumnus_course_id'] = isset($user_row->course_id) ? (string) $user_row->course_id : '';
	$_SESSION['alumnus_year']      = isset($user_row->year) ? (string) $user_row->year : '';

	if ( $remember ) {
		$expires = time() + DAY_IN_SECONDS * 14; // 14 days
		$token   = alumnus_build_auth_token( (string) $user_row->user, $expires );
		$secure   = is_ssl();
		$httponly = true;
		$cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
		$cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
		setcookie( ALUMNUS_AUTH_COOKIE, $token, $expires, $cookie_path, $cookie_domain, $secure, $httponly );
		// Modern SameSite attribute (best-effort)
		if ( PHP_VERSION_ID >= 70300 ) {
			@setcookie( ALUMNUS_AUTH_COOKIE, $token, array(
				'expires'  => $expires,
				'path'     => $cookie_path,
				'domain'   => $cookie_domain,
				'secure'   => $secure,
				'httponly' => $httponly,
				'samesite' => 'Lax',
			) );
		}
	}
}

/**
 * Return whether our custom alumni session is active.
 */
function alumnus_is_logged_in() {
	alumnus_session_start();
	if ( ! empty( $_SESSION['alumnus_logged_in'] ) && ! empty( $_SESSION['alumnus_user'] ) ) {
		return true;
	}
	// Try to rehydrate session from cookie
	if ( ! headers_sent() && isset($_COOKIE[ ALUMNUS_AUTH_COOKIE ]) ) {
		$username = alumnus_validate_auth_cookie( $_COOKIE[ ALUMNUS_AUTH_COOKIE ] );
		if ( $username !== '' ) {
			$_SESSION['alumnus_logged_in'] = true;
			$_SESSION['alumnus_user'] = $username;
			return true;
		}
	}
	return false;
}

/**
 * Get current alumni username from session/cookie or ''.
 */
function alumnus_current_username() {
	return alumnus_is_logged_in() ? (string) $_SESSION['alumnus_user'] : '';
}

/**
 * Destroy our session state and clear remember-me cookie.
 */
function alumnus_logout() {
	alumnus_session_start();
	unset( $_SESSION['alumnus_logged_in'], $_SESSION['alumnus_user'], $_SESSION['alumnus_course_id'], $_SESSION['alumnus_year'] );
	if ( isset($_COOKIE[ ALUMNUS_AUTH_COOKIE ]) ) {
		$cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
		$cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
		@setcookie( ALUMNUS_AUTH_COOKIE, '', time() - YEAR_IN_SECONDS, $cookie_path, $cookie_domain );
	}
}

/**
 * Support a simple logout URL flow: add alumnus_logout=1 to any URL; optionally include _wpnonce.
 */
function alumnus_maybe_handle_logout() {
	if ( isset($_GET['alumnus_logout']) && (int) $_GET['alumnus_logout'] === 1 ) {
		// Optional nonce check when provided
		if ( isset($_GET['_wpnonce']) && ! wp_verify_nonce( (string) $_GET['_wpnonce'], 'alumnus_logout' ) ) {
			wp_die( esc_html__( 'Invalid logout link.', 'alumnus' ) );
		}
		alumnus_logout();
		// Redirect to home or provided redirect_to
		$redir = isset($_GET['redirect_to']) ? esc_url_raw( wp_unslash($_GET['redirect_to']) ) : home_url('/');
		wp_safe_redirect( $redir );
		exit;
	}
}
add_action( 'init', 'alumnus_maybe_handle_logout', 2 );

// Ensure that when a user logs out of WordPress, our custom alumni session/cookie is also cleared
add_action( 'wp_logout', 'alumnus_logout' );

/**
 * Helper: Build a logout URL for templates (optionally with a redirect).
 */
function alumnus_logout_url( $redirect_to = '' ) {
	$base = $redirect_to !== '' ? $redirect_to : home_url('/');
	$url  = add_query_arg( 'alumnus_logout', '1', $base );
	return wp_nonce_url( $url, 'alumnus_logout' );
}
