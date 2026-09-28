<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.webexpert.gr/
 * @since      1.0.0
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/includes
 * @author     Web Expert <info@webexpert.gr>
 */
class DKExpress_For_Woocommerce {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      DKExpress_For_Woocommerce_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'DKEXPRESS_FOR_WOOCOMMERCE_VERSION' ) ) {
			$this->version = DKEXPRESS_FOR_WOOCOMMERCE_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'dkexpress-for-woocommerce';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - DKExpress_For_Woocommerce_Loader. Orchestrates the hooks of the plugin.
	 * - DKExpress_For_Woocommerce_i18n. Defines internationalization functionality.
	 * - DKExpress_For_Woocommerce_Admin. Defines all hooks for the admin area.
	 * - DKExpress_For_Woocommerce_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-dkexpress-for-woocommerce-loader.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-dkexpress-for-woocommerce-i18n.php';
		if (!class_exists('Webexpert_Woocommerce_Order_Tracking')) {
			if (!class_exists('PostTypes\PostType'))
				require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/PostTypes/src/PostType.php';
			if (!class_exists('PostTypes\Taxonomy'))
				require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/PostTypes/src/Taxonomy.php';
			if (!class_exists('PostTypes\Columns'))
				require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/PostTypes/src/Columns.php';
		}
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-dkexpress-for-woocommerce-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-dkexpress-for-woocommerce-public.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-dkexpress-for-woocommerce-cron.php';

		$this->loader = new DKExpress_For_Woocommerce_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the DKExpress_For_Woocommerce_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new DKExpress_For_Woocommerce_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new DKExpress_For_Woocommerce_Admin( $this->get_plugin_name(), $this->get_version() );

		$plugin_admin->set_plugin_base_path( plugin_dir_path( dirname( __FILE__ ) ) );
		$plugin_admin->init_improved_ux();

		// Admin menu & settings
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'register_admin_menu', 60 );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'init', $plugin_admin, 'jobs_ctp', 6 ); // before init@10, where the PostTypes library registers; after Order Tracking (5)
		$this->loader->add_action( 'add_meta_boxes', $plugin_admin, 'jobs_metabox' );
		$this->loader->add_filter('bulk_actions-edit-we_voucher_job', $plugin_admin ,'register_my_bulk_actions');
        $this->loader->add_filter( 'handle_bulk_actions-edit-we_voucher_job',$plugin_admin, 'register_my_bulk_actions_handler', 10, 3 );
        $this->loader->add_action('admin_head', $plugin_admin, 'remove_date_drop');
		$this->loader->add_action("wp_ajax_dkexpress_courier_create_voucher", $plugin_admin,"dkexpress_courier_create_voucher");
		$this->loader->add_action("wp_ajax_dkexpress_print_voucher", $plugin_admin,"dkexpress_print_voucher");
        $this->loader->add_action("wp_ajax_dkexpress_cancel_voucher", $plugin_admin,"dkexpress_cancel_voucher");
		$this->loader->add_action( 'restrict_manage_posts', $plugin_admin, 'form' );
		$this->loader->add_action( 'pre_get_posts', $plugin_admin, 'filterquery' );
		$this->loader->add_action( 'woocommerce_order_status_completed',$plugin_admin, 'dkexpress_auto_issue', 10, 1 );
		$this->loader->add_shortcode( 'dkexpress_track_status', $plugin_admin, 'dkexpress_track_status' );
		$this->loader->add_shortcode( 'dkexpress_track_checkpoints', $plugin_admin, 'dkexpress_track_checkpoints' );
		$this->loader->add_filter( 'webexpert_woocommerce_order_tracking_custom_shipping_company_name',$plugin_admin, 'dkexpress_shipping_company_name', 11, 2 );
		$this->loader->add_filter( 'webexpert_woocommerce_order_tracking_custom_shipping_tracking_url',$plugin_admin, 'dkexpress_shipping_tracking_url', 11, 2 );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'dkexpress_bulk_action_notices' );
		$this->loader->add_filter( 'woocommerce_my_account_my_orders_actions', $plugin_admin, 'webexpert_add_edit_order_my_account_orders_actions', 50, 2 );
		$this->loader->add_action( 'woocommerce_after_account_orders', $plugin_admin, 'action_after_account_orders_js');
		$this->loader->add_action('plugin_action_links', $plugin_admin, 'dkexpress_action_links', 10, 2);
		$this->loader->add_action( 'manage_shop_order_posts_custom_column', $plugin_admin, 'dkexpress_delivered_list', 10,2 );
		$this->loader->add_action( 'woocommerce_shop_order_list_table_custom_column', $plugin_admin, 'dkexpress_delivered_list' , 10, 2 );
		$this->loader->add_action( DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS, $plugin_admin, 'run_hourly_event' );
		$this->loader->add_action('dkexpress_tracking_order', $plugin_admin,'process_dkexpress_voucher_order_task');
	}

	public function define_public_hooks() {
		$plugin_public = new DKExpress_For_Woocommerce_Public($this->get_plugin_name() , $this->get_version());
		$this->loader->add_shortcode('dkexpress_track_form', $plugin_public, 'dkexpress_track_form');
	}

	/**
		 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    DKExpress_For_Woocommerce_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
