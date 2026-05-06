<?php
/**
 * Action Scheduler–based delivery and exponential backoff retries.
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Retry {

	public const ACTION_DELIVER = 'pos_woo_sync_deliver_order';
	public const GROUP          = 'pos-woo-sync';

	public static function init(): void {
		add_action( self::ACTION_DELIVER, array( __CLASS__, 'process_order' ), 10, 1 );
		add_action( self::ACTION_DELIVER . '_cron', array( __CLASS__, 'process_order' ), 10, 1 );
	}

	/**
	 * Queue async delivery (unique per order to avoid duplicate queue entries).
	 */
	public static function schedule( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::ACTION_DELIVER,
				array( 'order_id' => $order_id ),
				self::GROUP,
				true
			);
			return;
		}

		if ( ! wp_next_scheduled( self::ACTION_DELIVER . '_cron', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 2, self::ACTION_DELIVER . '_cron', array( $order_id ) );
		}
	}

	/**
	 * @param mixed $order_id Order ID from action args.
	 */
	public static function process_order( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! POS_Woo_Sync_Plugin::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			POS_Woo_Sync_Logger::error( 'Order not found for sync', array( 'order_id' => $order_id ) );
			return;
		}

		$synced_at = $order->get_meta( '_pos_woo_sync_synced_at', true );
		if ( is_string( $synced_at ) && $synced_at !== '' ) {
			return;
		}

		$settings = POS_Woo_Sync_Plugin::get_settings();
		$max      = max( 1, min( 12, (int) $settings['max_retry_attempts'] ) );
		$attempts = (int) $order->get_meta( '_pos_woo_sync_attempts', true );

		$payment_status = self::infer_payment_status( $order );
		$payload        = POS_Woo_Sync_Order_Payload::build( $order, $payment_status );
		$result         = POS_Woo_Sync_API_Client::send_order( $payload );

		if ( $result['ok'] ) {
			$duplicate = is_array( $result['data'] ) && ! empty( $result['data']['duplicate'] );
			$order->update_meta_data( '_pos_woo_sync_synced_at', gmdate( 'c' ) );
			$order->update_meta_data( '_pos_woo_sync_last_http_code', $result['code'] );
			$order->delete_meta_data( '_pos_woo_sync_last_error' );
			$order->delete_meta_data( '_pos_woo_sync_attempts' );
			$order->save();

			POS_Woo_Sync_Logger::info(
				$duplicate ? 'POS sync acknowledged duplicate' : 'POS sync success',
				array(
					'order_id' => $order_id,
					'code'     => $result['code'],
				)
			);
			return;
		}

		$attempts++;
		$order->update_meta_data( '_pos_woo_sync_attempts', (string) $attempts );
		$order->update_meta_data(
			'_pos_woo_sync_last_error',
			wp_strip_all_tags( $result['body'] )
		);
		$order->update_meta_data( '_pos_woo_sync_last_http_code', $result['code'] );
		$order->save();

		POS_Woo_Sync_Logger::error(
			'POS sync failed',
			array(
				'order_id'  => $order_id,
				'attempt'   => $attempts,
				'http_code' => $result['code'],
				'body'      => substr( $result['body'], 0, 500 ),
			)
		);

		if ( $attempts >= $max ) {
			return;
		}

		$delay = min( 3600, (int) pow( 2, $attempts ) * 60 );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delay,
				self::ACTION_DELIVER,
				array( 'order_id' => $order_id ),
				self::GROUP
			);
		} else {
			wp_schedule_single_event( time() + $delay, self::ACTION_DELIVER . '_cron', array( $order_id ) );
		}
	}

	private static function infer_payment_status( WC_Order $order ): string {
		$st = $order->get_status();
		if ( in_array( $st, array( 'processing', 'completed' ), true ) ) {
			return 'paid';
		}
		if ( $order->is_paid() ) {
			return 'paid';
		}
		return $st;
	}
}
