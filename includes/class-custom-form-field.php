<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single field definition on a Custom_Form (PLAN.md 7.45, Faza 2).
 * `options` holds a JSON array of strings, only meaningful for
 * field_type = 'select'.
 */
class Custom_Form_Field {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_custom_form_fields';
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * @return array<int, object>
	 */
	public static function for_form( int $custom_form_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE custom_form_id = %d ORDER BY sort_order ASC, id ASC', $custom_form_id )
		);
	}

	/**
	 * @return array<int, string> the field's own choice options, decoded
	 */
	public static function options_list( object $field ): array {
		$options = $field->options ? json_decode( $field->options, true ) : [];

		return is_array( $options ) ? $options : [];
	}
}
