<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved ticket list filters ("List Views"). A view with
 * `agent_wp_user_id = null` is shared/visible to every agent. See PLAN.md 3.2.
 */
class View {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_views';
	}

	/**
	 * The agent's own views plus every shared view.
	 *
	 * @return array<int, object>
	 */
	public static function for_agent( int $wp_user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE agent_wp_user_id = %d OR agent_wp_user_id IS NULL ORDER BY sort_order ASC, id ASC',
				$wp_user_id
			)
		);

		foreach ( $rows as $row ) {
			$row->filters = $row->filters ? json_decode( $row->filters, true ) : [];
		}

		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		if ( $row ) {
			$row->filters = $row->filters ? json_decode( $row->filters, true ) : [];
		}

		return $row ?: null;
	}

	/**
	 * @param array{agent_wp_user_id: int|null, name: string, filters: array<string, int>} $data
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'agent_wp_user_id' => $data['agent_wp_user_id'] ?? null,
			'name'             => sanitize_text_field( $data['name'] ),
			'filters'          => wp_json_encode( $data['filters'] ?? [] ),
			'is_default'       => ! empty( $data['is_default'] ) ? 1 : 0,
			'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'       => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( self::table(), [ 'id' => $id ] );
	}
}
