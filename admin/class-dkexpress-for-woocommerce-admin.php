<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.webexpert.gr/
 * @since      1.0.0
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    DKExpress_For_Woocommerce
 * @subpackage DKExpress_For_Woocommerce/admin
 * @author     Web Expert <info@webexpert.gr>
 */
class DKExpress_For_Woocommerce_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * The base path of this plugin.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string $plugin_base_path The base path of this plugin.
	 */
	private $plugin_base_path;

	/** @var WX_Courier_Metabox_UX_1|null */
	private $wxmb;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct($plugin_name, $version) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	public function set_plugin_base_path( $path ) {
		$this->plugin_base_path = $path;
	}

	/**
	 * DK Express basic service for a destination, as DK Express defines it:
	 * 111 inside Attica (their own network), 051 instead of 111 for same-day delivery,
	 * 211 everywhere else (delivered through ACS). Attica = Greek postcodes 1xxxx,
	 * minus the ones the shop lists as outside the DK Express network.
	 */
	public static function auto_basic_service( $order, $country, $postcode ) {
		$zip    = preg_replace( '/\D/', '', (string) $postcode );
		$attica = 'GR' === strtoupper( (string) $country ) && 5 === strlen( $zip ) && '1' === $zip[0];
		if ( $attica ) {
			foreach ( preg_split( '/[\s,;]+/', (string) get_option( 'dkexpress_off_network_postcodes', '' ), -1, PREG_SPLIT_NO_EMPTY ) as $prefix ) {
				$prefix = preg_replace( '/\D/', '', $prefix );
				if ( '' !== $prefix && 0 === strpos( $zip, $prefix ) ) {
					$attica = false;
					break;
				}
			}
		}

		$service = '211';
		if ( $attica ) {
			$service  = '111';
			$same_day = (array) get_option( 'dkexpress_same_day_shipping', [] );
			if ( $same_day && $order ) {
				foreach ( $order->get_items( 'shipping' ) as $item ) {
					if ( in_array( $item->get_method_id() . ':' . $item->get_instance_id(), $same_day, true ) || in_array( $item->get_method_id(), $same_day, true ) ) {
						$service = '051';
						break;
					}
				}
			}
		}
		return (string) apply_filters( 'dkexpress_basic_service', $service, $order, $country, $postcode );
	}

	/**
	 * Public tracking page on dkexpresscourier.gr.
	 */
	public static function tracking_url( $voucher ) {
		return 'https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01=' . rawurlencode( (string) $voucher );
	}

	/**
	 * Credentials of an account from the Accounts tab, as the API Context block.
	 */
	public static function account_context( $index ) {
		$index = (int) $index;
		$u = (array) get_option( 'dkexpress_username', array() );
		$p = (array) get_option( 'dkexpress_password', array() );
		$a = (array) get_option( 'dkexpress_api', array() );
		return array(
			'UserAlias'       => $u[ $index ] ?? ( $u[0] ?? '' ),
			'CredentialValue' => $p[ $index ] ?? ( $p[0] ?? '' ),
			'ApiKey'          => $a[ $index ] ?? ( $a[0] ?? '' ),
		);
	}

	/**
	 * Credentials a job was issued with; falls back to its account, then the default account.
	 */
	public static function job_context( $job_id ) {
		$context = array(
			'UserAlias'       => (string) get_post_meta( $job_id, 'dkexpress_username', true ),
			'CredentialValue' => (string) get_post_meta( $job_id, 'dkexpress_password', true ),
			'ApiKey'          => (string) get_post_meta( $job_id, 'dkexpress_api', true ),
		);
		if ( '' !== $context['UserAlias'] && '' !== $context['CredentialValue'] && '' !== $context['ApiKey'] ) {
			return $context;
		}
		$account = get_post_meta( $job_id, 'dkexpress_account', true );
		return self::account_context( '' !== (string) $account ? (int) $account : (int) get_option( 'dkexpress_default_account', 0 ) );
	}

	/**
	 * POST to the DK Express (Qualco) API.
	 *
	 * @return array|WP_Error Decoded response on Result=Success, WP_Error carrying the API's own messages otherwise.
	 */
	public static function api( $endpoint, array $body ) {
		$debug = get_option( 'dkexpress_debug', null ) == '1';
		if ( $debug ) {
			$logged = $body;
			unset( $logged['Context']['CredentialValue'], $logged['Context']['ApiKey'] );
			wc_get_logger()->info( $endpoint . ' ' . wp_json_encode( $logged, JSON_UNESCAPED_UNICODE ), array( 'source' => 'dkexpress-for-woocommerce' ) );
		}

		$response = wp_remote_post( DKEXPRESS_API_URL . $endpoint, array(
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $debug ) {
			// Labels come back as base64 PDFs; keep them out of the log.
			$logged = is_array( $data ) ? array_diff_key( $data, array( 'Voucher' => 1 ) ) : $raw;
			wc_get_logger()->info( $endpoint . ' ' . wc_print_r( $logged, true ), array( 'source' => 'dkexpress-for-woocommerce' ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'dkexpress_http', sprintf( 'DK Express API: HTTP %d', wp_remote_retrieve_response_code( $response ) ) );
		}
		if ( ( $data['Result'] ?? '' ) !== 'Success' ) {
			$messages = array();
			foreach ( (array) ( $data['Errors'] ?? array() ) as $error ) {
				$messages[] = is_array( $error ) ? ( $error['Message'] ?? $error['Code'] ?? '' ) : (string) $error;
			}
			$messages = array_filter( $messages );
			return new WP_Error( 'dkexpress_api', $messages ? implode( ' ', $messages ) : 'DK Express API: ' . ( $data['Result'] ?? 'Failure' ), $data );
		}
		return $data;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in DKExpress_For_Woocommerce_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The DKExpress_For_Woocommerce_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		if( ! wp_style_is( 'select2', 'registered' ) ) {
			wp_register_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', null, $this->version );
		}
		wp_enqueue_style( 'select2' );
		wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/dkexpress-for-woocommerce-admin.css', array(), DKEXPRESS_FOR_WOOCOMMERCE_VERSION, 'all');

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in DKExpress_For_Woocommerce_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The DKExpress_For_Woocommerce_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script('jquery-ui-datepicker');
		if( ! wp_script_is( 'select2', 'registered' ) ) {
			wp_register_script( 'select2', WC()->plugin_url() . '/assets/js/select2/select2.full.min.js', array( 'jquery' ), $this->version );
		}
		wp_enqueue_script('select2');
		wp_register_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/dkexpress-for-woocommerce-admin.js', array('jquery','select2'), $this->version, false);
		wp_enqueue_script($this->plugin_name);
		wp_localize_script($this->plugin_name, 'dkexpress_ajax_object', array(
			'ajax_url'           => admin_url('admin-ajax.php'),
			'nonce'              => wp_create_nonce('dkexpress_admin_nonce'),
			'popup_blocked_msg'  => __('Popup blocked by your browser.', $this->plugin_name),
			'popup_blocked_link' => __('Click here to open', $this->plugin_name),
			'print_error_msg'    => __('The voucher could not be printed.', $this->plugin_name),
		));
	}

	/**
	 * Voucher jobs are the shared we_voucher_job CPT used by every Web Expert courier plugin.
	 * Whoever registers it first wins; standalone, this plugin registers it and its status taxonomy.
	 */
	/**
	 * One plugin fills the shared Jobs list columns: Web Expert Order Tracking when present,
	 * otherwise the first courier plugin to get here. Everyone else would duplicate each cell.
	 */
	private function owns_job_columns() {
		if ( class_exists( 'Webexpert_Woocommerce_Order_Tracking' ) ) {
			return false;
		}
		if ( empty( $GLOBALS['wx_voucher_job_columns_owner'] ) ) {
			$GLOBALS['wx_voucher_job_columns_owner'] = $this->plugin_name;
		}
		return $GLOBALS['wx_voucher_job_columns_owner'] === $this->plugin_name;
	}

	public function jobs_ctp() {
		if ( ! taxonomy_exists( 'we_voucher_status' ) ) {
			register_taxonomy( 'we_voucher_status', 'we_voucher_job', array(
				'label'              => __( 'Status', $this->plugin_name ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'hierarchical'       => false,
				'rewrite'            => false,
			) );
		}
		if ( post_type_exists( 'we_voucher_job' ) ) {
			return;
		}

		$labels = array(
			'name'               => _x( 'Jobs', 'post type general name', $this->plugin_name ),
			'singular_name'      => _x( 'Job', 'post type singular name', $this->plugin_name ),
			'menu_name'          => _x( 'Jobs', 'admin menu', $this->plugin_name ),
			'name_admin_bar'     => _x( 'Jobs', 'add new on admin bar', $this->plugin_name ),
			'add_new'            => _x( 'Add New', 'book', $this->plugin_name ),
			'add_new_item'       => __( 'Add New Job', $this->plugin_name ),
			'new_item'           => __( 'New Job', $this->plugin_name ),
			'edit_item'          => __( 'Edit Job', $this->plugin_name ),
			'view_item'          => __( 'View Job', $this->plugin_name ),
			'all_items'          => __( 'All Jobs', $this->plugin_name ),
			'search_items'       => __( 'Search Jobs', $this->plugin_name ),
			'parent_item_colon'  => __( 'Parent Jobs:', $this->plugin_name ),
			'not_found'          => __( 'No jobs found.', $this->plugin_name ),
			'not_found_in_trash' => __( 'No jobs found in Trash.', $this->plugin_name )
		);

		$names = [
			'name'     => 'we_voucher_job',
			'singular' => __('Job',$this->plugin_name),
			'plural'   => __('Jobs',$this->plugin_name)
		];
		$jobs = new PostTypes\PostType($names,[],$labels);

		$jobs->options(
			[
				'has_archive'  => false,
				'show_ui'      => true,
				'public'       => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'=>false,
				'supports'     => ['title'],
				'map_meta_cap' => true,
				'capabilities' => array(
					'create_posts' => false,
				)
			]
		);

		$jobs->columns()->add(
			[
				'my_title'  => __('Title',$this->plugin_name),
				'status'  => __('Job Status',$this->plugin_name),
				'carrier'  => __('Carrier',$this->plugin_name),
				'delivery'  => __('Track & Trace',$this->plugin_name),
				'print'   => __('Print',$this->plugin_name),
				'actions' => __('Actions',$this->plugin_name),
			]
		);

		$jobs->columns()->hide( 'title' );
		$jobs->columns()->hide( 'date' );

		$jobs->columns()->sortable( [
			'my_title'  => [ 'title', false ],
		] );

		// Without Web Expert Order Tracking nobody else fills these columns.
		if ( $this->owns_job_columns() ) {
			$jobs->columns()->populate( 'my_title', function ( $column, $post_id ) {
				$order_id = get_post_meta( $post_id, 'order_id', true );
				echo '<strong><a href="' . esc_url( $order_id ? get_edit_post_link( $order_id ) : '' ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></strong>';
				$account = get_post_meta( $post_id, 'dkexpress_account', true );
				if ( '' !== (string) $account ) {
					echo '<div>' . esc_html( sprintf( __( 'Account %d', $this->plugin_name ), (int) $account + 1 ) ) . '</div>';
				}
			} );
			$jobs->columns()->populate( 'status', function ( $column, $post_id ) {
				$this->get_job_status_formatted( $post_id );
			} );
			$jobs->columns()->populate( 'carrier', function ( $column, $post_id ) {
				$provider = get_post_meta( $post_id, 'we_voucher_job_provider', true );
				echo esc_html( 'dkexpress' === $provider ? 'DK Express' : ucwords( str_replace( [ '-', '_' ], ' ', (string) $provider ) ) );
			} );
			$jobs->columns()->populate( 'delivery', function ( $column, $post_id ) {
				$voucher = get_post_meta( $post_id, 'voucher_id', true );
				$order   = wc_get_order( get_post_meta( $post_id, 'order_id', true ) );
				if ( ! $voucher || ! $order || 'dkexpress' !== get_post_meta( $post_id, 'we_voucher_job_provider', true ) ) {
					echo '-';
					return;
				}
				$status = $order->get_meta( 'voucher_delivery_status' );
				echo '<a target="_blank" href="' . esc_url( self::tracking_url( $voucher ) ) . '">' . esc_html( $voucher ) . '</a>';
				echo '<div>' . esc_html( is_string( $status ) && $status ? $status : '-' ) . '</div>';
			} );
			$jobs->columns()->populate( 'print', function ( $column, $post_id ) {
				if ( 'dkexpress' !== get_post_meta( $post_id, 'we_voucher_job_provider', true ) ) {
					return;
				}
				$order_id = get_post_meta( $post_id, 'order_id', true );
				$disabled = get_post_meta( $post_id, 'voucher_id', true ) ? '' : 'disabled';
				?>
				<button data-order="<?php echo esc_attr( $order_id ); ?>" type="button" class="button dkexpress_print_voucher_type1 has-spinner" data-type="pdf" <?php echo $disabled; ?>><?php _e( 'A4', $this->plugin_name ); ?> <span class="we_spinner"></span></button>
				<button data-order="<?php echo esc_attr( $order_id ); ?>" type="button" class="button dkexpress_print_voucher_type2 has-spinner" data-type="singlepdf_100x150" <?php echo $disabled; ?>><?php _e( '100x150', $this->plugin_name ); ?> <span class="we_spinner"></span></button>
				<?php
			} );
			$jobs->columns()->populate( 'actions', function ( $column, $post_id ) {
				if ( 'dkexpress' !== get_post_meta( $post_id, 'we_voucher_job_provider', true ) ) {
					return;
				}
				$disabled = get_post_meta( $post_id, 'voucher_id', true ) ? '' : 'disabled';
				?>
				<button data-order="<?php echo esc_attr( get_post_meta( $post_id, 'order_id', true ) ); ?>" type="button" class="button dkexpress_cancel_voucher has-spinner" data-success="<?php esc_attr_e( 'Voucher has been cancelled', $this->plugin_name ); ?>" data-error="<?php esc_attr_e( 'The voucher could not be cancelled.', $this->plugin_name ); ?>" <?php echo $disabled; ?>><?php _e( 'Cancel', $this->plugin_name ); ?> <span class="we_spinner"></span></button>
				<?php
			} );
		}

		$jobs->icon('dashicons-tag');

		$jobs->register();
	}

	public function webexpert_action_row($actions, $post) {
		//check for your post type
		if ($post->post_type =="we_voucher_job"){
			return [];
		}
		return $actions;
	}

	public function get_job_status($post_id) {
		$status = get_post_meta($post_id, 'webexpert_voucher_job_status', true);
		switch ($status) {
			case'we-voucher-open':
				return __('Opened', $this->plugin_name);
				break;
			case 'we-voucher-cancelled':
				return __('Cancelled', $this->plugin_name);
				break;
			case 'we-voucher-closed':
				return __('Closed', $this->plugin_name);
				break;
			default:
				return __('Not initialized', $this->plugin_name);
				break;
		}
	}

	public function get_job_status_formatted($post_id) {
		$status = $this->get_job_status($post_id);
		$status_class = get_post_meta($post_id, 'webexpert_voucher_job_status', true);
		echo "<span class='webexpert-job-status " . strtolower($status_class) . "'>$status</span>";
	}

	/**
	 * Order weight as the metabox computes it: per item max(volumetric, actual), summed; per-item fallback.
	 */
	public function wxmb_order_weight( $order ) {
		$total_weight   = 0;
		$weight_unit    = get_option( 'woocommerce_weight_unit' );
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );
		foreach ( $order->get_items() as $item ) {
			$product = $item['variation_id'] ? wc_get_product( $item['variation_id'] ) : wc_get_product( $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$volumetric_weight = 0.0;
			if ( get_option( 'dkexpress_disable_dimensions_volumetric', '0' ) != "1" && $product->get_length() && $product->get_width() && $product->get_height() ) {
				$length            = wc_get_dimension( str_replace( ",", ".", $product->get_length() ), 'cm', $dimension_unit );
				$width             = wc_get_dimension( str_replace( ",", ".", $product->get_width() ), 'cm', $dimension_unit );
				$height            = wc_get_dimension( str_replace( ",", ".", $product->get_height() ), 'cm', $dimension_unit );
				$volumetric_weight = ( $length * $width * $height ) / 5000 * $item->get_quantity();
			}
			$weight = 0.0;
			if ( $product->get_weight() ) {
				$weight = wc_get_weight( str_replace( ",", ".", $product->get_weight() ), 'kg', $weight_unit ) * $item->get_quantity();
			}
			$total_weight += max( $volumetric_weight, $weight );
		}
		$total_weight = apply_filters( 'dkexpress_custom_order_total_weight', $total_weight, $order );
		if ( ! $total_weight ) {
			$per_item = (int) get_option( 'dkexpress_default_weight_per_item' );
			if ( $per_item ) {
				$total_weight = count( $order->get_items() ) * $per_item;
			}
		}
		return (float) $total_weight;
	}

	/**
	 * "Improved UX" order metabox (option dkexpress_improved_ux). Shared class, config-driven.
	 */
	public function init_improved_ux() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wx-courier-metabox-ux.php';
		add_action( 'init', function () {
			$this->wxmb = new WX_Courier_Metabox_UX_1( $this->wxmb_config() );
		}, 5 );
	}

	public function wxmb_config() {
		$td        = $this->plugin_name;
		$asset_url = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/wxmb/';
		$fold      = function ( $s ) { return function_exists( 'we_shiplog_fold' ) ? we_shiplog_fold( $s ) : mb_strtoupper( remove_accents( (string) $s ), 'UTF-8' ); };

		return array(
			'slug'          => 'dkexpress',
			'vendor'        => 'dkexpress',
			'carriers'      => array( 'dkexpress' ),
			'text_domain'   => $td,
			'title'         => __( 'DK Express', $td ),
			'logo'          => $asset_url . 'dkexpress-logo.png',
			'metabox_id'    => 'dkexpress-voucher-options',
			'option'        => 'dkexpress_improved_ux',
			'nonce_action'  => 'dkexpress_admin_nonce',
			'ajax'          => array( 'create' => 'dkexpress_courier_create_voucher', 'print' => 'dkexpress_print_voucher' ),
			'print_result'  => 'success_base64',
			'print_field'   => 'print_type',
			'create_fields' => array( 'parcels' => 'parcels', 'weight' => 'weight', 'cod' => 'cod', 'comments' => 'comments', 'dkexpress_account' => 'account' ),
			'fields'        => array(
				'parcels'    => array( 'meta' => 'dkexpress_parcels', 'default' => 1 ),
				'weight'     => array( 'meta' => 'dkexpress_weight', 'default_option' => 'dkexpress_default_weight', 'compute' => array( $this, 'wxmb_order_weight' ) ),
				'cod'        => array( 'meta' => 'dkexpress_cod', 'gateways' => array( 'cod' ) ),
				'comments'   => array( 'meta' => 'dkexpress_comments' ),
				'account'    => array(
					'meta'           => 'dkexpress_account',
					'default_option' => 'dkexpress_default_account',
					'labels'         => function () {
						$out = array();
						foreach ( (array) get_option( 'dkexpress_username', array() ) as $i => $opt ) {
							if ( strlen( trim( (string) $opt ) ) > 0 ) { $out[ $i ] = '(' . ( $i + 1 ) . ') - ' . $opt; }
						}
						return $out;
					},
				),
				'print_type' => array(
					'options'        => array(
						'pdf'                 => 'A4',
						'clean'               => __( 'Clean', $td ),
						'singlepdf'           => __( 'Single PDF', $td ),
						'singleclean'         => __( 'Single Clean', $td ),
						'singlepdf_100x150'   => __( 'Single 100x150', $td ),
						'singleclean_100x150' => __( 'Clean 100x150', $td ),
						'singlepdf_100x170'   => __( 'Single 100x170', $td ),
						'singleclean_100x170' => __( 'Clean 100x170', $td ),
					),
					'default_option' => 'dkexpress_default_print_size',
					'default'        => 'pdf',
				),
			),
			'job'           => array( 'voucher_meta' => 'voucher_id', 'sub_voucher_meta' => 'sub_voucher_id' ),
			'tracking'      => array(
				'read'      => function ( $order ) {
					$rows = array();
					foreach ( (array) $order->get_meta( 'voucher_delivery_process' ) as $cp ) {
						$cp = (array) $cp;
						if ( empty( $cp['Note'] ) ) { continue; }
						$rows[] = array( 'status' => $cp['Note'], 'code' => (string) ( $cp['Status'] ?? '' ), 'datetime' => $cp['ExecutedOn'] ?? '', 'location' => $cp['StationName'] ?? '' );
					}
					return $rows;
				},
				'milestone' => function ( $row ) use ( $fold ) {
					$code = (string) $row['code'];
					$u = $fold( $row['status'] );
					if ( '29' === $code ) { return 'delivered'; }
					// Code 28 is also used for the delivery-note row logged in the same second as the delivery; only real failure text counts.
					if ( '28' === $code ) { return preg_match( '/ΑΠΟΤΥΧΙΑ|ΑΡΝΗΣΗ|ΜΗ ΠΑΡΑΔΟΣΗ|ΑΝΕΠΙΤΥΧ|ΑΠΩΝ|ΔΕΝ ΒΡΕΘΗΚΕ/u', $u ) ? 'failed' : null; }
					if ( '15' === $code ) { return 'picked'; }
					if ( '24' === $code ) { return 'out'; }
					if ( preg_match( '/ΕΠΙΣΤΡΟΦΗ|ΕΠΙΣΤΡΑΦΗΚΕ/u', $u ) ) { return 'returned'; }
					if ( preg_match( '/ΑΠΟΤΥΧΙΑ|ΑΡΝΗΣΗ|ΜΗ ΠΑΡΑΔΟΣΗ|ΑΝΕΠΙΤΥΧ|ΑΠΩΝ/u', $u ) ) { return 'failed'; }
					if ( preg_match( '/ΠΑΡΑΔΟΘΗΚΕ|^ΠΑΡΑΔΟΣΗ$/u', $u ) ) { return 'delivered'; }
					if ( preg_match( '/ΠΡΟΣ ΠΑΡΑΔΟΣΗ|ΔΙΑΝΟΜ|ΧΡΕΩΣΗ ΣΕ ΔΙΑΝΟΜΕΑ|ΧΡΕΩΣΗ ΣΕ COURIER/u', $u ) ) { return 'out'; }
					if ( preg_match( '/ΠΑΡΑΛΑΒΗ/u', $u ) ) { return 'picked'; }
					if ( preg_match( '/ΑΝΑΧΩΡΗΣΗ|ΑΦΙΞΗ|ΚΕΝΤΡΟ|ΔΙΑΛΟΓΗ|ΜΕΤΑΦΟΡ/u', $u ) ) { return 'transit'; }
					return null;
				},
				'noise'     => '',
				'phrase'    => function ( $raw ) { return function_exists( 'we_shiplog_dkexpress_phrase' ) ? we_shiplog_dkexpress_phrase( $raw ) : $raw; },
				'track_url' => function ( $voucher ) { return 'https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01=' . rawurlencode( $voucher ); },
			),
			'cancel_for_order' => array( $this, 'dkexpress_cancel_voucher_for_order' ),
			'track'            => function ( $order ) { $this->dkexpress_track( $order->get_id(), null ); },
			'keep_on_cancel'   => array( 'dkexpress_parcels', 'dkexpress_cod', 'dkexpress_weight', 'dkexpress_comments', 'dkexpress_special_cases', 'dkexpress_account' ),
			'created_action'   => 'dkexpress_voucher_created',
			'status_actions'   => array( 'delivered' => 'dkexpress_voucher_order_delivered', 'rejected' => 'dkexpress_voucher_order_rejected', 'picked' => 'dkexpress_voucher_order_picked' ),
			'return'           => array(
				'label'    => __( 'Return voucher', $td ),
				'at_issue' => array( 'post_key' => 'return_awb', 'label' => __( 'Prepaid return label', $td ), 'hint' => __( 'A return AWB is issued together with the voucher', $td ) ),
				'note'     => __( 'Return labels are issued together with the outbound voucher.', $td ),
				'print'    => function ( $order, $job_id, $number ) use ( $td ) {
					$type = (string) get_option( 'dkexpress_default_print_size', 'pdf' ) ?: 'pdf';
					$r    = self::api( 'Voucher', array( 'Context' => self::job_context( $job_id ), 'ShipmentNumber' => $number, 'Template' => $type ) );
					if ( is_wp_error( $r ) ) { return $r; }
					if ( empty( $r['Voucher'] ) ) { return new WP_Error( 'qualco_print', __( 'No label returned.', $td ) ); }
					return array( 'base64' => $r['Voucher'] );
				},
			),
			'asset_url'        => $asset_url,
			'asset_ver'        => $this->version,
		);
	}

	public function jobs_metabox() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'dkexpress-voucher-options',
			__('DK Express', $this->plugin_name),
			[$this, 'voucher_metabox_content'],
			$screen, 'side', 'core'
		);
	}

	public function voucher_metabox_content($post) {
		$order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : $post;
        if ($order) {
            $job_id = !empty($order->get_meta('we_voucher_job_id')) ? $order->get_meta('we_voucher_job_id') : null;
            $vendor=get_post_meta($job_id, 'we_voucher_job_provider', true);
            $lock_me= ($vendor && $vendor!=="dkexpress") ? 'disabled' : '';
            $weight = 0;

            $total_weight = 0;
            $dimension_unit = get_option('woocommerce_dimension_unit');
            $weight_unit = get_option('woocommerce_weight_unit');

            foreach ($order->get_items() as $item) {
                $product_variation_id = $item['variation_id'];
                if ($product_variation_id) {
                    $product = wc_get_product($item['variation_id']);
                } else {
                    $product = wc_get_product($item['product_id']);
                }
                if ($product) {
                    $volumetric_weight=0.0;
                    if(get_option('dkexpress_disable_dimensions_volumetric','0')!="1" && $product->get_length() && $product->get_width() && $product->get_height()) {
                        $length = (wc_get_dimension(str_replace(",", ".", $product->get_length()), 'cm', $dimension_unit));
                        $width = (wc_get_dimension(str_replace(",", ".", $product->get_width()), 'cm', $dimension_unit));
                        $height = (wc_get_dimension(str_replace(",", ".", $product->get_height()), 'cm', $dimension_unit));
                        $volumetric_weight = ($length*$width*$height) / 5000 * $item->get_quantity();
                    }

                    $weight=0.0;
                    if($product->get_weight()) {
                        $weight = wc_get_weight( str_replace( ",", ".", $product->get_weight() ), 'kg', $weight_unit ) * $item->get_quantity();
                    }

                    $total_weight += max( $volumetric_weight, $weight );
                }
            }
            $total_weight=apply_filters('dkexpress_custom_order_total_weight',$total_weight,$order);

            if(!$total_weight) {
                $perItem = (int) get_option('dkexpress_default_weight_per_item');
                if($perItem) {
                    $total_weight = count($order->get_items()) * $perItem;
                }
            }
            ?>
            <div class="webexpert-mb-locked-wrap">
            <div class="dkexpress-info-group">
                <div class="dkexpress-info-row">
                    <span class="dkexpress-info-label"><?php _e("Voucher", $this->plugin_name); ?></span>
                    <span class="dkexpress-info-value"><?php echo($job_id && $vendor=="dkexpress" ? get_post_meta($job_id, 'voucher_id', true) : '-'); ?></span>
                </div>
                <?php
                if ($vendor=="dkexpress") {
                    $sub_vouchers = get_post_meta($job_id, 'sub_voucher_id', true);
                    if ($sub_vouchers) { ?>
                        <div class="dkexpress-info-row">
                            <span class="dkexpress-info-label"><?php _e("Sub Voucher", $this->plugin_name); ?></span>
                            <span class="dkexpress-info-value"><?php echo implode(", ", $sub_vouchers); ?></span>
                        </div>
                    <?php }
                }
                ?>
                <div class="dkexpress-info-row">
                    <span class="dkexpress-info-label"><?php _e('Status',$this->plugin_name);?></span>
                    <span class="dkexpress-info-value"><?php echo $vendor=="dkexpress" ? $this->get_job_status_formatted($job_id) : '-'; ?></span>
                </div>
                <div class="dkexpress-info-row">
                    <span class="dkexpress-info-label"><?php _e('Track & Trace',$this->plugin_name);?></span>
                    <span class="dkexpress-info-value"><?php echo($vendor=="dkexpress" && $order->get_meta('voucher_delivery_status') ? $order->get_meta('voucher_delivery_status') : '-'); ?></span>
                </div>
            </div>

            <div class="webexpert-field-group">
                <input <?php echo $lock_me;?> type="hidden" name="order_id_for_voucher" id="order_id_for_voucher" value="<?php echo esc_attr($order->get_id()); ?>">
                <div class="webexpert-field">
                    <div class="webexpert-field-label">
                        <label for="dkexpress_parcels"><?php _e('Parcels', $this->plugin_name); ?></label>
                    </div>
                    <div class="webexpert-field-input">
                        <input <?php echo $lock_me;?> id="dkexpress_parcels" value="<?php echo esc_attr($order->get_meta('dkexpress_parcels') ? $order->get_meta('dkexpress_parcels') : 1); ?>" type="number" name="dkexpress_parcels" class="regular-text">
                    </div>
                </div>

                <div class="webexpert-field">
                    <div class="webexpert-field-label">
                        <label for="dkexpress_weight"><?php _e('Weight (in kg)', $this->plugin_name); ?></label>
                    </div>
                    <div class="webexpert-field-input">
                        <?php
                        $field_weight=get_option('dkexpress_default_weight');
                        if($order->get_meta('dkexpress_weight')>0) {
                            $field_weight=$order->get_meta('dkexpress_weight');
                        }else {
                            $weight = $total_weight;
                            if ($weight>0) {
                                $field_weight = $weight;
                            }
                        }
                        ?>
                        <input <?php echo $lock_me;?> id="dkexpress_weight" value="<?php echo esc_attr($field_weight); ?>" type="text" name="dkexpress_weight" class="regular-text">
                    </div>
                </div>

                <?php if ($order->get_payment_method() == "cod") : ?>
                    <div class="webexpert-field">
                        <div class="webexpert-field-label">
                            <label for="dkexpress_cod"><?php _e('Cash on delivery price', $this->plugin_name); ?></label>
                        </div>
                        <div class="webexpert-field-input">
                            <input <?php echo $lock_me;?> id="dkexpress_cod" type="text" placeholder="0.0" value="<?php echo esc_attr($order->get_meta('dkexpress_cod') ? wc_format_decimal(floatval(str_replace(",",".",$order->get_meta('dkexpress_cod'))), 2) : $order->get_total()); ?>" name="dkexpress_cod" class="regular-text">
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="dkexpress_cod" value="0">
                <?php endif; ?>
                <div class="webexpert-field">
                    <div class="webexpert-field-label">
                        <label for="dkexpress_comments"><?php _e('Comments', $this->plugin_name); ?></label>
                    </div>
                    <div class="webexpert-field-input">
                        <textarea <?php echo $lock_me;?> name="dkexpress_comments" id="dkexpress_comments" cols="25" rows="5"><?php echo esc_textarea(!empty($order->get_meta('dkexpress_comments')) ? $order->get_meta('dkexpress_comments') : $order->get_customer_note()); ?></textarea>
                    </div>
                </div>
                <?php
		            $existingAccounts = 0;
		            foreach( (array) get_option('dkexpress_username', []) as $index => $opt) {
			            if(strlen(trim($opt)) > 0) {
				            $existingAccounts++;
			            }
		            }
		            $selected = $order->get_meta('dkexpress_account');
		            if (empty($selected) && $selected !== '0' && $selected !== 0) {
			            $selected = get_option('dkexpress_default_account', 0);
		            }
		            ?>
                <?php if ($existingAccounts > 1): ?>
                <div class="webexpert-field">
                    <div class="webexpert-field-label">
                        <label for="dkexpress_account"><?php _e("Select your account", $this->plugin_name); ?></label>
                    </div>
                    <div class="webexpert-field-input">
                        <select id="dkexpress_account" name="dkexpress_account" class="webexpert-regular-select">
				            <?php for($i=0;$i<$existingAccounts;$i++) { ?>
                                <option <?php selected($selected,$i); ?> value="<?php echo esc_attr($i); ?>"><?php echo esc_html("(". ($i+1) . ") - "  . ( (array) get_option('dkexpress_username', []) )[$i] ?? ''); ?></option>
				            <?php } ?>
                        </select>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" id="dkexpress_account" name="dkexpress_account" value="<?php echo esc_attr( $selected ); ?>">
                <?php endif; ?>
                <div class="webexpert-field dkexpress-action-field">
                    <button <?php echo $lock_me;?> data-order="<?php echo esc_attr($order->get_id()); ?>" type="button" class="button button-primary has-spinner" id="dkexpress_courier_create_voucher" data-error="<?php esc_attr_e('There was an error issuing the voucher.',$this->plugin_name);?>" data-success="<?php esc_attr_e('Vouchers have been created!',$this->plugin_name);?>" <?php echo($job_id !== null ? 'disabled' : ''); ?>><?php _e('Create voucher', $this->plugin_name); ?> <span class="we_spinner"></span></button>
                </div>
            </div>

            <div class="webexpert-field-group">
                <div class="webexpert-field">
                    <div class="webexpert-field-label">
                        <label><?php _e('Print type', $this->plugin_name); ?></label>
                    </div>
                    <div class="dkexpress-radio-group">
                        <?php
                        $print_size = get_option('dkexpress_default_print_size','pdf');
                        $print_options = array(
                            'pdf'                  => __('A4', $this->plugin_name),
                            'clean'                => __('Clean', $this->plugin_name),
                            'singlepdf'            => __('Single PDF', $this->plugin_name),
                            'singleclean'          => __('Single Clean', $this->plugin_name),
                            'singlepdf_100x150'    => __('Single 100x150', $this->plugin_name),
                            'singleclean_100x150'  => __('Clean 100x150', $this->plugin_name),
                            'singlepdf_100x170'    => __('Single 100x170', $this->plugin_name),
                            'singleclean_100x170'  => __('Clean 100x170', $this->plugin_name),
                        );
                        foreach ($print_options as $value => $label) : ?>
                            <label><input <?php echo $lock_me;?> type="radio" name="dkexpress_print_voucher_type" value="<?php echo esc_attr($value); ?>" <?php checked($print_size, $value); ?>> <?php echo $label; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="webexpert-field dkexpress-action-field">
                    <button <?php echo $lock_me;?> data-order="<?php echo esc_attr($order->get_id()); ?>" type="button" class="button has-spinner" id="dkexpress_print_voucher" <?php echo($job_id === null ? 'disabled' : ''); ?>>
                        <?php _e('Print voucher', $this->plugin_name); ?> <span class="we_spinner"></span></button>
                </div>
            </div>

            <div class="webexpert-field-group dkexpress-danger-group">
                <div class="webexpert-field dkexpress-action-field">
                    <button <?php echo $lock_me;?> data-order="<?php echo esc_attr($order->get_id()); ?>" type="button" class="button dkexpress_cancel_voucher has-spinner" data-success="<?php esc_attr_e('Voucher has been cancelled',$this->plugin_name);?>" data-error="<?php esc_attr_e('The voucher could not be cancelled.',$this->plugin_name);?>" id="dkexpress_cancel_voucher" <?php echo($job_id === null ? 'disabled' : ''); ?>>
                        <?php _e('Cancel voucher', $this->plugin_name); ?> <span class="we_spinner"></span></button>
                </div>
            </div>

            </div><!-- /.webexpert-mb-locked-wrap -->
            <?php
            }
    }

	function form($post_id) {
		global $typenow;
		global $wp_query;

		if('we_voucher_job' !== $post_id){
			return;
		}

		?>
		<?php
		if ($typenow == 'we_voucher_job' && !class_exists('DKExpress_For_Woocommerce')) {
			$from = (isset($_GET['mishaDateFrom']) && $_GET['mishaDateFrom']) ? $_GET['mishaDateFrom'] : '';
			$to = (isset($_GET['mishaDateTo']) && $_GET['mishaDateTo']) ? $_GET['mishaDateTo'] : '';
			echo '<input type="text" name="mishaDateFrom" class="regular-text" placeholder="'.__('Date From',$this->plugin_name).'" value="' . esc_attr($from) . '"  autocomplete="off"><input autocomplete="off" type="text" name="mishaDateTo" placeholder="'.__('Date To',$this->plugin_name).'" value="' . esc_attr($to) . '" />';
		}
	}

	function filterquery($admin_query) {
		global $pagenow;

		if (
			is_admin()
			&& $admin_query->is_main_query()
			// by default filter will be added to all post types, you can operate with $_GET['post_type'] to restrict it for some types
			&& in_array($pagenow, array('edit.php', 'upload.php'))
			&& (!empty($_GET['mishaDateFrom']) || !empty($_GET['mishaDateTo']))
		) {

			$admin_query->set(
				'date_query', // I love date_query appeared in WordPress 3.7!
				array(
					'after'     => sanitize_text_field($_GET['mishaDateFrom']), // any strtotime()-acceptable format!
					'before'    => sanitize_text_field($_GET['mishaDateTo']),
					'inclusive' => true, // include the selected days as well
					'column'    => 'post_date' // 'post_modified', 'post_date_gmt', 'post_modified_gmt'
				)
			);

		}

		return $admin_query;

	}

	function remove_date_drop() {
		$screen = get_current_screen();

		if ('we_voucher_job' == $screen->post_type) {
			add_filter('months_dropdown_results', '__return_empty_array');
		}
	}

	function register_my_bulk_actions($bulk_actions) {
		unset($bulk_actions['edit']);
		unset($bulk_actions['trash']);
        $bulk_actions['print_jobs_dkexpress'] = __('Print jobs (DK Express)',$this->plugin_name);
        $bulk_actions['cancel_jobs_dkexpress'] = __('Cancel jobs (DK Express)',$this->plugin_name);
		$bulk_actions['trash'] = __('Delete jobs',$this->plugin_name);
		return $bulk_actions;
	}

	function dkexpress_bulk_action_notices() {
		if ( ! empty( $_REQUEST['dkexpress_jobs_cancelled'] ) ) {
			echo '<div id="message" class="updated notice notice-success is-dismissible">
			<p>'.__('Selected jobs were cancelled',$this->plugin_name).'</p>
		</div>';
		}

		if ( ! empty( $_REQUEST['dkexpress_jobs_print'] ) ) {
			echo '<div id="message" class="updated notice notice-success is-dismissible">
			<p>'.__('No vouchers were selected for print',$this->plugin_name).'</p>
		</div>';
		}

		if ( ! empty( $_REQUEST['dkexpress_jobs_cancel_errors'] ) ) {
			$errors = (array) get_transient( 'dkexpress_bulk_cancel_errors_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'DK Express could not cancel some vouchers:', $this->plugin_name ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul></div>';
		}

		if ( ! empty( $_REQUEST['dkexpress_jobs_print_error'] ) ) {
			$message = get_transient( 'dkexpress_bulk_print_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'DK Express could not print the selected vouchers.', $this->plugin_name ) . ( $message ? ' ' . esc_html( $message ) : '' ) . '</p></div>';
		}

		if ( !empty ( $_REQUEST['webexpert_not_all_cc_accounts_same'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
		        <p> ' . __("You should select jobs that have been created by the same DK Express account", $this->plugin_name) . ' </p>
		        </div>
		    ';
		}
	}

	function register_my_bulk_actions_handler($redirect, $doaction, $post_ids) {
		$redirect = remove_query_arg( array( 'elta_jobs_cancelled','acs_jobs_cancelled','dkexpress_jobs_cancelled','dkexpress_jobs_print','dkexpress_jobs_print_error','dkexpress_jobs_cancel_errors' ), $redirect );
        $print_type = get_option('dkexpress_default_print_size','pdf');
		if ($doaction == 'print_jobs_dkexpress') {
            $vouchers_to_print = [];
			$job_accounts      = [];
			$first_job         = 0;
            foreach ($post_ids as $job_id) {
                if ( 'dkexpress' !== get_post_meta( $job_id, 'we_voucher_job_provider', true ) ) {
                    continue;
                }
                $voucher_id = get_post_meta($job_id, 'voucher_id', true);
                if ($voucher_id) {
	                $job_accounts[] = self::job_context( $job_id )['UserAlias'];
	                $vouchers_to_print[] = $voucher_id;
	                $first_job = $first_job ?: $job_id;
                }
            }

			if ( empty( $vouchers_to_print ) ) {
				return add_query_arg( 'dkexpress_jobs_print', 1, $redirect );
			}

			// One label call per account: all selected jobs must belong to the same DK Express account.
			if ( count( array_unique( $job_accounts ) ) != 1 ) {
				return add_query_arg('webexpert_not_all_cc_accounts_same', count($post_ids), $redirect);
			}

            $apiResponse = self::api( 'Voucher', [
                'Context'        => self::job_context( $first_job ),
                'ShipmentNumber' => implode( ',', $vouchers_to_print ),
                'Template'       => $print_type,
            ] );

            if ( ! is_wp_error( $apiResponse ) && ! empty( $apiResponse['Voucher'] ) ) {
                $file = wp_upload_bits(date_i18n('dmY-His').".pdf", null, base64_decode($apiResponse['Voucher']) );
                echo "<html><body>";
                echo "<a id='trigger_me' target='_blank' href='".esc_url($file['url'])."'>".esc_html($file['url'])."</a>";
                echo '<script>
(function () {
    window.open(document.getElementById("trigger_me").href, "_blank");
    history.back(1);
})();
</script>';
                echo "</body></html>";
                exit;
            }
            set_transient( 'dkexpress_bulk_print_error_' . get_current_user_id(), is_wp_error( $apiResponse ) ? $apiResponse->get_error_message() : __( 'No label returned.', $this->plugin_name ), 60 );
            $redirect = add_query_arg('dkexpress_jobs_print_error', 1, $redirect);
		}
		if ($doaction == 'cancel_jobs_dkexpress') {
			// Void at DK Express, not just locally — otherwise the courier still comes for the parcel.
			$cancelled = 0;
			$errors    = [];
			foreach ($post_ids as $job_id) {
				if ( 'dkexpress' !== get_post_meta( $job_id, 'we_voucher_job_provider', true ) || '' === (string) get_post_meta( $job_id, 'voucher_id', true ) ) {
					continue;
				}
				$result = $this->dkexpress_cancel_job( $job_id );
				if ( is_wp_error( $result ) ) {
					$errors[] = get_post_meta( $job_id, 'voucher_id', true ) . ': ' . $result->get_error_message();
				} else {
					$cancelled++;
				}
			}
			if ( $errors ) {
				set_transient( 'dkexpress_bulk_cancel_errors_' . get_current_user_id(), $errors, 60 );
				$redirect = add_query_arg( 'dkexpress_jobs_cancel_errors', count( $errors ), $redirect );
			}
			$redirect = add_query_arg('dkexpress_jobs_cancelled', $cancelled, $redirect);
		}

		return $redirect;
	}

	function dkexpress_print_voucher() {
		check_ajax_referer('dkexpress_admin_nonce', 'nonce');
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'dkexpress-for-woocommerce' ) );
			return;
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
		$job_id = $order ? $order->get_meta( 'we_voucher_job_id' ) : null;
		$voucher_id = $job_id ? get_post_meta( $job_id, 'voucher_id', true ) : '';
		if ( ! $voucher_id ) {
			wp_send_json_error( __( 'This order has no DK Express voucher.', $this->plugin_name ) );
			return;
		}

		$print_type = isset( $_POST['print_type'] ) ? sanitize_text_field( $_POST['print_type'] ) : get_option( 'dkexpress_default_print_size', 'pdf' );
		$apiResponse = self::api( 'Voucher', [
			'Context'        => self::job_context( $job_id ),
			'ShipmentNumber' => $voucher_id,
			'Template'       => $print_type,
		] );

		if ( is_wp_error( $apiResponse ) ) {
			wp_send_json_error( $apiResponse->get_error_message() );
			return;
		}
		wp_send_json_success( $apiResponse );
	}

	function dkexpress_cancel_voucher() {
		check_ajax_referer('dkexpress_admin_nonce', 'nonce');
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'dkexpress-for-woocommerce' ) );
			return;
		}
		$order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : null;

		$result = $this->dkexpress_cancel_voucher_for_order( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success();
	}

	/**
	 * Cancel the voucher on an order, at the courier.
	 *
	 * Extracted from the AJAX handler so bulk cancels and Expertito can call
	 * it — neither has a nonce or a $_POST to offer. Returns WP_Error instead
	 * of emitting JSON, so a caller can tell "cancelled" from "there was
	 * nothing to cancel" from "the courier refused".
	 *
	 * @param int|string $order_id
	 * @return true|WP_Error
	 */
	public function dkexpress_cancel_voucher_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cancel_no_order', 'Order not found.' );
		}

		return $this->dkexpress_cancel_job( (int) $order->get_meta( 'we_voucher_job_id' ), $order );
	}

	/**
	 * Void one job's shipment at DK Express and clear it locally. Order-level meta is only
	 * cleared when the order still points at this job.
	 *
	 * @return true|WP_Error
	 */
	public function dkexpress_cancel_job( $job_id, $order = null ) {
		$job_id = (int) $job_id ?: null;
		$order  = $order ?: ( $job_id ? wc_get_order( get_post_meta( $job_id, 'order_id', true ) ) : null );
		$shipment_number = $job_id ? get_post_meta( $job_id, 'voucher_id', true ) : '';
		if ( empty( $job_id ) || empty( $shipment_number ) ) {
			return true;
		}

		$apiResponse = self::api( 'Shipment/Void', [
			'Context'        => self::job_context( $job_id ),
			'ShipmentNumber' => $shipment_number,
		] );
		// Already cancelled at DK Express (e.g. from their portal): just catch up locally.
		$already = is_wp_error( $apiResponse ) && in_array( 'ShipmentAlreadyCancelled', array_column( (array) ( $apiResponse->get_error_data()['Errors'] ?? [] ), 'Code' ), true );
		if ( is_wp_error( $apiResponse ) && ! $already ) {
			return $apiResponse;
		}

		update_post_meta($job_id, 'webexpert_voucher_job_status', 'we-voucher-cancelled');
		delete_post_meta($job_id, 'voucher_id');
		delete_post_meta($job_id, 'sub_voucher_id');
		delete_post_meta($job_id, 'dkexpress_account');
		delete_post_meta($job_id, 'dkexpress_username');
		delete_post_meta($job_id, 'dkexpress_password');
		delete_post_meta($job_id, 'dkexpress_api');
		if ( ! $order || (int) $order->get_meta( 'we_voucher_job_id' ) !== (int) $job_id ) {
			return true;
		}
		$order->delete_meta_data( '_webexpert_order_tracking_carrier');
		$order->delete_meta_data( 'we_voucher_job_id');
		$order->delete_meta_data( 'dkexpress_parcels');
		$order->delete_meta_data( 'dkexpress_cod');
		$order->delete_meta_data( 'dkexpress_weight');
		$order->delete_meta_data( 'dkexpress_comments');
		$order->delete_meta_data( 'dkexpress_special_cases');
		$order->delete_meta_data( '_shipping_tracking_number');
		$order->save();
		return true;
	}

	function dkexpress_courier_create_voucher_process($args) {
		$cc_account = isset($args['cc_account']) ? (int) $args['cc_account'] : (int) get_option('dkexpress_default_account', 0);
		$order_id = isset($args['order_id']) ? sanitize_text_field($args['order_id']) : null;
		$order = wc_get_order($order_id);
		if ( ! $order ) {
			return 'Invalid order.';
		}
		// Never issue a second shipment over a live one (double click, two tabs, auto-issue racing a manual issue).
		$existing_job = (int) $order->get_meta( 'we_voucher_job_id' );
		$existing     = $existing_job ? (string) get_post_meta( $existing_job, 'voucher_id', true ) : '';
		if ( '' !== $existing ) {
			/* translators: %s: voucher number */
			return sprintf( __( 'This order already has voucher %s. Cancel it first to issue a new one.', $this->plugin_name ), $existing );
		}
		$cod = isset($args['cod']) ? sanitize_text_field($args['cod']) : 0.0;
		$comments = isset($args['comments']) ? sanitize_text_field($args['comments']) : apply_filters('dkexpress_voucher_customer_note',$order->get_customer_note());
		$comments = apply_filters('dkexpress_voucher_custom_comments',$comments,$order_id);
		$weight = apply_filters('dkexpress_for_woocommerce_custom_weight',isset($args['weight']) ? sanitize_text_field($args['weight']) : get_option('dkexpress_default_weight'));
		$allowed_services = [ 'αμ' ];
		$services = array_filter(
			array_map( 'sanitize_text_field', (array) ( $args['services'] ?? [] ) ),
			fn( $s ) => in_array( $s, $allowed_services, true )
		);
		$parcels = max( 1, (int) ( $args['parcels'] ?? 1 ) );

		$weight = (float) str_replace( ',', '.', (string) $weight );
		if ( $weight < 1 ) {
			$weight = 1;
		}
		$perItem = round( $weight / $parcels, 2 );

		$context = self::account_context( $cc_account );

		$first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$full_name  = trim( "{$first_name} {$last_name}" );
		$company    = $order->get_shipping_company() ?: $order->get_billing_company();

		$shipping_address = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
		$billing_address  = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		$has_shipping     = '' !== $shipping_address;
		$city             = $has_shipping ? $order->get_shipping_city() : $order->get_billing_city();
		$country          = ( $has_shipping ? $order->get_shipping_country() : $order->get_billing_country() ) ?: 'GR';

		$items = [];
		for ( $i = 0; $i < $parcels; $i++ ) {
			$items[] = [
				'GoodsType' => 'NoDocs',
				'Content'   => 'ΔΕΜΑΤΑ',
				'Weight'    => [
					'Unit'  => 'kg',
					'Value' => $perItem,
				],
			];
		}

		$phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$billing_cellphone = !empty($order->get_meta('billing_cellphone')) ? $order->get_meta('billing_cellphone') : $order->get_billing_phone();
		$cellphone = !empty($order->get_meta('shipping_phone')) ? $order->get_meta('shipping_phone') : $billing_cellphone;

		$data = [
			'Context'      => $context,
			'ShipmentDate' => date_i18n('Y-m-d'),
			'Description'  => 'Αποστολή Παραγγελίας ' . $order->get_order_number(),
			'Comments'     => $comments,
			'BillTo'       => 'Requestor',
			'Consignee'    => [
				'CompanyName' => $company ?: $full_name,
				'ContactName' => $full_name,
				'Address'     => $has_shipping ? $shipping_address : $billing_address,
				'City'        => $city,
				'Area'        => $city,
				'ZipCode'     => $has_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
				'Country'     => $country,
				'Phone1'      => apply_filters('webexpert_custom_telephone',$phone,$order),
				'Mobile1'     => apply_filters('webexpert_custom_telephone_1',$cellphone,$order),
				'Reference'   => (string) $order->get_order_number(),
			],
			'Reference1'   => (string) $order->get_order_number(),
			'Items'        => $items,
		];

		// Account-level codes from the Accounts tab; left out when empty so DK Express uses the account's defaults.
		$requestor_codes = (array) get_option( 'dkexpress_requestor_code', [] );
		$requestor_code  = trim( (string) ( $requestor_codes[ $cc_account ] ?? '' ) );
		if ( '' !== $requestor_code ) {
			$data['Requestor'] = [ 'Code' => $requestor_code ];
			$data['Shipper']   = [ 'Code' => $requestor_code ];
		}
		// A code typed on the account wins; otherwise pick it from the destination (the API does not).
		$basic_services = (array) get_option( 'dkexpress_basic_service', [] );
		$basic_service  = trim( (string) ( $basic_services[ $cc_account ] ?? '' ) );
		if ( '' === $basic_service ) {
			$basic_service = self::auto_basic_service( $order, $country, $data['Consignee']['ZipCode'] );
		}
		if ( '' !== $basic_service ) {
			$data['BasicService'] = $basic_service;
		}

		if ( ! empty( $args['return_awb'] ) ) {
			$data['GenerateReturnAWB'] = true;
		}

		if ( mb_strtolower( $order->get_payment_method() ) == 'cod' ) {
			$cod_amount = (float) str_replace( ',', '.', (string) $cod );
			$data['CODs'] = [
				[
					'Type'   => 'Cash',
					'Amount' => [
						'Currency' => 'EUR',
						'Value'    => round( $cod_amount > 0 ? $cod_amount : (float) $order->get_total(), 2 ),
					],
				],
			];
		}

		$apiResponse = self::api( 'Shipment', apply_filters( 'dkexpress_custom_voucher', $data, $args ) );
		if ( is_wp_error( $apiResponse ) ) {
			return $apiResponse->get_error_message();
		}
		if ( empty( $apiResponse['ShipmentNumber'] ) ) {
			return __( 'DK Express did not return a shipment number.', $this->plugin_name );
		}

		$post_id = wp_insert_post(
			[
				'post_title'  => __("Job for order",$this->plugin_name)." #{$order->get_id()}",
				'post_status' => "publish",
				'post_type'   => "we_voucher_job"
			]
		);

		if ($post_id === 0 || is_wp_error($post_id)) {
			return "Error creating Job cpt";
		}

		if (term_exists("we_created",'we_voucher_status')) {
			wp_set_post_terms($post_id,"we_created",'we_voucher_status',false);
		}

		update_post_meta($post_id, 'we_voucher_job_provider', 'dkexpress');
		update_post_meta($post_id, 'sub_voucher_id', []);
		update_post_meta($post_id, 'dkexpress_job_id',$apiResponse['ShipmentNumber']);
		update_post_meta($post_id, 'voucher_id', $apiResponse['ShipmentNumber'] );
		if ( ! empty( $apiResponse['ReturnShipmentNumber'] ) ) { update_post_meta( $post_id, 'return_voucher_id', (string) $apiResponse['ReturnShipmentNumber'] ); }
		update_post_meta($post_id, 'order_id', $order->get_id());
		update_post_meta($post_id, 'webexpert_voucher_job_status', 'we-voucher-open');
		update_post_meta($post_id, 'dkexpress_account', $cc_account);
		update_post_meta($post_id, 'dkexpress_username', $context['UserAlias']);
		update_post_meta($post_id, 'dkexpress_password', $context['CredentialValue']);
		update_post_meta($post_id, 'dkexpress_api',      $context['ApiKey']);
		$order->update_meta_data( 'dkexpress_account', $cc_account);
		$order->update_meta_data('_webexpert_order_tracking_carrier', 'dkexpress');
		$order->update_meta_data('we_voucher_job_id', $post_id);
		$order->update_meta_data( 'dkexpress_parcels', $parcels);
		$order->update_meta_data( 'dkexpress_cod', $cod);
		$order->update_meta_data( 'dkexpress_weight', $weight);
		$order->update_meta_data( 'dkexpress_comments', $comments);
		$order->update_meta_data( 'dkexpress_special_cases', $services);
		$order->update_meta_data( '_shipping_tracking_number', $apiResponse['ShipmentNumber']);
		$order->save();
		do_action('dkexpress_voucher_created',$order,$post_id);
		return 'success';
	}

	function dkexpress_courier_create_voucher() {
		check_ajax_referer('dkexpress_admin_nonce', 'nonce');
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'dkexpress-for-woocommerce' ) );
			return;
		}
		$order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : null;
		$order=wc_get_order($order_id);
		if ( ! $order ) { wp_send_json_error( 'Invalid order.' ); return; }
		$cod = isset($_POST['cod']) ? sanitize_text_field($_POST['cod']) : 0.0;
		$comments = isset($_POST['comments']) ? sanitize_text_field($_POST['comments']) : apply_filters('dkexpress_voucher_customer_note',$order->get_customer_note());;
        $cc_account = (isset($_POST['dkexpress_account']) && is_numeric($_POST['dkexpress_account']) && (int)$_POST['dkexpress_account'] >= 0)
            ? (int) sanitize_text_field($_POST['dkexpress_account'])
            : (int) get_option('dkexpress_default_account', 0);
		$weight = isset($_POST['weight']) ? sanitize_text_field($_POST['weight']) : get_option('dkexpress_default_weight');
		$allowed_services = [ 'αμ' ];
		$services = array_filter(
			array_map( 'sanitize_text_field', (array) ( $_POST['services'] ?? [] ) ),
			fn( $s ) => in_array( $s, $allowed_services, true )
		);
		$parcels = isset($_POST['parcels']) ? sanitize_text_field($_POST['parcels']) : 1;
		wp_send_json($this->dkexpress_courier_create_voucher_process(['order_id'=>$order_id, 'cc_account' => $cc_account, 'cod'=>$cod,'comments'=>$comments,'weight'=>$weight,'services'=>$services,'parcels'=>$parcels,'return_awb'=>!empty($_POST['return_awb'])]));
	}

	function dkexpress_auto_issue($order_id) {
        $order=wc_get_order($order_id);
        if ($order) {
            $job_id = $order->get_meta('we_voucher_job_id') ? $order->get_meta('we_voucher_job_id') : null;
            $voucher_no = get_post_meta($job_id, 'voucher_id', true) ? get_post_meta($job_id, 'voucher_id', true) : null;
            if (get_option('dkexpress_auto_issue_upon_complete',null)=='1' && empty($voucher_no)) {
				$disable_on_gateways=[];
				if (!empty(get_option('dkexpress_disable_on_payments'))) {
					$disable_on_gateways=get_option('dkexpress_disable_on_payments',[]);
				}

				$disable_on_shipping=[];
				if (!empty(get_option('dkexpress_disable_on_shipping'))) {
					$disable_on_shipping=get_option('dkexpress_disable_on_shipping',[]);
				}
				if (in_array($order->get_payment_method(),$disable_on_gateways)) {
					return false;
				}

				$shipping=$order->get_items( 'shipping' );
				foreach ($shipping as $s) {
					$shipping="{$s->get_method_id()}:{$s->get_instance_id()}";
				}

				if (in_array($shipping,$disable_on_shipping)) {
					return false;
				}

				if (apply_filters('dkexpress_disable_on_custom_hook',false,$order)) {
				    return false;
				}

                $total_weight = 0;
                $dimension_unit = get_option('woocommerce_dimension_unit');
                $weight_unit = get_option('woocommerce_weight_unit');
                foreach ($order->get_items() as $item) {
                    $product_variation_id = $item['variation_id'];
                    if ($product_variation_id) {
                        $product = wc_get_product($item['variation_id']);
                    } else {
                        $product = wc_get_product($item['product_id']);
                    }
                    if ($product) {
                        $volumetric_weight=0.0;
                        if (get_option('dkexpress_disable_dimensions_volumetric','')!=1) {
                            if ( $product->get_length() && $product->get_width() && $product->get_height() ) {
                                $length            = ( wc_get_dimension( str_replace( ",", ".", $product->get_length() ), 'cm', $dimension_unit ) );
                                $width             = ( wc_get_dimension( str_replace( ",", ".", $product->get_width() ), 'cm', $dimension_unit ) );
                                $height            = ( wc_get_dimension( str_replace( ",", ".", $product->get_height() ), 'cm', $dimension_unit ) );
                                $volumetric_weight = ( $length * $width * $height ) / 5000 * $item->get_quantity();
                            }
                        }

                        $weight=0.0;
                        if($product->get_weight()) {
                            $weight = wc_get_weight( str_replace( ",", ".", $product->get_weight() ), 'kg', $weight_unit ) * $item->get_quantity();
                        }

                        if ($volumetric_weight>$weight) {
                            $total_weight+=$volumetric_weight;
                        }else {
                            $total_weight+=$weight;
                        }
                    }
                }

                $total_weight=apply_filters('dkexpress_default_weight',$total_weight,$order);
                $parcels = $order->get_meta('dkexpress_parcels') ? $order->get_meta('dkexpress_parcels') : 1;
                $services = $order->get_meta('dkexpress_special_cases') ? $order->get_meta('dkexpress_special_cases') : [];
				$comments = $order->get_meta('dkexpress_comments') ? $order->get_meta('dkexpress_comments') : apply_filters('dkexpress_voucher_customer_note',$order->get_customer_note());;
				$comments = apply_filters('dkexpress_voucher_custom_comments',$comments,$order_id);
				$weight = $total_weight>0 ? $total_weight : get_option('dkexpress_default_weight');

	            $cod='0';
	            if ($order->get_payment_method() == "cod") :
                    $cod = $order->get_meta( 'dkexpress_cod') ? wc_format_decimal(floatval(str_replace(",",".",$order->get_meta('dkexpress_cod'))), 2) : $order->get_total();
                    if (!in_array('αμ', $services, true)) {
                        array_push($services, 'αμ');
                    }
                endif;

                $account_meta = $order->get_meta('dkexpress_account');
                $auto_account = ($account_meta !== '' && $account_meta !== false) ? (int)$account_meta : (int)get_option('dkexpress_default_account', 0);
                $this->dkexpress_courier_create_voucher_process(['order_id'=>$order_id,'cc_account'=>$auto_account,'cod'=>$cod,'comments'=>$comments,'weight'=>$weight,'services'=>$services,'parcels'=>$parcels]);
            }
        }
    }

	function run_hourly_event() {
		$interval = 4 * HOUR_IN_SECONDS;
		$jobs = get_posts( [
			'post_type'      => 'we_voucher_job',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'we_voucher_job_provider',
			'meta_value'     => 'dkexpress',
			'date_query'     => [ [ 'after' => '90 days ago', 'inclusive' => true ] ],
			'tax_query'      => [ [
				'taxonomy' => 'we_voucher_status',
				'field'    => 'slug',
				'terms'    => [ 'we_created', 'we_picked' ],
			] ],
		] );
		foreach ( $jobs as $job_id ) {
			$order_id = get_post_meta( $job_id, 'order_id', true );
			if ( empty( $order_id ) ) { continue; }
			$order = wc_get_order( $order_id );
			if ( ! $order ) { continue; }
			$last_check = (int) $order->get_meta( '_webexpert_last_tracking_check' );
			if ( $last_check && ( time() - $last_check ) < $interval ) { continue; }
			if ( false === as_next_scheduled_action( 'dkexpress_tracking_order', [ 'order_id' => $order_id ] ) ) {
				as_schedule_single_action( time(), 'dkexpress_tracking_order', [ 'order_id' => $order_id ] );
			}
		}
	}

	function process_dkexpress_voucher_order_task($order_id) {
		$order = wc_get_order($order_id);

		$job_id = $order->get_meta('we_voucher_job_id') ? $order->get_meta('we_voucher_job_id') : null;
		if ($job_id && false !== get_post_status($job_id)) {
			$vendor = get_post_meta($job_id, 'we_voucher_job_provider', true);
		} else {
			$vendor = !empty($order->get_meta('_webexpert_order_tracking_carrier')) ? $order->get_meta('_webexpert_order_tracking_carrier') : '';
		}

		if ($vendor == 'dkexpress') {
            if (!empty($order->get_meta('_webexpert_order_tracking_delivered'))) {
                as_unschedule_all_actions('dkexpress_tracking_order', array('order_id' => $order_id));
            }
            $this->dkexpress_track($order->get_id(), null, true);
            $order->update_meta_data( '_webexpert_last_tracking_check', time() );
            $order->save();
		}
	}

	/**
	 * Courier timestamp → 'Y-m-d H:i:s' in site time, or '' when unparseable / implausible
	 * (future or >3 years old), so a garbled value can never corrupt a returns window.
	 */
	public static function parse_courier_datetime( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		// Drop fractional seconds — '...:58.13' → '...:58'.
		$raw = preg_replace( '/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $raw );

		$tz      = wp_timezone();
		$formats = array(
			'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i',
			'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
			'!Y-m-d', '!d/m/Y', '!d-m-Y',
		);
		foreach ( $formats as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $raw, $tz );
			if ( ! $dt instanceof DateTimeImmutable ) {
				continue;
			}
			// PHP 8.2+ returns false here when the parse was clean.
			$err = DateTimeImmutable::getLastErrors();
			if ( is_array( $err ) && ( ! empty( $err['error_count'] ) || ! empty( $err['warning_count'] ) ) ) {
				continue;
			}
			$ts = $dt->getTimestamp();
			if ( $ts > time() + DAY_IN_SECONDS || $ts < time() - ( 3 * YEAR_IN_SECONDS ) ) {
				return '';
			}
			return $dt->format( 'Y-m-d H:i:s' );
		}
		return '';
	}

	function dkexpress_track($order_id,$voucher_no) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return [];
		}

		$job_id = $order->get_meta( 'we_voucher_job_id' ) ?: null;
		if ( empty( $voucher_no ) ) {
			$voucher_no = ( $job_id ? get_post_meta( $job_id, 'voucher_id', true ) : '' ) ?: apply_filters( 'dkexpress_custom_voucher_no', $order->get_meta( '_shipping_tracking_number' ), $order_id );
		}
		if ( ! $voucher_no ) {
			return [];
		}

		$context = $job_id ? self::job_context( $job_id ) : self::account_context( (int) get_option( 'dkexpress_default_account', 0 ) );
		$apiResponse = self::api( 'Tracking', [
			'Context'    => $context,
			'Identifier' => $voucher_no,
		] );
		if ( is_wp_error( $apiResponse ) ) {
			return [];
		}

		$tracking_list = (array) ( $apiResponse['TrackingList'] ?? [] );
		$statuses = array_map( function ( $row ) { return (string) ( $row['Status'] ?? '' ); }, $tracking_list );
		$delivered_at = '';
		// Code 29 is both the delivery and, days later on COD orders, the payout to the merchant: the earliest one is the delivery.
		foreach ( $tracking_list as $row ) {
			if ( '29' === (string) ( $row['Status'] ?? '' ) ) {
				$at = self::parse_courier_datetime( $row['ExecutedOn'] ?? '' );
				if ( '' !== $at && ( '' === $delivered_at || $at < $delivered_at ) ) {
					$delivered_at = $at;
				}
			}
		}

		if ( in_array( '29', $statuses, true ) ) {
			if ( ! empty( $job_id ) && term_exists( "we_delivered", 'we_voucher_status' ) ) {
				wp_set_post_terms( $job_id, "we_delivered", 'we_voucher_status', false );
			}
			do_action('dkexpress_voucher_order_delivered', $order, $voucher_no);
			$order->update_meta_data( '_webexpert_order_tracking_delivered', 1 );
			if ( ! $order->get_meta( '_webexpert_order_tracking_delivered_timestamp' ) ) { $order->update_meta_data( '_webexpert_order_tracking_delivered_timestamp', $delivered_at ?: current_time( 'mysql' ) ); }
			$order->save();
		} elseif ( in_array( '28', $statuses, true ) ) {
			if ( ! empty( $job_id ) && term_exists( "we_rejected", 'we_voucher_status' ) ) {
				wp_set_post_terms( $job_id, "we_rejected", 'we_voucher_status', false );
			}
			do_action('dkexpress_voucher_order_rejected', $order, $voucher_no);
			$order->update_meta_data( '_webexpert_order_tracking_rejected', 1 );
			$order->save();
		} elseif ( in_array( '15', $statuses, true ) ) {
			if ( ! empty( $job_id ) && term_exists( "we_picked", 'we_voucher_status' ) ) {
				wp_set_post_terms( $job_id, "we_picked", 'we_voucher_status', false );
			}
			do_action('dkexpress_voucher_order_picked', $order, $voucher_no);
			$order->update_meta_data( '_webexpert_order_tracking_picked', 1 );
			$order->save();
		} elseif ( ! empty( $job_id ) && term_exists( "we_created", 'we_voucher_status' ) ) {
			wp_set_post_terms( $job_id, "we_created", 'we_voucher_status', false );
		}

		if ( ! empty( $tracking_list ) ) {
			$order->update_meta_data( 'voucher_delivery_process', $tracking_list );
			$order->update_meta_data( 'voucher_delivery_process_time', date_i18n('Y-m-d H:i:s'));
			$order->update_meta_data( 'voucher_delivery_status', $tracking_list[0]['Note'] ?? '');
			$order->save();
		}

		return $tracking_list;
	}

	function dkexpress_track_checkpoints($atts) {
		$a = shortcode_atts( array(
			'order_id' => null,
			'voucher_no' => null,
		), $atts );

		$buffer="-";
		$track=$this->dkexpress_track($a['order_id'],$a['voucher_no']);
		if(count($track)) {
			$buffer="<ul>";
			$buffer.="<li class='MainStatusHeader'>Κατάστημα: <span class='MainStatus'> " . $track[0]['StationName'] . "</span></li>";
			foreach($track as $trackArray) {
				$statusDate = str_replace('T', ' ', $trackArray['ExecutedOn']);
				$buffer.="<li> <span class='Status'>{$trackArray['Note']}</span> <span class='StatusDate'>{$statusDate}</span> <span class='Shop'>{$trackArray['StationName']}</span> </li>";
			}

        	$buffer .= "</ul>";
        }

		return $buffer;

	}

	function dkexpress_track_status($atts) {
		$a = shortcode_atts( array(
			'order_id' => null,
			'voucher_no' => null,
		), $atts );

		$track=$this->dkexpress_track($a['order_id'],$a['voucher_no']);

		if(!empty($track)) {
			return $track[0]['Note'];
		}

		return '-';
	}

	function dkexpress_shipping_company_name($shipping_company_name,$order_id) {
		$order=wc_get_order($order_id);
		if ($order) {
			$job_id = $order->get_meta( 'we_voucher_job_id') ? $order->get_meta( 'we_voucher_job_id') : null;
			$vendor=get_post_meta($job_id, 'we_voucher_job_provider', true);
			if (apply_filters('webexpert_custom_vendor_field',$vendor,$order) == "dkexpress") {
				return apply_filters('dkexpress_order_tracking_change_title',__('DK Express',$this->plugin_name));
			}

		}
		return $shipping_company_name;
	}

	function dkexpress_shipping_tracking_url($shipping_company_name,$order_id) {
		$order=wc_get_order($order_id);
		if ($order) {
			$job_id = $order->get_meta('we_voucher_job_id') ? $order->get_meta('we_voucher_job_id') : null;
			$vendor=get_post_meta($job_id, 'we_voucher_job_provider', true);
			if (apply_filters('webexpert_custom_vendor_field',$vendor,$order) == "dkexpress") {
				return apply_filters('dkexpress_order_tracking_change_url','https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01={tracking_number}',$order);
			}

		}
		return $shipping_company_name;
	}

	function webexpert_add_edit_order_my_account_orders_actions( $actions, $order ) {
		if ( $order->has_status( 'completed' ) ) {
			$job_id = $order->get_meta( 'we_voucher_job_id') ? $order->get_meta( 'we_voucher_job_id') : null;
			$vendor=get_post_meta($job_id, 'we_voucher_job_provider', true);
			$voucher_no = get_post_meta($job_id, 'voucher_id', true) ? get_post_meta($job_id, 'voucher_id', true) : null;
			if (empty($voucher_no)) {
				$voucher_no=apply_filters('dkexpress_custom_voucher_no',$order->get_meta('_shipping_tracking_number'),$order->get_id());
			}
			if (apply_filters('webexpert_custom_vendor_field',$vendor,$order) == "dkexpress") {
				$actions['tracking'] = array(
					'url'  => apply_filters('dkexpress_order_tracking_change_url','https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01='.$voucher_no,$order),
					'name' => __('Tracking', $this->plugin_name)
				);
			}
		}
		return $actions;
	}

	function dkexpress_action_links($links, $file)
	{
		static $this_plugin;
		if (!$this_plugin) {
			$this_plugin = ( dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/' . $this->plugin_name . '.php' );
		}
		if ($file == $this_plugin) {
			$settings_link = '<a href="' . admin_url("admin.php?page=dkexpress-settings").'">'.__('Settings').'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	function action_after_account_orders_js() {
		$action_slug = 'tracking';
		?>
        <script>
            jQuery(function($){
                $('a.<?php echo $action_slug; ?>').each( function(){
                    $(this).attr('target','_blank');
                })
            });
        </script>
		<?php
	}

	function dkexpress_delivered_list( $column, $the_order ) {
		if ( ! is_a( $the_order, 'WC_Order' ) ) {
			global $post;
			$the_order = wc_get_order( $post->ID );
		}

        if ($column == 'order_number') {
            $job_id = $the_order->get_meta('we_voucher_job_id') ? $the_order->get_meta('we_voucher_job_id') : null;
            if ( $job_id && false !== get_post_status( $job_id ) ) {
                $vendor = get_post_meta( $job_id, 'we_voucher_job_provider', true );
            }else {
                $vendor = !empty($the_order->get_meta('_webexpert_order_tracking_carrier')) ? $the_order->get_meta('_webexpert_order_tracking_carrier') : '';
            }

            $is_delivered = false;
            if ($vendor == "dkexpress" ) {
                if ( !empty($job_id) && has_term( 'we_delivered', 'we_voucher_status', $job_id ) ) {
                    $is_delivered=true;
                }
                if (!empty($the_order->get_meta('voucher_delivery_status')) && stripos($the_order->get_meta('voucher_delivery_status'), "αποστολή παραδόθηκε") !== false) {
                    $is_delivered=true;
                }
                if ( !empty($the_order->get_meta('_webexpert_order_tracking_delivered')) ) {
                    $is_delivered=true;
                }
            }
            if ($is_delivered) {
                echo "<div class='webexpert-delivered-icon'>" . wc_help_tip(__('Delivered', $this->plugin_name), false) . "</div>";
            }
        }
	}

	public function register_admin_menu() {
		add_submenu_page( 'woocommerce', __( 'DK Express', $this->plugin_name ), __( 'DK Express', $this->plugin_name ), 'manage_woocommerce', 'dkexpress-settings', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'dkexpress-settings-group', 'dkexpress_username' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_password' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_api' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_requestor_code' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_basic_service' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_default_weight' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_auto_issue_upon_complete' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_default_print_size' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_improved_ux' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_default_weight_per_item' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_disable_on_payments' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_disable_on_shipping' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_same_day_shipping' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_off_network_postcodes', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'dkexpress-settings-group', 'dkexpress_debug' );
		register_setting( 'dkexpress-settings-group', 'dkexpress_disable_dimensions_volumetric' );
		register_setting( 'dkexpress-settings-group' ,'dkexpress_default_account');


	}

	public function render_settings_page() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'accounts';
		$tabs = array(
			'accounts' => __( 'Accounts', $this->plugin_name ),
			'settings' => __( 'Settings', $this->plugin_name ),
		);
		// The order exporter lives in Web Expert Order Tracking; no Tools tab without it.
		if ( class_exists( 'Webexpert_woocommerce_order_tracking' ) ) {
			$tabs['tools'] = __( 'Tools', $this->plugin_name );
		}
		if ( ! isset( $tabs[ $active_tab ] ) ) { $active_tab = 'accounts'; }
		?>
		<div class="wrap dkexpress-settings-wrap">
			<h1><?= esc_html(get_admin_page_title()); ?></h1>

			<nav class="nav-tab-wrapper dkexpress-tabs">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<a href="?page=dkexpress-settings&tab=<?php echo esc_attr( $tab ); ?>" class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post">
				<?php
				settings_fields('dkexpress-settings-group');
				do_settings_sections('dkexpress-settings-group');
				?>

				<?php // -- TAB: Accounts -- ?>
				<div class="dkexpress-tab-content" id="tab-accounts" <?php echo $active_tab !== 'accounts' ? 'style="display:none"' : ''; ?>>
					<?php
					$existingAccounts = 1;
					foreach( (array) get_option('dkexpress_username', []) as $index => $opt) {
						if(strlen(trim($opt)) > 0 && $index > 0) {
							$existingAccounts++;
						}
					}
					for($i=0;$i<$existingAccounts;$i++) { ?>
						<div class="dkexpress-account-table">
							<?php if($i>0) { ?>
								<span class="dkexpress-account-delete">&times;</span>
							<?php } ?>
							<p class="account_id" style="width: fit-content;font-size: 80%;text-align:center"><?php _e('Account ID:', $this->plugin_name);?> <?php echo $i+1;?></p>
							<table class="form-table">
								<tr valign="top">
									<th scope="row"><label for="dkexpress_username"><?php _e('Username', $this->plugin_name);?></label></th>
									<td><input type="text" id="dkexpress_username" name="dkexpress_username[]" class="regular-text" value="<?php echo esc_attr( ( (array) get_option('dkexpress_username', []) )[$i] ?? '' ); ?>" /></td>
								</tr>
								<tr valign="top">
									<th scope="row"><label for="dkexpress_password"><?php _e('Password', $this->plugin_name);?></label></th>
									<td><input type="text" id="dkexpress_password" name="dkexpress_password[]" class="regular-text" value="<?php echo esc_attr( ( (array) get_option('dkexpress_password', []) )[$i] ?? ''); ?>" /></td>
								</tr>
								<tr valign="top">
									<th scope="row"><label for="dkexpress_api"><?php _e('API', $this->plugin_name);?></label></th>
									<td><input type="text" id="dkexpress_api" name="dkexpress_api[]" class="regular-text" value="<?php echo esc_attr( ( (array) get_option('dkexpress_api', []) )[$i] ?? '' ); ?>" /></td>
								</tr>
								<tr valign="top">
									<th scope="row"><label for="dkexpress_requestor_code"><?php _e('Customer code', $this->plugin_name);?></label></th>
									<td><input type="text" id="dkexpress_requestor_code" name="dkexpress_requestor_code[]" class="regular-text" placeholder="100-0000-0000" value="<?php echo esc_attr( ( (array) get_option('dkexpress_requestor_code', []) )[$i] ?? '' ); ?>" /></td>
								</tr>
								<tr valign="top">
									<th scope="row"><label for="dkexpress_basic_service"><?php _e('Basic service code', $this->plugin_name);?></label></th>
									<td><input type="text" id="dkexpress_basic_service" name="dkexpress_basic_service[]" class="regular-text" value="<?php echo esc_attr( ( (array) get_option('dkexpress_basic_service', []) )[$i] ?? '' ); ?>" /></td>
								</tr>
							</table>
						</div>
					<?php } ?>
					<div>
						<a href="#" class="button button-primary webexpert-add-extra-dkexpress-acc"><?php _e("Add extra account" , $this->plugin_name) ?></a>
					</div>
					<?php submit_button(); ?>
				</div>

				<?php // -- TAB: Settings -- ?>
				<div class="dkexpress-tab-content" id="tab-settings" <?php echo $active_tab !== 'settings' ? 'style="display:none"' : ''; ?>>

					<div class="dkexpress-section">
						<h2 class="dkexpress-section-title"><?php _e('Voucher', $this->plugin_name); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row"><?php _e('Auto issue', $this->plugin_name);?></th>
								<td><label for="dkexpress_auto_issue_upon_complete">
										<input name="dkexpress_auto_issue_upon_complete" type="checkbox" id="dkexpress_auto_issue_upon_complete" value="1" <?php checked( get_option('dkexpress_auto_issue_upon_complete'), 1 ); ?>>
										<?php _e('Auto issue voucher upon order completion', $this->plugin_name);?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Improved UX', $this->plugin_name ); ?></th>
								<td><label for="dkexpress_improved_ux">
										<input name="dkexpress_improved_ux" type="checkbox" id="dkexpress_improved_ux" value="1" <?php checked( get_option( 'dkexpress_improved_ux', '1' ), '1' ); ?>>
										<?php _e( 'Use the new voucher box on the order page', $this->plugin_name ); ?></label></td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="dkexpress_default_account"><?php _e("Default DK Express account", $this->plugin_name) ?></label>
								</th>
								<td>
									<select name="dkexpress_default_account" class="webexpert-regular-select" id="dkexpress_default_account">
										<?php
										$existingAccounts = 0;
										foreach( (array) get_option('dkexpress_username', []) as $index => $opt) {
											if(strlen(trim($opt)) > 0 && $index > 0) {
												$existingAccounts++;
											}
										}
										for ($i=0;$i<($existingAccounts + 1);$i++) { ?>
											<option <?php selected(get_option('dkexpress_default_account',0),$i);?> value="<?php echo esc_attr($i); ?>"><?php echo "(" . ($i + 1) . ") - " . esc_html( ( (array) get_option('dkexpress_username', []) )[$i] ?? '' ); ?></option>
										<?php } ?>
									</select>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_default_print_size"><?php _e('Default print size', $this->plugin_name);?></label></th>
								<td>
									<?php $cc_print_size = get_option('dkexpress_default_print_size','singlepdf'); ?>
									<select id="dkexpress_default_print_size" name="dkexpress_default_print_size" class="webexpert-regular-select">
										<option <?php selected($cc_print_size, 'pdf'); ?> value="pdf"><?php _e('A4', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'clean'); ?> value="clean"><?php _e('Clean', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singlepdf'); ?> value="singlepdf"><?php _e('Single PDF', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singleclean'); ?> value="singleclean"><?php _e('Single Clean', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singlepdf_100x150'); ?> value="singlepdf_100x150"><?php _e('Single 100x150', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singleclean_100x150'); ?> value="singleclean_100x150"><?php _e('Clean 100x150', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singlepdf_100x170'); ?> value="singlepdf_100x170"><?php _e('Single 100x170', $this->plugin_name); ?></option>
										<option <?php selected($cc_print_size, 'singleclean_100x170'); ?> value="singleclean_100x170"><?php _e('Clean 100x170', $this->plugin_name); ?></option>
									</select>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_default_weight"><?php _e('Default weight (kg)', $this->plugin_name);?></label></th>
								<td><input type="text" placeholder="0.5" id="dkexpress_default_weight" name="dkexpress_default_weight" class="regular-text" value="<?php echo esc_attr( get_option('dkexpress_default_weight') ); ?>" /></td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_default_weight_per_item"><?php _e('Default weight per item (kg)', $this->plugin_name);?></label></th>
								<td><input type="text" placeholder="2" id="dkexpress_default_weight_per_item" name="dkexpress_default_weight_per_item" class="regular-text" value="<?php echo esc_attr( get_option('dkexpress_default_weight_per_item') ); ?>" /></td>
							</tr>
						</table>
					</div>

					<div class="dkexpress-section">
						<h2 class="dkexpress-section-title"><?php _e('Shipping', $this->plugin_name); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row"><?php _e('Disable volumetric', $this->plugin_name);?></th>
								<td><label for="dkexpress_disable_dimensions_volumetric">
										<input name="dkexpress_disable_dimensions_volumetric" type="checkbox" id="dkexpress_disable_dimensions_volumetric" value="1" <?php checked( get_option('dkexpress_disable_dimensions_volumetric',0), 1 ); ?>>
										<?php _e('Disable volumetric weight calculation', $this->plugin_name);?></label></td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_disable_on_payments"><?php _e('Disable on specific Payment Gateways', $this->plugin_name);?></label></th>
								<td>
									<select multiple id="dkexpress_disable_on_payments" name="dkexpress_disable_on_payments[]">
										<?php
										$dkexpress_disable_on_payments=[];
										if (!empty(get_option('dkexpress_disable_on_payments'))) {
											$dkexpress_disable_on_payments=get_option('dkexpress_disable_on_payments',[]);
										}
										foreach ( WC()->payment_gateways->payment_gateways() as $method ) { ?>
											<option value="<?php echo esc_attr($method->id);?>" <?php selected( in_array($method->id,$dkexpress_disable_on_payments) ); ?>><?php echo esc_html($method->get_method_title());?></option>
										<?php } ?>
									</select>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_disable_on_shipping"><?php _e('Disable on specific Shipping Methods', $this->plugin_name);?></label></th>
								<td>
									<?php
									$methods=[];
									$zone = new \WC_Shipping_Zone( 0 );
									foreach ( $zone->get_shipping_methods() as $shipping_method ) {
										$id=$shipping_method->id;
										$id.=!empty($shipping_method->get_instance_id()) ? ':'.$shipping_method->get_instance_id() : '';
										$methods[$id]=$shipping_method->get_method_title();
									}
									$zones = WC_Shipping_Zones::get_zones();
									foreach ( $zones as $zone ) {
										$zone = new \WC_Shipping_Zone( $zone['id'] );
										foreach ( $zone->get_shipping_methods() as $shipping_method ) {
											$id=$shipping_method->id;
											$id.=!empty($shipping_method->get_instance_id()) ? ':'.$shipping_method->get_instance_id() : '';
											$methods[$id]=$shipping_method->get_title();
										}
									}
									$dkexpress_disable_on_shipping=[];
									if (!empty(get_option('dkexpress_disable_on_shipping'))) {
										$dkexpress_disable_on_shipping=get_option('dkexpress_disable_on_shipping',[]);
									}
									?>
									<select id="dkexpress_disable_on_shipping" name="dkexpress_disable_on_shipping[]" multiple>
										<?php foreach ( $methods as $k=>$method ) { ?>
											<option value="<?php echo esc_attr($k);?>" <?php selected( in_array($k,$dkexpress_disable_on_shipping) ); ?>><?php echo esc_html($method);?></option>
										<?php } ?>
									</select>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_same_day_shipping"><?php _e('Same-day delivery shipping methods (051)', $this->plugin_name);?></label></th>
								<td>
									<?php $dkexpress_same_day_shipping = (array) get_option( 'dkexpress_same_day_shipping', [] ); ?>
									<select id="dkexpress_same_day_shipping" name="dkexpress_same_day_shipping[]" multiple>
										<?php foreach ( $methods as $k => $method ) { ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( in_array( $k, $dkexpress_same_day_shipping, true ) ); ?>><?php echo esc_html( $method ); ?></option>
										<?php } ?>
									</select>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="dkexpress_off_network_postcodes"><?php _e('Attica postcodes outside the DK Express network', $this->plugin_name);?></label></th>
								<td><input type="text" id="dkexpress_off_network_postcodes" name="dkexpress_off_network_postcodes" class="regular-text" placeholder="18010, 18020" value="<?php echo esc_attr( get_option( 'dkexpress_off_network_postcodes', '' ) ); ?>" /></td>
							</tr>
						</table>
					</div>

					<div class="dkexpress-section">
						<h2 class="dkexpress-section-title"><?php _e('Advanced', $this->plugin_name); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row"><?php _e('Debug', $this->plugin_name);?></th>
								<td><label for="dkexpress_debug">
										<input name="dkexpress_debug" type="checkbox" id="dkexpress_debug" value="1" <?php checked( get_option('dkexpress_debug',0), 1 ); ?>>
										<?php _e('Debug mode logs every request made', $this->plugin_name);?></label></td>
							</tr>
						</table>
					</div>

					<?php submit_button(); ?>
				</div>

				</form>

			<?php // -- TAB: Tools -- ?>
			<div class="dkexpress-tab-content" id="tab-tools" <?php echo $active_tab !== 'tools' ? 'style="display:none"' : ''; ?>>

				<div class="dkexpress-section">
					<h2 class="dkexpress-section-title"><?php _e( 'Export Orders', $this->plugin_name ); ?></h2>
					<form>
					<table class="form-table">
						<tr valign="top">
						<th><?php _e( 'Export', $this->plugin_name ); ?></th>
						<td>
					<?php if ( class_exists( 'Webexpert_woocommerce_order_tracking' ) ) { ?>
						<p><a class="button button-primary has-spinner" href="<?php echo esc_url( admin_url( 'admin.php?page=webexpert-woocommerce-order-tracking&tab=export' ) ); ?>"><?php _e( 'Export Orders', $this->plugin_name ); ?></a></p>
					<?php } else {
						echo '<p>' . __( 'Please install Web Expert WooCommerce Order Tracking in order to enable exporter.', $this->plugin_name ) . '</p>';
					} ?>
						</td>
						</tr>
					</table>
					</form>
				</div>

			</div>

		</div>
		<?php
	}


}