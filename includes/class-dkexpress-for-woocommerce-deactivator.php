<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://www.webexpert.gr/
 * @since      1.0.0
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 * @author     Web Expert <info@webexpert.gr>
 */
class DKExpress_For_Woocommerce_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-dkexpress-for-woocommerce-cron.php';
		DKExpress_For_Woocommerce_Cron::unschedule();
	}

}
