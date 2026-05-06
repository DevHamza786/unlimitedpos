<?php
/**
 * Build JSON payload from WC_Order for the POS API.
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Order_Payload {

	/**
	 * @param WC_Order $order Order instance.
	 * @param string   $payment_status Human-readable / gateway status hint.
	 * @return array<string,mixed>
	 */
	public static function build( WC_Order $order, string $payment_status = 'paid' ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$items[] = array(
				'id'         => (string) $item_id,
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => (float) $item->get_quantity(),
				'subtotal'   => (float) $order->get_item_subtotal( $item, false, false ),
				'total'      => (float) $item->get_total(),
				'tax'        => (float) $item->get_total_tax(),
				'product_id' => $product ? (string) $product->get_id() : '',
			);
		}

		return array(
			'business_id'     => (int) POS_Woo_Sync_Plugin::get_settings()['business_id'],
			'order_id'        => (string) $order->get_id(),
			'order_key'       => $order->get_order_key(),
			'transaction_id'  => self::resolve_transaction_id( $order ),
			'payment_status'  => $payment_status,
			'currency'        => $order->get_currency(),
			'total_amount'    => (float) $order->get_total(),
			'tax'             => (float) $order->get_total_tax(),
			'customer'        => array(
				'name'  => trim( $order->get_formatted_billing_full_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			),
			'items'           => $items,
			'created_at'      => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : gmdate( 'c' ),
		);
	}

	/**
	 * Square / other gateways store IDs in order meta with varying keys.
	 */
	public static function resolve_transaction_id( WC_Order $order ): string {
		$tid = $order->get_transaction_id();
		if ( is_string( $tid ) && $tid !== '' ) {
			return $tid;
		}

		$meta_keys = array(
			'_square_charge_id',
			'_square_payment_id',
			'square_payment_id',
			'_wc_square_payment_id',
			'_payment_intent_id',
			'_stripe_intent_id',
			'_stripe_charge_id',
		);

		foreach ( $meta_keys as $key ) {
			$v = $order->get_meta( $key, true );
			if ( is_string( $v ) && $v !== '' ) {
				return $v;
			}
			if ( is_numeric( $v ) ) {
				return (string) $v;
			}
		}

		return '';
	}
}
