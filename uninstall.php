<?php
/**
 * Fires only on actual "Delete" from wp-admin -> Plugins (never on deactivate -
 * see includes/class-deactivator.php), removing every trace of the plugin's own
 * data: custom tables, options, scheduled events and the auto-created portal pages.
 * Never touches wp_users, wp_posts (other than its own pages) or any other plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function thickgrass_uninstall_site(): void {
	global $wpdb;

	$tables = [
		'thickgrass_choices',
		'thickgrass_organizations',
		'thickgrass_business_hours',
		'thickgrass_users',
		'thickgrass_agents',
		'thickgrass_agent_groups',
		'thickgrass_agent_organizations',
		'thickgrass_assets',
		'thickgrass_custom_fields',
		'thickgrass_sla_definitions',
		'thickgrass_views',
		'thickgrass_tickets',
		'thickgrass_ticket_field_values',
		'thickgrass_comments',
		'thickgrass_attachments',
		'thickgrass_approvals',
		'thickgrass_activity_log',
		'thickgrass_calls',
		'thickgrass_kb_articles',
		'thickgrass_canned_responses',
		'thickgrass_canned_response_groups',
		'thickgrass_canned_response_organizations',
		'thickgrass_custom_forms',
		'thickgrass_custom_form_fields',
		'thickgrass_custom_form_field_values',
	];

	foreach ( $tables as $suffix ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" );
	}

	$page_options = [
		'thickgrass_page_new_ticket',
		'thickgrass_page_my_tickets',
		'thickgrass_page_approval',
		'thickgrass_page_kb',
	];

	foreach ( $page_options as $option_name ) {
		$page_id = (int) get_option( $option_name );

		if ( $page_id && get_post( $page_id ) ) {
			wp_delete_post( $page_id, true );
		}
	}

	$options = array_merge( $page_options, [
		'thickgrass_role_map',
		'thickgrass_seeded',
		'thickgrass_db_version',
		'thickgrass_auto_assign_default_organization',
		'thickgrass_email_pipe_settings',
		'thickgrass_email_templates',
	] );

	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}

	wp_clear_scheduled_hook( 'thickgrass_email_pipe_check' );
	wp_clear_scheduled_hook( 'thickgrass_sla_escalation_check' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids' ] );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		thickgrass_uninstall_site();
		restore_current_blog();
	}
} else {
	thickgrass_uninstall_site();
}
