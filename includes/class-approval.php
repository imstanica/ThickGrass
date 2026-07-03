<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single approval request on a ticket - an agent asks a specific WP user
 * to Approve/Reject before the ticket proceeds (PLAN.md Faza 3: "Flux de
 * aprobare"), decided straight from the emailed link - see
 * Shortcodes::render_approval()/handle_approval_decision(). No WP login is
 * required to decide: the token itself is the credential, same trust model
 * as a password-reset link.
 *
 * Only one PENDING request is allowed per ticket at a time (see
 * has_pending()) - the automatic status transitions below assume a single
 * outcome per ticket, which would not make sense with two concurrent
 * requests racing each other. A ticket can still accumulate a HISTORY of
 * multiple past requests, just never two pending ones simultaneously.
 *
 * Whether the "Request approval" action is even offered is controlled by two
 * independently configurable triggers (PLAN.md 7.36), both read from
 * `wp_thickgrass_choices.meta` - nothing hardcoded to a specific ticket type
 * or status name:
 * - the ticket's own type must have `meta.enables_approval` (Configurable
 *   lists → Ticket type)
 * - the ticket's CURRENT status must have `meta.allows_approval_request`
 *   (Configurable lists → Status)
 * See can_request().
 */
class Approval {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_approvals';
	}

	public static function create( int $ticket_id, int $approver_wp_user_id, int $requested_by_wp_user_id, string $comment = '' ): int {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'ticket_id'               => $ticket_id,
			'approver_wp_user_id'     => $approver_wp_user_id,
			'requested_by_wp_user_id' => $requested_by_wp_user_id,
			'status'                  => 'pending',
			'token'                   => wp_generate_password( 32, false ),
			'comment'                 => $comment,
			'requested_at'            => current_time( 'mysql' ),
		] );

		$approval_id = (int) $wpdb->insert_id;

		self::notify_requested( $approval_id );
		self::apply_ticket_type_transition( $ticket_id, $requested_by_wp_user_id, 'awaiting_approval_status_id', 'awaiting_approval_on_hold_reason_id' );

		return $approval_id;
	}

	/**
	 * Whether there is already a request in flight for this ticket - see the
	 * class docblock for why only one is allowed at a time.
	 */
	public static function has_pending( int $ticket_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE ticket_id = %d AND status = %s LIMIT 1',
			$ticket_id,
			'pending'
		) );
	}

	/**
	 * Whether the "Request approval" action should be offered on this ticket
	 * right now - see the class docblock for the two independent triggers.
	 */
	public static function can_request( object $ticket ): bool {
		if ( self::has_pending( (int) $ticket->id ) ) {
			return false;
		}

		$ticket_type = Choices::get( (int) $ticket->ticket_type_id );
		$status      = Choices::get( (int) $ticket->status_id );

		return $ticket_type && ! empty( $ticket_type->meta['enables_approval'] )
			&& $status && ! empty( $status->meta['allows_approval_request'] );
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	public static function get_by_token( string $token ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ) );

		return $row ?: null;
	}

	/**
	 * @return array<int, object>
	 */
	public static function for_ticket( int $ticket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d ORDER BY requested_at DESC', $ticket_id )
		);
	}

	/**
	 * No-op if the request was already decided (or doesn't exist) - a stale
	 * or reused link can't flip a decision back and forth.
	 *
	 * @param string $decision 'approved'|'rejected'
	 */
	public static function decide( int $id, string $decision ): void {
		global $wpdb;

		$approval = self::get( $id );

		if ( ! $approval || 'pending' !== $approval->status ) {
			return;
		}

		$wpdb->update( self::table(), [
			'status'     => $decision,
			'decided_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );

		Activity_Log::record( (int) $approval->ticket_id, (int) $approval->approver_wp_user_id, 'approval_decision', 'pending', $decision );

		self::notify_decided( $id, $decision );

		$status_meta_key = 'approved' === $decision ? 'approved_status_id' : 'rejected_status_id';
		$reason_meta_key = 'approved' === $decision ? 'approved_on_hold_reason_id' : 'rejected_on_hold_reason_id';
		self::apply_ticket_type_transition( (int) $approval->ticket_id, (int) $approval->approver_wp_user_id, $status_meta_key, $reason_meta_key );
	}

	/**
	 * Moves the ticket to whichever status (and, optionally, on_hold_reason)
	 * is configured on its own ticket type for this moment
	 * (requested/approved/rejected) - through Ticket::update_field(), so
	 * every normal side effect of a status change already applies for free:
	 * activity log entry, on-hold SLA pause/resume (if that status is ALSO
	 * flagged "on hold" - see the class docblock), resolved/closed
	 * timestamps, and the existing status_changed email. Both calls share one
	 * timestamp so they group into a single "Field changes" entry in the
	 * Activity feed, same as a manual multi-field save. A no-op if the ticket
	 * type has nothing configured for the status meta key; the reason is
	 * applied only if a status was actually set.
	 */
	private static function apply_ticket_type_transition( int $ticket_id, int $actor_wp_user_id, string $status_meta_key, string $reason_meta_key ): void {
		$ticket = Ticket::get( $ticket_id );

		if ( ! $ticket ) {
			return;
		}

		$ticket_type = Choices::get( (int) $ticket->ticket_type_id );
		$status_id   = $ticket_type->meta[ $status_meta_key ] ?? null;

		if ( ! $status_id ) {
			return;
		}

		$timestamp          = current_time( 'mysql' );
		$on_hold_reason_id  = $ticket_type->meta[ $reason_meta_key ] ?? null;

		Ticket::update_field( $ticket_id, 'status_id', (int) $status_id, $actor_wp_user_id, $timestamp );

		if ( $on_hold_reason_id ) {
			Ticket::update_field( $ticket_id, 'on_hold_reason_id', (int) $on_hold_reason_id, $actor_wp_user_id, $timestamp );
		}
	}

	public static function decision_url( string $token ): string {
		$page_id = (int) get_option( 'thickgrass_page_approval' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		return add_query_arg( 'token', $token, $url );
	}

	private static function notify_requested( int $approval_id ): void {
		$approval = self::get( $approval_id );
		$ticket   = $approval ? Ticket::get( (int) $approval->ticket_id ) : null;
		$approver = $approval ? get_userdata( (int) $approval->approver_wp_user_id ) : null;

		if ( ! $ticket || ! $approver ) {
			return;
		}

		$requested_by = get_userdata( (int) $approval->requested_by_wp_user_id );

		Email_Notifications::send(
			'approval_requested',
			(int) $ticket->id,
			[ $approver->user_email ],
			Email_Notifications::ticket_placeholders( $ticket, [
				'requested_by_name' => $requested_by ? $requested_by->display_name : '—',
				'url'               => self::decision_url( $approval->token ),
			] ),
			(int) $approval->requested_by_wp_user_id
		);
	}

	private static function notify_decided( int $approval_id, string $decision ): void {
		$approval     = self::get( $approval_id );
		$ticket       = $approval ? Ticket::get( (int) $approval->ticket_id ) : null;
		$requested_by = $approval ? get_userdata( (int) $approval->requested_by_wp_user_id ) : null;

		if ( ! $ticket || ! $requested_by ) {
			return;
		}

		$approver = get_userdata( (int) $approval->approver_wp_user_id );

		Email_Notifications::send(
			'approval_decided',
			(int) $ticket->id,
			[ $requested_by->user_email ],
			Email_Notifications::ticket_placeholders( $ticket, [
				'approver_name' => $approver ? $approver->display_name : '—',
				'decision'      => ucfirst( $decision ),
				'url'           => admin_url( 'admin.php?page=thickgrass&view=' . $ticket->id ),
			] ),
			(int) $approval->approver_wp_user_id
		);
	}
}
