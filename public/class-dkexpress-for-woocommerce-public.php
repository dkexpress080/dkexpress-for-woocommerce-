<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       f43
 * @since      1.0.0
 *
 * @package    42f342f
 * @subpackage 42f342f/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/public
 * @author     Web Expert <info@webexpert.gr>
 */
class DKExpress_For_Woocommerce_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	function dkexpress_track_form() {
		// Sites running Web Expert Order Tracking get its unified form.
		if ( shortcode_exists( 'webexpert_order_track_form' ) ) {
			return do_shortcode( '[webexpert_order_track_form]' );
		}

		// Standalone: search straight on dkexpresscourier.gr.
		ob_start();
		?>
		<form class="dkexpress-track-form" method="get" target="_blank" action="<?php echo esc_url( DKEXPRESS_TRACKING_URL ); ?>">
			<label for="dkexpress-track-voucher"><?php esc_html_e( 'Voucher number', $this->plugin_name ); ?></label>
			<input type="text" id="dkexpress-track-voucher" name="voucher" required>
			<button type="submit" class="button"><?php esc_html_e( 'Track', $this->plugin_name ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}
}
