<?php
/**
 * WooCommerce hooks → queue POS sync (no Square polling).
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Hooks {

	public static function init(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_status_processing' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_status_completed' ), 20, 1 );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_payment_complete( $order_id ): void {
		self::maybe_queue( (int) $order_id, 'payment_complete' );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_status_processing( $order_id ): void {
		self::maybe_queue( (int) $order_id, 'status_processing' );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_status_completed( $order_id ): void {
		self::maybe_queue( (int) $order_id, 'status_completed' );
	}

	private static function maybe_queue( int $order_id, string $context ): void {
		if ( $order_id <= 0 || ! POS_Woo_Sync_Plugin::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( '_pos_woo_sync_synced_at', true ) ) {
			return;
		}

		// Only push orders that are actually paid (Square marks processing/completed after capture).
		if ( ! $order->is_paid() && ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}

		POS_Woo_Sync_Logger::info(
			'Queued POS sync',
			array(
				'order_id' => $order_id,
				'context'  => $context,
			)
		);

		POS_Woo_Sync_Retry::schedule( $order_id );
	}
}
