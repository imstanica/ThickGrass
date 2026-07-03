<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table definitions for Phase 1 (see PLAN.md section 3).
 * Columns reflect what is documented in PLAN.md; they get refined over time
 * as we receive the exact field definitions per table.
 */
class DB_Schema {

	/**
	 * @return array<string, string> table suffix (without prefix) => columns/keys for dbDelta
	 */
	public static function get_tables(): array {
		return [

			// Generic configuration engine - see PLAN.md 3.1.
			'thickgrass_choices' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				list_key varchar(64) NOT NULL,
				parent_id bigint(20) unsigned DEFAULT NULL,
				label varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				meta longtext DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY list_key (list_key),
				KEY parent_id (parent_id)
			",

			'thickgrass_organizations' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				address varchar(255) DEFAULT NULL,
				phone varchar(50) DEFAULT NULL,
				location varchar(191) DEFAULT NULL,
				manager_wp_user_id bigint(20) unsigned DEFAULT NULL,
				is_default tinyint(1) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			",

			'thickgrass_business_hours' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organization_id bigint(20) unsigned DEFAULT NULL,
				weekday tinyint(1) unsigned NOT NULL,
				is_working_day tinyint(1) NOT NULL DEFAULT 1,
				start_time time DEFAULT NULL,
				end_time time DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY organization_id (organization_id)
			",

			'thickgrass_users' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NOT NULL,
				organization_id bigint(20) unsigned DEFAULT NULL,
				manager_wp_user_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY wp_user_id (wp_user_id),
				KEY organization_id (organization_id)
			",

			'thickgrass_agents' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NOT NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY wp_user_id (wp_user_id)
			",

			// n:n junction agent <-> assignment group. assignment_group_id points to
			// wp_thickgrass_choices.id (list_key = 'assignment_group'), not a dedicated table.
			'thickgrass_agent_groups' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agent_id bigint(20) unsigned NOT NULL,
				assignment_group_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY agent_group (agent_id, assignment_group_id)
			",

			// n:n junction agent <-> organization ("Locations" a given agent supports).
			'thickgrass_agent_organizations' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agent_id bigint(20) unsigned NOT NULL,
				organization_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY agent_org (agent_id, organization_id)
			",

			'thickgrass_assets' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				asset_type_id bigint(20) unsigned DEFAULT NULL,
				owner_wp_user_id bigint(20) unsigned DEFAULT NULL,
				organization_id bigint(20) unsigned DEFAULT NULL,
				description text DEFAULT NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY organization_id (organization_id)
			",

			'thickgrass_custom_fields' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				applies_to_category_id bigint(20) unsigned DEFAULT NULL,
				applies_to_ticket_type_id bigint(20) unsigned DEFAULT NULL,
				field_key varchar(100) NOT NULL,
				label varchar(191) NOT NULL,
				field_type varchar(30) NOT NULL DEFAULT 'text',
				options longtext DEFAULT NULL,
				is_required tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY applies_to_category_id (applies_to_category_id),
				KEY applies_to_ticket_type_id (applies_to_ticket_type_id)
			",

			'thickgrass_sla_definitions' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organization_id bigint(20) unsigned DEFAULT NULL,
				priority_id bigint(20) unsigned DEFAULT NULL,
				category_id bigint(20) unsigned DEFAULT NULL,
				ticket_type_id bigint(20) unsigned DEFAULT NULL,
				assignment_minutes int(11) unsigned NOT NULL DEFAULT 30,
				response_minutes int(11) unsigned NOT NULL,
				first_update_minutes int(11) unsigned NOT NULL DEFAULT 240,
				resolution_minutes int(11) unsigned NOT NULL,
				escalate_to_priority_id bigint(20) unsigned DEFAULT NULL,
				is_default tinyint(1) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY organization_id (organization_id),
				KEY priority_id (priority_id)
			",

			'thickgrass_views' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agent_wp_user_id bigint(20) unsigned DEFAULT NULL,
				name varchar(191) NOT NULL,
				filters longtext DEFAULT NULL,
				is_default tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY agent_wp_user_id (agent_wp_user_id)
			",

			'thickgrass_tickets' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_number varchar(30) NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				status_id bigint(20) unsigned NOT NULL,
				on_hold_reason_id bigint(20) unsigned DEFAULT NULL,
				close_reason_id bigint(20) unsigned DEFAULT NULL,
				close_notes longtext DEFAULT NULL,
				priority_id bigint(20) unsigned DEFAULT NULL,
				impact_id bigint(20) unsigned DEFAULT NULL,
				category_id bigint(20) unsigned DEFAULT NULL,
				title varchar(255) NOT NULL,
				description longtext DEFAULT NULL,
				requester_wp_user_id bigint(20) unsigned NOT NULL,
				location_organization_id bigint(20) unsigned DEFAULT NULL,
				assigned_agent_id bigint(20) unsigned DEFAULT NULL,
				assignment_group_id bigint(20) unsigned DEFAULT NULL,
				asset_id bigint(20) unsigned DEFAULT NULL,
				sla_id bigint(20) unsigned DEFAULT NULL,
				sla_assignment_due datetime DEFAULT NULL,
				sla_response_due datetime DEFAULT NULL,
				sla_first_update_due datetime DEFAULT NULL,
				sla_resolution_due datetime DEFAULT NULL,
				sla_hold_started_at datetime DEFAULT NULL,
				sla_escalated_at datetime DEFAULT NULL,
				sla_breach_notified_at datetime DEFAULT NULL,
				assigned_at datetime DEFAULT NULL,
				first_responded_at datetime DEFAULT NULL,
				first_updated_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT NULL,
				resolved_at datetime DEFAULT NULL,
				closed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY ticket_number (ticket_number),
				KEY status_id (status_id),
				KEY priority_id (priority_id),
				KEY assigned_agent_id (assigned_agent_id),
				KEY assignment_group_id (assignment_group_id),
				KEY requester_wp_user_id (requester_wp_user_id)
			",

			'thickgrass_ticket_field_values' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				custom_field_id bigint(20) unsigned NOT NULL,
				value longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id),
				KEY custom_field_id (custom_field_id)
			",

			'thickgrass_comments' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				author_wp_user_id bigint(20) unsigned NOT NULL,
				body longtext NOT NULL,
				is_work_note tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id)
			",

			'thickgrass_attachments' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned DEFAULT NULL,
				comment_id bigint(20) unsigned DEFAULT NULL,
				file_path varchar(255) NOT NULL,
				file_name varchar(255) NOT NULL,
				uploaded_by_wp_user_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id),
				KEY comment_id (comment_id)
			",

			'thickgrass_approvals' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				approver_wp_user_id bigint(20) unsigned NOT NULL,
				requested_by_wp_user_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				token varchar(64) NOT NULL,
				comment text DEFAULT NULL,
				requested_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				decided_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY ticket_id (ticket_id)
			",

			'thickgrass_activity_log' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				actor_wp_user_id bigint(20) unsigned NOT NULL,
				field_changed varchar(100) DEFAULT NULL,
				old_value text DEFAULT NULL,
				new_value text DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id)
			",

			'thickgrass_calls' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(20) NOT NULL DEFAULT 'manual',
				contact_type_id bigint(20) unsigned DEFAULT NULL,
				caller_wp_user_id bigint(20) unsigned DEFAULT NULL,
				caller_name varchar(191) DEFAULT NULL,
				caller_email varchar(191) DEFAULT NULL,
				assignment_group_id bigint(20) unsigned DEFAULT NULL,
				location_organization_id bigint(20) unsigned DEFAULT NULL,
				short_description varchar(255) NOT NULL,
				notes longtext DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				close_reason_id bigint(20) unsigned DEFAULT NULL,
				created_by_agent_id bigint(20) unsigned DEFAULT NULL,
				converted_ticket_id bigint(20) unsigned DEFAULT NULL,
				email_message_id varchar(255) DEFAULT NULL,
				email_subject varchar(255) DEFAULT NULL,
				email_raw_body longtext DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				converted_at datetime DEFAULT NULL,
				closed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			",

			// Faza 2: Knowledge Base (PLAN.md 7.43) - `category_id` is a
			// `wp_thickgrass_choices` row (list_key = 'kb_category'), same
			// generic engine as everything else; `tags` is a plain
			// comma-separated string, not a real taxonomy - deliberately
			// minimal.
			'thickgrass_kb_articles' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(191) NOT NULL,
				content longtext DEFAULT NULL,
				category_id bigint(20) unsigned DEFAULT NULL,
				tags varchar(255) DEFAULT NULL,
				is_published tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY category_id (category_id)
			",

			// Faza 2: Canned Responses (PLAN.md 7.44) - body carries the same
			// {ticket_number}/{title}/etc. placeholders as email templates
			// (Email_Notifications::ticket_placeholders()). Scoped by BOTH
			// assignment group and location/organization via two independent
			// junction tables, each following the exact same "no rows = visible
			// everywhere on that dimension" convention already used for
			// agent<->group/organization scoping (see Ticket::agent_scope_sql()).
			// `category_id` added in Faza 2 polish (PLAN.md 7.46) - a
			// `wp_thickgrass_choices` row (list_key = 'canned_response_category'),
			// same generic engine/single-select convention as Kb_Article::category_id.
			'thickgrass_canned_responses' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(191) NOT NULL,
				body longtext DEFAULT NULL,
				category_id bigint(20) unsigned DEFAULT NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY category_id (category_id)
			",

			'thickgrass_canned_response_groups' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				canned_response_id bigint(20) unsigned NOT NULL,
				assignment_group_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY response_group (canned_response_id, assignment_group_id)
			",

			'thickgrass_canned_response_organizations' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				canned_response_id bigint(20) unsigned NOT NULL,
				organization_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY response_org (canned_response_id, organization_id)
			",

			// Faza 2: Custom Forms (PLAN.md 7.45) - `meta` on the form itself
			// holds the admin-fixed defaults for standard ticket columns
			// (e.g. {\"assignment_group_id\":5}), applied silently at
			// submission time - the end-user filling the form never sees or
			// edits those, only the fields defined in
			// `thickgrass_custom_form_fields` below.
			'thickgrass_custom_forms' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				description longtext DEFAULT NULL,
				meta longtext DEFAULT NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			",

			// `options` holds the choice list (JSON array of strings) for
			// field_type = 'select' - unused for the other field types.
			'thickgrass_custom_form_fields' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				custom_form_id bigint(20) unsigned NOT NULL,
				label varchar(191) NOT NULL,
				field_key varchar(64) NOT NULL,
				field_type varchar(20) NOT NULL DEFAULT 'text',
				options longtext DEFAULT NULL,
				is_required tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY custom_form_id (custom_form_id)
			",

			// `value` holds the submitted text for every field type except
			// 'file', where it holds the resulting `wp_thickgrass_attachments.id`
			// instead (the upload itself is stored as a normal ticket
			// attachment - see ThickGrass\\Attachment - not duplicated here).
			'thickgrass_custom_form_field_values' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				custom_form_field_id bigint(20) unsigned NOT NULL,
				value longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id)
			",
		];
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::get_tables() as $suffix => $columns ) {
			$table = $wpdb->prefix . $suffix;
			$sql   = "CREATE TABLE {$table} (\n{$columns}\n) {$charset_collate};";
			dbDelta( $sql );
		}

		update_option( 'thickgrass_db_version', THICKGRASS_DB_VERSION );
	}
}
