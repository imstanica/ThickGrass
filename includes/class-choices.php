<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic engine for small configurable value lists
 * (priority, impact, status, categories, call_close_reason etc.).
 * See PLAN.md section 3.1.
 */
class Choices {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_choices';
	}

	/**
	 * Lists known to the plugin. Only the key + label + whether it's hierarchical
	 * are defined in code - the values within each list are 100% admin-editable.
	 *
	 * @return array<string, array{label: string, hierarchical: bool}>
	 */
	public static function get_registered_lists(): array {
		return [
			'priority'          => [ 'label' => __( 'Priority', 'thickgrass' ), 'hierarchical' => false ],
			'impact'            => [ 'label' => __( 'Impact', 'thickgrass' ), 'hierarchical' => false ],
			'status'            => [ 'label' => __( 'Statuses', 'thickgrass' ), 'hierarchical' => false ],
			'category'          => [ 'label' => __( 'Categories', 'thickgrass' ), 'hierarchical' => true ],
			'call_close_reason' => [ 'label' => __( 'Close reason', 'thickgrass' ), 'hierarchical' => false ],
			'asset_type'        => [ 'label' => __( 'Asset type', 'thickgrass' ), 'hierarchical' => false ],
			'assignment_group'  => [ 'label' => __( 'Assignment groups', 'thickgrass' ), 'hierarchical' => false ],
			'ticket_type'       => [ 'label' => __( 'Ticket types', 'thickgrass' ), 'hierarchical' => false ],
			'contact_type'      => [ 'label' => __( 'Contact type', 'thickgrass' ), 'hierarchical' => false ],
			'on_hold_reason'    => [ 'label' => __( 'On hold reasons', 'thickgrass' ), 'hierarchical' => false ],
			'kb_category'       => [ 'label' => __( 'KB categories', 'thickgrass' ), 'hierarchical' => true ],
			'canned_response_category' => [ 'label' => __( 'Canned response categories', 'thickgrass' ), 'hierarchical' => false ],
		];
	}

	/**
	 * @return array<int, object> rows from the requested list, ordered by sort_order
	 */
	public static function get_list( string $list_key, bool $only_active = true ): array {
		global $wpdb;

		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE list_key = %s";
		$args  = [ $list_key ];

		if ( $only_active ) {
			$sql .= ' AND is_active = 1';
		}

		$sql   .= ' ORDER BY sort_order ASC, id ASC';
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		foreach ( $rows as $row ) {
			$row->meta = $row->meta ? json_decode( $row->meta, true ) : [];
		}

		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		if ( $row ) {
			$row->meta = $row->meta ? json_decode( $row->meta, true ) : [];
		}

		return $row ?: null;
	}

	/**
	 * @param array{list_key: string, label: string, slug?: string, parent_id?: int, sort_order?: int, is_active?: bool, meta?: array} $data
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$record                = self::prepare_record( $data );
		$record['created_at']  = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $record );

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$record              = self::prepare_record( $data );
		$record['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table(), $record, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( self::table(), [ 'id' => $id ] );
	}

	/**
	 * Atomically reads-and-increments a numeric value stored inside `meta` (e.g.
	 * ticket_type.next_number) and returns the value BEFORE incrementing (the one
	 * the caller should actually use). Wrapped in a transaction with `FOR UPDATE`
	 * so concurrent ticket creation can never hand out the same number twice.
	 */
	public static function increment_meta_counter( int $id, string $meta_key, int $default = 1 ): int {
		global $wpdb;

		$table = self::table();

		$wpdb->query( 'START TRANSACTION' );

		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT meta FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
		$meta    = $row && $row->meta ? json_decode( $row->meta, true ) : [];
		$current = (int) ( $meta[ $meta_key ] ?? $default );

		$meta[ $meta_key ] = $current + 1;
		$wpdb->update( $table, [ 'meta' => wp_json_encode( $meta ) ], [ 'id' => $id ] );

		$wpdb->query( 'COMMIT' );

		return $current;
	}

	private static function prepare_record( array $data ): array {
		$record = [
			'list_key'   => sanitize_key( $data['list_key'] ),
			'label'      => sanitize_text_field( $data['label'] ),
			'slug'       => sanitize_title( $data['slug'] ?? $data['label'] ),
			'parent_id'  => ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null,
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'is_active'  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'meta'       => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
		];

		return $record;
	}
}
