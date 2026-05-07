<?php
/**
 * Bootstrap: load WooCommerce-dependent pieces after WC loads.
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Plugin {

	const OPTION = 'pos_woo_sync_settings';

	public static function init(): void {
		POS_Woo_Sync_Admin::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		POS_Woo_Sync_Hooks::init();
		POS_Woo_Sync_Retry::init();
		POS_Woo_Sync_Webhook_Bridge::init();
	}

	public static function activate(): void {
		if ( ! get_option( self::OPTION, false ) ) {
			add_option(
				self::OPTION,
				array(
					'enabled'            => '0',
					'api_url'            => '',
					'api_secret'         => '',
					'business_id'        => '',
					'sslverify'          => '1',
					'enable_hmac'        => '0',
					'max_retry_attempts' => '5',
				)
			);
		}
	}

	public static function get_settings(): array {
		$defaults = array(
			'enabled'            => '0',
			'api_url'            => '',
			'api_secret'         => '',
			'business_id'        => '',
			'sslverify'          => '1',
			'enable_hmac'        => '0',
			'max_retry_attempts' => '5',
		);
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	public static function is_enabled(): bool {
		$s = self::get_settings();
		return $s['enabled'] === '1'
			&& $s['api_url'] !== ''
			&& $s['api_secret'] !== ''
			&& $s['business_id'] !== '';
	}

	public static function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Dollydustcountry POS WooCommerce Order Sync requires WooCommerce to be installed and active.', 'ultimatepos-woo-sync' );
		echo '</p></div>';
	}
}
