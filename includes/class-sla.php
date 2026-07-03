<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SLA definitions (assignment/response/first-update/resolution time targets)
 * and the logic that stamps a ticket's due dates from them. See PLAN.md
 * section 3.2 and the "SLA" Faza 1 feature.
 */
class Sla {

	/** Dimensions a definition can be scoped to, most to least specific for tie-breaking. */
	private const DIMENSIONS = [ 'ticket_type_id', 'category_id', 'organization_id', 'priority_id' ];

	/**
	 * The 4 SLA target types tracked per ticket: which due-date column each
	 * one stamps, which column marks it as actually completed (null means
	 * "still pending"), and a human label for the stats panel/list.
	 *
	 * @var array<string, array{due: string, completed: string, minutes_field: string, label: string}>
	 */
	public const TARGETS = [
		'assignment'     => [ 'due' => 'sla_assignment_due', 'completed' => 'assigned_at', 'minutes_field' => 'assignment_minutes', 'label' => 'Assignment' ],
		'first_response' => [ 'due' => 'sla_response_due', 'completed' => 'first_responded_at', 'minutes_field' => 'response_minutes', 'label' => 'First Response' ],
		'first_update'   => [ 'due' => 'sla_first_update_due', 'completed' => 'first_updated_at', 'minutes_field' => 'first_update_minutes', 'label' => 'First Update' ],
		'resolution'     => [ 'due' => 'sla_resolution_due', 'completed' => 'resolved_at', 'minutes_field' => 'resolution_minutes', 'label' => 'Resolution' ],
	];

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_sla_definitions';
	}

	/**
	 * Picks the best-matching active definition for the given context: a
	 * definition matches when every dimension it specifies (non-null) equals
	 * the context's value for that dimension - dimensions it leaves null act
	 * as wildcards. Among matches, the one specifying the most dimensions
	 * wins ("most specific wins"); ties are broken by DIMENSIONS order
	 * (ticket type > category > organization > priority).
	 *
	 * Falls back to the single definition flagged `is_default` (seeded on
	 * activation, always present, never deletable - see Activator) when
	 * nothing else matches, so there is always an SLA target unless the
	 * admin has deliberately deactivated the default rule too.
	 */
	public static function find_definition( ?int $organization_id, ?int $priority_id, ?int $category_id, ?int $ticket_type_id ): ?object {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE is_active = 1' );

		$context = [
			'organization_id' => $organization_id,
			'priority_id'     => $priority_id,
			'category_id'     => $category_id,
			'ticket_type_id'  => $ticket_type_id,
		];

		$best       = null;
		$best_score = -1;
		$default    = null;

		foreach ( $rows as $row ) {
			if ( ! empty( $row->is_default ) ) {
				$default = $row;
				continue;
			}

			$score = 0;
			$match = true;

			foreach ( $context as $field => $value ) {
				if ( null === $row->$field ) {
					continue;
				}

				if ( (int) $row->$field !== (int) $value ) {
					$match = false;
					break;
				}

				$score++;
			}

			if ( ! $match ) {
				continue;
			}

			if ( $score > $best_score || ( $score === $best_score && self::wins_tie_break( $row, $best ) ) ) {
				$best       = $row;
				$best_score = $score;
			}
		}

		return $best ?? $default;
	}

	private static function wins_tie_break( object $candidate, ?object $current_best ): bool {
		if ( ! $current_best ) {
			return true;
		}

		foreach ( self::DIMENSIONS as $field ) {
			$candidate_set = null !== $candidate->$field;
			$best_set      = null !== $current_best->$field;

			if ( $candidate_set !== $best_set ) {
				return $candidate_set;
			}
		}

		return false;
	}

	/**
	 * Finds the matching SLA definition for a ticket and stamps sla_id plus
	 * all 4 target due dates (see TARGETS). No-ops (leaves previous values
	 * untouched) if no definition matches. Called on ticket creation and
	 * again whenever priority changes (see Ticket::create()/update_field()).
	 * Logs a 'sla_change' activity entry whenever the computed due dates
	 * actually differ from what the ticket had before, powering the SLA
	 * history panel on the ticket screen.
	 */
	public static function calculate_due_dates( int $ticket_id ): void {
		global $wpdb;

		$ticket = Ticket::get( $ticket_id );

		if ( ! $ticket ) {
			return;
		}

		$organization_id = self::organization_id_for_wp_user( (int) $ticket->requester_wp_user_id );
		$priority_id     = $ticket->priority_id ? (int) $ticket->priority_id : null;
		$category_id     = $ticket->category_id ? (int) $ticket->category_id : null;
		$ticket_type_id  = (int) $ticket->ticket_type_id;
		$definition      = self::find_definition( $organization_id, $priority_id, $category_id, $ticket_type_id );

		if ( ! $definition ) {
			return;
		}

		$created = new \DateTimeImmutable( $ticket->created_at, wp_timezone() );
		$update  = [ 'sla_id' => $definition->id, 'sla_escalated_at' => null, 'sla_breach_notified_at' => null ];

		foreach ( self::TARGETS as $target ) {
			$minutes = (int) $definition->{$target['minutes_field']};

			$due = $organization_id
				? Business_Hours::add_minutes( $organization_id, $created, $minutes )
				// No known organization for this requester - no business hours to apply, use plain calendar time.
				: $created->modify( "+{$minutes} minutes" );

			$update[ $target['due'] ] = $due->format( 'Y-m-d H:i:s' );
		}

		self::log_due_date_changes( $ticket, $update );

		$wpdb->update( Ticket::table(), $update, [ 'id' => $ticket_id ] );
	}

	/**
	 * @param array<string, mixed> $update
	 */
	private static function log_due_date_changes( object $ticket, array $update ): void {
		$changes = [];

		foreach ( self::TARGETS as $target ) {
			$old = $ticket->{$target['due']} ?? null;
			$new = $update[ $target['due'] ];

			if ( $old !== $new ) {
				$changes[] = sprintf( '%s: %s', $target['label'], $new );
			}
		}

		if ( ! $changes ) {
			return;
		}

		Activity_Log::record(
			(int) $ticket->id,
			get_current_user_id(),
			'sla_change',
			null,
			sprintf(
				/* translators: %s: comma-separated list of "Target: new due date" */
				__( 'SLA targets recalculated — %s', 'thickgrass' ),
				implode( ', ', $changes )
			)
		);
	}

	/**
	 * Shifts all 4 target deadlines forward by the wall-clock duration a
	 * ticket just spent on hold, so an "On Hold" period does not count
	 * against the SLA clock (called from Ticket::update_field() when a
	 * status with meta.is_on_hold_state is left). Plain calendar time, not
	 * business-hours time, to keep the calculation simple and predictable
	 * regardless of when the hold started/ended. Logs a 'sla_change' entry so
	 * the adjustment shows up in the ticket's SLA history.
	 *
	 * @return array<string, string> columns to merge into the ticket update
	 */
	public static function due_dates_after_hold( object $ticket, string $held_since, string $resumed_at ): array {
		$held_minutes = (int) floor( ( strtotime( $resumed_at ) - strtotime( $held_since ) ) / 60 );

		if ( $held_minutes <= 0 ) {
			return [];
		}

		$columns = [];

		foreach ( self::TARGETS as $target ) {
			$field = $target['due'];

			if ( ! empty( $ticket->$field ) ) {
				$columns[ $field ] = ( new \DateTimeImmutable( $ticket->$field ) )->modify( "+{$held_minutes} minutes" )->format( 'Y-m-d H:i:s' );
			}
		}

		if ( $columns ) {
			Activity_Log::record(
				(int) $ticket->id,
				get_current_user_id(),
				'sla_change',
				null,
				sprintf(
					/* translators: %d: minutes the ticket spent on hold */
					__( 'SLA targets extended by %d minute(s) — On Hold period does not count against the SLA clock.', 'thickgrass' ),
					$held_minutes
				),
				$resumed_at
			);
		}

		return $columns;
	}

	/**
	 * Marks $column with $timestamp only if it is still empty - used for
	 * "first X happened" events (first response, first update) where several
	 * calls may race to claim the same milestone; the `IS NULL` guard makes
	 * only the first one actually stick.
	 */
	public static function maybe_stamp( int $ticket_id, string $column, string $timestamp ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . Ticket::table() . " SET {$column} = %s WHERE id = %d AND {$column} IS NULL",
			$timestamp,
			$ticket_id
		) );
	}

	/**
	 * Runs on the `thickgrass_sla_escalation_check` WP-Cron event (hourly -
	 * see Activator::maybe_schedule_sla_escalation_cron()). Auto-escalates any
	 * unresolved ticket whose resolution deadline has passed, provided its
	 * matched SLA definition opted in via `escalate_to_priority_id`. Each
	 * ticket is only escalated once (`sla_escalated_at` guards against
	 * repeats, and is cleared again if the definition is later recalculated -
	 * see calculate_due_dates()).
	 */
	public static function run_escalations(): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id AS ticket_id, d.escalate_to_priority_id
			FROM " . Ticket::table() . " t
			INNER JOIN " . self::table() . " d ON d.id = t.sla_id
			WHERE t.resolved_at IS NULL
				AND t.sla_escalated_at IS NULL
				AND t.sla_resolution_due IS NOT NULL
				AND t.sla_resolution_due < %s
				AND d.escalate_to_priority_id IS NOT NULL",
			$now
		) );

		foreach ( $rows as $row ) {
			$ticket_id = (int) $row->ticket_id;

			Ticket::update_field( $ticket_id, 'priority_id', (int) $row->escalate_to_priority_id, 0, $now );

			$wpdb->update( Ticket::table(), [ 'sla_escalated_at' => $now ], [ 'id' => $ticket_id ] );

			Activity_Log::record(
				$ticket_id,
				0,
				'sla_escalation',
				null,
				__( 'Resolution SLA breached: priority escalated automatically.', 'thickgrass' ),
				$now
			);
		}
	}

	/**
	 * Runs on the same `thickgrass_sla_escalation_check` cron tick as
	 * run_escalations() (PLAN.md: "Email de avertizare la... depășirea
	 * deadline-ului SLA") - independent of whether escalation is configured
	 * for the matched definition, every unresolved ticket whose resolution
	 * deadline has passed gets exactly one notification email, to whoever is
	 * working it (the assigned agent, or the whole assignment group if
	 * nobody is assigned yet). `sla_breach_notified_at` guards against
	 * repeats, and is cleared again if the definition is later recalculated -
	 * see calculate_due_dates() - so a ticket gets a fresh notification
	 * chance against its new deadline.
	 */
	public static function run_notifications(): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		$ticket_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM " . Ticket::table() . "
			WHERE resolved_at IS NULL
				AND sla_breach_notified_at IS NULL
				AND sla_resolution_due IS NOT NULL
				AND sla_resolution_due < %s",
			$now
		) );

		foreach ( $ticket_ids as $ticket_id ) {
			$ticket_id = (int) $ticket_id;
			$ticket    = Ticket::get( $ticket_id );

			if ( ! $ticket ) {
				continue;
			}

			$agent_email = Email_Notifications::agent_email( $ticket->assigned_agent_id ? (int) $ticket->assigned_agent_id : null );
			$recipients  = $agent_email ? [ $agent_email ] : Email_Notifications::group_agent_emails( $ticket->assignment_group_id ? (int) $ticket->assignment_group_id : null );

			Email_Notifications::send( 'sla_breach', $ticket_id, $recipients, Email_Notifications::ticket_placeholders( $ticket ) );

			$wpdb->update( Ticket::table(), [ 'sla_breach_notified_at' => $now ], [ 'id' => $ticket_id ] );
		}
	}

	/**
	 * Simple, human-facing status for one SLA target on one ticket - "on
	 * time"/"met"/"breached"/"not applicable" instead of raw due-date
	 * timestamps (PLAN.md: dashboard should show meaningful names, not dates).
	 *
	 * @return array{status: string, label: string, class: string}
	 */
	public static function target_status( object $ticket, string $target_key ): array {
		$target        = self::TARGETS[ $target_key ];
		$due_field     = $target['due'];
		$completed_field = $target['completed'];

		$due = $ticket->$due_field ?? null;

		if ( ! $due ) {
			return [ 'status' => 'not_applicable', 'label' => __( 'N/A', 'thickgrass' ), 'class' => 'thickgrass-badge' ];
		}

		$due_at       = new \DateTimeImmutable( $due, wp_timezone() );
		$completed_at = $ticket->$completed_field ?? null;

		if ( $completed_at ) {
			$met = new \DateTimeImmutable( $completed_at, wp_timezone() ) <= $due_at;

			return $met
				? [ 'status' => 'met', 'label' => __( 'Met', 'thickgrass' ), 'class' => 'thickgrass-badge thickgrass-badge-blue' ]
				: [ 'status' => 'breached', 'label' => __( 'Breached', 'thickgrass' ), 'class' => 'thickgrass-badge thickgrass-badge-red' ];
		}

		$breached = new \DateTimeImmutable( 'now', wp_timezone() ) > $due_at;

		return $breached
			? [ 'status' => 'breached', 'label' => __( 'Breached', 'thickgrass' ), 'class' => 'thickgrass-badge thickgrass-badge-red' ]
			: [ 'status' => 'on_time', 'label' => __( 'On Time', 'thickgrass' ), 'class' => 'thickgrass-badge thickgrass-badge-green' ];
	}

	/**
	 * One badge summarizing all 4 targets at once, for compact contexts like
	 * the ticket list (see Dashboard_Page::sla_cell_html()) - "worst wins":
	 * any target breached outranks everything else, then on_time, then met,
	 * then not_applicable (a ticket with no SLA at all).
	 *
	 * @return array{status: string, label: string, class: string}
	 */
	public static function overall_status( object $ticket ): array {
		$severity = [ 'breached' => 3, 'on_time' => 2, 'met' => 1, 'not_applicable' => 0 ];
		$worst    = null;

		foreach ( array_keys( self::TARGETS ) as $target_key ) {
			$status = self::target_status( $ticket, $target_key );

			if ( null === $worst || $severity[ $status['status'] ] > $severity[ $worst['status'] ] ) {
				$worst = $status;
			}
		}

		return $worst;
	}

	private static function organization_id_for_wp_user( int $wp_user_id ): ?int {
		global $wpdb;

		$table           = $wpdb->prefix . 'thickgrass_users';
		$organization_id = $wpdb->get_var( $wpdb->prepare( "SELECT organization_id FROM {$table} WHERE wp_user_id = %d", $wp_user_id ) );

		return $organization_id ? (int) $organization_id : null;
	}
}
