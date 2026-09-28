<?php
/**
 * WooCommerce Order Fulfillments integration for DK Express Courier.
 *
 * @since 2.0.5
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class DKExpress_Fulfillments {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_fulfillment_shipping_providers', [ $this, 'add_dkexpress_provider' ], 15 );
		add_action( 'dkexpress_voucher_created', [ $this, 'on_voucher_created' ], 10, 2 );
		add_action( 'dkexpress_voucher_order_delivered', [ $this, 'on_voucher_delivered' ], 10, 2 );
	}

	/**
	 * Add DK Express shipping provider.
	 */
	public function add_dkexpress_provider( array $providers ): array {
		$providers['dkexpress'] = new DKExpress_Fulfillment_Shipping_Provider();
		return $providers;
	}

	/**
	 * Create a fulfillment when a voucher is created.
	 *
	 * @param WC_Order $order    The order.
	 * @param int      $post_id  The we_voucher_job post ID.
	 */
	public function on_voucher_created( $order, $post_id ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_we_dkexpress_fulfillment_id' ) ) {
			return;
		}

		$tracking_number = $order->get_meta( '_shipping_tracking_number' );
		if ( empty( $tracking_number ) ) {
			return;
		}

		try {
			$fulfillment = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();
			$fulfillment->set_entity_type( 'WC_Order' );
			$fulfillment->set_entity_id( (string) $order->get_id() );
			$fulfillment->set_status( 'unfulfilled' );

			$items = [];
			foreach ( $order->get_items() as $item_id => $item ) {
				$items[] = [ 'item_id' => (int) $item_id, 'qty' => $item->get_quantity() ];
			}
			$fulfillment->set_items( $items );

			$fulfillment->set_tracking_number( $tracking_number );
			$fulfillment->set_shipment_provider( 'dkexpress' );
			$fulfillment->set_tracking_url(
				'https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01=' . $tracking_number
			);

			$parcels = $order->get_meta( 'dkexpress_parcels' );
			if ( $parcels ) {
				$fulfillment->add_meta_data( 'Parcels', (string) $parcels );
			}

			$weight = $order->get_meta( 'dkexpress_weight' );
			if ( $weight ) {
				$fulfillment->add_meta_data( 'Weight', $weight . ' kg' );
			}

			$cod = $order->get_meta( 'dkexpress_cod' );
			if ( $cod && floatval( $cod ) > 0 ) {
				$fulfillment->add_meta_data( 'COD', $cod . ' €' );
			}

			$fulfillment->save();

			$order->update_meta_data( '_we_dkexpress_fulfillment_id', $fulfillment->get_id() );
			$order->save();
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DK Express Fulfillments: Failed to create fulfillment for order #' . $order->get_id() . ' — ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Mark fulfillment as fulfilled when courier confirms delivery.
	 *
	 * @param WC_Order $order      The order.
	 * @param string   $voucher_no The voucher/tracking number.
	 */
	public function on_voucher_delivered( $order, $voucher_no ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		$fulfillment_id = $order->get_meta( '_we_dkexpress_fulfillment_id' );
		if ( ! $fulfillment_id ) {
			return;
		}

		try {
			$fulfillment = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment( $fulfillment_id );
			if ( 'fulfilled' !== $fulfillment->get_status() ) {
				$fulfillment->set_status( 'fulfilled' );
				$fulfillment->save();
				do_action( 'woocommerce_fulfillment_created_notification', $order->get_id(), $fulfillment, $order );
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DK Express Fulfillments: Failed to update fulfillment for order #' . $order->get_id() . ' — ' . $e->getMessage() );
			}
		}
	}
}

/**
 * DK Express shipping provider for WooCommerce Fulfillments.
 */
class DKExpress_Fulfillment_Shipping_Provider extends \Automattic\WooCommerce\Admin\Features\Fulfillments\Providers\AbstractShippingProvider {

	public function get_key(): string {
		return 'dkexpress';
	}

	public function get_name(): string {
		return 'DK Express';
	}

	public function get_icon(): string {
		return '';
	}

	public function get_tracking_url( string $tracking_number ): string {
		return 'https://www.dkexpresscourier.gr/el/courier/voucher/%CE%91%CE%BD%CE%B1%CE%B6%CE%AE%CF%84%CE%B7%CF%83%CE%B7Voucher.html?r18p01=' . $tracking_number;
	}

	public function get_shipping_from_countries(): array {
		return [ 'GR' ];
	}

	public function get_shipping_to_countries(): array {
		return [ 'GR' ];
	}
}
