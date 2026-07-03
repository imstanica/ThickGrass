<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation does not delete data/tables - it only stops the plugin's hooks.
 * Actual data removal remains the responsibility of an eventual uninstall.php,
 * triggered by an explicit user action (uninstall), not by a simple deactivation.
 */
class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'thickgrass_email_pipe_check' );
		wp_clear_scheduled_hook( 'thickgrass_sla_escalation_check' );
	}
}
