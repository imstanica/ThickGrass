<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick call/interaction log: minimal fields, created manually by an agent
 * (or, in Faza 2, from an inbound email).
 * A call is either converted into a ticket or closed without one. See PLAN.md
 * section 3.2 and the "Calls" feature in the Faza 1 checklist.
 */
class Call {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_calls';
	}

	/**
	 * @param array{
	 *   short_description: string,
	 *   notes?: string,
	 *   contact_type_id?: int|null,
	 *   caller_wp_user_id?: int|null,
	 *   caller_name?: string,
	 *   caller_email?: string,
	 *   assignment_group_id?: int|null,
	 *   location_organization_id?: int|null,
	 *   created_by_agent_id?: int|null,
	 *   source?: string
	 * } $data
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'source'                   => $data['source'] ?? 'manual',
			'contact_type_id'          => $data['contact_type_id'] ?? null,
			'caller_wp_user_id'        => $data['caller_wp_user_id'] ?? null,
			'caller_name'              => sanitize_text_field( $data['caller_name'] ?? '' ),
			'caller_email'             => sanitize_email( $data['caller_email'] ?? '' ),
			'assignment_group_id'      => $data['assignment_group_id'] ?? null,
			'location_organization_id' => $data['location_organization_id'] ?? null,
			'short_description'        => sanitize_text_field( $data['short_description'] ),
			'notes'                    => wp_kses_post( $data['notes'] ?? '' ),
			'status'                   => 'open',
			'created_by_agent_id'      => $data['created_by_agent_id'] ?? null,
			'created_at'               => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * $agent_id (when given) restricts the list to calls that agent logged
	 * themselves - filtered in SQL, not fetched in full and pared down in PHP
	 * (PLAN.md: "tabelul Calls trebuie sa arate doar call-urile create de
	 * agentul respectiv"). `null` means unfiltered (only used internally by
	 * legacy/other call sites, if any - Calls_Page::render_list() always
	 * passes the current agent's id).
	 *
	 * @return array<int, object>
	 */
	public static function get_all( ?int $agent_id = null ): array {
		global $wpdb;

		$sql = 'SELECT * FROM ' . self::table();

		if ( null !== $agent_id ) {
			return $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE created_by_agent_id = %d ORDER BY id DESC', $agent_id ) );
		}

		return $wpdb->get_results( $sql . ' ORDER BY id DESC' );
	}

	public static function close_without_ticket( int $call_id, int $close_reason_id ): void {
		global $wpdb;

		$wpdb->update( self::table(), [
			'status'          => 'closed_no_ticket',
			'close_reason_id' => $close_reason_id,
			'closed_at'       => current_time( 'mysql' ),
		], [ 'id' => $call_id ] );
	}

	/**
	 * Creates a ticket from this call's data and marks the call as converted.
	 * $ticket_data follows the same shape as Ticket::create().
	 */
	public static function convert_to_ticket( int $call_id, array $ticket_data ): int {
		global $wpdb;

		$ticket_id = Ticket::create( $ticket_data );

		$wpdb->update( self::table(), [
			'status'              => 'converted',
			'converted_ticket_id' => $ticket_id,
			'converted_at'        => current_time( 'mysql' ),
		], [ 'id' => $call_id ] );

		return $ticket_id;
	}
}
