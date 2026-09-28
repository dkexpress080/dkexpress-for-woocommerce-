<?php
/**
 * PHPUnit bootstrap for DK Express Courier for WooCommerce tests.
 *
 * Loads the WordPress + WooCommerce test environment, then the plugin.
 */

// Set the WP tests path.
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find WordPress test library at: {$_tests_dir}\n";
    echo "Set the WP_TESTS_DIR environment variable to your wordpress-develop/tests/phpunit path.\n";
    exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin and WooCommerce before WP sets up.
 */
tests_add_filter( 'muplugins_loaded', function () {
    // Load WooCommerce first
    $wc_plugin = defined( 'WOOCOMMERCE_ABSPATH' )
        ? WOOCOMMERCE_ABSPATH . 'woocommerce.php'
        : dirname( __DIR__, 2 ) . '/woocommerce/woocommerce.php';
    if ( file_exists( $wc_plugin ) ) {
        require_once $wc_plugin;
    }

    // Load the plugin under test
    require_once dirname( __DIR__ ) . '/dkexpress-for-woocommerce.php';
} );

// Install WooCommerce tables after WP is set up.
tests_add_filter( 'setup_theme', function () {
    if ( class_exists( 'WC_Install' ) ) {
        WC_Install::install();
        // Reset so HPOS-detection doesn't corrupt the output buffer during AJAX tests.
        update_option( 'woocommerce_newly_installed', 'no' );
    }
} );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Helper: create a WC order for testing.
function dkexpress_test_create_order( array $args = [] ): WC_Order {
    $order = wc_create_order( array_merge( [
        'status' => 'processing',
    ], $args ) );

    $order->set_billing_email( $args['billing_email'] ?? 'test@example.com' );
    $order->set_billing_phone( $args['billing_phone'] ?? '6900000001' );
    $order->set_billing_first_name( 'Test' );
    $order->set_billing_last_name( 'Customer' );
    $order->set_billing_address_1( 'Test Street 1' );
    $order->set_billing_postcode( '10431' );
    $order->set_billing_city( 'Athens' );
    $order->set_billing_country( 'GR' );
    $order->set_payment_method( $args['payment_method'] ?? 'bacs' );
    $order->calculate_totals();
    $order->save();

    return $order;
}

// Helper: create a DK Express admin instance.
function dkexpress_test_admin_instance(): DKExpress_For_Woocommerce_Admin {
    $admin = new DKExpress_For_Woocommerce_Admin(
        'dkexpress-for-woocommerce',
        DKEXPRESS_FOR_WOOCOMMERCE_VERSION
    );
    $admin->set_plugin_base_path( dirname( __DIR__ ) . '/' );
    return $admin;
}
