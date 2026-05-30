<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteScanner {

	private const FORM_PLUGINS = array(
		'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
		'gravityforms/gravityforms.php'         => 'Gravity Forms',
		'wpforms-lite/wpforms.php'              => 'WPForms Lite',
		'wpforms/wpforms.php'                   => 'WPForms',
		'fluentform/fluentform.php'             => 'Fluent Forms',
		'ninja-forms/ninja-forms.php'           => 'Ninja Forms',
	);

	private const MAIL_PLUGINS = array(
		'wp-mail-smtp/wp-mail-smtp.php'       => 'WP Mail SMTP',
		'wp-mail-logging/wp-mail-logging.php' => 'WP Mail Logging',
		'fluentsmtp/fluentsmtp.php'           => 'FluentSMTP',
	);

	public function scan(): array {
		// get_plugins() lives in wp-admin and is not autoloaded in REST context.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$all_plugins     = get_plugins();
		$inactive_count  = max( 0, count( $all_plugins ) - count( $active_plugins ) );

		$active_list = array();
		foreach ( $active_plugins as $file ) {
			$data          = $all_plugins[ $file ] ?? array();
			$active_list[] = array(
				'name'        => $data['Name'] ?? $file,
				'plugin_file' => $file,
				'version'     => $data['Version'] ?? null,
			);
		}

		$theme        = wp_get_theme();
		$parent_theme = null;
		if ( $theme->parent() ) {
			$parent = $theme->parent();
			$parent_theme = array(
				'name'    => $parent->get( 'Name' ),
				'version' => $parent->get( 'Version' ),
			);
		}

		return array(
			'site_url'                       => site_url(),
			'home_url'                       => home_url(),
			'wp_version'                     => get_bloginfo( 'version' ),
			'php_version'                    => PHP_VERSION,
			'active_theme'                   => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
			'parent_theme'                   => $parent_theme,
			'is_divi_active'                 => $this->detect_divi( $active_plugins, $theme ),
			'is_gitpress_active'             => $this->detect_gitpress( $active_plugins ),
			'is_gitpress_shortcode_registered' => shortcode_exists( 'divi_github_content' ),
			'active_plugins'                 => $active_list,
			'inactive_plugins_count'         => $inactive_count,
			'available_plugin_updates'       => $this->get_plugin_updates(),
			'available_theme_updates'        => $this->get_theme_updates(),
			'detected_form_plugins'          => $this->detect_known( $active_plugins, self::FORM_PLUGINS ),
			'detected_mail_plugins'          => $this->detect_known( $active_plugins, self::MAIL_PLUGINS ),
			'has_divi_contact_form'          => $this->detect_divi_contact_form( $active_plugins ),
			'permalink_structure'            => get_option( 'permalink_structure' ),
			'search_engine_visibility'       => ! (bool) get_option( 'blog_public' ),
			'timezone_string'                => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ),
			'admin_email_domain'             => $this->extract_email_domain( (string) get_option( 'admin_email' ) ),
		);
	}

	private function detect_divi( array $active_plugins, \WP_Theme $theme ): bool {
		if ( 'Divi' === $theme->get( 'Name' ) || 'Divi' === $theme->get( 'Template' ) ) {
			return true;
		}
		if ( defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Module' ) ) {
			return true;
		}
		return false;
	}

	private function detect_gitpress( array $active_plugins ): bool {
		foreach ( $active_plugins as $file ) {
			$lower = strtolower( $file );
			if ( str_contains( $lower, 'gitpress' ) || str_contains( $lower, 'divi-github-sync' ) ) {
				return true;
			}
		}
		return false;
	}

	private function detect_known( array $active_plugins, array $map ): array {
		$found = array();
		foreach ( $map as $file => $name ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	private function detect_divi_contact_form( array $active_plugins ): bool {
		foreach ( $active_plugins as $file ) {
			$lower = strtolower( $file );
			if ( str_contains( $lower, 'et-divi-contact' ) ) {
				return true;
			}
		}
		// Best-effort: check if the et_pb_contact_form shortcode is registered.
		return shortcode_exists( 'et_pb_contact_form' );
	}

	private function get_plugin_updates(): array {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates || empty( $updates->response ) ) {
			return array();
		}
		$result = array();
		foreach ( (array) $updates->response as $file => $data ) {
			$result[] = array(
				'plugin_file'     => $file,
				'current_version' => $data->Version ?? null,
				'new_version'     => $data->new_version ?? null,
			);
		}
		return $result;
	}

	private function get_theme_updates(): array {
		$updates = get_site_transient( 'update_themes' );
		if ( ! $updates || empty( $updates->response ) ) {
			return array();
		}
		$result = array();
		foreach ( (array) $updates->response as $slug => $data ) {
			$result[] = array(
				'theme'           => $slug,
				'new_version'     => $data['new_version'] ?? null,
			);
		}
		return $result;
	}

	private function extract_email_domain( string $email ): string {
		$parts = explode( '@', $email, 2 );
		return $parts[1] ?? '';
	}
}
