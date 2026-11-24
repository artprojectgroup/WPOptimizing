<?php
/**
 * Cookie consent that never reaches Google: a consent banner and an analytics plugin that
 * starts Google's Consent Mode as «denied», with nothing between them to pass the answer on.
 *
 * Seen on artprojectgroup.es (10/11/2026). Complianz showed its banner and the visitor
 * accepted, Complianz loaded gtag.js, and every hit still went out as `gcs=G100`
 * (consent denied): Google Analytics for WooCommerce sets the default to «denied» for the
 * EU and only sends `gtag('consent','update',…)` through the WP Consent API, and its script
 * returns at once when `wp_has_consent()` does not exist. Without the WP Consent API plugin the
 * answer of the banner never got to Google: no visit in Realtime, only modelled pings. Nothing
 * in either plugin says so, which is why it deserves a card in Home.
 *
 * The finder is pure (active plugin folders and whether the API is loaded in, a finding out).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent banners that report the visitor's choice through the WP Consent API.
 *
 * @return array<string,string> Plugin folder => name.
 */
function wpoptimizing_consent_banner_plugins() {
	return array(
		'complianz-gdpr'                          => 'Complianz',
		'complianz-gdpr-premium'                  => 'Complianz',
		'complianz-gdpr-premium-multisite'        => 'Complianz',
		'cookie-law-info'                         => 'CookieYes',
		'cookiebot'                               => 'Cookiebot',
		'cookie-notice'                           => 'Cookie Notice & Compliance',
		'real-cookie-banner'                      => 'Real Cookie Banner',
		'real-cookie-banner-pro'                  => 'Real Cookie Banner',
		'gdpr-cookie-compliance'                  => 'GDPR Cookie Compliance',
		'iubenda-cookie-law-solution'             => 'iubenda',
		'beautiful-and-responsive-cookie-consent' => 'Beautiful Cookie Consent Banner',
	);
}

/**
 * Analytics plugins that start Consent Mode as «denied» and only grant it through the WP
 * Consent API.
 *
 * @return array<string,string> Plugin folder => name.
 */
function wpoptimizing_consent_mode_plugins() {
	return array(
		'woocommerce-google-analytics-integration' => 'Google Analytics for WooCommerce',
		'google-site-kit'                          => 'Site Kit by Google',
		'google-listings-and-ads'                  => 'Google for WooCommerce',
	);
}

/**
 * The gap, if there is one. Pure.
 *
 * @param string[] $active  Active plugin basenames (`folder/file.php`), site and network.
 * @param bool     $has_api Whether the WP Consent API is loaded (`wp_has_consent()` exists).
 * @return array{banner:string,analytics:string}|array{} Names of the two plugins, or empty.
 */
function wpoptimizing_consent_gap( array $active, $has_api ) {
	if ( $has_api ) {
		return array();
	}
	$folders = array();
	foreach ( $active as $basename ) {
		$folders[ strtolower( strtok( str_replace( '\\', '/', (string) $basename ), '/' ) ) ] = true;
	}
	if ( isset( $folders['wp-consent-api'] ) ) {
		return array(); // Active but not loaded yet in this request: it is there.
	}
	$banner = '';
	foreach ( wpoptimizing_consent_banner_plugins() as $folder => $name ) {
		if ( isset( $folders[ $folder ] ) ) {
			$banner = $name;
			break;
		}
	}
	$analytics = '';
	foreach ( wpoptimizing_consent_mode_plugins() as $folder => $name ) {
		if ( isset( $folders[ $folder ] ) ) {
			$analytics = $name;
			break;
		}
	}
	if ( '' === $banner || '' === $analytics ) {
		return array();
	}
	return array(
		'banner'    => $banner,
		'analytics' => $analytics,
	);
}

/**
 * The gap on this site: its active plugins plus the network-activated ones.
 *
 * @return array{banner:string,analytics:string}|array{}
 */
function wpoptimizing_consent_gap_here() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	return wpoptimizing_consent_gap( $active, function_exists( 'wp_has_consent' ) );
}
