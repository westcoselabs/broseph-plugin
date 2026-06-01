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
		$masked     = $raw_secret ? str_repeat( '•', 40 ) . substr( $raw_secret, -6 ) : '—';
		?>
		<div class="wrap broseph-wrap">
			<h1 class="broseph-page-title"><?php esc_html_e( 'Connection', 'broseph' ); ?></h1>

			<div class="broseph-notice-box">
				<span class="dashicons dashicons-lock"></span>
				<?php esc_html_e( 'Use these credentials in Open Claw to connect this site to Broseph. Never expose the shared secret publicly.', 'broseph' ); ?>
			</div>

			<div class="broseph-card broseph-card-standalone">
				<h2 class="broseph-card-title"><?php esc_html_e( 'API Credentials', 'broseph' ); ?></h2>
				<table class="widefat broseph-info-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site ID', 'broseph' ); ?></th>
							<td><code><?php echo $site_id ? esc_html( $site_id ) : '—'; ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shared Secret', 'broseph' ); ?></th>
							<td>
								<code class="broseph-secret-masked"><?php echo esc_html( $masked ); ?></code>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:1em;">
									<?php wp_nonce_field( self::NONCE_ACTION, 'broseph_nonce' ); ?>
									<input type="hidden" name="action" value="broseph_regenerate_secret">
									<button type="submit" class="button button-secondary"
										onclick="return confirm('<?php echo esc_js( __( 'Regenerate the shared secret? Open Claw will need to be updated with the new value.', 'broseph' ) ); ?>')">
										<?php esc_html_e( 'Regenerate Secret', 'broseph' ); ?>
									</button>
								</form>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'REST API Base', 'broseph' ); ?></th>
							<td><code><?php echo esc_html( rest_url( 'broseph/v1' ) ); ?></code></td>
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
