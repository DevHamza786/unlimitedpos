<?php
/**
 * Settings: POS API URL, secret, business ID, enable sync.
 *
 * @package UltimatePOS_Woo_Sync
 */

defined( 'ABSPATH' ) || exit;

final class POS_Woo_Sync_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'POS Order Sync', 'ultimatepos-woo-sync' ),
			__( 'POS Order Sync', 'ultimatepos-woo-sync' ),
			'manage_woocommerce',
			'ultimatepos-woo-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'pos_woo_sync_settings_group',
			POS_Woo_Sync_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * @param array<string,string> $input Raw settings.
	 * @return array<string,string>
	 */
	public static function sanitize( $input ): array {
		$prev   = POS_Woo_Sync_Plugin::get_settings();
		$out    = $prev;
		$input  = is_array( $input ) ? $input : array();

		$out['enabled']     = isset( $input['enabled'] ) && $input['enabled'] === '1' ? '1' : '0';
		$out['api_url']     = isset( $input['api_url'] ) ? esc_url_raw( trim( (string) $input['api_url'] ) ) : '';
		$out['business_id'] = isset( $input['business_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $input['business_id'] ) : '';
		$out['sslverify']   = isset( $input['sslverify'] ) && $input['sslverify'] === '1' ? '1' : '0';
		$out['enable_hmac'] = isset( $input['enable_hmac'] ) && $input['enable_hmac'] === '1' ? '1' : '0';

		if ( isset( $input['max_retry_attempts'] ) ) {
			$out['max_retry_attempts'] = (string) max( 1, min( 12, (int) $input['max_retry_attempts'] ) );
		}

		$secret_in = isset( $input['api_secret'] ) ? trim( (string) $input['api_secret'] ) : '';
		if ( $secret_in !== '' ) {
			$out['api_secret'] = $secret_in;
		}

		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s     = POS_Woo_Sync_Plugin::get_settings();
		$nonce = wp_nonce_field( 'pos_woo_sync_save', '_wpnonce', true, false );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dollydustcountry POS — WooCommerce order sync', 'ultimatepos-woo-sync' ); ?></h1>
			<p><?php esc_html_e( 'On successful payment, order payloads are sent to your POS API (async + retries). Uses WooCommerce hooks only — no Square polling.', 'ultimatepos-woo-sync' ); ?></p>

			<form method="post" action="options.php">
				<?php
				echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				settings_fields( 'pos_woo_sync_settings_group' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable sync', 'ultimatepos-woo-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], '1' ); ?> />
								<?php esc_html_e( 'Send orders to POS when payment completes', 'ultimatepos-woo-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pos_api_url"><?php esc_html_e( 'POS API URL', 'ultimatepos-woo-sync' ); ?></label></th>
						<td>
							<input type="url" class="large-text" id="pos_api_url" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>" placeholder="https://your-pos.com/api/wc-inbound/orders" />
							<p class="description"><?php esc_html_e( 'Full URL to POST JSON (Dollydustcountry POS: /api/wc-inbound/orders).', 'ultimatepos-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pos_api_secret"><?php esc_html_e( 'API secret', 'ultimatepos-woo-sync' ); ?></label></th>
						<td>
							<input type="password" class="large-text" id="pos_api_secret" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[api_secret]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep current secret', 'ultimatepos-woo-sync' ); ?>" />
							<p class="description"><?php esc_html_e( 'Must match WC_INBOUND_SYNC_SECRET on the POS server. Stored in the WordPress database — restrict DB access.', 'ultimatepos-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pos_business_id"><?php esc_html_e( 'POS business ID', 'ultimatepos-woo-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="pos_business_id" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[business_id]" value="<?php echo esc_attr( $s['business_id'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verify SSL', 'ultimatepos-woo-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[sslverify]" value="1" <?php checked( $s['sslverify'], '1' ); ?> />
								<?php esc_html_e( 'Verify TLS certificate (disable only for local dev)', 'ultimatepos-woo-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'HMAC signatures', 'ultimatepos-woo-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[enable_hmac]" value="1" <?php checked( $s['enable_hmac'], '1' ); ?> />
								<?php esc_html_e( 'Send X-WC-Sync-Timestamp + X-WC-Signature (enable WC_INBOUND_SYNC_REQUIRE_HMAC on POS too)', 'ultimatepos-woo-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pos_max_retry"><?php esc_html_e( 'Max retry attempts', 'ultimatepos-woo-sync' ); ?></label></th>
						<td>
							<input type="number" min="1" max="12" id="pos_max_retry" name="<?php echo esc_attr( POS_Woo_Sync_Plugin::OPTION ); ?>[max_retry_attempts]" value="<?php echo esc_attr( $s['max_retry_attempts'] ); ?>" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Order meta (debug)', 'ultimatepos-woo-sync' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.5em;">
				<li><code>_pos_woo_sync_synced_at</code> — <?php esc_html_e( 'ISO time when POS accepted the order', 'ultimatepos-woo-sync' ); ?></li>
				<li><code>_pos_woo_sync_last_error</code> — <?php esc_html_e( 'Last failure body / message', 'ultimatepos-woo-sync' ); ?></li>
				<li><code>_pos_woo_sync_attempts</code> — <?php esc_html_e( 'Retry counter', 'ultimatepos-woo-sync' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Logs: WooCommerce → Status → Logs → source “ultimatepos-woo-sync”.', 'ultimatepos-woo-sync' ); ?></p>
		</div>
		<?php
	}
}
