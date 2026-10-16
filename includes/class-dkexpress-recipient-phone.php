<?php
/**
 * Recipient phone lookup for vouchers.
 *
 * Order meta keys from the «Customer Phone Field» setting come first, then the
 * WebExpert SMS cellphone (when that plugin adds its own field), then the native
 * shipping and billing phone. Shared by the WebExpert courier plugins; each one
 * ships its own copy under its own class name so versions never collide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WebExpert_DKExpress_Recipient_Phone' ) ) {

	class WebExpert_DKExpress_Recipient_Phone {

		const OPTION = 'dkexpress_phone_meta_keys';

		/**
		 * Best mobile number for the courier's SMS / mobile field.
		 *
		 * @param WC_Order $order Order.
		 */
		public static function mobile( $order ): string {
			$candidates = self::candidates( $order );
			foreach ( $candidates as $candidate ) {
				if ( self::is_usable( $candidate ) ) {
					return $candidate;
				}
			}

			return $candidates[0] ?? '';
		}

		/**
		 * Landline / main phone: the native shipping or billing phone, else the mobile.
		 *
		 * @param WC_Order $order Order.
		 */
		public static function phone( $order ): string {
			foreach ( [ $order->get_shipping_phone(), $order->get_billing_phone() ] as $candidate ) {
				$candidate = trim( (string) $candidate );
				if ( self::is_usable( $candidate ) ) {
					return $candidate;
				}
			}

			return self::mobile( $order );
		}

		/**
		 * Non-empty raw phone numbers on the order, best first.
		 *
		 * @param WC_Order $order Order.
		 * @return string[]
		 */
		public static function candidates( $order ): array {
			$candidates = [];

			foreach ( self::meta_keys() as $key ) {
				$value = $order->get_meta( $key );
				if ( '' === trim( (string) $value ) && '_' !== $key[0] ) {
					// «billing_mobile_phone» typed for the «_billing_mobile_phone» meta.
					$value = $order->get_meta( '_' . $key );
				}
				$candidates[] = $value;
			}

			// WebExpert SMS: its own cellphone field, same lookup as its SMS recipient.
			if ( class_exists( 'Webexpert_Woocommerce_Sms' ) && 'yes' === get_option( 'webex_woocommerce_sms_add_cellphone' ) ) {
				$candidates[] = $order->get_meta( '_billing_cellphone' );
				$candidates[] = $order->get_meta( '_wc_other/webexpert-sms/cellphone' );
			}

			$candidates[] = $order->get_meta( 'billing_cellphone' );
			$candidates[] = $order->get_shipping_phone();
			$candidates[] = $order->get_billing_phone();

			$candidates = array_map( static fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : '', $candidates );

			return array_values( array_unique( array_filter( $candidates, 'strlen' ) ) );
		}

		/**
		 * Order meta keys from the setting, in order.
		 *
		 * @return string[]
		 */
		public static function meta_keys(): array {
			return self::split( (string) get_option( self::OPTION, '' ) );
		}

		/**
		 * Sanitize callback for the setting.
		 *
		 * @param mixed $value Raw value.
		 */
		public static function sanitize( $value ): string {
			return implode( ', ', self::split( is_string( $value ) ? $value : '' ) );
		}

		private static function split( string $raw ): array {
			$keys = array_map( static fn( $k ) => sanitize_text_field( trim( $k ) ), preg_split( '/[\s,]+/', $raw ) );

			return array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
		}

		private static function is_usable( string $phone ): bool {
			return strlen( preg_replace( '/\D+/', '', $phone ) ) >= 8;
		}
	}
}
