<?php
/**
 * Plugin Name: UltimatePOS WooCommerce Order Sync
 * Description: Push successful WooCommerce orders (e.g. Square payments) to your Ultimate POS / custom dashboard API using WooCommerce hooks, with retries and logging.
 * Version: 1.0.0
 * Author: Ultimate POS Integration
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Text Domain: ultimatepos-woo-sync
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'POS_WOO_SYNC_VERSION', '1.0.0' );
define( 'POS_WOO_SYNC_FILE', __FILE__ );
define( 'POS_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'POS_WOO_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-logger.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-order-payload.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-api-client.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-retry.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-webhook-bridge.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-hooks.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-admin.php';
require_once POS_WOO_SYNC_PATH . 'includes/class-pos-woo-sync-plugin.php';

add_action( 'plugins_loaded', array( 'POS_Woo_Sync_Plugin', 'init' ) );

register_activation_hook( __FILE__, array( 'POS_Woo_Sync_Plugin', 'activate' ) );
