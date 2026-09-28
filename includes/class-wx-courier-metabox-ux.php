<?php
/**
 * WebExpert courier plugins — "Improved UX" order metabox.
 *
 * Shared, identical file across all courier voucher plugins. The class name
 * carries a version suffix so that two plugins shipping different revisions
 * never collide: each plugin instantiates the class its own copy defines.
 * Bump the suffix (and the asset handle below) whenever this file, the CSS
 * or the JS changes.
 *
 * The class is config-driven. It replaces the render callback and the title of
 * the plugin's existing order metabox when the plugin's "Improved UX" option
 * is on, and leaves everything else (AJAX create/print/cancel handlers,
 * tracking crons, fulfillments) untouched. It calls the plugin's existing AJAX
 * actions from its own JS, and adds three small endpoints of its own:
 * refresh, cancel (wraps the plugin's cancel-for-order and keeps the typed
 * details) and reset (local clear only).
 *
 * @package WebExpert\Courier
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WX_Courier_Metabox_UX_1' ) ) :

class WX_Courier_Metabox_UX_1 {

	const VERSION = '1';
	const HANDLE  = 'wx-courier-metabox-ux-1';

	/** @var array */
	private $c;

	/** @var string text domain */
	private $td;

	/** @var bool */
	private $enabled = false;

	/** @var array vendor slug => human label, for the "handled by" line */
	private static $vendor_labels = array(
		'acs'                => 'ACS Courier',
		'elta-courier'       => 'Elta Courier',
		'elta'               => 'Elta Courier',
		'speedex'            => 'Speedex',
		'geniki-taxydromiki' => 'Geniki Taxydromiki',
		'geniki_taxydromiki' => 'Geniki Taxydromiki',
		'courier-center'     => 'Courier Center',
		'courier_center'     => 'Courier Center',
		'taxydema'           => 'Taxydema',
		'dkexpress'          => 'DK Express',
		'easymail'           => 'EasyMail',
		'boxnow'             => 'Box Now',
	);

	/**
	 * @param array $config See README block at the bottom of this file.
	 */
	public function __construct( array $config ) {
		$this->c  = $this->defaults( $config );
		$this->td = $this->c['text_domain'];

		// Data hygiene runs regardless of the UI option: every path that stores a fresh track
		// (cron task, front-end shortcode, manual refresh) ends in $order->save().
		if ( 'full' === $this->c['mode'] ) {
			add_action( 'woocommerce_after_order_object_save', array( $this, 'maybe_reconcile_on_save' ), 20, 1 );
		}

		$on = ( '1' === (string) get_option( $this->c['option'], '0' ) );
		// Preview for one person: user meta _wxmb_preview = 1 turns the new box on
		// for that user only while the site-wide option stays off.
		if ( ! $on && is_user_logged_in() && '1' === (string) get_user_meta( get_current_user_id(), '_wxmb_preview', true ) ) {
			$on = true;
		}
		/**
		 * Final say on whether the Improved UX box renders for this request.
		 *
		 * @param bool   $on   Enabled.
		 * @param string $slug Courier slug.
		 */
		if ( ! apply_filters( 'wxmb_enabled', $on, $this->c['slug'] ) ) {
			return;
		}
		$this->enabled = true;

		add_action( 'add_meta_boxes', array( $this, 'rewire_metabox' ), 99, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		if ( 'full' === $this->c['mode'] ) {
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_refresh', array( $this, 'ajax_refresh' ) );
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_cancel', array( $this, 'ajax_cancel' ) );
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_reset', array( $this, 'ajax_reset' ) );
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_track', array( $this, 'ajax_track' ) );
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_return_create', array( $this, 'ajax_return_create' ) );
			add_action( 'wp_ajax_wxmb_' . $this->c['slug'] . '_return_print', array( $this, 'ajax_return_print' ) );
			if ( ! empty( $this->c['created_action'] ) ) {
				add_action( $this->c['created_action'], array( $this, 'on_created' ), 10, 2 );
			}
		}
	}

	public function is_enabled() {
		return $this->enabled;
	}

	/* ------------------------------------------------------------------ */
	/* Config                                                               */
	/* ------------------------------------------------------------------ */

	private function defaults( array $c ) {
		$c += array(
			'mode'             => 'full', // 'full' | 'header' (Box Now: only title/chip/quiet box)
			'slug'             => 'courier',
			'vendor'           => '',
			'carriers'         => array(),
			'text_domain'      => 'default',
			'title'            => 'Courier',
			'logo'             => '',
			'metabox_id'       => '',
			'option'           => '',
			'licensed'         => '__return_true',
			'license_url'      => '',
			'nonce_action'     => '',
			'ajax'             => array(),
			'print_result'     => 'success_url',
			'create_fields'    => array(),
			'print_field'      => 'print_type',
			'fields'           => array(),
			'job'              => array(),
			'tracking'         => array(),
			'cancel_for_order' => null,
			'track'            => null, // callable($order): fresh courier track, writes the plugin's tracking meta
			'return'           => array(), // meta, at_issue{post_key,label,hint}, create(callable), print(callable), note
			'status_actions'   => array(), // ['delivered'=>action, 'rejected'=>action, 'picked'=>action] fired ($order, $voucher) when reconcile moves the term
			'keep_on_cancel'   => array(),
			'created_action'   => '',
			'original_callback'=> null,
			'status_for_header'=> null, // header mode: callable($order) => array(label,class)
		);
		$c['job'] += array(
			'voucher_meta'     => 'voucher_id',
			'sub_voucher_meta' => 'sub_voucher_id',
			'status_meta'      => 'webexpert_voucher_job_status',
		);
		$c['return'] += array(
			'meta'     => 'return_voucher_id',
			'at_issue' => null,
			'create'   => null,
			'print'    => null,
			'label'    => '',
			'note'     => '',
		);
		$c['tracking'] += array(
			'read'      => null,
			'milestone' => null,
			'noise'     => '',
			'phrase'    => null,
			'track_url' => null,
		);
		return $c;
	}

	private function licensed() {
		return (bool) call_user_func( $this->c['licensed'] );
	}

	private function order_from( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		if ( is_numeric( $post_or_order ) ) {
			return wc_get_order( (int) $post_or_order );
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Metabox rewiring                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace title (logo + status chip) and, in full mode, the render
	 * callback of the plugin's metabox. Runs after the plugin registered it.
	 */
	public function rewire_metabox( $post_type_or_screen, $post_or_order = null ) {
		global $wp_meta_boxes;

		$order = $this->order_from( $post_or_order );
		if ( ! $order && isset( $GLOBALS['post'] ) ) {
			$order = $this->order_from( $GLOBALS['post'] );
		}

		$id = $this->c['metabox_id'];
		if ( ! $id || empty( $wp_meta_boxes ) ) {
			return;
		}

		foreach ( $wp_meta_boxes as $screen => $contexts ) {
			foreach ( $contexts as $context => $priorities ) {
				foreach ( $priorities as $priority => $boxes ) {
					if ( empty( $boxes[ $id ] ) || ! is_array( $boxes[ $id ] ) ) {
						continue;
					}
					$box = $boxes[ $id ];
					if ( null === $this->c['original_callback'] ) {
						$this->c['original_callback'] = $box['callback'];
					}
					if ( 'full' === $this->c['mode'] ) {
						$box['callback'] = array( $this, 'render_metabox' );
					} else {
						$box['callback'] = array( $this, 'render_header_mode' );
					}
					$wp_meta_boxes[ $screen ][ $context ][ $priority ][ $id ] = $box;
				}
			}
		}
	}

	/**
	 * Hidden header fragment; the JS moves it into the postbox title (keeps the
	 * Screen Options label clean, which reuses the registered title).
	 */
	private function head_html( $order ) {
		$logo = '';
		if ( $this->c['logo'] ) {
			$logo = '<img class="wxmb-logo" src="' . esc_url( $this->c['logo'] ) . '" alt="" />';
		}
		$chip      = $order ? $this->chip_for( $order ) : array( '', '' );
		$chip_html = '<span class="wxmb-chip ' . esc_attr( $chip[1] ) . '" data-wxmb-chip="' . esc_attr( $this->c['slug'] ) . '">' . esc_html( $chip[0] ) . '</span>';
		return '<span class="wxmb-head" hidden><span class="wxmb-title' . ( $logo ? ' has-logo' : '' ) . '">' . $logo . '<span class="wxmb-title-text">' . esc_html( $this->c['title'] ) . '</span></span>' . $chip_html . '</span>';
	}

	/**
	 * @return array [label, class]
	 */
	private function chip_for( $order ) {
		$st = $this->state( $order );
		if ( 'header' === $this->c['mode'] && is_callable( $this->c['status_for_header'] ) ) {
			if ( 'quiet' === $st['state'] ) {
				return array( '', 'is-hidden' );
			}
			$r = call_user_func( $this->c['status_for_header'], $order );
			return is_array( $r ) ? $r : array( '', '' );
		}
		switch ( $st['state'] ) {
			case 'quiet':
				return array( '', 'is-hidden' );
			case 'issued':
				$m = $st['milestone'];
				if ( 'delivered' === $m ) {
					return array( __( 'Delivered', $this->td ), 'is-done' );
				}
				if ( 'failed' === $m ) {
					return array( __( 'Not delivered', $this->td ), 'is-bad' );
				}
				if ( 'returned' === $m ) {
					return array( __( 'Returned', $this->td ), 'is-bad' );
				}
				if ( 'out' === $m ) {
					return array( __( 'Out for delivery', $this->td ), 'is-live' );
				}
				if ( 'transit' === $m || 'picked' === $m ) {
					return array( __( 'In transit', $this->td ), 'is-live' );
				}
				return array( __( 'Issued', $this->td ), 'is-live' );
			default:
				return array( __( 'Not issued', $this->td ), '' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* State                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Compute everything the templates need for an order.
	 */
	private function state( $order ) {
		$job_id = $order->get_meta( 'we_voucher_job_id' ) ?: 0;
		$vendor = $job_id ? get_post_meta( $job_id, 'we_voucher_job_provider', true ) : '';
		if ( '' === $vendor ) {
			$carrier = $order->get_meta( '_webexpert_order_tracking_carrier' );
			if ( $carrier && ! in_array( $carrier, (array) $this->c['carriers'], true ) && $carrier !== $this->c['vendor'] ) {
				$vendor = $carrier;
			}
		}
		$mine = ( $vendor === $this->c['vendor'] );

		$st = array(
			'state'     => 'draft',
			'job_id'    => $job_id,
			'vendor'    => $vendor,
			'owner'     => $vendor && ! $mine ? $this->vendor_label( $vendor ) : '',
			'voucher'   => '',
			'subs'      => array(),
			'issued_at' => '',
			'milestone' => '',
			'rail'      => array(),
			'events'    => array(),
			'latest'    => null,
			'checked'   => (int) $order->get_meta( '_webexpert_last_tracking_check' ),
			'cancelled' => $order->get_meta( '_wxmb_' . $this->c['slug'] . '_last_cancelled' ),
		);

		if ( $vendor && ! $mine ) {
			$st['state'] = 'quiet';
			return $st;
		}

		if ( $job_id && $mine ) {
			$voucher = get_post_meta( $job_id, $this->c['job']['voucher_meta'], true );
			if ( $voucher ) {
				$st['state']   = 'issued';
				$st['voucher'] = (string) $voucher;
				$subs          = get_post_meta( $job_id, $this->c['job']['sub_voucher_meta'], true );
				$st['subs']    = is_array( $subs ) ? array_values( array_filter( array_map( 'strval', $subs ) ) ) : array();
				$post          = get_post( $job_id );
				$st['issued_at'] = $post ? $post->post_date : '';
				$this->fill_tracking( $order, $st );
			}
		}
		return $st;
	}

	private function vendor_label( $vendor ) {
		$v = (string) $vendor;
		if ( isset( self::$vendor_labels[ $v ] ) ) {
			return self::$vendor_labels[ $v ];
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $v ) );
	}

	/**
	 * Normalise checkpoints, build the rail.
	 */
	private function fill_tracking( $order, array &$st ) {
		$rows = array();
		if ( is_callable( $this->c['tracking']['read'] ) ) {
			$rows = (array) call_user_func( $this->c['tracking']['read'], $order );
		}
		$rows = $this->sanitise_rows( $rows );

		// Order oldest → newest when timestamps are usable.
		$all_dated = ! empty( $rows );
		foreach ( $rows as $r ) {
			if ( '' === $r['datetime'] ) {
				$all_dated = false;
				break;
			}
		}
		if ( $all_dated ) {
			$idx = 0;
			foreach ( $rows as &$r ) {
				$r['_i'] = $idx++;
			}
			unset( $r );
			usort( $rows, function ( $a, $b ) {
				$c = strcmp( $a['datetime'], $b['datetime'] );
				return 0 !== $c ? $c : $a['_i'] - $b['_i'];
			} );
		}

		$noise    = $this->c['tracking']['noise'];
		$ms_fn    = $this->c['tracking']['milestone'];
		$order_ms = array( 'picked' => 1, 'transit' => 2, 'out' => 3, 'delivered' => 4, 'failed' => 4, 'returned' => 4 );
		$reached  = array();
		$top      = '';
		$top_rank = 0;
		$top_dt   = '';
		$events   = array();
		$last_non_noise = null;

		foreach ( $rows as $r ) {
			$is_noise = $noise && preg_match( $noise, $this->fold( $r['status'] ) );
			$ms       = is_callable( $ms_fn ) ? call_user_func( $ms_fn, $r ) : null;
			if ( $ms && isset( $order_ms[ $ms ] ) ) {
				if ( ! isset( $reached[ $ms ] ) ) {
					$reached[ $ms ] = $r['datetime'];
				}
				// Progress states win by rank. Terminal states (same rank) win by recency;
				// on an identical timestamp keep "delivered" — couriers log a delivery-note
				// row in the same second as the delivery itself.
				if ( $order_ms[ $ms ] > $top_rank
					|| ( $order_ms[ $ms ] === $top_rank && $ms !== $top && ( strcmp( $r['datetime'], $top_dt ) > 0 || ( $r['datetime'] === $top_dt && 'delivered' === $ms ) ) ) ) {
					$top      = $ms;
					$top_rank = $order_ms[ $ms ];
					$top_dt   = $r['datetime'];
				} elseif ( $ms === $top ) {
					$top_dt = $r['datetime'];
				}
			}
			$r['phrase'] = $this->phrase( $r['status'] );
			$r['noise']  = (bool) $is_noise;
			$events[]    = $r;
			if ( ! $is_noise ) {
				$last_non_noise = $r;
			}
		}

		$st['milestone'] = $top;
		$st['events']    = $events;
		$st['latest']    = $last_non_noise;

		$rail = array(
			array( 'key' => 'issued',    'label' => __( 'Issued', $this->td ),           'at' => $st['issued_at'] ),
			array( 'key' => 'picked',    'label' => __( 'Picked up', $this->td ),        'at' => isset( $reached['picked'] ) ? $reached['picked'] : '' ),
			array( 'key' => 'transit',   'label' => __( 'In transit', $this->td ),       'at' => isset( $reached['transit'] ) ? $reached['transit'] : '' ),
			array( 'key' => 'out',       'label' => __( 'Out for delivery', $this->td ), 'at' => isset( $reached['out'] ) ? $reached['out'] : '' ),
		);
		if ( 'failed' === $top ) {
			$rail[] = array( 'key' => 'failed', 'label' => __( 'Not delivered', $this->td ), 'at' => $reached['failed'], 'bad' => true );
		} elseif ( 'returned' === $top ) {
			$rail[] = array( 'key' => 'returned', 'label' => __( 'Returned to sender', $this->td ), 'at' => $reached['returned'], 'bad' => true );
		} else {
			$rail[] = array( 'key' => 'delivered', 'label' => __( 'Delivered', $this->td ), 'at' => isset( $reached['delivered'] ) ? $reached['delivered'] : '' );
		}

		// A later milestone implies the earlier ones even when the courier skipped the scan.
		$rank_top = $top ? $order_ms[ $top ] : 0;
		$cur_set  = false;
		foreach ( $rail as $i => &$step ) {
			$rank = 'issued' === $step['key'] ? 0 : ( isset( $order_ms[ $step['key'] ] ) ? $order_ms[ $step['key'] ] : 99 );
			$done = ( 'issued' === $step['key'] && $st['issued_at'] ) || ( $rank > 0 && $rank <= $rank_top );
			$step['done'] = $done && empty( $step['bad'] );
			$step['cur']  = false;
		}
		unset( $step );
		// Current = the step right after the last done one, unless terminal.
		if ( ! in_array( $top, array( 'delivered', 'failed', 'returned' ), true ) ) {
			foreach ( $rail as $i => &$step ) {
				if ( ! $step['done'] ) {
					$step['cur'] = true;
					break;
				}
			}
			unset( $step );
		}
		$st['rail'] = $rail;
	}

	private function sanitise_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
			if ( '' === $status ) {
				continue;
			}
			$out[] = array(
				'status'   => $status,
				'code'     => isset( $r['code'] ) ? (string) $r['code'] : '',
				'datetime' => isset( $r['datetime'] ) ? self::normalise_datetime( $r['datetime'] ) : '',
				'location' => isset( $r['location'] ) ? trim( (string) $r['location'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Accepts 'Y-m-d\TH:i:s(.u)', 'Y-m-d H:i:s', 'd/m/Y H:i(:s)', 'd-m-Y H:i', unix ints.
	 * Returns 'Y-m-d H:i:s' or ''.
	 */
	public static function normalise_datetime( $raw ) {
		if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) && strlen( $raw ) >= 9 ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $raw );
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$raw = preg_replace( '/\.\d+(Z)?$/', '', $raw );
		$raw = rtrim( $raw, 'Z' );
		$formats = array( 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y' );
		foreach ( $formats as $f ) {
			$d = DateTime::createFromFormat( $f, $raw );
			if ( $d && $d->format( str_replace( '!', '', $f ) ) === $raw ) {
				return $d->format( 'Y-m-d H:i:s' );
			}
		}
		$ts = strtotime( $raw );
		return $ts ? date( 'Y-m-d H:i:s', $ts ) : '';
	}

	private function fold( $s ) {
		$s = (string) $s;
		if ( function_exists( 'we_shiplog_fold' ) ) {
			return we_shiplog_fold( $s );
		}
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
	}

	private function phrase( $raw ) {
		$fn = $this->c['tracking']['phrase'];
		if ( is_callable( $fn ) ) {
			$p = call_user_func( $fn, $raw );
			if ( is_string( $p ) && '' !== $p ) {
				return $p;
			}
		}
		return $raw;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                              */
	/* ------------------------------------------------------------------ */

	public function render_metabox( $post_or_order ) {
		$order = $this->order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}
		echo $this->box_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Header mode (Box Now): quiet box when another courier owns the order,
	 * otherwise the plugin's original callback.
	 */
	public function render_header_mode( $post_or_order ) {
		$order = $this->order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$st = $this->state( $order );
		echo $this->head_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		if ( 'quiet' === $st['state'] ) {
			echo '<div class="wxmb wxmb-quiet">' . $this->quiet_html( $st ) . '</div>'; // phpcs:ignore
			return;
		}
		if ( is_callable( $this->c['original_callback'] ) ) {
			call_user_func( $this->c['original_callback'], $post_or_order );
		}
	}

	private function quiet_html( array $st ) {
		return '<p class="wxmb-quiet-line">' . sprintf(
			/* translators: %s: courier name */
			esc_html__( 'Not used for this order. Shipped with %s.', $this->td ),
			'<b>' . esc_html( $st['owner'] ) . '</b>'
		) . '</p>';
	}

	/**
	 * Whole inner HTML of the box for a given order (also used by refresh).
	 */
	public function box_html( $order ) {
		$this->reconcile( $order );
		$st   = $this->state( $order );
		$chip = $this->chip_for( $order );
		$cfg  = $this->js_config( $order );

		$lic = $this->licensed();
		ob_start();
		?>
		<div class="wxmb webexpert-mb-locked-wrap<?php echo $lic ? '' : ' is-locked'; ?>" id="wxmb-<?php echo esc_attr( $this->c['slug'] ); ?>" data-wxmb="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>" data-state="<?php echo esc_attr( $st['state'] ); ?>" data-chip-label="<?php echo esc_attr( $chip[0] ); ?>" data-chip-class="<?php echo esc_attr( $chip[1] ); ?>">
			<?php echo $this->head_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php if ( ! $lic ) : ?>
				<div class="webexpert-mb-lock-overlay">
					<span class="dashicons dashicons-lock"></span>
					<strong><?php esc_html_e( 'License required', $this->td ); ?></strong>
					<p><?php esc_html_e( 'Activate your license to use this feature.', $this->td ); ?></p>
					<a href="<?php echo esc_url( $this->c['license_url'] ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Activate', $this->td ); ?></a>
				</div>
			<?php endif; ?>
			<div class="wxmb-notices" aria-live="polite"></div>
			<?php
			if ( 'quiet' === $st['state'] ) {
				echo $this->quiet_html( $st ); // phpcs:ignore
			} elseif ( 'issued' === $st['state'] ) {
				$this->issued_html( $order, $st );
			} else {
				$this->draft_html( $order, $st );
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---- draft ---------------------------------------------------------- */

	private function draft_html( $order, array $st ) {
		$f = $this->c['fields'];
		$slug = $this->c['slug'];

		if ( ! empty( $st['cancelled'] ) && is_array( $st['cancelled'] ) ) {
			$c = $st['cancelled'];
			printf(
				'<div class="wxmb-notice wxmb-notice-warn" data-dismiss="1"><span>%s</span><button type="button" class="wxmb-x" aria-label="%s">&times;</button></div>',
				sprintf(
					/* translators: 1: voucher number 2: date/time */
					esc_html__( 'Voucher %1$s cancelled %2$s. Details below were kept.', $this->td ),
					'<b>' . esc_html( $c['voucher'] ) . '</b>',
					esc_html( $this->rel_time( (int) $c['time'] ) )
				),
				esc_attr__( 'Dismiss', $this->td )
			);
		}
		echo '<div class="wxmb-group">';
		echo '<input type="hidden" class="wxmb-order-id" value="' . esc_attr( $order->get_id() ) . '" />';

		// Parcels — compact stepper row.
		if ( isset( $f['parcels'] ) ) {
			$v = (int) ( $order->get_meta( $f['parcels']['meta'] ) ?: ( isset( $f['parcels']['default'] ) ? $f['parcels']['default'] : 1 ) );
			$v = max( 1, $v );
			?>
			<div class="wxmb-field wxmb-field-inline">
				<label for="wxmb-<?php echo esc_attr( $slug ); ?>-parcels"><?php esc_html_e( 'Parcels', $this->td ); ?></label>
				<span class="wxmb-stepper">
					<button type="button" class="wxmb-step" data-step="-1" aria-label="-">&minus;</button>
					<input type="number" min="1" step="1" id="wxmb-<?php echo esc_attr( $slug ); ?>-parcels" data-field="parcels" value="<?php echo esc_attr( $v ); ?>" />
					<button type="button" class="wxmb-step" data-step="1" aria-label="+">+</button>
				</span>
			</div>
			<?php
		}

		// Weight.
		if ( isset( $f['weight'] ) ) {
			$w    = $f['weight'];
			$meta = $order->get_meta( $w['meta'] );
			$hint = '';
			if ( $meta && (float) $meta > 0 ) {
				$val  = $meta;
				$hint = __( 'saved', $this->td );
			} else {
				$computed = is_callable( $w['compute'] ) ? (float) call_user_func( $w['compute'], $order ) : 0;
				if ( $computed > 0 ) {
					$val  = $computed;
					$hint = __( 'auto from products', $this->td );
				} else {
					$val  = ! empty( $w['default_option'] ) ? get_option( $w['default_option'] ) : '';
					$hint = __( 'default', $this->td );
				}
			}
			?>
			<div class="wxmb-field">
				<label for="wxmb-<?php echo esc_attr( $slug ); ?>-weight"><?php esc_html_e( 'Weight', $this->td ); ?> <small>· <?php echo esc_html( $hint ); ?></small></label>
				<span class="wxmb-input-unit">
					<input type="text" inputmode="decimal" id="wxmb-<?php echo esc_attr( $slug ); ?>-weight" data-field="weight" value="<?php echo esc_attr( $val ); ?>" />
					<span class="wxmb-unit">kg</span>
				</span>
			</div>
			<?php
		}

		// Pickup date.
		if ( isset( $f['pickup_date'] ) ) {
			$meta  = $order->get_meta( $f['pickup_date']['meta'] );
			$today = date_i18n( 'Y-m-d' );
			$val   = $meta ? date( 'Y-m-d', strtotime( $meta ) ) : $today;
			$moved = false;
			if ( $val < $today ) {
				$val   = $today;
				$moved = (bool) $meta;
			}
			?>
			<div class="wxmb-field">
				<label for="wxmb-<?php echo esc_attr( $slug ); ?>-pickup"><?php esc_html_e( 'Pickup date', $this->td ); ?><?php if ( $moved ) : ?> <small>· <?php esc_html_e( 'moved to today', $this->td ); ?></small><?php endif; ?></label>
				<input type="date" id="wxmb-<?php echo esc_attr( $slug ); ?>-pickup" data-field="pickup_date" value="<?php echo esc_attr( $val ); ?>" min="<?php echo esc_attr( $today ); ?>" />
			</div>
			<?php
		}

		// COD.
		$is_cod = false;
		if ( isset( $f['cod'] ) ) {
			$gw = isset( $f['cod']['gateways'] ) ? $f['cod']['gateways'] : array( 'cod' );
			if ( is_callable( $gw ) ) {
				$gw = (array) call_user_func( $gw, $order );
			}
			$is_cod = in_array( $order->get_payment_method(), (array) $gw, true );
			if ( $is_cod ) {
				$meta = $order->get_meta( $f['cod']['meta'] );
				$val  = ( '' !== $meta && null !== $meta ) ? wc_format_decimal( floatval( str_replace( ',', '.', (string) $meta ) ), 2 ) : wc_format_decimal( $order->get_total(), 2 );
				?>
				<div class="wxmb-field">
					<label for="wxmb-<?php echo esc_attr( $slug ); ?>-cod"><?php esc_html_e( 'Cash on delivery', $this->td ); ?></label>
					<span class="wxmb-input-unit">
						<input type="text" inputmode="decimal" id="wxmb-<?php echo esc_attr( $slug ); ?>-cod" data-field="cod" value="<?php echo esc_attr( $val ); ?>" />
						<span class="wxmb-unit"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ) ) ); ?></span>
					</span>
				</div>
				<?php
			} else {
				echo '<input type="hidden" data-field="cod" value="0" />';
			}
		}

		// Services as chips + hidden multi-select.
		if ( isset( $f['services'] ) && ! empty( $f['services']['options'] ) ) {
			$s        = $f['services'];
			$selected = $order->get_meta( $s['meta'] );
			$selected = is_array( $selected ) ? $selected : array();
			if ( empty( $selected ) && is_callable( $s['auto'] ?? null ) ) {
				$selected = (array) call_user_func( $s['auto'], $order );
			}
			$locked    = array();
			if ( $is_cod && ! empty( $s['cod_code'] ) ) {
				$locked[]   = $s['cod_code'];
				$selected[] = $s['cod_code'];
			}
			$selected  = array_values( array_unique( $selected ) );
			$secondary = isset( $s['secondary'] ) ? (array) $s['secondary'] : array();
			$return    = isset( $s['return_code'] ) ? $s['return_code'] : '';
			$primary   = array();
			$more      = array();
			foreach ( $s['options'] as $code => $label ) {
				if ( $code === $return ) {
					continue;
				}
				if ( in_array( $code, $secondary, true ) ) {
					$more[ $code ] = $label;
				} else {
					$primary[ $code ] = $label;
				}
			}
			$show_more = false;
			foreach ( $more as $code => $l ) {
				if ( in_array( $code, $selected, true ) ) {
					$show_more = true;
				}
			}
			if ( ! empty( $s['secondary_show'] ) && is_callable( $s['secondary_show'] ) && call_user_func( $s['secondary_show'], $order ) ) {
				$show_more = true;
			}
			?>
			<div class="wxmb-field">
				<label><?php esc_html_e( 'Services', $this->td ); ?></label>
				<div class="wxmb-chips" data-target="wxmb-<?php echo esc_attr( $slug ); ?>-services">
					<?php foreach ( $primary as $code => $label ) :
						$on = in_array( $code, $selected, true );
						$lk = in_array( $code, $locked, true );
						?>
						<button type="button" class="wxmb-chip-btn<?php echo $on ? ' on' : ''; ?><?php echo $lk ? ' lock' : ''; ?>" data-code="<?php echo esc_attr( $code ); ?>" <?php echo $lk ? 'disabled' : ''; ?> aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
					<?php if ( $more ) : ?>
						<button type="button" class="wxmb-chip-btn wxmb-more<?php echo $show_more ? ' open' : ''; ?>" data-more="1"><?php echo esc_html( isset( $s['secondary_label'] ) ? $s['secondary_label'] : __( 'More', $this->td ) ); ?> &#9662;</button>
						<span class="wxmb-chips-more"<?php echo $show_more ? '' : ' hidden'; ?>>
							<?php foreach ( $more as $code => $label ) :
								$on = in_array( $code, $selected, true );
								?>
								<button type="button" class="wxmb-chip-btn<?php echo $on ? ' on' : ''; ?>" data-code="<?php echo esc_attr( $code ); ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
				<select multiple hidden id="wxmb-<?php echo esc_attr( $slug ); ?>-services" data-field="services" class="wxmb-hidden-select">
					<?php foreach ( $s['options'] as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( in_array( $code, $selected, true ) ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php
			// Insurance amount, shown only when its service is on.
			if ( isset( $f['insurance'] ) ) {
				$ins_code = $f['insurance']['service'];
				$ins_val  = $order->get_meta( $f['insurance']['meta'] );
				$ins_on   = in_array( $ins_code, $selected, true ) || ( $ins_val && (float) $ins_val > 0 );
				?>
				<div class="wxmb-field wxmb-sub" data-service="<?php echo esc_attr( $ins_code ); ?>"<?php echo $ins_on ? '' : ' hidden'; ?>>
					<label for="wxmb-<?php echo esc_attr( $slug ); ?>-ins"><?php esc_html_e( 'Insurance amount', $this->td ); ?></label>
					<span class="wxmb-input-unit">
						<input type="text" inputmode="decimal" id="wxmb-<?php echo esc_attr( $slug ); ?>-ins" data-field="insurance" value="<?php echo esc_attr( $ins_val ); ?>" />
						<span class="wxmb-unit"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ) ) ); ?></span>
					</span>
				</div>
				<?php
			}
			// Prepaid return label as a checkbox.
			if ( $return && isset( $s['options'][ $return ] ) ) {
				$on = in_array( $return, $selected, true );
				?>
				<div class="wxmb-field">
					<label class="wxmb-check">
						<input type="checkbox" class="wxmb-service-check" data-code="<?php echo esc_attr( $return ); ?>" <?php checked( $on ); ?> />
						<span><?php echo esc_html( $s['options'][ $return ] ); ?><?php if ( ! empty( $s['return_hint'] ) ) : ?><small><?php echo esc_html( $s['return_hint'] ); ?></small><?php endif; ?></span>
					</label>
				</div>
				<?php
			}
		} elseif ( isset( $f['services'] ) && ! empty( $f['services']['checkbox'] ) ) {
			// Single boolean service (Speedex/EasyMail Saturday).
			$s    = $f['services'];
			$meta = $order->get_meta( $s['meta'] );
			$on   = is_array( $meta ) ? ! empty( $meta ) : ( '1' === (string) $meta );
			?>
			<div class="wxmb-field">
				<label class="wxmb-check">
					<input type="checkbox" data-field="services" value="<?php echo esc_attr( $s['checkbox_value'] ?? '1' ); ?>"<?php echo ! empty( $s['checkbox_array'] ) ? ' data-array="1"' : ''; ?> <?php checked( $on ); ?> />
					<span><?php echo esc_html( $s['checkbox'] ); ?></span>
				</label>
			</div>
			<?php
		}

		// Return label requested together with the voucher (couriers with a flag on the create call).
		if ( ! empty( $this->c['return']['at_issue'] ) && is_array( $this->c['return']['at_issue'] ) ) {
			$ai = $this->c['return']['at_issue'];
			?>
			<div class="wxmb-field">
				<label class="wxmb-check">
					<input type="checkbox" data-field="return_flag" value="1" />
					<span><?php echo esc_html( ! empty( $ai['label'] ) ? $ai['label'] : __( 'Prepaid return label', $this->td ) ); ?><?php if ( ! empty( $ai['hint'] ) ) : ?><small><?php echo esc_html( $ai['hint'] ); ?></small><?php endif; ?></span>
				</label>
			</div>
			<?php
		}

		// Comments.
		if ( isset( $f['comments'] ) ) {
			$meta = $order->get_meta( $f['comments']['meta'] );
			$val  = ( '' !== (string) $meta ) ? $meta : $order->get_customer_note();
			$from = ( '' === (string) $meta && '' !== (string) $order->get_customer_note() );
			$max  = isset( $f['comments']['max'] ) ? (int) $f['comments']['max'] : 0;
			?>
			<div class="wxmb-field">
				<label for="wxmb-<?php echo esc_attr( $slug ); ?>-comments"><?php esc_html_e( 'Comments', $this->td ); ?><?php if ( $from ) : ?> <small>· <?php esc_html_e( 'from customer note', $this->td ); ?></small><?php endif; ?><?php if ( $max ) : ?> <small>· <?php echo esc_html( sprintf( __( 'max %d chars', $this->td ), $max ) ); ?></small><?php endif; ?></label>
				<textarea id="wxmb-<?php echo esc_attr( $slug ); ?>-comments" data-field="comments" rows="3"<?php echo $max ? ' maxlength="' . (int) $max . '"' : ''; ?>><?php echo esc_textarea( $val ); ?></textarea>
			</div>
			<?php
		}

		// Account.
		if ( isset( $f['account'] ) ) {
			$a      = $f['account'];
			$labels = is_callable( $a['labels'] ) ? (array) call_user_func( $a['labels'] ) : array();
			$sel    = $order->get_meta( $a['meta'] );
			if ( '' === (string) $sel ) {
				$sel = ! empty( $a['default_option'] ) ? get_option( $a['default_option'], 0 ) : 0;
			}
			if ( count( $labels ) > 1 ) {
				?>
				<div class="wxmb-field">
					<label for="wxmb-<?php echo esc_attr( $slug ); ?>-account"><?php esc_html_e( 'Account', $this->td ); ?></label>
					<select id="wxmb-<?php echo esc_attr( $slug ); ?>-account" data-field="account">
						<?php foreach ( $labels as $i => $label ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (string) $sel, (string) $i ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php
			} else {
				echo '<input type="hidden" data-field="account" value="' . esc_attr( $sel ) . '" />';
			}
		}

		?>
			<div class="wxmb-actions">
				<button type="button" class="button button-primary wxmb-btn wxmb-create"><?php esc_html_e( 'Create voucher', $this->td ); ?><span class="wxmb-spin"></span></button>
			</div>
		</div>
		<?php
	}

	/* ---- issued --------------------------------------------------------- */

	private function issued_html( $order, array $st ) {
		$slug = $this->c['slug'];
		$url  = is_callable( $this->c['tracking']['track_url'] ) ? call_user_func( $this->c['tracking']['track_url'], $st['voucher'], $order ) : '';
		$top  = $st['milestone'];

		if ( 'failed' === $top ) {
			echo '<div class="wxmb-notice wxmb-notice-err"><span><b>' . esc_html__( 'Not delivered.', $this->td ) . '</b> ' . esc_html__( 'The courier will retry or return the parcel. Check the last event below.', $this->td ) . '</span></div>';
		} elseif ( 'returned' === $top ) {
			echo '<div class="wxmb-notice wxmb-notice-err"><span><b>' . esc_html__( 'Returned to sender.', $this->td ) . '</b></span></div>';
		}
		?>
		<div class="wxmb-group wxmb-info">
			<input type="hidden" class="wxmb-order-id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
			<div class="wxmb-row wxmb-voucher-row">
				<span class="wxmb-row-label"><?php esc_html_e( 'Voucher', $this->td ); ?></span>
				<span class="wxmb-row-value">
					<span class="wxmb-voucher" data-copy="<?php echo esc_attr( $st['voucher'] ); ?>"><?php echo esc_html( $st['voucher'] ); ?></span>
					<button type="button" class="wxmb-ico wxmb-copy" title="<?php esc_attr_e( 'Copy', $this->td ); ?>" data-copy="<?php echo esc_attr( $st['voucher'] ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
					<?php if ( $url ) : ?><a class="wxmb-ico" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Track on courier site', $this->td ); ?>"><span class="dashicons dashicons-external"></span></a><?php endif; ?>
				</span>
			</div>
			<?php if ( $st['subs'] ) : ?>
				<div class="wxmb-row">
					<span class="wxmb-row-label"><?php echo esc_html( sprintf( _n( '%d sub-voucher', '%d sub-vouchers', count( $st['subs'] ), $this->td ), count( $st['subs'] ) ) ); ?></span>
					<span class="wxmb-row-value wxmb-subs"><?php echo esc_html( implode( ', ', $st['subs'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="wxmb-rail">
				<?php foreach ( $st['rail'] as $step ) :
					$cls = 'wxmb-r';
					if ( ! empty( $step['done'] ) ) { $cls .= ' done'; }
					if ( ! empty( $step['cur'] ) )  { $cls .= ' cur'; }
					if ( ! empty( $step['bad'] ) )  { $cls .= ' bad'; }
					?>
					<div class="<?php echo esc_attr( $cls ); ?>">
						<span class="wxmb-dot"></span><span class="wxmb-line"></span>
						<span class="wxmb-r-label"><?php echo esc_html( $step['label'] ); ?></span>
						<span class="wxmb-r-time"><?php echo esc_html( $this->short_time( $step['at'] ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="wxmb-last">
				<?php if ( $st['latest'] ) : ?>
					<span class="wxmb-last-text"><?php echo esc_html( $st['latest']['phrase'] ); ?><?php if ( $st['latest']['location'] ) : ?>, <?php echo esc_html( $st['latest']['location'] ); ?><?php endif; ?></span>
					<small><?php echo esc_html( $this->short_time( $st['latest']['datetime'] ) ); ?><?php if ( $st['checked'] && ! in_array( $top, array( 'delivered', 'returned' ), true ) ) : ?> · <?php echo esc_html( sprintf( __( 'updated %s', $this->td ), $this->rel_time( $st['checked'] ) ) ); ?><?php elseif ( 'delivered' === $top ) : ?> · <?php esc_html_e( 'tracking stopped', $this->td ); ?><?php endif; ?>
						<?php if ( 'delivered' !== $top && is_callable( $this->c['track'] ) ) : ?> · <a href="#" class="wxmb-track-now"><?php esc_html_e( 'refresh', $this->td ); ?></a><?php endif; ?>
						<?php if ( count( $st['events'] ) ) : ?> · <a href="#" class="wxmb-toggle-events"><?php echo esc_html( sprintf( _n( 'all %d events', 'all %d events', count( $st['events'] ), $this->td ), count( $st['events'] ) ) ); ?></a><?php endif; ?></small>
				<?php else : ?>
					<span class="wxmb-last-text wxmb-muted"><?php esc_html_e( 'No courier scans yet.', $this->td ); ?></span>
					<small><?php echo $st['checked'] ? esc_html( sprintf( __( 'checked %s', $this->td ), $this->rel_time( $st['checked'] ) ) ) : esc_html__( 'waiting for the first tracking check', $this->td ); ?>
						<?php if ( is_callable( $this->c['track'] ) ) : ?> · <a href="#" class="wxmb-track-now"><?php esc_html_e( 'refresh', $this->td ); ?></a><?php endif; ?>
						<?php if ( count( $st['events'] ) ) : ?> · <a href="#" class="wxmb-toggle-events"><?php echo esc_html( sprintf( _n( 'all %d events', 'all %d events', count( $st['events'] ), $this->td ), count( $st['events'] ) ) ); ?></a><?php endif; ?></small>
				<?php endif; ?>
			</div>
			<?php if ( count( $st['events'] ) ) : ?>
				<ul class="wxmb-events" hidden>
					<?php foreach ( array_reverse( $st['events'] ) as $e ) : ?>
						<li class="<?php echo $e['noise'] ? 'wxmb-ev-noise' : ''; ?>">
							<span class="wxmb-ev-time"><?php echo esc_html( $this->short_time( $e['datetime'] ) ); ?></span>
							<span class="wxmb-ev-text"><?php echo esc_html( $e['phrase'] ); ?><?php if ( $e['location'] ) : ?> <em><?php echo esc_html( $e['location'] ); ?></em><?php endif; ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<div class="wxmb-group">
			<?php $this->print_controls( $order, 'delivered' !== $top ); ?>
			<div class="wxmb-details"><?php echo esc_html( $this->details_line( $order ) ); ?></div>
			<?php $this->return_block( $order, $st ); ?>
			<div class="wxmb-foot">
				<span class="wxmb-foot-right">
					<a href="#" class="wxmb-cancel wxmb-danger-link" data-voucher="<?php echo esc_attr( $st['voucher'] ); ?>"><?php esc_html_e( 'Cancel voucher', $this->td ); ?></a>
					<span class="wxmb-more-wrap">
						<button type="button" class="wxmb-kebab" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'More', $this->td ); ?>">&#8943;</button>
						<span class="wxmb-menu" hidden>
							<a href="#" class="wxmb-reset"><?php esc_html_e( 'Reset job (local only)', $this->td ); ?></a>
						</span>
					</span>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Return voucher block inside the issued state: existing return number with print,
	 * or a button to issue one, or a note when the courier has no such call.
	 */
	private function return_block( $order, array $st ) {
		$r = $this->c['return'];
		if ( empty( $st['job_id'] ) ) {
			return;
		}
		$number = (string) get_post_meta( $st['job_id'], $r['meta'], true );
		$label  = $r['label'] ?: __( 'Return voucher', $this->td );
		if ( '' !== $number ) {
			?>
			<div class="wxmb-return">
				<span class="wxmb-return-label"><?php echo esc_html( $label ); ?></span>
				<span class="wxmb-return-no" data-copy="<?php echo esc_attr( $number ); ?>"><?php echo esc_html( $number ); ?></span>
				<button type="button" class="wxmb-ico wxmb-copy" title="<?php esc_attr_e( 'Copy', $this->td ); ?>" data-copy="<?php echo esc_attr( $number ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
				<?php if ( is_callable( $r['print'] ) ) : ?>
					<button type="button" class="button button-small wxmb-return-print"><?php esc_html_e( 'Print return label', $this->td ); ?></button>
				<?php endif; ?>
			</div>
			<?php
			return;
		}
		if ( is_callable( $r['create'] ) ) {
			?>
			<div class="wxmb-return">
				<button type="button" class="button wxmb-btn-inline wxmb-return-create"><?php esc_html_e( 'Issue return voucher', $this->td ); ?><span class="wxmb-spin"></span></button>
			</div>
			<?php
			return;
		}
		if ( ! empty( $r['note'] ) ) {
			echo '<div class="wxmb-return wxmb-return-note">' . esc_html( $r['note'] ) . '</div>';
		}
	}

	private function print_controls( $order, $primary = true ) {
		$f = $this->c['fields'];
		$opts = isset( $f['print_type']['options'] ) ? $f['print_type']['options'] : array();
		$def  = '';
		if ( isset( $f['print_type']['default_option'] ) ) {
			$def = (string) get_option( $f['print_type']['default_option'], '' );
		}
		if ( ( '' === $def || ! isset( $opts[ $def ] ) ) && $opts ) {
			$def = isset( $f['print_type']['default'] ) && isset( $opts[ $f['print_type']['default'] ] ) ? $f['print_type']['default'] : (string) array_key_first( $opts );
		}
		$label = $opts && isset( $opts[ $def ] ) ? sprintf( __( 'Print %s', $this->td ), $opts[ $def ] ) : __( 'Print voucher', $this->td );
		$cls   = $primary ? 'button button-primary' : 'button';
		?>
		<div class="wxmb-actions wxmb-print-wrap">
			<span class="wxmb-split">
				<button type="button" class="<?php echo esc_attr( $cls ); ?> wxmb-btn wxmb-print" data-type="<?php echo esc_attr( $def ); ?>"><?php echo esc_html( $label ); ?><span class="wxmb-spin"></span></button>
				<?php if ( count( $opts ) > 1 ) : ?>
					<button type="button" class="<?php echo esc_attr( $cls ); ?> wxmb-caret" aria-haspopup="true" aria-expanded="false">&#9662;</button>
					<span class="wxmb-menu wxmb-print-menu" hidden>
						<?php foreach ( $opts as $val => $l ) : ?>
							<a href="#" class="wxmb-print-opt" data-type="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $l ); ?></a>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	private function details_line( $order ) {
		$f     = $this->c['fields'];
		$parts = array();
		if ( isset( $f['parcels'] ) ) {
			$n = (int) $order->get_meta( $f['parcels']['meta'] );
			if ( $n > 0 ) {
				$parts[] = sprintf( _n( '%d parcel', '%d parcels', $n, $this->td ), $n );
			}
		}
		if ( isset( $f['weight'] ) ) {
			$w = $order->get_meta( $f['weight']['meta'] );
			if ( '' !== (string) $w ) {
				$parts[] = wc_format_decimal( str_replace( ',', '.', (string) $w ), 2 ) . ' kg';
			}
		}
		if ( isset( $f['pickup_date'] ) ) {
			$d = $order->get_meta( $f['pickup_date']['meta'] );
			if ( $d ) {
				$parts[] = sprintf( __( 'pickup %s', $this->td ), date_i18n( 'j M', strtotime( $d ) ) );
			}
		}
		if ( isset( $f['cod'] ) ) {
			$c = $order->get_meta( $f['cod']['meta'] );
			if ( '' !== (string) $c && (float) str_replace( ',', '.', (string) $c ) > 0 ) {
				$parts[] = sprintf( __( 'COD %s', $this->td ), wp_strip_all_tags( wc_price( (float) str_replace( ',', '.', (string) $c ), array( 'currency' => $order->get_currency() ) ) ) );
			}
		}
		if ( isset( $f['services'] ) ) {
			$s = $order->get_meta( $f['services']['meta'] );
			if ( is_array( $s ) && $s ) {
				$labels = array();
				foreach ( $s as $code ) {
					$labels[] = isset( $f['services']['options'][ $code ] ) ? $f['services']['options'][ $code ] : $code;
				}
				$parts[] = implode( ', ', $labels );
			} elseif ( ! is_array( $s ) && '1' === (string) $s && ! empty( $f['services']['checkbox'] ) ) {
				$parts[] = $f['services']['checkbox'];
			}
		}
		return $parts ? __( 'Details:', $this->td ) . ' ' . implode( ' · ', $parts ) : __( 'Details', $this->td );
	}

	private function short_time( $dt ) {
		if ( ! $dt ) {
			return '';
		}
		$ts = strtotime( $dt );
		if ( ! $ts ) {
			return '';
		}
		$fmt = ( date( 'Y', $ts ) === date_i18n( 'Y' ) ) ? 'j M H:i' : 'j M Y';
		return date_i18n( $fmt, $ts );
	}

	private function rel_time( $ts ) {
		$ts = (int) $ts;
		if ( ! $ts ) {
			return '';
		}
		$now  = time();
		$diff = max( 0, $now - $ts );
		if ( $diff < 60 ) {
			return __( 'just now', $this->td );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human time diff (core string, translated by WordPress) */
			return sprintf( __( '%s ago' ), human_time_diff( $ts, $now ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		return wp_date( 'j M H:i', $ts );
	}

	/* ------------------------------------------------------------------ */
	/* JS config                                                             */
	/* ------------------------------------------------------------------ */

	private function js_config( $order ) {
		$slug   = $this->c['slug'];
		$fields = (array) $this->c['create_fields'];
		if ( ! empty( $this->c['return']['at_issue']['post_key'] ) ) {
			$fields[ $this->c['return']['at_issue']['post_key'] ] = 'return_flag';
		}
		return array(
			'slug'         => $slug,
			'orderId'      => $order->get_id(),
			'nonce'        => wp_create_nonce( $this->c['nonce_action'] ),
			'nonce2'       => wp_create_nonce( 'wxmb_' . $slug ),
			'ajax'         => array(
				'create'  => isset( $this->c['ajax']['create'] ) ? $this->c['ajax']['create'] : '',
				'print'   => isset( $this->c['ajax']['print'] ) ? $this->c['ajax']['print'] : '',
				'refresh' => 'wxmb_' . $slug . '_refresh',
				'cancel'  => 'wxmb_' . $slug . '_cancel',
				'reset'   => 'wxmb_' . $slug . '_reset',
				'track'   => 'wxmb_' . $slug . '_track',
				'returnCreate' => 'wxmb_' . $slug . '_return_create',
				'returnPrint'  => 'wxmb_' . $slug . '_return_print',
			),
			'createFields' => $fields,
			'printField'   => $this->c['print_field'],
			'printResult'  => $this->c['print_result'],
			'licenseUrl'   => $this->c['license_url'],
			'strings'      => array(
				'created'      => __( 'Voucher created.', $this->td ),
				'cancelled'    => __( 'Voucher cancelled.', $this->td ),
				'reset'        => __( 'Job reset. The voucher, if any, still exists at the courier.', $this->td ),
				'confirmCancel'=> __( 'Cancel voucher %s at the courier? The label becomes invalid.', $this->td ),
				'confirmReset' => __( 'Reset the voucher process for this order? This only clears local data.', $this->td ),
				'printed'      => __( 'Label opened in a new tab.', $this->td ),
				'popup'        => __( 'Your browser blocked the label window.', $this->td ),
				'popupLink'    => __( 'Open label', $this->td ),
				'error'        => __( 'Something went wrong.', $this->td ),
				'license'      => __( 'License required.', $this->td ),
				'copied'       => __( 'Copied', $this->td ),
				'noPrint'      => __( 'No label returned.', $this->td ),
				'tracked'      => __( 'Tracking refreshed.', $this->td ),
				'confirmReturn'=> __( 'Issue a return voucher at the courier for this order? The customer sends the parcel back with it.', $this->td ),
				'returned'     => __( 'Return voucher issued.', $this->td ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                                */
	/* ------------------------------------------------------------------ */

	public function enqueue( $hook ) {
		if ( ! $this->is_order_screen( $hook ) ) {
			return;
		}
		$base = $this->c['asset_url'];
		// Cache-bust on the file itself: the shared assets change independently of the plugin version.
		$dir  = dirname( __DIR__ ) . '/admin/wxmb/';
		$vcss = self::VERSION . '.' . $this->c['asset_ver'] . '.' . ( file_exists( $dir . 'wx-courier-metabox-ux.css' ) ? filemtime( $dir . 'wx-courier-metabox-ux.css' ) : 0 );
		$vjs  = self::VERSION . '.' . $this->c['asset_ver'] . '.' . ( file_exists( $dir . 'wx-courier-metabox-ux.js' ) ? filemtime( $dir . 'wx-courier-metabox-ux.js' ) : 0 );
		if ( ! wp_style_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::HANDLE, $base . 'wx-courier-metabox-ux.css', array(), $vcss );
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_script( self::HANDLE, $base . 'wx-courier-metabox-ux.js', array( 'jquery' ), $vjs, true );
			wp_localize_script( self::HANDLE, 'wxCourierMetaboxUX', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
		}
	}

	private function is_order_screen( $hook ) {
		if ( false !== strpos( (string) $hook, 'wc-orders' ) ) {
			return true;
		}
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && ( 'shop_order' === $screen->post_type || 'woocommerce_page_wc-orders' === $screen->id ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                  */
	/* ------------------------------------------------------------------ */

	private function guard() {
		check_ajax_referer( 'wxmb_' . $this->c['slug'], 'nonce2' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', $this->td ) ), 403 );
		}
		if ( ! $this->licensed() ) {
			wp_send_json_error( array( 'message' => __( 'License required.', $this->td ), 'license_error' => true, 'redirect' => $this->c['license_url'] ), 403 );
		}
		$order = isset( $_POST['order_id'] ) ? wc_get_order( absint( $_POST['order_id'] ) ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', $this->td ) ) );
		}
		return $order;
	}

	private function respond( $order, $message = '' ) {
		$chip = $this->chip_for( $order );
		wp_send_json_success( array(
			'html'    => $this->box_html( $order ),
			'chip'    => array( 'label' => $chip[0], 'class' => $chip[1] ),
			'message' => $message,
		) );
	}

	public function ajax_refresh() {
		$order = $this->guard();
		$this->respond( $order );
	}

	/**
	 * Fresh courier track on demand, then reconcile and re-render.
	 */
	public function ajax_track() {
		$order = $this->guard();
		if ( ! is_callable( $this->c['track'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Tracking is not available.', $this->td ) ) );
		}
		call_user_func( $this->c['track'], $order );
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_webexpert_last_tracking_check', time() );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->reconcile( $order );
		$this->respond( wc_get_order( $order->get_id() ), __( 'Tracking refreshed.', $this->td ) );
	}

	/**
	 * Standalone return voucher (customer → shop) through the courier's own return call.
	 */
	public function ajax_return_create() {
		$order = $this->guard();
		$r     = $this->c['return'];
		if ( ! is_callable( $r['create'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This courier has no return voucher call.', $this->td ) ) );
		}
		$st = $this->state( $order );
		if ( 'issued' !== $st['state'] || ! $st['job_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Issue the outbound voucher first.', $this->td ) ) );
		}
		if ( '' !== (string) get_post_meta( $st['job_id'], $r['meta'], true ) ) {
			wp_send_json_error( array( 'message' => __( 'A return voucher already exists for this order.', $this->td ) ) );
		}
		$result = call_user_func( $r['create'], $order, (int) $st['job_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$number = is_array( $result ) ? (string) ( $result['number'] ?? '' ) : (string) $result;
		if ( '' === $number ) {
			wp_send_json_error( array( 'message' => __( 'The courier returned no return voucher number.', $this->td ) ) );
		}
		update_post_meta( $st['job_id'], $r['meta'], $number );
		update_post_meta( $st['job_id'], '_wxmb_return_created', time() );
		$order->add_order_note( sprintf( __( '%1$s return voucher %2$s issued.', $this->td ), $this->c['title'], $number ) );
		$this->respond( wc_get_order( $order->get_id() ), __( 'Return voucher issued.', $this->td ) );
	}

	public function ajax_return_print() {
		$order = $this->guard();
		$r     = $this->c['return'];
		if ( ! is_callable( $r['print'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Printing the return label is not available.', $this->td ) ) );
		}
		$job_id = (int) $order->get_meta( 'we_voucher_job_id' );
		$number = $job_id ? (string) get_post_meta( $job_id, $r['meta'], true ) : '';
		if ( '' === $number ) {
			wp_send_json_error( array( 'message' => __( 'No return voucher on this order.', $this->td ) ) );
		}
		$result = call_user_func( $r['print'], $order, $job_id, $number );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( ! is_array( $result ) || ( empty( $result['url'] ) && empty( $result['base64'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'No label returned.', $this->td ) ) );
		}
		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------ */
	/* Reconcile: keep job term / flags / cache in line with the checkpoints */
	/* ------------------------------------------------------------------ */

	/** @var bool re-entrancy guard (reconcile saves the order, which fires the save hook again) */
	private static $reconciling = false;

	/** @var array order ids reconciled in this request */
	private static $reconciled = array();

	public function maybe_reconcile_on_save( $order ) {
		if ( self::$reconciling || ! $order instanceof WC_Order ) {
			return;
		}
		$carrier = (string) $order->get_meta( '_webexpert_order_tracking_carrier' );
		if ( '' === $carrier || ( $carrier !== $this->c['vendor'] && ! in_array( $carrier, (array) $this->c['carriers'], true ) ) ) {
			return;
		}
		if ( ! $order->get_meta( 'we_voucher_job_id' ) ) {
			return;
		}
		$this->reconcile( $order );
	}

	/**
	 * Milestone the cached checkpoints prove, the term the job carries, and what should change.
	 *
	 * @return array changes: [ ['type'=>'term'|'flag'|'cache'|'orphan', ...], ... ]
	 */
	public function reconcile_plan( $order ) {
		$st      = $this->state( $order );
		$changes = array();
		$job_id  = (int) $st['job_id'];

		if ( 'issued' === $st['state'] && $job_id ) {
			$map = array( 'delivered' => 'we_delivered', 'failed' => 'we_rejected', 'returned' => 'we_rejected', 'picked' => 'we_picked', 'transit' => 'we_picked', 'out' => 'we_picked' );
			$want = isset( $map[ $st['milestone'] ] ) ? $map[ $st['milestone'] ] : '';
			$have = wp_get_post_terms( $job_id, 'we_voucher_status', array( 'fields' => 'slugs' ) );
			$have = is_array( $have ) ? $have : array();
			// Never downgrade a job that is already terminal.
			$terminal = array_intersect( $have, array( 'we_delivered', 'we_rejected' ) );
			if ( $want && ! in_array( $want, $have, true ) && ( ! $terminal || in_array( $want, array( 'we_delivered', 'we_rejected' ), true ) ) && term_exists( $want, 'we_voucher_status' ) ) {
				$changes[] = array( 'type' => 'term', 'job' => $job_id, 'from' => implode( ',', $have ), 'to' => $want );
			}
			$flags = array();
			if ( 'delivered' === $st['milestone'] && ! $order->get_meta( '_webexpert_order_tracking_delivered' ) ) {
				$flags['_webexpert_order_tracking_delivered'] = 1;
				$when = '';
				foreach ( $st['rail'] as $step ) {
					if ( 'delivered' === $step['key'] ) {
						$when = $step['at'];
					}
				}
				if ( ! $order->get_meta( '_webexpert_order_tracking_delivered_timestamp' ) ) {
					$flags['_webexpert_order_tracking_delivered_timestamp'] = $when ?: current_time( 'mysql' );
				}
			}
			if ( in_array( $st['milestone'], array( 'failed', 'returned' ), true ) && ! $order->get_meta( '_webexpert_order_tracking_rejected' ) ) {
				$flags['_webexpert_order_tracking_rejected'] = 1;
			}
			if ( in_array( $st['milestone'], array( 'picked', 'transit', 'out' ), true ) && ! $order->get_meta( '_webexpert_order_tracking_picked' ) ) {
				$flags['_webexpert_order_tracking_picked'] = 1;
			}
			if ( $flags ) {
				$changes[] = array( 'type' => 'flag', 'set' => $flags );
			}
			// Cached status left as an object/array by an older plugin version.
			$cached = $order->get_meta( 'voucher_delivery_status' );
			if ( ( is_object( $cached ) || is_array( $cached ) ) && $st['latest'] ) {
				$changes[] = array( 'type' => 'cache', 'to' => $st['latest']['status'] );
			}
		}

		// Orphans: this vendor's other jobs for the order that the order no longer points to.
		// Raw SQL on purpose. A WP_Query here is at the mercy of any theme or plugin that
		// hooks pre_get_posts and calls $query->set( 'meta_query', ... ): that REPLACES our
		// meta_query, and the box would then read the 20 newest jobs of the whole shop as
		// orphans of this order and cancel them. Also skipped entirely when the order has no
		// linked job — with nothing to compare against, every job would look superseded.
		$orphans = array();
		if ( $job_id ) {
			global $wpdb;
			$orphans = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} mo ON mo.post_id = p.ID AND mo.meta_key = 'order_id'
					INNER JOIN {$wpdb->postmeta} mv ON mv.post_id = p.ID AND mv.meta_key = 'we_voucher_job_provider'
				WHERE p.post_type = 'we_voucher_job'
					AND p.post_status NOT IN ( 'trash', 'auto-draft' )
					AND p.post_date_gmt < %s
					AND p.ID <> %d
					AND mo.meta_value = %s
					AND mv.meta_value = %s
				ORDER BY p.post_date DESC
				LIMIT 20",
				gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ),
				$job_id,
				(string) $order->get_id(),
				(string) $this->c['vendor']
			) );
		}
		$tracking_no = trim( (string) $order->get_meta( '_shipping_tracking_number' ) );
		foreach ( $orphans as $oid ) {
			$oid = (int) $oid;
			// Belt and braces: re-read the metas instead of trusting the rows we were handed.
			if ( ! $oid || $oid === (int) $job_id
				|| (string) get_post_meta( $oid, 'order_id', true ) !== (string) $order->get_id()
				|| (string) get_post_meta( $oid, 'we_voucher_job_provider', true ) !== (string) $this->c['vendor'] ) {
				continue;
			}
			// The order ships on this voucher, whatever we_voucher_job_id says. A double submit can
			// leave the two metas pointing at different twins; the tracking number is the one the
			// customer was emailed and the one the cron follows, so it is never the orphan.
			$oid_voucher = trim( (string) get_post_meta( $oid, $this->c['job']['voucher_meta'], true ) );
			if ( '' !== $tracking_no && $oid_voucher === $tracking_no ) {
				continue;
			}
			$terms = wp_get_post_terms( $oid, 'we_voucher_status', array( 'fields' => 'slugs' ) );
			$terms = is_array( $terms ) ? $terms : array();
			$status = get_post_meta( $oid, 'webexpert_voucher_job_status', true );
			if ( $terms || 'we-voucher-cancelled' !== $status ) {
				$changes[] = array( 'type' => 'orphan', 'job' => (int) $oid, 'terms' => implode( ',', $terms ), 'status' => $status );
			}
		}
		return $changes;
	}

	/**
	 * Apply the plan. Returns the changes applied.
	 */
	public function reconcile( $order, $dry = false ) {
		if ( ! $order instanceof WC_Order || 'full' !== $this->c['mode'] ) {
			return array();
		}
		$key = $order->get_id() . '|' . $this->c['vendor'];
		if ( ! $dry && isset( self::$reconciled[ $key ] ) ) {
			return array();
		}
		$changes = $this->reconcile_plan( $order );
		if ( $dry ) {
			return $changes;
		}
		self::$reconciled[ $key ] = true;
		if ( ! $changes ) {
			return array();
		}
		self::$reconciling = true;
		$dirty   = false;
		$fire    = array();
		$voucher = (string) $order->get_meta( '_shipping_tracking_number' );
		foreach ( $changes as $ch ) {
			switch ( $ch['type'] ) {
				case 'term':
					wp_set_post_terms( $ch['job'], $ch['to'], 'we_voucher_status', false );
					$map = array( 'we_delivered' => 'delivered', 'we_rejected' => 'rejected', 'we_picked' => 'picked' );
					if ( isset( $map[ $ch['to'] ] ) && ! empty( $this->c['status_actions'][ $map[ $ch['to'] ] ] ) ) {
						$fire[] = $this->c['status_actions'][ $map[ $ch['to'] ] ];
					}
					break;
				case 'flag':
					foreach ( $ch['set'] as $k => $v ) {
						$order->update_meta_data( $k, $v );
					}
					$dirty = true;
					break;
				case 'cache':
					$order->update_meta_data( 'voucher_delivery_status', $ch['to'] );
					$dirty = true;
					break;
				case 'orphan':
					wp_set_post_terms( $ch['job'], array(), 'we_voucher_status', false );
					update_post_meta( $ch['job'], 'webexpert_voucher_job_status', 'we-voucher-cancelled' );
					update_post_meta( $ch['job'], '_wxmb_superseded', time() );
					break;
			}
		}
		if ( $dirty ) {
			$order->save();
		}
		// Let the plugin's own listeners (fulfillments, notifications) see the status the courier reported.
		foreach ( array_unique( $fire ) as $action ) {
			do_action( $action, wc_get_order( $order->get_id() ), $voucher );
		}
		self::$reconciling = false;
		return $changes;
	}

	public function ajax_cancel() {
		$order = $this->guard();
		if ( ! is_callable( $this->c['cancel_for_order'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancel is not available.', $this->td ) ) );
		}
		$st      = $this->state( $order );
		$voucher = $st['voucher'];

		// Keep what the admin typed so a re-issue is two clicks.
		$keep = array();
		foreach ( (array) $this->c['keep_on_cancel'] as $key ) {
			$keep[ $key ] = $order->get_meta( $key );
		}

		$result = call_user_func( $this->c['cancel_for_order'], $order->get_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'The courier refused the cancellation.', $this->td ) ) );
		}

		$order = wc_get_order( $order->get_id() );
		foreach ( $keep as $key => $val ) {
			if ( '' !== (string) ( is_array( $val ) ? wp_json_encode( $val ) : $val ) ) {
				$order->update_meta_data( $key, $val );
			}
		}
		$order->update_meta_data( '_wxmb_' . $this->c['slug'] . '_last_cancelled', array( 'voucher' => $voucher, 'time' => time() ) );
		$order->save();
		if ( $voucher ) {
			$order->add_order_note( sprintf( __( '%1$s voucher %2$s cancelled.', $this->td ), $this->c['title'], $voucher ) );
		}
		$this->respond( $order, __( 'Voucher cancelled.', $this->td ) );
	}

	public function ajax_reset() {
		$order = $this->guard();
		$st    = $this->state( $order );
		foreach ( array( 'we_voucher_job_id', '_webexpert_order_tracking_carrier', '_shipping_tracking_number', 'voucher_delivery_status', 'voucher_delivery_process', 'voucher_delivery_process_time', 'tracking_path', '_webexpert_order_tracking_delivered', '_webexpert_order_tracking_delivered_timestamp', '_webexpert_order_tracking_rejected', '_webexpert_order_tracking_picked', '_webexpert_last_tracking_check' ) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();
		if ( $st['voucher'] ) {
			$order->add_order_note( sprintf( __( '%1$s job reset locally. Voucher %2$s was not cancelled at the courier.', $this->td ), $this->c['title'], $st['voucher'] ) );
		}
		$this->respond( $order, __( 'Job reset.', $this->td ) );
	}

	/**
	 * Hooked to the plugin's "voucher created" action: order note + clear the cancel notice.
	 */
	public function on_created( $order, $job_id = 0 ) {
		$order = $this->order_from( $order );
		if ( ! $order ) {
			return;
		}
		$voucher = $job_id ? get_post_meta( $job_id, $this->c['job']['voucher_meta'], true ) : $order->get_meta( '_shipping_tracking_number' );
		$order->delete_meta_data( '_wxmb_' . $this->c['slug'] . '_last_cancelled' );
		$order->save();
		$order->add_order_note( sprintf( __( '%1$s voucher %2$s issued.', $this->td ), $this->c['title'], $voucher ) );
	}
}

endif;

/*
 * ---------------------------------------------------------------------------
 * CONFIG REFERENCE (keys read by this class)
 * ---------------------------------------------------------------------------
 * mode            'full' | 'header'
 * slug            short id used in element ids, AJAX action names, meta keys (e.g. 'acs')
 * vendor          value this plugin writes to job meta we_voucher_job_provider
 * carriers        extra values of _webexpert_order_tracking_carrier that mean "mine"
 * text_domain
 * title           metabox title text
 * logo            URL of the logo (svg/png, ~18px tall when rendered)
 * metabox_id      the id passed to add_meta_box by the plugin
 * option          option name of the "Improved UX" toggle ('1' = on)
 * licensed        callable(): bool
 * license_url
 * nonce_action    the plugin's admin nonce action string (sent as `nonce` to plugin AJAX)
 * ajax            ['create' => action, 'print' => action]
 * print_result    'success_url' | 'success_url_or_list' | 'files' | 'upload' | 'string_url' | 'success_base64'
 * create_fields   [ POST key => field key ] field keys: parcels, weight, pickup_date, cod, services, insurance, comments, account
 * print_field     POST key for print type
 * fields          parcels{meta,default} weight{meta,default_option,compute} pickup_date{meta}
 *                 cod{meta,gateways(array|callable)} services{meta,options,secondary,secondary_label,secondary_show,auto,cod_code,return_code,return_hint | checkbox,checkbox_value}
 *                 insurance{meta,service} comments{meta,max} account{meta,labels(callable),default_option}
 *                 print_type{options,default_option,default}
 * job             voucher_meta, sub_voucher_meta, status_meta
 * tracking        read(callable $order => rows[status,code,datetime,location]) milestone(callable row => picked|transit|out|delivered|failed|returned|null)
 *                 noise(regex on folded status) phrase(callable raw => text) track_url(callable voucher,$order => url)
 * cancel_for_order callable($order_id) => true|WP_Error
 * keep_on_cancel  meta keys to restore after the plugin's cancel wipes them
 * created_action  the plugin's "voucher created" action name ($order, $job_id)
 * asset_url       URL of the folder holding wx-courier-metabox-ux.css/js
 * asset_ver       plugin version string for cache busting
 * status_for_header (header mode) callable($order) => [label, class]
 */
