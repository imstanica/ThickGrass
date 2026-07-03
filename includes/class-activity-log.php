<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-ticket audit trail.
 * See PLAN.md section 3.2.
 */
class Activity_Log {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_activity_log';
	}

	/**
	 * @param mixed       $old_value
	 * @param mixed       $new_value
	 * @param string|null $timestamp pass the same value for several calls that belong to
	 *                               one form submission, so the UI can group them into a
	 *                               single "Field changes" entry (see Dashboard_Page)
	 */
	public static function record( int $ticket_id, int $actor_wp_user_id, string $field_changed, $old_value, $new_value, ?string $timestamp = null ): void {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'ticket_id'        => $ticket_id,
			'actor_wp_user_id' => $actor_wp_user_id,
			'field_changed'    => $field_changed,
			'old_value'        => self::to_string( $old_value ),
			'new_value'        => self::to_string( $new_value ),
			'created_at'       => $timestamp ?? current_time( 'mysql' ),
		] );
	}

	/**
	 * @return array<int, object>
	 */
	public static function for_ticket( int $ticket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d ORDER BY created_at ASC, id ASC', $ticket_id )
		);
	}

	/**
	 * @param mixed $value
	 */
	private static function to_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}
}
