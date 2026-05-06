<?php
/**
 * Structured logging via WC logger (wp-content/uploads/wc-logs/).
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Logger {

	private const SOURCE = 'ultimatepos-woo-sync';

	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			error_log( '[' . self::SOURCE . "] {$level}: {$message} " . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$logger  = wc_get_logger();
		$payload = array(
			'source' => self::SOURCE,
		);
		if ( ! empty( $context ) ) {
			$payload['context'] = $context;
		}

		switch ( $level ) {
			case 'emergency':
			case 'alert':
			case 'critical':
				$logger->critical( $message, $payload );
				break;
			case 'error':
				$logger->error( $message, $payload );
				break;
			case 'warning':
				$logger->warning( $message, $payload );
				break;
			case 'notice':
			case 'info':
				$logger->info( $message, $payload );
				break;
			default:
				$logger->debug( $message, $payload );
		}
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}
}
