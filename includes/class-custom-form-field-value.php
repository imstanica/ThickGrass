<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A submitted answer to one Custom_Form_Field, attached to the ticket the
 * submission created (PLAN.md 7.45, Faza 2). For field_type = 'file', `value`
 * holds a `wp_thickgrass_attachments.id` instead of literal text - the upload
 * itself is stored as a normal ticket attachment (see ThickGrass\Attachment),
 * not duplicated here.
 */
class Custom_Form_Field_Value {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_custom_form_field_values';
	}

	public static function create( int $ticket_id, int $custom_form_field_id, string $value ): int {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'ticket_id'             => $ticket_id,
			'custom_form_field_id'  => $custom_form_field_id,
			'value'                 => $value,
		] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Joined with the field definition, so callers get ->label/->field_type
	 * alongside ->value without a second query.
	 *
	 * @return array<int, object>
	 */
	public static function for_ticket( int $ticket_id ): array {
		global $wpdb;

		$values_table = self::table();
		$fields_table = Custom_Form_Field::table();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT v.value, f.label, f.field_type FROM {$values_table} v
			INNER JOIN {$fields_table} f ON f.id = v.custom_form_field_id
			WHERE v.ticket_id = %d ORDER BY f.sort_order ASC, f.id ASC",
			$ticket_id
		) );
	}
}
