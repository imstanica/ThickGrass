<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A form-builder-defined intake form (PLAN.md 7.45, Faza 2), filled by
 * end-users on the front-end portal via `[thickgrass_custom_form]`. `meta`
 * carries admin-fixed defaults for standard ticket columns (assignment
 * group/assigned agent/location) - applied silently at submission, never
 * shown to the end-user filling the form.
 */
class Custom_Form {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_custom_forms';
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return self::decode_meta( $row );
	}

	public static function get_active_by_slug( string $slug ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s AND is_active = 1', $slug ) );

		return self::decode_meta( $row );
	}

	private static function decode_meta( $row ): ?object {
		if ( ! $row ) {
			return null;
		}

		$row->meta = $row->meta ? json_decode( $row->meta, true ) : [];

		return $row;
	}
}
