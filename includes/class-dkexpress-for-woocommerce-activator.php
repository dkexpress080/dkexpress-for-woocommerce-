<?php

/**
 * Fired during plugin activation
 *
 * @link       https://www.webexpert.gr/
 * @since      1.0.0
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 * @author     Web Expert <info@webexpert.gr>
 */
class DKExpress_For_Woocommerce_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-dkexpress-for-woocommerce-cron.php';
		DKExpress_For_Woocommerce_Cron::schedule();

		// Standalone installs have no Web Expert Order Tracking to register the job status taxonomy.
		if ( ! taxonomy_exists( 'we_voucher_status' ) ) {
			register_taxonomy( 'we_voucher_status', 'we_voucher_job', array( 'public' => false, 'rewrite' => false ) );
		}

		add_option( 'dkexpress_improved_ux', '1' );

		$terms = array('Created', 'Picked', 'Rejected', 'Delivered');
		foreach ($terms as $term) {
			$slugged_term="we_".sanitize_title($term);
			if (!term_exists($term, 'we_voucher_status')) {
				wp_insert_term($term, 'we_voucher_status',['slug'=>$slugged_term]);
			}
		}
	}
}