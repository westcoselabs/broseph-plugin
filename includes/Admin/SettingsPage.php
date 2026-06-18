<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	private const OPTION_GROUP = 'broseph_settings';

	// All options managed by this page, grouped for registration and rendering.
	private const OPTION_GROUPS = array(
		'content' => array(
			'broseph_allow_publish_pages'          => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_live_edits'              => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_delete_drafts'           => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_gitpress_pages'          => array( 'default' => '1', 'type' => 'boolean' ),
			'broseph_allow_convert_pages_to_gitpress' => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_manage_gitpress_layout'  => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_divi_template_pages'     => array( 'default' => '1', 'type' => 'boolean' ),
			'broseph_allow_divi_code_module_edits'  => array( 'default' => '1', 'type' => 'boolean' ),
			'broseph_allow_seo_meta_edits'          => array( 'default' => '1', 'type' => 'boolean' ),
		),
		'code' => array(
			'broseph_allow_js_snippets'             => array( 'default' => '0', 'type' => 'boolean' ),
		),
		'forms_mail' => array(
			'broseph_allow_mail_tests'              => array( 'default' => '1', 'type' => 'boolean' ),
			'broseph_allow_form_submission_tests'   => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_form_test_mail_fallback' => array( 'default' => '1', 'type' => 'boolean' ),
		),
		'updates' => array(
			'broseph_allow_plugin_updates'          => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_theme_updates'           => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_active_theme_updates'    => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_inactive_plugin_updates' => array( 'default' => '0', 'type' => 'boolean' ),
			'broseph_allow_core_updates'            => array( 'default' => '0', 'type' => 'boolean' ),
		),
		// Legacy + behavioural options kept in the option group so options.php allows saving them.
		'_legacy' => array(
			'broseph_prefer_gitpress_landing_pages' => array( 'default' => '1', 'type' => 'boolean' ),
			'broseph_allow_plugin_theme_updates'    => array( 'default' => '0', 'type' => 'boolean' ),
		),
	);

	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		foreach ( self::OPTION_GROUPS as $options ) {
			foreach ( $options as $option_name => $cfg ) {
				register_setting(
					self::OPTION_GROUP,
					$option_name,
					array(
						'type'              => $cfg['type'],
						'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
						'default'           => $cfg['default'],
					)
				);
			}
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=broseph-settings' );
		?>
		<div class="wrap broseph-wrap">
			<h1 class="broseph-page-title"><?php esc_html_e( 'Permissions', 'broseph' ); ?></h1>
			<?php settings_errors(); ?>

			<p class="description" style="margin-bottom:1.5em;">
				<?php esc_html_e( 'Open Claw reads these permissions from /tools. If a permission is disabled here, the matching endpoint will reject the action even if the API request is signed.', 'broseph' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<!-- ── 1. Content Permissions ──────────────────────────── -->
				<div class="broseph-card broseph-card-standalone">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Content Permissions', 'broseph' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->row( 'broseph_allow_publish_pages', __( 'Allow publishing pages', 'broseph' ),
							__( 'Allows Open Claw to publish drafts when explicitly requested.', 'broseph' ) );
						$this->row( 'broseph_allow_live_edits', __( 'Allow live edits', 'broseph' ),
							__( 'Allows Open Claw to edit existing published pages directly. When disabled, edits create draft copies.', 'broseph' ) );
						$this->row( 'broseph_allow_delete_drafts', __( 'Allow deleting/trashing drafts', 'broseph' ),
							__( 'Allows Open Claw to trash draft pages only. Permanent deletion is never allowed.', 'broseph' ) );
						$this->row( 'broseph_allow_gitpress_pages', __( 'Allow GitPress page creation', 'broseph' ),
							__( 'Allows Open Claw to create draft pages using GitPress page-level shortcode/full-page canvas.', 'broseph' ) );
						$this->row( 'broseph_allow_convert_pages_to_gitpress', __( 'Allow converting existing pages to GitPress', 'broseph' ),
							__( 'Allows Open Claw to convert an existing WordPress page in place from Divi/native content to GitPress page-level rendering.', 'broseph' ) );
						$this->row( 'broseph_allow_manage_gitpress_layout', __( 'Allow managing GitPress header/footer layout', 'broseph' ),
							__( 'Allows Open Claw to read and update the global GitPress Managed header and footer shortcodes.', 'broseph' ) );
						$this->row( 'broseph_allow_divi_template_pages', __( 'Allow Divi template page creation', 'broseph' ),
							__( 'Allows Open Claw to create draft pages from existing Divi templates.', 'broseph' ) );
						$this->row( 'broseph_allow_divi_code_module_edits', __( 'Allow Divi Code Module edits', 'broseph' ),
							__( 'Allows Open Claw to add/update Divi Code Modules. Live edits still require Allow Live Edits.', 'broseph' ) );
						$this->row( 'broseph_allow_seo_meta_edits', __( 'Allow SEO meta edits', 'broseph' ),
							__( 'Allows Open Claw to update SEO titles, meta descriptions, focus keyphrases, and slugs for pages/posts.', 'broseph' ) );
						?>
					</table>
				</div>

				<!-- ── 2. Code Permissions ─────────────────────────────── -->
				<div class="broseph-card broseph-card-standalone">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Code Permissions', 'broseph' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->row( 'broseph_allow_js_snippets', __( 'Allow JS snippets', 'broseph' ),
							__( 'Allows JavaScript inside approved code modules.', 'broseph' ) );
						$this->row_disabled( __( 'PHP execution', 'broseph' ),
							__( 'Permanently disabled — PHP execution in content is never allowed.', 'broseph' ) );
						?>
					</table>
				</div>

				<!-- ── 3. Forms & Mail Permissions ──────────────────────── -->
				<div class="broseph-card broseph-card-standalone">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Forms &amp; Mail Permissions', 'broseph' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->row( 'broseph_allow_mail_tests', __( 'Allow mail tests', 'broseph' ),
							__( 'Allows Open Claw to send controlled wp_mail() test messages.', 'broseph' ) );
						$this->row( 'broseph_allow_form_submission_tests', __( 'Allow contact form submission tests', 'broseph' ),
							__( 'Allows Open Claw to submit controlled test payloads to supported form adapters such as Divi Contact Forms.', 'broseph' ) );
						$this->row( 'broseph_allow_form_test_mail_fallback', __( 'Allow form test mail fallback', 'broseph' ),
							__( 'Allows /forms/full-test to run /mail/test if direct form submission is unsupported.', 'broseph' ) );
						?>
					</table>
				</div>

				<!-- ── 4. Update Permissions ────────────────────────────── -->
				<div class="broseph-card broseph-card-standalone">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Update Permissions', 'broseph' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->row( 'broseph_allow_plugin_updates', __( 'Allow plugin updates', 'broseph' ),
							__( 'Allows Open Claw to update explicitly listed plugins.', 'broseph' ) );
						$this->row( 'broseph_allow_theme_updates', __( 'Allow theme updates', 'broseph' ),
							__( 'Allows Open Claw to update explicitly listed themes.', 'broseph' ) );
						$this->row( 'broseph_allow_active_theme_updates', __( 'Allow active theme updates', 'broseph' ),
							__( 'Allows Open Claw to update the currently active theme. Requires Allow theme updates.', 'broseph' ) );
						$this->row( 'broseph_allow_inactive_plugin_updates', __( 'Allow inactive plugin updates', 'broseph' ),
							__( 'Allows Open Claw to update plugins that are not currently active.', 'broseph' ) );
						$this->row( 'broseph_allow_core_updates', __( 'Allow WordPress core updates', 'broseph' ),
							__( 'Allows Open Claw to apply WordPress core updates. Disabled by default.', 'broseph' ) );
						$this->row_disabled( __( 'Broseph self-update', 'broseph' ),
							__( 'Permanently disabled — Broseph cannot update itself via Open Claw.', 'broseph' ) );
						?>
					</table>
				</div>

				<?php submit_button( __( 'Save Permissions', 'broseph' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function row( string $option, string $label, string $description ): void {
		$value   = get_option( $option, $this->get_default( $option ) );
		$checked = checked( '1', $value, false );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="checkbox" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="1" <?php echo $checked; // phpcs:ignore ?>>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function row_disabled( string $label, string $description ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="checkbox" disabled aria-disabled="true">
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function get_default( string $option ): string {
		foreach ( self::OPTION_GROUPS as $options ) {
			if ( isset( $options[ $option ] ) ) {
				return $options[ $option ]['default'];
			}
		}
		return '0';
	}

	public function sanitize_checkbox( mixed $value ): string {
		return ( '1' === (string) $value || true === $value ) ? '1' : '0';
	}
}
