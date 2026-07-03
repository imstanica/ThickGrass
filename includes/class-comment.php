<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ticket replies. `is_work_note` distinguishes internal notes (agents/managers
 * only) from public replies (visible to the requester too) - see PLAN.md 3.2.
 */
class Comment {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_comments';
	}

	public static function create( int $ticket_id, int $author_wp_user_id, string $body, bool $is_work_note ): int {
		global $wpdb;

		$created_at = current_time( 'mysql' );

		$wpdb->insert( self::table(), [
			'ticket_id'         => $ticket_id,
			'author_wp_user_id' => $author_wp_user_id,
			'body'              => wp_kses_post( $body ),
			'is_work_note'      => $is_work_note ? 1 : 0,
			'created_at'        => $created_at,
		] );

		self::maybe_stamp_sla_events( $ticket_id, $author_wp_user_id, $is_work_note, $created_at );

		if ( ! $is_work_note ) {
			self::notify_new_reply( $ticket_id, $author_wp_user_id );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Work notes are internal-only and never trigger an email (PLAN.md) - a
	 * public reply from the requester notifies whoever is working the ticket
	 * (the assigned agent, or the whole assignment group if nobody is
	 * assigned yet); a public reply from staff notifies the requester.
	 */
	private static function notify_new_reply( int $ticket_id, int $author_wp_user_id ): void {
		$ticket = Ticket::get( $ticket_id );

		if ( ! $ticket ) {
			return;
		}

		if ( $author_wp_user_id === (int) $ticket->requester_wp_user_id ) {
			$agent_email = Email_Notifications::agent_email( $ticket->assigned_agent_id ? (int) $ticket->assigned_agent_id : null );
			$recipients  = $agent_email ? [ $agent_email ] : Email_Notifications::group_agent_emails( $ticket->assignment_group_id ? (int) $ticket->assignment_group_id : null );
			$url         = admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id );
		} else {
			$recipients = [ Email_Notifications::requester_email( (int) $ticket->requester_wp_user_id ) ];
			$url        = Email_Notifications::portal_url( $ticket_id );
		}

		Email_Notifications::send( 'new_reply', $ticket_id, $recipients, Email_Notifications::ticket_placeholders( $ticket, [ 'url' => $url ] ), $author_wp_user_id );
	}

	/**
	 * "First Update" is any staff activity (public reply or work note);
	 * "First Response" is specifically the first PUBLIC reply, since that is
	 * the one the caller actually sees - see PLAN.md SLA section. Only counts
	 * when the author isn't the ticket's own requester (a requester replying
	 * to their own ticket isn't "staff responding").
	 */
	private static function maybe_stamp_sla_events( int $ticket_id, int $author_wp_user_id, bool $is_work_note, string $created_at ): void {
		$ticket = Ticket::get( $ticket_id );

		if ( ! $ticket || $author_wp_user_id === (int) $ticket->requester_wp_user_id ) {
			return;
		}

		Sla::maybe_stamp( $ticket_id, 'first_updated_at', $created_at );

		if ( ! $is_work_note ) {
			Sla::maybe_stamp( $ticket_id, 'first_responded_at', $created_at );
		}
	}

	/**
	 * @return array<int, object>
	 */
	public static function for_ticket( int $ticket_id, bool $include_work_notes = true ): array {
		global $wpdb;

		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d';
		$args = [ $ticket_id ];

		if ( ! $include_work_notes ) {
			$sql .= ' AND is_work_note = 0';
		}

		$sql .= ' ORDER BY created_at ASC, id ASC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}
}
