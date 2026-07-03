<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate(): void {
		DB_Schema::install();
		self::migrate_legacy_dedicated_tables();
		self::migrate_company_to_organizations();
		self::migrate_close_reason_into_call_close_reason();
		self::maybe_seed_defaults();
		self::maybe_seed_contact_types();
		self::maybe_seed_on_hold_reasons();
		self::maybe_flag_default_on_hold_status();
		self::maybe_flag_default_organization();
		self::maybe_seed_default_sla();
		self::normalize_sla_zero_fks();
		self::maybe_schedule_sla_escalation_cron();
		self::maybe_schedule_email_pipe_cron();
		self::maybe_set_default_role_map();
		self::maybe_create_portal_pages();
		Email_Notifications::maybe_seed_defaults();
		Imap_Mailbox::maybe_seed_defaults();
	}

	/**
	 * Creates the two end-user portal pages (see PLAN.md 1.2: the plugin must
	 * work out of the box, with no manual setup step outside of activating it).
	 * Safe to run on every activation - no-ops once the pages already exist.
	 */
	private static function maybe_create_portal_pages(): void {
		self::maybe_create_page( 'thickgrass_page_new_ticket', __( 'New Ticket', 'thickgrass' ), '[thickgrass_new_ticket]' );
		self::maybe_create_page( 'thickgrass_page_my_tickets', __( 'My Tickets', 'thickgrass' ), '[thickgrass_my_tickets]' );
		self::maybe_create_page( 'thickgrass_page_approval', __( 'Ticket Approval', 'thickgrass' ), '[thickgrass_approval]' );
		self::maybe_create_page( 'thickgrass_page_kb', __( 'Knowledge Base', 'thickgrass' ), '[thickgrass_kb]' );
	}

	private static function maybe_create_page( string $option_name, string $title, string $content ): void {
		$existing_id = (int) get_option( $option_name );

		if ( $existing_id && get_post( $existing_id ) ) {
			return;
		}

		$page_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( $option_name, $page_id );
		}
	}

	/**
	 * Applies the ThickGrass role -> WP role mapping to the corresponding WP capabilities.
	 * Called both on activation (with the default mapping) and from the settings screen
	 * when the admin changes the mapping (see PLAN.md section 2 - configurable, not hardcoded).
	 *
	 * @param array<string, string> $role_map e.g. ['manager' => 'administrator', 'agent' => 'editor', 'enduser' => 'subscriber']
	 */
	public static function apply_role_capabilities( array $role_map ): void {
		$capabilities_by_role = [
			'manager' => [ 'thickgrass_manage', 'thickgrass_agent', 'thickgrass_enduser' ],
			'agent'   => [ 'thickgrass_agent', 'thickgrass_enduser' ],
			'enduser' => [ 'thickgrass_enduser' ],
		];

		foreach ( $role_map as $thickgrass_role => $wp_role_slug ) {
			$wp_role = get_role( $wp_role_slug );

			if ( ! $wp_role ) {
				continue;
			}

			foreach ( $capabilities_by_role[ $thickgrass_role ] ?? [] as $cap ) {
				$wp_role->add_cap( $cap );
			}
		}
	}

	private static function maybe_set_default_role_map(): void {
		if ( get_option( 'thickgrass_role_map' ) ) {
			return;
		}

		$default_map = [
			'manager' => 'administrator',
			'agent'   => 'editor',
			'enduser' => 'subscriber',
		];

		update_option( 'thickgrass_role_map', $default_map );
		self::apply_role_capabilities( $default_map );
	}

	private static function maybe_seed_defaults(): void {
		if ( get_option( 'thickgrass_seeded' ) ) {
			return;
		}

		self::seed_choices();
		self::seed_ticket_types();

		update_option( 'thickgrass_seeded', 1 );
	}

	private static function seed_choices(): void {
		$seed = [
			'priority'          => [ 'Low', 'Medium', 'High', 'Critical' ],
			'impact'            => [ 'Low', 'Medium', 'High' ],
			'category'          => [ 'General' ],
			'call_close_reason' => [ 'Resolved by phone', 'Spam', 'Duplicate', 'Other' ],
		];

		foreach ( $seed as $list_key => $labels ) {
			foreach ( $labels as $index => $label ) {
				Choices::insert( [
					'list_key'   => $list_key,
					'label'      => $label,
					'sort_order' => $index,
				] );
			}
		}

		$statuses = [
			[ 'label' => 'New', 'meta' => [] ],
			[ 'label' => 'In Progress', 'meta' => [] ],
			[ 'label' => 'On Hold', 'meta' => [] ],
			[ 'label' => 'Resolved', 'meta' => [ 'is_resolved_state' => true ] ],
			[ 'label' => 'Closed', 'meta' => [ 'is_resolved_state' => true, 'is_closed_state' => true ] ],
			[ 'label' => 'Reopened', 'meta' => [] ],
		];

		foreach ( $statuses as $index => $status ) {
			Choices::insert( [
				'list_key'   => 'status',
				'label'      => $status['label'],
				'sort_order' => $index,
				'meta'       => $status['meta'],
			] );
		}
	}

	/**
	 * Ticket types and assignment groups live in the generic `wp_thickgrass_choices`
	 * table (list_key = 'ticket_type' / 'assignment_group'), not in dedicated tables -
	 * see PLAN.md section 3.1/3.2.
	 */
	private static function seed_ticket_types(): void {
		if ( Choices::get_list( 'ticket_type', false ) ) {
			return;
		}

		$defaults = [
			[ 'name' => 'Request', 'prefix' => 'REQ' ],
			[ 'name' => 'Incident', 'prefix' => 'INC' ],
		];

		foreach ( $defaults as $index => $type ) {
			Choices::insert( [
				'list_key'   => 'ticket_type',
				'label'      => $type['name'],
				'sort_order' => $index,
				'meta'       => [ 'prefix' => $type['prefix'], 'padding' => 5, 'next_number' => 1 ],
			] );
		}
	}

	/**
	 * Not gated by `thickgrass_seeded` on purpose - runs its own idempotency
	 * check, so it seeds "Contact type" on the next activation even for sites
	 * that already ran the initial seed before this list existed.
	 */
	private static function maybe_seed_contact_types(): void {
		if ( Choices::get_list( 'contact_type', false ) ) {
			return;
		}

		$defaults = [ 'Phone', 'Email', 'Self-service', 'Chat', 'Walk-in' ];

		foreach ( $defaults as $index => $label ) {
			Choices::insert( [
				'list_key'   => 'contact_type',
				'label'      => $label,
				'sort_order' => $index,
			] );
		}
	}

	/**
	 * Not gated by `thickgrass_seeded` on purpose, same reasoning as
	 * maybe_seed_contact_types().
	 */
	private static function maybe_seed_on_hold_reasons(): void {
		if ( Choices::get_list( 'on_hold_reason', false ) ) {
			return;
		}

		$defaults = [ 'Awaiting Caller/User', 'Awaiting Vendor', 'Awaiting Change', 'Awaiting Problem' ];

		foreach ( $defaults as $index => $label ) {
			Choices::insert( [
				'list_key'   => 'on_hold_reason',
				'label'      => $label,
				'sort_order' => $index,
			] );
		}
	}

	/**
	 * One-time (idempotent) cleanup: the ticket-level "Close reason" field
	 * (PLAN.md 7.38) originally seeded its own `close_reason` list, separate
	 * from the one Calls already had (`call_close_reason`) - the user asked
	 * to merge them into one, reused by both (PLAN.md 7.42), rather than
	 * maintaining two near-identical lists. Existing choice ids are preserved
	 * wherever possible (just relabels the list_key) so `tickets.close_reason_id`
	 * keeps pointing at a valid row without needing to touch ticket data - the
	 * one exception is a label that already exists in `call_close_reason`
	 * (e.g. both lists seeded a "Duplicate"), where the duplicate is dropped
	 * and any ticket referencing it is repointed at the original instead.
	 * No-ops entirely once no `close_reason` rows are left.
	 */
	private static function migrate_close_reason_into_call_close_reason(): void {
		global $wpdb;

		$table         = $wpdb->prefix . 'thickgrass_choices';
		$tickets_table = $wpdb->prefix . 'thickgrass_tickets';

		foreach ( $wpdb->get_results( "SELECT * FROM {$table} WHERE list_key = 'close_reason'" ) as $row ) {
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE list_key = 'call_close_reason' AND label = %s",
				$row->label
			) );

			if ( $existing_id ) {
				$wpdb->update( $tickets_table, [ 'close_reason_id' => $existing_id ], [ 'close_reason_id' => $row->id ] );
				$wpdb->delete( $table, [ 'id' => $row->id ] );
			} else {
				$wpdb->update( $table, [ 'list_key' => 'call_close_reason' ], [ 'id' => $row->id ] );
			}
		}
	}

	/**
	 * The "On hold reason" field on a ticket should only apply while its status
	 * is the "On Hold" one - but which status counts as "on hold" is itself
	 * configurable (meta.is_on_hold_state on the `status` choice), not hardcoded.
	 * This flags the seeded "On Hold" status by default, once, the first time it
	 * runs - if an admin has already flagged a (possibly different) status as
	 * on-hold, that choice is left alone.
	 */
	private static function maybe_flag_default_on_hold_status(): void {
		$statuses = Choices::get_list( 'status', false );

		foreach ( $statuses as $status ) {
			if ( ! empty( $status->meta['is_on_hold_state'] ) ) {
				return;
			}
		}

		foreach ( $statuses as $status ) {
			if ( 0 === strcasecmp( $status->label, 'On Hold' ) ) {
				$meta                     = $status->meta;
				$meta['is_on_hold_state'] = true;

				// Choices::update() replaces the whole record, so every existing
				// field must be passed along - only `meta` actually changes here.
				Choices::update( $status->id, [
					'list_key'   => $status->list_key,
					'label'      => $status->label,
					'slug'       => $status->slug,
					'parent_id'  => $status->parent_id,
					'sort_order' => $status->sort_order,
					'is_active'  => $status->is_active,
					'meta'       => $meta,
				] );

				return;
			}
		}
	}

	/**
	 * One-time cleanup for sites that activated the plugin before ticket types and
	 * assignment groups were folded into the generic choices engine: migrates any
	 * rows from the old dedicated tables into `wp_thickgrass_choices`, remaps the
	 * agent<->assignment group junction table to the new ids, then drops the old
	 * tables. No-ops entirely once those tables no longer exist.
	 */
	private static function migrate_legacy_dedicated_tables(): void {
		global $wpdb;

		$old_groups_table = $wpdb->prefix . 'thickgrass_assignment_groups';

		if ( self::table_exists( $old_groups_table ) ) {
			$id_map = [];

			foreach ( $wpdb->get_results( "SELECT * FROM {$old_groups_table}" ) as $row ) {
				$new_id = Choices::insert( [
					'list_key'  => 'assignment_group',
					'label'     => $row->name,
					'is_active' => (bool) $row->is_active,
					'meta'      => [ 'description' => $row->description ],
				] );

				$id_map[ (int) $row->id ] = $new_id;
			}

			$junction = $wpdb->prefix . 'thickgrass_agent_groups';

			foreach ( $id_map as $old_id => $new_id ) {
				$wpdb->update( $junction, [ 'assignment_group_id' => $new_id ], [ 'assignment_group_id' => $old_id ] );
			}

			$wpdb->query( "DROP TABLE IF EXISTS {$old_groups_table}" );
		}

		$old_types_table = $wpdb->prefix . 'thickgrass_ticket_type';

		if ( self::table_exists( $old_types_table ) ) {
			foreach ( $wpdb->get_results( "SELECT * FROM {$old_types_table}" ) as $row ) {
				Choices::insert( [
					'list_key'   => 'ticket_type',
					'label'      => $row->name,
					'sort_order' => (int) $row->sort_order,
					'is_active'  => (bool) $row->is_active,
					'meta'       => [
						'prefix'      => $row->prefix,
						'padding'     => (int) $row->padding,
						'next_number' => (int) $row->next_number,
					],
				] );
			}

			$wpdb->query( "DROP TABLE IF EXISTS {$old_types_table}" );
		}
	}

	/**
	 * One-time rename of the old "Company" entity into "Organizations": copies
	 * rows from the old `thickgrass_company` table into the new
	 * `thickgrass_organizations` table (created by DB_Schema::install() just
	 * before this runs), moves the `company_id` FK values on every table that
	 * references it into the new `organization_id` column added by dbDelta,
	 * drops the now-unused `company_id` columns, then drops the old table.
	 * No-ops entirely once `thickgrass_company` no longer exists.
	 */
	private static function migrate_company_to_organizations(): void {
		global $wpdb;

		$old_table = $wpdb->prefix . 'thickgrass_company';

		if ( ! self::table_exists( $old_table ) ) {
			return;
		}

		$organizations_table = $wpdb->prefix . 'thickgrass_organizations';

		foreach ( $wpdb->get_results( "SELECT * FROM {$old_table}" ) as $row ) {
			$wpdb->insert( $organizations_table, [
				'id'                 => $row->id,
				'name'               => $row->name,
				'manager_wp_user_id' => $row->manager_wp_user_id,
				'is_active'          => $row->is_active,
				'created_at'         => $row->created_at,
			] );
		}

		foreach ( [ 'thickgrass_business_hours', 'thickgrass_users', 'thickgrass_assets', 'thickgrass_sla_definitions' ] as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( self::column_exists( $table, 'company_id' ) ) {
				$wpdb->query( "UPDATE {$table} SET organization_id = company_id" );
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN company_id" );
			}
		}

		$wpdb->query( "DROP TABLE IF EXISTS {$old_table}" );
	}

	/**
	 * Ensures exactly one organization is flagged `is_default` (see PLAN.md:
	 * "creaza o organizatie default, ce poate fi modificata dar nu stearsa").
	 * Runs its own idempotency check, so it is safe on every activation:
	 * no-ops once a default already exists, otherwise flags the oldest
	 * organization (e.g. one just migrated from the old Company table), or
	 * seeds a brand new one if none exist at all.
	 */
	private static function maybe_flag_default_organization(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_organizations';

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_default = 1" ) > 0 ) {
			return;
		}

		$first_id = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );

		if ( $first_id ) {
			$wpdb->update( $table, [ 'is_default' => 1 ], [ 'id' => (int) $first_id ] );
			return;
		}

		$wpdb->insert( $table, [
			'name'       => __( 'Default organization', 'thickgrass' ),
			'is_default' => 1,
			'is_active'  => 1,
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Ensures exactly one SLA definition is flagged `is_default`, used by
	 * Sla::find_definition() as the universal fallback when nothing more
	 * specific matches a ticket (PLAN.md: "adauga un sla default"). Runs its
	 * own idempotency check, so it is safe on every activation: no-ops once a
	 * default already exists. Seeded values (1h response / 8h resolution) are
	 * only a starting point - the row itself stays editable, just never
	 * deletable, same pattern as the default Organization.
	 */
	private static function maybe_seed_default_sla(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_sla_definitions';

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_default = 1" ) > 0 ) {
			return;
		}

		$wpdb->insert( $table, [
			'response_minutes'   => 60,
			'resolution_minutes' => 480,
			'is_default'         => 1,
			'is_active'          => 1,
			'created_at'         => current_time( 'mysql' ),
		] );
	}

	/**
	 * One-time (but idempotent) cleanup for SLA definitions saved before the
	 * `Generic_Form::sanitize()` fix that made an empty "select" always store
	 * NULL: rows saved through the old, buggy path have `0` instead of NULL
	 * on FK columns meant to mean "any" (e.g. a Category left blank stored
	 * `category_id = 0`, which then matched tickets with no category at all
	 * instead of acting as a wildcard). Safe to run every activation - it is
	 * a no-op once no `0` values remain.
	 */
	private static function normalize_sla_zero_fks(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_sla_definitions';

		foreach ( [ 'organization_id', 'priority_id', 'category_id', 'ticket_type_id', 'escalate_to_priority_id' ] as $column ) {
			$wpdb->query( "UPDATE {$table} SET {$column} = NULL WHERE {$column} = 0" );
		}
	}

	/**
	 * Schedules the hourly job that auto-escalates tickets past their SLA
	 * resolution deadline (see Sla::run_escalations()). `wp_next_scheduled()`
	 * makes this safe to call on every activation/upgrade without creating
	 * duplicate events.
	 */
	private static function maybe_schedule_sla_escalation_cron(): void {
		if ( ! wp_next_scheduled( 'thickgrass_sla_escalation_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'thickgrass_sla_escalation_check' );
		}
	}

	/**
	 * See Imap_Mailbox::poll() - the custom 'thickgrass_five_minutes' schedule
	 * is registered by Plugin::register_cron_schedules().
	 */
	private static function maybe_schedule_email_pipe_cron(): void {
		if ( ! wp_next_scheduled( 'thickgrass_email_pipe_check' ) ) {
			wp_schedule_event( time(), 'thickgrass_five_minutes', 'thickgrass_email_pipe_check' );
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		return (bool) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
	}
}
