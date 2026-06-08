<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for Open Claw capability checks.
 *
 * Every can_*() method maps 1:1 to a WordPress option registered in SettingsPage.
 * Update permissions include a migration shim: if the granular option has never
 * been saved, the legacy broseph_allow_plugin_theme_updates value is used instead.
 */
class PermissionsService {

	// ── Content ───────────────────────────────────────────────────────────────

	public function can_publish_pages(): bool {
		return (bool) get_option( 'broseph_allow_publish_pages', false );
	}

	public function can_live_edit(): bool {
		return (bool) get_option( 'broseph_allow_live_edits', false );
	}

	public function can_delete_drafts(): bool {
		return (bool) get_option( 'broseph_allow_delete_drafts', false );
	}

	public function can_create_gitpress_pages(): bool {
		return (bool) get_option( 'broseph_allow_gitpress_pages', true );
	}

	public function can_create_divi_template_pages(): bool {
		return (bool) get_option( 'broseph_allow_divi_template_pages', true );
	}

	public function can_edit_divi_code_modules(): bool {
		return (bool) get_option( 'broseph_allow_divi_code_module_edits', true );
	}

	public function can_edit_seo_meta(): bool {
		return (bool) get_option( 'broseph_allow_seo_meta_edits', true );
	}

	// ── Code ─────────────────────────────────────────────────────────────────

	public function can_use_js_snippets(): bool {
		return (bool) get_option( 'broseph_allow_js_snippets', false );
	}

	// PHP execution: permanently blocked — no option, no override.
	public function can_execute_php(): false {
		return false;
	}

	// ── Forms & mail ──────────────────────────────────────────────────────────

	public function can_send_mail_tests(): bool {
		return (bool) get_option( 'broseph_allow_mail_tests', true );
	}

	public function can_submit_form_tests(): bool {
		return (bool) get_option( 'broseph_allow_form_submission_tests', false );
	}

	public function can_use_form_mail_fallback(): bool {
		return (bool) get_option( 'broseph_allow_form_test_mail_fallback', true );
	}

	// ── Updates ───────────────────────────────────────────────────────────────

	/**
	 * Reads the granular option if it has been saved; otherwise falls back to
	 * the legacy broseph_allow_plugin_theme_updates value.
	 * Using null as the default distinguishes "never set" from "explicitly off".
	 */
	public function can_update_plugins(): bool {
		$value = get_option( 'broseph_allow_plugin_updates', null );
		if ( null !== $value ) {
			return (bool) $value;
		}
		return (bool) get_option( 'broseph_allow_plugin_theme_updates', false );
	}

	public function can_update_themes(): bool {
		$value = get_option( 'broseph_allow_theme_updates', null );
		if ( null !== $value ) {
			return (bool) $value;
		}
		return (bool) get_option( 'broseph_allow_plugin_theme_updates', false );
	}

	public function can_update_active_theme(): bool {
		return (bool) get_option( 'broseph_allow_active_theme_updates', false );
	}

	public function can_update_inactive_plugins(): bool {
		return (bool) get_option( 'broseph_allow_inactive_plugin_updates', false );
	}

	public function can_update_core(): bool {
		return (bool) get_option( 'broseph_allow_core_updates', false );
	}

	// Broseph self-update: permanently blocked.
	public function can_self_update(): false {
		return false;
	}

	// ── Summary ───────────────────────────────────────────────────────────────

	public function get_permissions_summary(): array {
		return array(
			'can_publish_pages'             => $this->can_publish_pages(),
			'can_live_edit'                 => $this->can_live_edit(),
			'can_delete_drafts'             => $this->can_delete_drafts(),
			'can_create_gitpress_pages'     => $this->can_create_gitpress_pages(),
			'can_create_divi_template_pages' => $this->can_create_divi_template_pages(),
			'can_edit_divi_code_modules'    => $this->can_edit_divi_code_modules(),
			'can_edit_seo_meta'             => $this->can_edit_seo_meta(),
			'can_use_js_snippets'           => $this->can_use_js_snippets(),
			'can_send_mail_tests'           => $this->can_send_mail_tests(),
			'can_submit_form_tests'         => $this->can_submit_form_tests(),
			'can_use_form_mail_fallback'    => $this->can_use_form_mail_fallback(),
			'can_update_plugins'            => $this->can_update_plugins(),
			'can_update_themes'             => $this->can_update_themes(),
			'can_update_active_theme'       => $this->can_update_active_theme(),
			'can_update_inactive_plugins'   => $this->can_update_inactive_plugins(),
			'can_update_core'               => $this->can_update_core(),
			'can_execute_php'               => false,
			'can_self_update'               => false,
		);
	}
}
