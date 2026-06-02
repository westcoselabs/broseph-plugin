<?php
/**
 * Plugin Name:       Broseph
 * Plugin URI:        https://openclaw.io
 * Description:       Connects your WordPress site to Open Claw, the AI agent system by WestCose Labs. Enables secure AI-assisted content creation, Divi page management, GitPress shortcode injection, and site health reporting — all controlled through signed API requests. No content is published or modified without explicit approval.
 * Version:           0.1.0
 * Author:            WestCose Labs
 * Author URI:        https://westcoselabs.com
 * Text Domain:       broseph
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BROSEPH_VERSION', '0.1.0' );
define( 'BROSEPH_PLUGIN_FILE', __FILE__ );
define( 'BROSEPH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BROSEPH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core infrastructure.
require_once BROSEPH_PLUGIN_DIR . 'includes/Logging/AuditLogRepository.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Logging/ActionLogger.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Logging/ReportRepository.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Activator.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Deactivator.php';

// Admin.
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/DashboardPage.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/ConnectionPage.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/SettingsPage.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/LogsPage.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/ReportsPage.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Admin/AdminMenu.php';

// Auth.
require_once BROSEPH_PLUGIN_DIR . 'includes/Auth/RequestSigner.php';

// REST — base must come before controllers.
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/BaseController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/ScanController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/PagesController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/GitPressController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/DiviController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/LandingPagesController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/ReportsController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/FormsController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/MailController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/UpdatesController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/ToolsController.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/REST/Routes.php';

// Services.
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/SiteScanner.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/PageService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/GitPressIntegration.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/DiviService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/ContentStrategyResolver.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/LandingPageService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/ReportBuilder.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/FormService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/MailLogService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/MailTestService.php';
require_once BROSEPH_PLUGIN_DIR . 'includes/Services/UpdateService.php';

// Plugin bootstrap.
require_once BROSEPH_PLUGIN_DIR . 'includes/Plugin.php';

register_activation_hook( __FILE__, array( 'Broseph\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Broseph\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Broseph\\Plugin', 'get_instance' ) );
