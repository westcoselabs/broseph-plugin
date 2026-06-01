<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConnectionPage {

	private const NONCE_ACTION = 'broseph_regenerate_secret';
	private const PAGE_SLUG    = 'broseph-connection';

	public function init(): void {
		add_action( 'admin_post_broseph_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );
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

		$site_id    = (string) get_option( 'broseph_site_id', '' );
		$raw_secret = (string) get_option( 'broseph_shared_secret', '' );
		$has_secret = '' !== $raw_secret;
		/*
		 * The masked display shows 40 bullets + last 6 chars.
		 * The full secret is placed in data-secret for manage_options users only —
		 * never emitted in public routes, REST responses, or script localisation.
		 */
		$masked   = $has_secret ? str_repeat( '•', 40 ) . substr( $raw_secret, -6 ) : '—';
		$rest_base = rest_url( 'broseph/v1' );
		?>
		<div class="wrap broseph-wrap" id="broseph-connection-wrap"
			data-site-id="<?php echo esc_attr( $site_id ); ?>"
			data-site-url="<?php echo esc_attr( site_url() ); ?>"
			data-rest-base="<?php echo esc_attr( $rest_base ); ?>">

			<h1 class="broseph-page-title"><?php esc_html_e( 'Connection', 'broseph' ); ?></h1>

			<div class="broseph-notice-box">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Use these credentials inside Open Claw. Never commit the shared secret to GitHub or expose it publicly.', 'broseph' ); ?>
			</div>

			<details class="broseph-help-box">
				<summary class="broseph-help-summary">
					<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
					<?php esc_html_e( 'How to connect Open Claw', 'broseph' ); ?>
				</summary>
				<div class="broseph-help-content">
					<ol>
						<li><?php esc_html_e( 'Copy the Open Claw ENV template using the button below.', 'broseph' ); ?></li>
						<li><?php esc_html_e( 'Paste it into your Open Claw Docker environment file.', 'broseph' ); ?></li>
						<li><?php esc_html_e( 'Click Reveal, then Copy Secret, and replace BROSEPH_SHARED_SECRET in the file.', 'broseph' ); ?></li>
						<li><?php esc_html_e( 'Restart the Open Claw container.', 'broseph' ); ?></li>
						<li><?php esc_html_e( 'Run the Broseph status test from Open Claw to confirm the connection.', 'broseph' ); ?></li>
					</ol>
					<p class="broseph-help-warning">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'Do not commit the shared secret to GitHub or share it in screenshots.', 'broseph' ); ?>
					</p>
				</div>
			</details>

			<div class="broseph-card broseph-card-standalone">
				<h2 class="broseph-card-title"><?php esc_html_e( 'API Credentials', 'broseph' ); ?></h2>

				<div id="broseph-copy-status" class="broseph-copy-status" role="status" aria-live="polite" aria-atomic="true"></div>

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
									<!--
										data-secret is only rendered for manage_options users.
										It never appears in REST responses, public pages, or script localisation.
									-->
									<code id="broseph-secret-display"
										class="broseph-secret-masked"
										data-masked="<?php echo esc_attr( $masked ); ?>"
										data-secret="<?php echo esc_attr( $raw_secret ); ?>"
										aria-live="polite"
										aria-label="<?php esc_attr_e( 'Shared secret value', 'broseph' ); ?>">
										<?php echo esc_html( $masked ); ?>
									</code>
									<?php if ( $has_secret ) : ?>
									<div class="broseph-cred-actions">
										<button type="button" id="broseph-reveal-btn"
											class="button button-small"
											aria-expanded="false"
											aria-controls="broseph-secret-display">
											<?php esc_html_e( 'Reveal', 'broseph' ); ?>
										</button>
										<button type="button" id="broseph-copy-secret-btn"
											class="button button-small"
											disabled
											aria-disabled="true">
											<?php esc_html_e( 'Copy Secret', 'broseph' ); ?>
										</button>
									</div>
									<?php endif; ?>
								</div>
								<div id="broseph-reveal-countdown" class="broseph-countdown" hidden aria-live="polite"></div>
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
							<?php esc_html_e( 'Copies BROSEPH_SITE_URL, BROSEPH_REST_BASE, BROSEPH_SITE_ID, and BROSEPH_SHARED_SECRET as .env lines. Reveal the secret first to include the real value, or paste REVEAL_SECRET_FIRST as a placeholder.', 'broseph' ); ?>
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
		<?php
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
