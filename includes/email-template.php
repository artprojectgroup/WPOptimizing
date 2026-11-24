<?php
/**
 * Shared email layout for every WPOptimizing email (maintenance report, network alert,
 * license notices), so they all look the same. One place to swap the corporate colours and
 * logo when they are finalised — change wpoptimizing_email_brand() and every email follows.
 *
 * Self-contained inline styles for email-client robustness (no external CSS, no <style>).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brand identity for emails: name, optional logo URL and the header/accent colour. Reads the
 * white-label brand when present; every value is filterable so the final corporate palette and
 * logo plug in here once.
 *
 * @return array{name:string,logo:string,color:string}
 */
function wpoptimizing_email_brand() {
	$name = function_exists( 'wpoptimizing_brand_name' ) ? (string) wpoptimizing_brand_name() : 'WPOptimizing';

	// White-label logo wins; otherwise our own full white logo, isotype + wordmark (a PNG, not
	// the SVG — email clients like Gmail refuse to render SVG). Served from the plugin's own URL.
	$logo = function_exists( 'wpoptimizing_brand_logo' ) ? (string) wpoptimizing_brand_logo() : '';
	if ( '' === $logo && defined( 'WPOPTIMIZING_FILE' ) ) {
		$png = plugin_dir_path( WPOPTIMIZING_FILE ) . 'assets/images/logo-email.png';
		if ( file_exists( $png ) ) {
			$logo = plugins_url( 'assets/images/logo-email.png', WPOPTIMIZING_FILE );
		}
	}

	return array(
		'name'   => (string) apply_filters( 'wpoptimizing_email_brand_name', $name ),
		'logo'   => (string) apply_filters( 'wpoptimizing_email_brand_logo', $logo ),
		// Corporate teal for the header (a solid colour, because Outlook ignores CSS gradients),
		// with the acid-lime and near-black as accents. Filterable so a white-label brand can
		// override the palette.
		'color'  => (string) apply_filters( 'wpoptimizing_email_brand_color', '#01515c' ),
		'accent' => (string) apply_filters( 'wpoptimizing_email_brand_accent', '#c5d809' ),
		'ink'    => (string) apply_filters( 'wpoptimizing_email_brand_ink', '#001f1c' ),
	);
}

/**
 * Wrap body HTML in the shared email shell (header with brand + subtitle, the body, an optional
 * CTA button and a footer). Returns a complete HTML document. All emails use this so the design
 * is unified.
 *
 * @param array $args {
 *   @type string $subtitle   Small text after the brand in the header (e.g. "Weekly report").
 *   @type string $body       Inner HTML (rows/sections), already escaped by the caller.
 *   @type string $cta_url    Optional primary-button URL.
 *   @type string $cta_label  Optional primary-button label.
 *   @type string $footer     Footer HTML (already escaped).
 * }
 * @return string
 */
function wpoptimizing_email_html( array $args ) {
	$brand    = wpoptimizing_email_brand();
	$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
	$body     = isset( $args['body'] ) ? (string) $args['body'] : '';
	$cta_url  = isset( $args['cta_url'] ) ? (string) $args['cta_url'] : '';
	$cta_lbl  = isset( $args['cta_label'] ) ? (string) $args['cta_label'] : '';
	$footer   = isset( $args['footer'] ) ? (string) $args['footer'] : '';
	$color    = $brand['color'];
	$accent   = isset( $brand['accent'] ) ? (string) $brand['accent'] : '#c5d809';
	$ink      = isset( $brand['ink'] ) ? (string) $brand['ink'] : '#001f1c';
	// Corporate 45deg gradient as a progressive enhancement: Outlook ignores background-image
	// and falls back to the solid teal below it, so it never looks broken.
	$header_bg = $color . ';background-image:linear-gradient(135deg,' . $color . ' 0%,#023d43 55%,' . $ink . ' 100%)';
	$font      = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f0;font-family:<?php echo esc_attr( $font ); ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f0;padding:28px 0">
<tr><td align="center">
	<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border:1px solid <?php echo esc_attr( $ink ); ?>;border-radius:8px;overflow:hidden">
		<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:22px 28px">
			<?php if ( '' !== $brand['logo'] ) : ?>
				<img src="<?php echo esc_url( $brand['logo'] ); ?>" alt="<?php echo esc_attr( $brand['name'] ); ?>" height="34" style="height:34px;width:auto;vertical-align:middle;border:0;display:inline-block">
				<?php if ( '' !== $subtitle ) : ?>
					<span style="color:rgba(255,255,255,.88);font-size:13px;margin-left:10px;vertical-align:middle;font-family:<?php echo esc_attr( $font ); ?>"><?php echo esc_html( $subtitle ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#fff;font-size:20px;font-weight:800;letter-spacing:-.4px;vertical-align:middle;font-family:<?php echo esc_attr( $font ); ?>"><?php echo esc_html( $brand['name'] ); ?></span>
				<?php if ( '' !== $subtitle ) : ?>
					<span style="color:rgba(255,255,255,.88);font-size:13px;margin-left:8px;vertical-align:middle"><?php echo esc_html( $subtitle ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</td></tr>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped table rows. The three callers escape every interpolated value: wpoptimizing.php:1324, includes/maintenance-report.php:375 and includes/admin/class-network-dashboard.php:1313 (verified 2026-09-16). ?>
		<?php if ( '' !== $cta_url && '' !== $cta_lbl ) : ?>
		<tr><td style="padding:22px 28px 26px">
			<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $accent ); ?>;color:<?php echo esc_attr( $ink ); ?>;text-decoration:none;font-size:14px;font-weight:700;padding:11px 24px;border-radius:4px;font-family:<?php echo esc_attr( $font ); ?>"><?php echo esc_html( $cta_lbl ); ?></a>
		</td></tr>
		<?php endif; ?>
		<tr><td style="padding:16px 28px;border-top:1px solid #e4eae7;font-size:11px;color:#5a6b66;font-family:<?php echo esc_attr( $font ); ?>">
			<?php echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped markup from the same three callers listed on the $body line above. ?>
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}
