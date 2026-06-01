<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConnectionPage {

	private const NONCE_ACTION        = 'broseph_regenerate_secret';
	private const REVEAL_NONCE_ACTION = 'broseph_reveal_secret';
	private const PAGE_SLUG           = 'broseph-connection';

	public function init(): void {
		add_action( 'admin_post_broseph_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );
		add_action( 'wp_ajax_broseph_reveal_secret',        array( $this, 'handle_reveal_secret' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) && 'secret' === $_GET['updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Shared secret regenerated. Update Open Claw with the new value.', 'broseph' )
				. '</p></div>';
		}

		$site_id      = (string) get_option( 'broseph_site_id', '' );
		$raw_secret   = (string) get_option( 'broseph_shared_secret', '' );
		$has_secret   = '' !== $raw_secret;
		// Full secret never emitted in HTML — only last-6 tail after bullets.
		$masked       = $has_secret ? str_repeat( '•', 40 ) . substr( $raw_secret, -6 ) : '—';
		$rest_base    = rest_url( 'broseph/v1' );
		$reveal_nonce = wp_create_nonce( self::REVEAL_NONCE_ACTION );
		?>
		<div class="wrap broseph-wrap" id="broseph-connection-wrap">
			<h1 class="broseph-page-title"><?php esc_html_e( 'Connection', 'broseph' ); ?></h1>

			<div class="broseph-notice-box">
				<span class="dashicons dashicons-lock"></span>
				<?php esc_html_e( 'Use these credentials inside Open Claw. Never commit the shared secret to GitHub or expose it publicly.', 'broseph' ); ?>
			</div>

			<div class="broseph-card broseph-card-standalone">
				<h2 class="broseph-card-title"><?php esc_html_e( 'API Credentials', 'broseph' ); ?></h2>
				<table class="widefat broseph-info-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site ID', 'broseph' ); ?></th>
							<td>
								<div class="broseph-cred-row">
									<code id="broseph-site-id"><?php echo $site_id ? esc_html( $site_id ) : '—'; ?></code>
									<?php if ( $site_id ) : ?>
									<div class="broseph-cred-actions">
										<button type="button" id="broseph-copy-siteid-btn" class="button button-small">
											<?php esc_html_e( 'Copy', 'broseph' ); ?>
										</button>
									</div>
									<?php endif; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shared Secret', 'broseph' ); ?></th>
							<td>
								<div class="broseph-cred-row">
									<code id="broseph-secret-display"
										class="broseph-secret-masked"
										data-masked="<?php echo esc_attr( $masked ); ?>">
										<?php echo esc_html( $masked ); ?>
									</code>
									<?php if ( $has_secret ) : ?>
									<div class="broseph-cred-actions">
										<button type="button" id="broseph-reveal-btn" class="button button-small">
											<?php esc_html_e( 'Reveal', 'broseph' ); ?>
										</button>
										<button type="button" id="broseph-copy-secret-btn" class="button button-small" disabled>
											<?php esc_html_e( 'Copy Secret', 'broseph' ); ?>
										</button>
									</div>
									<?php endif; ?>
								</div>
								<div id="broseph-reveal-countdown" class="broseph-countdown" hidden></div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'REST API Base', 'broseph' ); ?></th>
							<td><code><?php echo esc_html( $rest_base ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site URL', 'broseph' ); ?></th>
							<td><?php echo esc_html( site_url() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugin Version', 'broseph' ); ?></th>
							<td><?php echo esc_html( BROSEPH_VERSION ); ?></td>
						</tr>
					</tbody>
				</table>

				<div class="broseph-cred-footer">
					<?php if ( $site_id && $has_secret ) : ?>
					<div class="broseph-env-copy-wrap">
						<button type="button" id="broseph-copy-env-btn" class="button button-secondary">
							<?php esc_html_e( 'Copy Open Claw ENV Template', 'broseph' ); ?>
						</button>
						<p class="broseph-muted">
							<?php esc_html_e( 'Copies BROSEPH_SITE_URL, BROSEPH_REST_BASE, BROSEPH_SITE_ID, and BROSEPH_SHARED_SECRET as .env lines ready to paste into Open Claw.', 'broseph' ); ?>
						</p>
					</div>
					<?php endif; ?>

					<div class="broseph-regen-wrap">
						<h3 class="broseph-regen-title"><?php esc_html_e( 'Regenerate Secret', 'broseph' ); ?></h3>
						<p class="broseph-muted">
							<?php esc_html_e( 'Generates a new shared secret. You must immediately update Open Claw with the new value — the current connection will break until you do.', 'broseph' ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::NONCE_ACTION, 'broseph_nonce' ); ?>
							<input type="hidden" name="action" value="broseph_regenerate_secret">
							<button type="submit" class="button broseph-regen-btn"
								onclick="return confirm('<?php echo esc_js( __( 'Regenerate the shared secret? Open Claw will need to be updated with the new value.', 'broseph' ) ); ?>')">
								<?php esc_html_e( 'Regenerate Secret', 'broseph' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>

			<div class="broseph-card broseph-card-standalone">
				<h2 class="broseph-card-title"><?php esc_html_e( 'Open Claw Status', 'broseph' ); ?></h2>
				<p class="broseph-muted"><?php esc_html_e( 'Open Claw connection status and last-seen timestamp will appear here once the integration is configured.', 'broseph' ); ?></p>
			</div>
		</div>

		<script>
		/* Broseph connection page config — injected server-side, consumed by admin.js */
		window.brosephConnection = {
			ajaxUrl:  <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:    <?php echo wp_json_encode( $reveal_nonce ); ?>,
			siteId:   <?php echo wp_json_encode( $site_id ); ?>,
			siteUrl:  <?php echo wp_json_encode( site_url() ); ?>,
			restBase: <?php echo wp_json_encode( $rest_base ); ?>
		};
		</script>
		<?php
	}

	/**
	 * AJAX handler — returns the full shared secret to an authenticated manage_options user.
	 * Never logged. Never exposed in REST or default page HTML.
	 */
	public function handle_reveal_secret(): void {
		check_ajax_referer( self::REVEAL_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$secret = (string) get_option( 'broseph_shared_secret', '' );

		if ( '' === $secret ) {
			wp_send_json_error( 'No shared secret is configured.', 404 );
		}

		wp_send_json_success( array( 'secret' => $secret ) );
	}

	public function handle_regenerate_secret(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'broseph' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'broseph_nonce' );

		update_option( 'broseph_shared_secret', \Broseph\Activator::generate_secret(), false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'secret',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
