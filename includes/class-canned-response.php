<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Predefined reply templates agents can insert straight into a ticket
 * comment (PLAN.md 7.44, Faza 2). Scoped by BOTH assignment group and
 * location via two independent junction tables, each following the
 * "no rows for a response on a given dimension = wildcard, visible
 * everywhere on that dimension" convention.
 */
class Canned_Response {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_canned_responses';
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * @return array<int, object>
	 */
	public static function all(): array {
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY sort_order ASC, id ASC' );
	}

	/**
	 * Active responses available for a ticket in the given assignment group
	 * and location - a response with no rows at all in one of the junction
	 * tables is treated as available on every value of that dimension.
	 *
	 * @return array<int, object>
	 */
	public static function for_context( ?int $assignment_group_id, ?int $organization_id ): array {
		global $wpdb;

		$table  = self::table();
		$groups = $wpdb->prefix . 'thickgrass_canned_response_groups';
		$orgs   = $wpdb->prefix . 'thickgrass_canned_response_organizations';

		$sql = "SELECT r.* FROM {$table} r
			WHERE r.is_active = 1
			AND (
				NOT EXISTS ( SELECT 1 FROM {$groups} g WHERE g.canned_response_id = r.id )
				OR EXISTS ( SELECT 1 FROM {$groups} g WHERE g.canned_response_id = r.id AND g.assignment_group_id = %d )
			)
			AND (
				NOT EXISTS ( SELECT 1 FROM {$orgs} o WHERE o.canned_response_id = r.id )
				OR EXISTS ( SELECT 1 FROM {$orgs} o WHERE o.canned_response_id = r.id AND o.organization_id = %d )
			)
			ORDER BY r.sort_order ASC, r.id ASC";

		return $wpdb->get_results( $wpdb->prepare( $sql, (int) $assignment_group_id, (int) $organization_id ) );
	}
}
