<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	private const OPTION_GROUP = 'broseph_settings';
	private const PAGE_SLUG    = 'broseph-settings';

	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
			__( 'Safety & Behavior Toggles', 'broseph' ),
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap broseph-wrap">
			<h1 class="broseph-page-title"><?php esc_html_e( 'Settings', 'broseph' ); ?></h1>
			<?php settings_errors(); ?>

			<div class="broseph-card broseph-card-standalone">
				<h2 class="broseph-card-title"><?php esc_html_e( 'Safety & Behavior Toggles', 'broseph' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Control what Broseph is allowed to do on this site.', 'broseph' ); ?></p>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( self::PAGE_SLUG );
					submit_button( __( 'Save Settings', 'broseph' ) );
					?>
				</form>
			</div>
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
}
