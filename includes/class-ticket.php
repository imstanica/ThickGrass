<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core ticket entity. See PLAN.md section 3.2.
 */
class Ticket {

	/** Fields that can be changed through update_field(), each change is logged. */
	private const EDITABLE_FIELDS = [
		'status_id',
		'on_hold_reason_id',
		'close_reason_id',
		'close_notes',
		'priority_id',
		'impact_id',
		'category_id',
		'assigned_agent_id',
		'assignment_group_id',
		'asset_id',
		'title',
		'description',
		'requester_wp_user_id',
		'location_organization_id',
	];

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_tickets';
	}

	/**
	 * @param array{
	 *   ticket_type_id: int,
	 *   title: string,
	 *   requester_wp_user_id: int,
	 *   description?: string,
	 *   status_id?: int,
	 *   priority_id?: int,
	 *   impact_id?: int,
	 *   category_id?: int,
	 *   assigned_agent_id?: int,
	 *   assignment_group_id?: int,
	 *   location_organization_id?: int,
	 *   asset_id?: int
	 * } $data
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$ticket_type = Choices::get( (int) $data['ticket_type_id'] );

		if ( ! $ticket_type || 'ticket_type' !== $ticket_type->list_key ) {
			throw new \InvalidArgumentException( 'Invalid ticket type.' );
		}

		$number  = Choices::increment_meta_counter( $ticket_type->id, 'next_number' );
		$padding = (int) ( $ticket_type->meta['padding'] ?? 5 );
		$prefix  = (string) ( $ticket_type->meta['prefix'] ?? '' );

		$ticket_number = $prefix . str_pad( (string) $number, $padding, '0', STR_PAD_LEFT );

		$record = [
			'ticket_number'        => $ticket_number,
			'ticket_type_id'       => $ticket_type->id,
			'status_id'            => (int) ( $data['status_id'] ?? self::default_status_id() ),
			'priority_id'          => $data['priority_id'] ?? null,
			'impact_id'            => $data['impact_id'] ?? null,
			'category_id'          => $data['category_id'] ?? null,
			'title'                => sanitize_text_field( $data['title'] ),
			'description'          => wp_kses_post( $data['description'] ?? '' ),
			'requester_wp_user_id' => (int) $data['requester_wp_user_id'],
			'assigned_agent_id'    => $data['assigned_agent_id'] ?? null,
			'assignment_group_id'  => $data['assignment_group_id'] ?? null,
			'location_organization_id' => $data['location_organization_id'] ?? null,
			'asset_id'             => $data['asset_id'] ?? null,
			'created_at'           => current_time( 'mysql' ),
		];

		// Rare, but a ticket can be created already assigned (e.g. a Call
		// converted straight to a specific agent) - the Assignment SLA target
		// is met immediately in that case, not left pending.
		if ( ! empty( $record['assigned_agent_id'] ) ) {
			$record['assigned_at'] = $record['created_at'];
		}

		$wpdb->insert( self::table(), $record );
		$ticket_id = (int) $wpdb->insert_id;

		Activity_Log::record( $ticket_id, get_current_user_id(), 'created', null, $ticket_number );
		Sla::calculate_due_dates( $ticket_id );

		// Notifies the ticket's own assignment group (PLAN.md: "Email la
		// creare tichet (către agent/manager)") - a ticket with no group at
		// all (e.g. one opened straight from the end-user portal, which does
		// not collect one yet) simply has nobody to notify through this channel.
		$ticket = self::get( $ticket_id );

		if ( $ticket ) {
			Email_Notifications::send(
				'ticket_created',
				$ticket_id,
				Email_Notifications::group_agent_emails( $ticket->assignment_group_id ? (int) $ticket->assignment_group_id : null ),
				Email_Notifications::ticket_placeholders( $ticket ),
				get_current_user_id() ?: null
			);
		}

		return $ticket_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * Used by Imap_Mailbox::poll() to match an inbound reply's "[Ticket #N]"
	 * subject tag back to the ticket it belongs to.
	 */
	public static function get_by_number( string $ticket_number ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_number = %s', $ticket_number ) );

		return $row ?: null;
	}

	/**
	 * @return array<int, object>
	 */
	public static function get_all(): array {
		return self::query( [] );
	}

	/**
	 * Simple equality filtering for the agent workbench list - see PLAN.md 7.5.
	 * A value of the string 'none' on `assigned_agent_id` filters for
	 * unassigned tickets (`IS NULL`) instead of an equality match.
	 *
	 * When $agent_id is given, results are additionally restricted to
	 * tickets that match BOTH that agent's own assignment groups AND their
	 * own locations (see Agents_Page - `wp_thickgrass_agent_groups`/
	 * `wp_thickgrass_agent_organizations`), so "which tickets can this agent
	 * see" is answered entirely in SQL - never by fetching every ticket and
	 * filtering in PHP (PLAN.md: explicit requirement). A ticket's location is
	 * its own `location_organization_id` override if set, else its
	 * requester's own organization (see Dashboard_Page::effective_location_organization_id()
	 * for the same fallback used on the ticket screen).
	 *
	 * @param array<string, int|string> $filters ticket column => id (or 'none' for assigned_agent_id), e.g. ['status_id' => 3, 'assigned_agent_id' => 'none']
	 * @return array<int, object>
	 */
	public static function query( array $filters, ?int $agent_id = null ): array {
		global $wpdb;

		$table       = self::table();
		$users_table = $wpdb->prefix . 'thickgrass_users';
		$allowed     = [ 'status_id', 'priority_id', 'category_id', 'assigned_agent_id', 'assignment_group_id', 'ticket_type_id' ];
		$where       = [];
		$args        = [];

		if ( null !== $agent_id ) {
			[ $scope_where, $scope_args ] = self::agent_scope_sql( $agent_id );
			$where = array_merge( $where, $scope_where );
			$args  = array_merge( $args, $scope_args );
		}

		foreach ( $filters as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) || '' === $value || null === $value ) {
				continue;
			}

			if ( 'assigned_agent_id' === $field && 'none' === $value ) {
				$where[] = 't.assigned_agent_id IS NULL';
				continue;
			}

			$where[] = "t.{$field} = %d";
			$args[]  = (int) $value;
		}

		$sql = "SELECT t.* FROM {$table} t LEFT JOIN {$users_table} u ON u.wp_user_id = t.requester_wp_user_id";

		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY t.id DESC';

		return $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
	}

	/**
	 * Counts still-open tickets (not yet resolved) with no assigned agent,
	 * grouped by ticket type - powers the dashboard's stat boxes. Agent-scoped
	 * the same way query() is, so the stat box counts always match what that
	 * agent's ticket list actually shows underneath them.
	 *
	 * @return array<int, int> ticket_type_id => count
	 */
	public static function count_unassigned_by_type( ?int $agent_id = null ): array {
		global $wpdb;

		$table       = self::table();
		$users_table = $wpdb->prefix . 'thickgrass_users';
		$where       = [ 't.assigned_agent_id IS NULL', 't.resolved_at IS NULL' ];
		$args        = [];

		if ( null !== $agent_id ) {
			[ $scope_where, $scope_args ] = self::agent_scope_sql( $agent_id );
			$where = array_merge( $where, $scope_where );
			$args  = array_merge( $args, $scope_args );
		}

		$sql = "SELECT t.ticket_type_id, COUNT(*) AS total FROM {$table} t
			LEFT JOIN {$users_table} u ON u.wp_user_id = t.requester_wp_user_id
			WHERE " . implode( ' AND ', $where ) . '
			GROUP BY t.ticket_type_id';

		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ (int) $row->ticket_type_id ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * The same "both assignment group AND location" rule agent_scope_sql()
	 * applies to the ticket LIST, checked here directly against one
	 * already-loaded ticket instead of as a SQL WHERE fragment - used to
	 * guard direct access by id (e.g. `?view=<id>` on the ticket screen, or
	 * any handler acting on a `ticket_id` from $_GET/$_POST), which
	 * previously only checked the generic `thickgrass_agent` capability and
	 * not scope, unlike the list (PLAN.md: "niciun bypass bazat pe
	 * capability, inclusiv pentru manageri" already meant this for the
	 * list - this closes the same gap for direct access). Mirrors
	 * agent_scope_sql()'s exact semantics: a ticket with no assignment group,
	 * or no resolvable location, is visible to nobody via this check (same
	 * as `NULL IN (...)` being false in SQL), not treated as a wildcard.
	 */
	public static function agent_can_view( int $agent_id, object $ticket ): bool {
		global $wpdb;

		if ( ! $ticket->assignment_group_id ) {
			return false;
		}

		$groups_table = $wpdb->prefix . 'thickgrass_agent_groups';
		$in_group     = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$groups_table} WHERE agent_id = %d AND assignment_group_id = %d",
			$agent_id,
			$ticket->assignment_group_id
		) );

		if ( ! $in_group ) {
			return false;
		}

		$location_id = self::effective_location_organization_id( $ticket );

		if ( ! $location_id ) {
			return false;
		}

		$orgs_table = $wpdb->prefix . 'thickgrass_agent_organizations';

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$orgs_table} WHERE agent_id = %d AND organization_id = %d",
			$agent_id,
			$location_id
		) );
	}

	/**
	 * A ticket's location is its own `location_organization_id` override if
	 * set, else its requester's own organization - the single source of
	 * truth for this fallback, used by both agent_can_view() above and the
	 * ticket screen's Location field (Dashboard_Page::effective_location_organization_id()).
	 */
	public static function effective_location_organization_id( object $ticket ): ?int {
		if ( $ticket->location_organization_id ) {
			return (int) $ticket->location_organization_id;
		}

		global $wpdb;

		$users_table     = $wpdb->prefix . 'thickgrass_users';
		$organization_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT organization_id FROM {$users_table} WHERE wp_user_id = %d",
			$ticket->requester_wp_user_id
		) );

		return $organization_id ? (int) $organization_id : null;
	}

	/**
	 * @return array{0: array<int, string>, 1: array<int, int>} WHERE fragments (assume table alias `t` + a LEFT JOIN to wp_thickgrass_users aliased `u`) and their bound args
	 */
	private static function agent_scope_sql( int $agent_id ): array {
		global $wpdb;

		$groups_table = $wpdb->prefix . 'thickgrass_agent_groups';
		$orgs_table   = $wpdb->prefix . 'thickgrass_agent_organizations';

		return [
			[
				"t.assignment_group_id IN ( SELECT assignment_group_id FROM {$groups_table} WHERE agent_id = %d )",
				"COALESCE( t.location_organization_id, u.organization_id ) IN ( SELECT organization_id FROM {$orgs_table} WHERE agent_id = %d )",
			],
			[ $agent_id, $agent_id ],
		];
	}

	/**
	 * Tickets opened by a given WP user - used by the end-user portal ("My tickets").
	 *
	 * @return array<int, object>
	 */
	public static function get_for_requester( int $wp_user_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE requester_wp_user_id = %d ORDER BY id DESC', $wp_user_id )
		);
	}

	/**
	 * Updates a single field and records the change in the activity log.
	 * Setting the status to a "resolved"/"closed" state automatically stamps
	 * resolved_at/closed_at the first time it happens.
	 *
	 * @param mixed       $new_value
	 * @param string|null $timestamp pass the same value across several fields saved
	 *                               together so the UI can group them into one
	 *                               "Field changes" entry
	 */
	public static function update_field( int $ticket_id, string $field, $new_value, int $actor_wp_user_id, ?string $timestamp = null ): void {
		global $wpdb;

		if ( ! in_array( $field, self::EDITABLE_FIELDS, true ) ) {
			return;
		}

		$ticket = self::get( $ticket_id );

		if ( ! $ticket || (string) $ticket->$field === (string) $new_value ) {
			return;
		}

		$timestamp = $timestamp ?? current_time( 'mysql' );
		$old_value = $ticket->$field;
		$update    = [ $field => $new_value, 'updated_at' => $timestamp ];

		// "First Update" SLA target: the first time anyone other than the
		// requester touches the ticket (any field, not just a comment - see
		// Comment::create() for the comment-side half of this). Actor 0 is
		// the automated escalation job (Sla::run_escalations()), which is not
		// a real update from staff, so it deliberately does not count.
		if ( $actor_wp_user_id && $actor_wp_user_id !== (int) $ticket->requester_wp_user_id && empty( $ticket->first_updated_at ) ) {
			$update['first_updated_at'] = $timestamp;
		}

		if ( 'assigned_agent_id' === $field && $new_value && empty( $ticket->assigned_at ) ) {
			$update['assigned_at'] = $timestamp;
		}

		$auto_assigned_agent_id = null;

		if ( 'status_id' === $field ) {
			$status = Choices::get( (int) $new_value );

			// A status flagged "once only" (e.g. "New") can never be
			// re-entered once the ticket has left it (PLAN.md 7.38 - "un
			// status default ar trebui sa fie posibil doar o data").
			// Checked before anything else changes.
			if ( $status && ! empty( $status->meta['once_only_state'] ) && self::has_left_status( $ticket_id, (int) $new_value ) ) {
				return;
			}

			if ( $status && ! empty( $status->meta['is_resolved_state'] ) && empty( $ticket->resolved_at ) ) {
				$update['resolved_at'] = $timestamp;
			}

			if ( $status && ! empty( $status->meta['is_closed_state'] ) && empty( $ticket->closed_at ) ) {
				$update['closed_at'] = $timestamp;
			}

			// Stops/resumes the SLA clock across an "On Hold" period - which
			// status counts as "on hold" is configurable (meta.is_on_hold_state),
			// the same flag the ticket form uses to show/hide the on-hold-reason
			// field. See Sla::due_dates_after_hold() and PLAN.md SLA section.
			$old_status  = Choices::get( (int) $old_value );
			$was_on_hold = $old_status && ! empty( $old_status->meta['is_on_hold_state'] );
			$is_on_hold  = $status && ! empty( $status->meta['is_on_hold_state'] );

			if ( $is_on_hold && ! $was_on_hold && empty( $ticket->sla_hold_started_at ) ) {
				$update['sla_hold_started_at'] = $timestamp;
			} elseif ( $was_on_hold && ! $is_on_hold && ! empty( $ticket->sla_hold_started_at ) ) {
				$update += Sla::due_dates_after_hold( $ticket, $ticket->sla_hold_started_at, $timestamp );
				$update['sla_hold_started_at'] = null;
			}

			// "Assign to me" happens automatically the first time a ticket
			// enters a status flagged for it (meta.auto_assign_to_actor, e.g.
			// "In Progress"). Never overrides an existing assignment, and
			// only applies when the actor is themselves a
			// real, active agent (a requester, or the automated escalation
			// job - actor 0 - never trigger it, since neither is an agent).
			if ( $status && ! empty( $status->meta['auto_assign_to_actor'] ) && empty( $ticket->assigned_agent_id ) ) {
				$auto_assigned_agent_id = self::agent_id_for_wp_user( $actor_wp_user_id );

				if ( $auto_assigned_agent_id ) {
					$update['assigned_agent_id'] = $auto_assigned_agent_id;
					$update['assigned_at']       = $timestamp;
				}
			}
		}

		$wpdb->update( self::table(), $update, [ 'id' => $ticket_id ] );

		Activity_Log::record( $ticket_id, $actor_wp_user_id, $field, $old_value, $new_value, $timestamp );

		// Logged as its own entry (same timestamp, so it groups with the
		// status change above in the Activity feed) - matches how a human
		// manually assigning an agent through the ticket form is logged.
		if ( $auto_assigned_agent_id ) {
			Activity_Log::record( $ticket_id, $actor_wp_user_id, 'assigned_agent_id', null, $auto_assigned_agent_id, $timestamp );
		}

		// Priority drives which SLA definition applies - re-evaluate the targets,
		// but leave them alone once the ticket is already resolved.
		if ( 'priority_id' === $field && empty( $ticket->resolved_at ) ) {
			Sla::calculate_due_dates( $ticket_id );
		}

		// Notify the requester (PLAN.md: "Email la fiecare... schimbare de
		// status (către end-user)") - skip the vanishingly rare case where the
		// requester somehow changed their own ticket's status.
		if ( 'status_id' === $field && $actor_wp_user_id !== (int) $ticket->requester_wp_user_id ) {
			$new_status = Choices::get( (int) $new_value );

			Email_Notifications::send(
				'status_changed',
				$ticket_id,
				[ Email_Notifications::requester_email( (int) $ticket->requester_wp_user_id ) ],
				Email_Notifications::ticket_placeholders( $ticket, [
					'status' => $new_status ? $new_status->label : '—',
					'url'    => Email_Notifications::portal_url( $ticket_id ),
				] ),
				$actor_wp_user_id
			);
		}
	}

	private static function default_status_id(): int {
		$statuses = Choices::get_list( 'status' );

		return $statuses ? (int) $statuses[0]->id : 0;
	}

	/**
	 * Whether the ticket has, at any point in its history, moved AWAY from
	 * the given status - used to block re-entering a "once only" status
	 * (see update_field()).
	 */
	private static function has_left_status( int $ticket_id, int $status_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . Activity_Log::table() . " WHERE ticket_id = %d AND field_changed = 'status_id' AND old_value = %s LIMIT 1",
			$ticket_id,
			(string) $status_id
		) );
	}

	/**
	 * @return int|null the active agent record id for a WP user, or null if
	 * they aren't a registered, active agent at all (a requester, or actor 0
	 * for the automated SLA escalation job).
	 */
	private static function agent_id_for_wp_user( int $wp_user_id ): ?int {
		global $wpdb;

		if ( ! $wp_user_id ) {
			return null;
		}

		$table = $wpdb->prefix . 'thickgrass_agents';
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d AND is_active = 1", $wp_user_id ) );

		return $id ? (int) $id : null;
	}
}
