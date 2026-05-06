<?php
/**
 * Future: register REST routes for Square webhooks or WP pseudo-webhooks.
 * Keep lightweight — delegate to the same queue as WooCommerce hooks.
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Webhook_Bridge {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'pos-woo-sync/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return new WP_REST_Response(
						array(
							'ok'      => true,
							'plugin'  => POS_WOO_SYNC_VERSION,
							'wc'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
							'sync_on' => POS_Woo_Sync_Plugin::is_enabled(),
						),
						200
					);
				},
				'permission_callback' => '__return_true',
			)
		);

		/**
		 * Example future endpoint: POST with Square signature validation,
		 * map event → WC order ID → POS_Woo_Sync_Retry::schedule( $id ).
		 */
		register_rest_route(
			'pos-woo-sync/v1',
			'/square-placeholder',
			array(
				'methods'             => 'POST',
				'callback'            => static function () {
					return new WP_REST_Response(
						array(
							'message' => 'Not implemented. Use WooCommerce hooks or extend this callback.',
						),
						501
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}
