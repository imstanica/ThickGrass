<?php
/**
 * Plugin Name: ThickGrass
 * Description: End-user ticketing / support plugin - tickets, SLAs, a Knowledge Base, canned responses, and custom intake forms, all built on the plugin's own tables.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ThickGrass
 * Text Domain: thickgrass
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THICKGRASS_VERSION', '1.0.0' );
define( 'THICKGRASS_DB_VERSION', '1.15.0' );
define( 'THICKGRASS_FILE', __FILE__ );
define( 'THICKGRASS_DIR', plugin_dir_path( __FILE__ ) );
define( 'THICKGRASS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( $class ) {
	$prefix = 'ThickGrass\\';

	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative   = substr( $class, strlen( $prefix ) );
	$parts      = explode( '\\', $relative );
	$class_name = array_pop( $parts );

	// "Generic_List_Table" / "DB_Schema" / "TicketType" -> "generic-list-table" / "db-schema" / "ticket-type".
	$kebab     = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '-', $class_name );
	$kebab     = str_replace( '_', '-', $kebab );
	$kebab     = strtolower( preg_replace( '/-+/', '-', $kebab ) );
	$file_name = 'class-' . $kebab . '.php';
	$sub_dir   = strtolower( implode( '/', $parts ) );
	$path      = THICKGRASS_DIR . 'includes/' . ( $sub_dir ? $sub_dir . '/' : '' ) . $file_name;

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, [ '\ThickGrass\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\ThickGrass\Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	\ThickGrass\Plugin::instance()->init();
} );
