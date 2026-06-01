<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminMenu {

	private DashboardPage  $dashboard;
	private ConnectionPage $connection;
	private ReportsPage    $reports;
	private LogsPage       $logs;
	private SettingsPage   $settings;

	public function __construct(
		DashboardPage $dashboard,
		ConnectionPage $connection,
		ReportsPage $reports,
		LogsPage $logs,
		SettingsPage $settings
	) {
		$this->dashboard  = $dashboard;
		$this->connection = $connection;
		$this->reports    = $reports;
		$this->logs       = $logs;
		$this->settings   = $settings;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->connection->init();
		$this->settings->init();
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'Broseph', 'broseph' ),
			__( 'Broseph', 'broseph' ),
			'manage_options',
			'broseph',
			array( $this->dashboard, 'render_page' ),
			'dashicons-superhero',
			30
		);

		add_submenu_page(
			'broseph',
			__( 'Dashboard', 'broseph' ),
			__( 'Dashboard', 'broseph' ),
			'manage_options',
			'broseph',
			array( $this->dashboard, 'render_page' )
		);

		add_submenu_page(
			'broseph',
			__( 'Connection', 'broseph' ),
			__( 'Connection', 'broseph' ),
			'manage_options',
			'broseph-connection',
			array( $this->connection, 'render_page' )
		);

		add_submenu_page(
			'broseph',
			__( 'Reports', 'broseph' ),
			__( 'Reports', 'broseph' ),
			'manage_options',
			'broseph-reports',
			array( $this->reports, 'render_page' )
		);

		add_submenu_page(
			'broseph',
			__( 'Logs', 'broseph' ),
			__( 'Logs', 'broseph' ),
			'manage_options',
			'broseph-logs',
			array( $this->logs, 'render_page' )
		);

		add_submenu_page(
			'broseph',
			__( 'Settings', 'broseph' ),
			__( 'Settings', 'broseph' ),
			'manage_options',
			'broseph-settings',
			array( $this->settings, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		$broseph_hooks = array(
			'toplevel_page_broseph',
			'broseph_page_broseph-connection',
			'broseph_page_broseph-reports',
			'broseph_page_broseph-logs',
			'broseph_page_broseph-settings',
		);

		if ( ! in_array( $hook, $broseph_hooks, true ) ) {
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
}
