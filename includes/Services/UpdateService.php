<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateService {

	// ── Public API ────────────────────────────────────────────────────────────

	public function get_available_updates(): array {
		$this->require_plugin_file();

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );

		// Plugins with updates only.
		$plugins        = array();
		$update_keys    = ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) )
			? array_keys( (array) $plugin_updates->response )
			: array();

		foreach ( $update_keys as $plugin_file ) {
			$data       = $all_plugins[ $plugin_file ] ?? null;
			$plugins[]  = array(
				'plugin_file'      => $plugin_file,
				'name'             => $data['Name'] ?? $plugin_file,
				'current_version'  => $data['Version'] ?? null,
				'new_version'      => $plugin_updates->response[ $plugin_file ]->new_version ?? null,
				'active'           => in_array( $plugin_file, $active_plugins, true ),
				'update_available' => true,
			);
		}

		// Themes with updates only.
		$themes       = array();
		$active_theme = (string) get_option( 'stylesheet', '' );
		$theme_keys   = ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) )
			? array_keys( (array) $theme_updates->response )
			: array();

		foreach ( $theme_keys as $slug ) {
			$theme     = wp_get_theme( $slug );
			$themes[]  = array(
				'theme'            => $slug,
				'name'             => $theme->exists() ? $theme->get( 'Name' ) : $slug,
				'current_version'  => $theme->exists() ? $theme->get( 'Version' ) : null,
				'new_version'      => $theme_updates->response[ $slug ]['new_version'] ?? null,
				'active'           => ( $slug === $active_theme ),
				'update_available' => true,
			);
		}

		return array(
			'plugins'         => $plugins,
			'themes'          => $themes,
			'updates_allowed' => (bool) get_option( 'broseph_allow_plugin_theme_updates', false ),
			'notes'           => array(),
		);
	}

	/**
	 * @return array<int,array>|\WP_Error Results array or WP_Error on hard failure.
	 */
	public function update_plugins( array $plugin_files, bool $allow_inactive ): array|\WP_Error {
		$gate = $this->check_updates_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$fs = $this->init_upgrader_env();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$this->require_plugin_file();

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_updates = get_site_transient( 'update_plugins' );
		$broseph_file   = plugin_basename( BROSEPH_PLUGIN_FILE );
		$results        = array();

		foreach ( $plugin_files as $raw ) {
			$plugin_file = sanitize_text_field( (string) $raw );

			if ( ! $this->is_valid_plugin_file( $plugin_file ) ) {
				$results[] = $this->plugin_row( $plugin_file, null, null, null, 'skipped', 'Invalid plugin file format.' );
				continue;
			}

			// Protect Broseph.
			if ( $plugin_file === $broseph_file ) {
				$pdata     = $all_plugins[ $plugin_file ] ?? array();
				$results[] = $this->plugin_row(
					$plugin_file,
					$pdata['Name'] ?? 'Broseph',
					$pdata['Version'] ?? null,
					null, 'skipped', 'Broseph cannot update itself.'
				);
				continue;
			}

			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				$results[] = $this->plugin_row( $plugin_file, null, null, null, 'skipped', 'Plugin not found in installed plugins.' );
				continue;
			}

			$pdata        = $all_plugins[ $plugin_file ];
			$name         = $pdata['Name'];
			$prev_version = $pdata['Version'] ?? null;

			if ( empty( $plugin_updates->response[ $plugin_file ] ) ) {
				$results[] = $this->plugin_row( $plugin_file, $name, $prev_version, null, 'skipped', 'No update available for this plugin.' );
				continue;
			}

			$target_version = $plugin_updates->response[ $plugin_file ]->new_version ?? null;

			$is_active = in_array( $plugin_file, $active_plugins, true );
			if ( ! $is_active && ! $allow_inactive ) {
				$results[] = $this->plugin_row(
					$plugin_file, $name, $prev_version, $target_version,
					'skipped', 'Plugin is inactive. Set allow_inactive: true to update inactive plugins.'
				);
				continue;
			}

			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin_file );

			if ( is_wp_error( $result ) ) {
				$results[] = $this->plugin_row( $plugin_file, $name, $prev_version, $target_version, 'failed', $result->get_error_message() );
			} elseif ( false === $result ) {
				$results[] = $this->plugin_row( $plugin_file, $name, $prev_version, $target_version, 'failed', 'Upgrade returned false. Check filesystem permissions or try again.' );
			} else {
				$new_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
				$new_version = $new_data['Version'] ?? $target_version;
				$results[]   = $this->plugin_row(
					$plugin_file, $name, $prev_version, $new_version,
					'updated', "Updated from {$prev_version} to {$new_version}."
				);
			}
		}

		return $results;
	}

	/**
	 * @return array<int,array>|\WP_Error Results array or WP_Error on hard failure.
	 */
	public function update_themes( array $theme_slugs, bool $allow_active_theme ): array|\WP_Error {
		$gate = $this->check_updates_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$fs = $this->init_upgrader_env();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$theme_updates = get_site_transient( 'update_themes' );
		$active_theme  = (string) get_option( 'stylesheet', '' );
		$results       = array();

		foreach ( $theme_slugs as $raw ) {
			$slug = sanitize_key( (string) $raw );

			if ( '' === $slug ) {
				$results[] = $this->theme_row( '', null, null, null, 'skipped', 'Invalid theme slug.' );
				continue;
			}

			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				$results[] = $this->theme_row( $slug, null, null, null, 'skipped', 'Theme not found.' );
				continue;
			}

			$name         = $theme->get( 'Name' );
			$prev_version = $theme->get( 'Version' );

			if ( empty( $theme_updates->response[ $slug ] ) ) {
				$results[] = $this->theme_row( $slug, $name, $prev_version, null, 'skipped', 'No update available for this theme.' );
				continue;
			}

			$target_version = $theme_updates->response[ $slug ]['new_version'] ?? null;

			if ( $slug === $active_theme && ! $allow_active_theme ) {
				$results[] = $this->theme_row(
					$slug, $name, $prev_version, $target_version,
					'skipped', 'This is the active theme. Set allow_active_theme: true to update it.'
				);
				continue;
			}

			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $slug );

			if ( is_wp_error( $result ) ) {
				$results[] = $this->theme_row( $slug, $name, $prev_version, $target_version, 'failed', $result->get_error_message() );
			} elseif ( false === $result ) {
				$results[] = $this->theme_row( $slug, $name, $prev_version, $target_version, 'failed', 'Upgrade returned false. Check filesystem permissions or try again.' );
			} else {
				wp_clean_themes_cache();
				$refreshed   = wp_get_theme( $slug );
				$new_version = ( $refreshed->exists() ? $refreshed->get( 'Version' ) : null ) ?? $target_version;
				$results[]   = $this->theme_row(
					$slug, $name, $prev_version, $new_version,
					'updated', "Updated from {$prev_version} to {$new_version}."
				);
			}
		}

		return $results;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function check_updates_gate(): true|\WP_Error {
		if ( ! (bool) get_option( 'broseph_allow_plugin_theme_updates', false ) ) {
			return new \WP_Error(
				'broseph_updates_disabled',
				'Plugin/theme updates are disabled. Enable in Broseph > Settings.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	private function init_upgrader_env(): true|\WP_Error {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		// Loads Plugin_Upgrader, Theme_Upgrader, Automatic_Upgrader_Skin, etc.
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Theme_Upgrader' ) ) {
			return new \WP_Error(
				'broseph_no_upgrader',
				'WordPress upgrader classes are unavailable.',
				array( 'status' => 503 )
			);
		}

		// Init WP_Filesystem (false = no interactive FTP prompt in API context).
		global $wp_filesystem;
		if ( ! WP_Filesystem( false ) ) {
			return new \WP_Error(
				'broseph_filesystem_error',
				'Cannot initialise WordPress filesystem. Server may require FTP credentials for file operations.',
				array( 'status' => 503 )
			);
		}

		return true;
	}

	private function require_plugin_file(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Validates plugin_file is in "folder/file.php" format.
	 * Blocks path traversal and absolute paths.
	 */
	private function is_valid_plugin_file( string $plugin_file ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9\-_.]+\/[a-zA-Z0-9\-_.]+\.php$/', $plugin_file );
	}

	private function plugin_row(
		string $plugin_file,
		?string $name,
		?string $prev_version,
		?string $target_version,
		string $status,
		string $message
	): array {
		return array(
			'plugin_file'      => $plugin_file,
			'name'             => $name,
			'previous_version' => $prev_version,
			'target_version'   => $target_version,
			'status'           => $status,
			'message'          => $message,
		);
	}

	private function theme_row(
		string $theme,
		?string $name,
		?string $prev_version,
		?string $target_version,
		string $status,
		string $message
	): array {
		return array(
			'theme'            => $theme,
			'name'             => $name,
			'previous_version' => $prev_version,
			'target_version'   => $target_version,
			'status'           => $status,
			'message'          => $message,
		);
	}
}
