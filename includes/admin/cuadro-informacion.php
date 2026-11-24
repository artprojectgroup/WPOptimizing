<?php
/**
 * Sidebar info box with APG links for WPOptimizing admin pages.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

global $wpoptimizing_info;
?>
<div class="informacion">
	<div class="fila wpopt-brand-row">
		<a class="wpopt-wordmark" href="https://wpoptimizing.com" title="WPOptimizing" target="_blank" rel="noopener"><b>WPO</b>ptimizing</a>
	</div>
	<div class="fila">
		<div class="columna">
			<p>Art Project Group:</p>
			<p>
				<a href="http://www.artprojectgroup.es" title="Art Project Group" target="_blank"><strong class="artprojectgroup">APG</strong></a>
			</p>
		</div>
	</div>

	<div class="columna">
		<p><?php esc_html_e( 'Follow us:', 'wpoptimizing' ); ?></p>
		<p>
			<a href="https://www.facebook.com/artprojectgroup" title="<?php echo esc_attr__( 'Follow us on ', 'wpoptimizing' ); ?>Facebook" target="_blank"><span class="genericon genericon-facebook-alt"></span></a>
			<a href="https://x.com/artprojectgroup" title="<?php echo esc_attr__( 'Follow us on ', 'wpoptimizing' ); ?>X" target="_blank"><span class="genericon genericon-x-alt"></span></a>
			<a href="http://es.linkedin.com/in/artprojectgroup" title="<?php echo esc_attr__( 'Follow us on ', 'wpoptimizing' ); ?>LinkedIn" target="_blank"><span class="genericon genericon-linkedin"></span></a>
		</p>
	</div>
	<div class="fila">
			<p><?php esc_html_e( 'More plugins:', 'wpoptimizing' ); ?></p>
			<p>
				<a href="http://profiles.wordpress.org/artprojectgroup/" title="<?php echo esc_attr__( 'More plugins on ', 'wpoptimizing' ); ?>WordPress" target="_blank"><span class="genericon genericon-wordpress"></span></a>
			</p>
	</div>
	<div class="columna">
		<p><?php esc_html_e( 'Contact us:', 'wpoptimizing' ); ?></p>
		<p>
			<a href="mailto:info@artprojectgroup.es" title="<?php echo esc_attr__( 'Contact us by ', 'wpoptimizing' ); ?>e-mail"><span class="genericon genericon-mail"></span></a>
		</p>
	</div>
</div>
