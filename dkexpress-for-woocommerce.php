<?php
/**
 * @package           DKExpress_For_Woocommerce
 *
 * @wordpress-plugin
 * Plugin Name:       DK Express for WooCommerce
 * Plugin URI:        https://www.dkexpresscourier.gr/
 * Description:       Issue, print, cancel and track DK Express vouchers from the WooCommerce order screen.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            DK Express
 * Author URI:        https://www.dkexpresscourier.gr/
 * License:           GPLv2 or later
 * Text Domain:       dkexpress-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to: 10.5.3
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DKEXPRESS_FOR_WOOCOMMERCE_VERSION', '1.0.2' );
define( 'DKEXPRESS_API_URL', 'https://dk-prod.qualco.eu/dkservice/api/' );

/**
 * Updates from DK Express's GitHub: the latest release (tag vX.Y.Z), preferring an attached
 * dkexpress-for-woocommerce.zip asset and falling back to GitHub's source archive.
 */
require plugin_dir_path( __FILE__ ) . 'includes/update/plugin-update-checker.php';
$dkexpress_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/dkexpress080/dkexpress-for-woocommerce-/',
	__FILE__,
	'dkexpress-for-woocommerce'
);
$dkexpress_update_checker->setBranch( 'main' );
$dkexpress_update_checker->getVcsApi()->enableReleaseAssets( '/dkexpress-for-woocommerce\.zip$/' );

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * WooCommerce Order Fulfillments integration.
 */
add_action( 'woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment' )
		&& 'yes' === get_option( 'woocommerce_feature_fulfillments_enabled' ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dkexpress-fulfillments.php';
		DKExpress_Fulfillments::instance();
	}
} );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-dkexpress-for-woocommerce-activator.php
 */
function activate_dkexpress_for_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-dkexpress-for-woocommerce-activator.php';
	DKExpress_For_Woocommerce_Activator::activate();
}
register_activation_hook( __FILE__, 'activate_dkexpress_for_woocommerce' );

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-dkexpress-for-woocommerce-deactivator.php
 */
function deactivate_dkexpress_for_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-dkexpress-for-woocommerce-deactivator.php';
	DKExpress_For_Woocommerce_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_dkexpress_for_woocommerce' );

/**
 * Re-run activation/deactivation on plugin update.
 */
add_action( 'upgrader_process_complete', 'update_dkexpress_for_woocommerce_check', 10, 2 );
function update_dkexpress_for_woocommerce_check($upgrader_object, $options) {
    $current_plugin_path_name = plugin_basename(__FILE__);
    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        foreach ($options['plugins'] as $each_plugin) {
            if ($each_plugin == $current_plugin_path_name) {
                require_once plugin_dir_path(__FILE__) . 'includes/class-dkexpress-for-woocommerce-activator.php';
                require_once plugin_dir_path(__FILE__) . 'includes/class-dkexpress-for-woocommerce-deactivator.php';
                DKExpress_For_Woocommerce_Deactivator::deactivate();
                DKExpress_For_Woocommerce_Activator::activate();
                update_option('dkexpress_for_woocommerce_version', DKEXPRESS_FOR_WOOCOMMERCE_VERSION);
            }
        }
    }
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-dkexpress-for-woocommerce.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_dkexpress_for_woocommerce() {
	$plugin = new DKExpress_For_Woocommerce();
	$plugin->run();
}
run_dkexpress_for_woocommerce();
