<?php
declare( strict_types=1 );

namespace Broseph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate(): void {
		// Intentionally left minimal — credentials and settings are preserved.
		// Uninstall.php handles full removal when the plugin is deleted.
	}
}
