<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	private const OPTION_GROUP = 'broseph_settings';
	private const PAGE_SLUG    = 'broseph';
	private const NONCE_ACTION = 'broseph_regenerate_secret';

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_broseph_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );
	}

	public function add_menu_page(): void {
		add_options_page(
			__( 'Broseph Settings', 'broseph' ),
			__( 'Broseph', 'broseph' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'broseph_allow_live_edits',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'broseph_allow_js_snippets',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'broseph_prefer_gitpress_landing_pages',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		add_settings_section(
			'broseph_toggles',
			__( 'Feature Toggles', 'broseph' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'broseph_allow_live_edits',
			__( 'Allow Live Edits', 'broseph' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'broseph_toggles',
			array( 'option' => 'broseph_allow_live_edits' )
		);

		add_settings_field(
			'broseph_allow_js_snippets',
			__( 'Allow JS Snippets', 'broseph' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'broseph_toggles',
			array( 'option' => 'broseph_allow_js_snippets' )
		);

		add_settings_field(
			'broseph_prefer_gitpress_landing_pages',
			__( 'Prefer GitPress Landing Pages', 'broseph' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'broseph_toggles',
			array( 'option' => 'broseph_prefer_gitpress_landing_pages' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'broseph-admin',
			BROSEPH_PLUGIN_URL . 'assets/admin.css',
			array(),
			BROSEPH_VERSION
		);
		wp_enqueue_script(
			'broseph-admin',
			BROSEPH_PLUGIN_URL . 'assets/admin.js',
			array(),
			BROSEPH_VERSION,
			true
		);
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

		$theme       = wp_get_theme();
		$site_id     = esc_html( (string) get_option( 'broseph_site_id', '' ) );
		$raw_secret  = (string) get_option( 'broseph_shared_secret', '' );
		$masked      = $raw_secret ? str_repeat( '•', 40 ) . esc_html( substr( $raw_secret, -6 ) ) : '—';
		?>
		<div class="wrap broseph-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="broseph-info-table-wrap">
				<h2><?php esc_html_e( 'Site Information', 'broseph' ); ?></h2>
				<table class="widefat broseph-info-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site ID', 'broseph' ); ?></th>
							<td><code><?php echo $site_id; ?></code></td>
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
							<th scope="row"><?php esc_html_e( 'Plugin Version', 'broseph' ); ?></th>
							<td><?php echo esc_html( BROSEPH_VERSION ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site URL', 'broseph' ); ?></th>
							<td><?php echo esc_html( site_url() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Home URL', 'broseph' ); ?></th>
							<td><?php echo esc_html( home_url() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WordPress Version', 'broseph' ); ?></th>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'PHP Version', 'broseph' ); ?></th>
							<td><?php echo esc_html( PHP_VERSION ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Active Theme', 'broseph' ); ?></th>
							<td><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'broseph' ) );
				?>
			</form>
		</div>
		<?php
	}

	public function render_checkbox_field( array $args ): void {
		$option  = $args['option'];
		$checked = checked( '1', get_option( $option ), false );
		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s>',
			esc_attr( $option ),
			$checked
		);
	}

	public function sanitize_checkbox( mixed $value ): string {
		return ( '1' === (string) $value || true === $value ) ? '1' : '0';
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
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
