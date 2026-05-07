<?php
/**
 * HTTPS POST to POS with optional HMAC (must match Laravel wc_inbound_sync.require_hmac).
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_API_Client {

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, code:int, body:string, data:?array}
	 */
	public static function send_order( array $payload ): array {
		$settings = POS_Woo_Sync_Plugin::get_settings();
		$url      = esc_url_raw( $settings['api_url'] );
		$secret   = $settings['api_secret'];

		if ( $url === '' || $secret === '' ) {
			return array(
				'ok'   => false,
				'code' => 0,
				'body' => 'Missing API URL or secret',
				'data' => null,
			);
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
				'body' => 'JSON encode failed',
				'data' => null,
			);
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'DollydustcountryPOS-Woo-Sync/' . POS_WOO_SYNC_VERSION . '; ' . home_url( '/' ),
		);

		$headers['Authorization'] = 'Bearer ' . $secret;

		if ( $settings['enable_hmac'] === '1' ) {
			$ts                = (string) time();
			$headers['X-WC-Sync-Timestamp'] = $ts;
			$headers['X-WC-Sync-Signature'] = hash_hmac( 'sha256', $ts . "\n" . $body, $secret );
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => 20,
			'redirection' => 3,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $body,
			'sslverify'   => $settings['sslverify'] === '1',
			'data_format' => 'body',
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			return array(
				'ok'   => false,
				'code' => 0,
				'body' => $msg,
				'data' => null,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$data = is_array( $data ) ? $data : null;

		$ok = $code >= 200 && $code < 300;

		return array(
			'ok'   => $ok,
			'code' => $code,
			'body' => $raw,
			'data' => $data,
		);
	}
}
