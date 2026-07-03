<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for SLA response/resolution time targets, scoped to any combination of
 * organization/priority/category/ticket type (leave a field empty to mean
 * "any"). Matching logic (most specific definition wins) lives in
 * ThickGrass\Sla::find_definition(). See PLAN.md 7.7/7.19 - the "SLA" feature.
 */
class Sla_Definitions_Page extends Abstract_CRUD_Page {

	protected function table_suffix(): string {
		return 'thickgrass_sla_definitions';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-sla-definitions';
	}

	protected function page_title(): string {
		return __( 'SLA definitions', 'thickgrass' );
	}

	/**
	 * Embedded as a tab inside "Configurable lists" (PLAN.md) rather than
	 * owning its own top-level admin page - see class-choices-page.php.
	 */
	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'sla_definitions' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'organization_id', 'label' => __( 'Organization (leave empty for any)', 'thickgrass' ), 'type' => 'select', 'options' => [ Admin_Helpers::class, 'organization_options' ] ],
			[ 'key' => 'ticket_type_id', 'label' => __( 'Ticket type (leave empty for any)', 'thickgrass' ), 'type' => 'select', 'options' => static fn() => Admin_Helpers::choice_options( 'ticket_type' ) ],
			[ 'key' => 'category_id', 'label' => __( 'Category (leave empty for any)', 'thickgrass' ), 'type' => 'select', 'options' => static fn() => Admin_Helpers::choice_options( 'category' ) ],
			[ 'key' => 'priority_id', 'label' => __( 'Priority (leave empty for any)', 'thickgrass' ), 'type' => 'select', 'options' => static fn() => Admin_Helpers::choice_options( 'priority' ) ],
			[ 'key' => 'assignment_minutes', 'label' => __( 'Assignment time (minutes)', 'thickgrass' ), 'type' => 'number', 'required' => true ],
			[ 'key' => 'response_minutes', 'label' => __( 'First response time (minutes)', 'thickgrass' ), 'type' => 'number', 'required' => true ],
			[ 'key' => 'first_update_minutes', 'label' => __( 'First update time (minutes)', 'thickgrass' ), 'type' => 'number', 'required' => true ],
			[ 'key' => 'resolution_minutes', 'label' => __( 'Resolution time (minutes)', 'thickgrass' ), 'type' => 'number', 'required' => true ],
			[ 'key' => 'escalate_to_priority_id', 'label' => __( 'Escalate to priority on breach (leave empty to disable)', 'thickgrass' ), 'type' => 'select', 'options' => static fn() => Admin_Helpers::choice_options( 'priority' ) ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'organization'         => __( 'Organization', 'thickgrass' ),
			'ticket_type'          => __( 'Ticket type', 'thickgrass' ),
			'category'             => __( 'Category', 'thickgrass' ),
			'priority'             => __( 'Priority', 'thickgrass' ),
			'assignment_minutes'   => __( 'Assignment (min)', 'thickgrass' ),
			'response_minutes'     => __( 'First response (min)', 'thickgrass' ),
			'first_update_minutes' => __( 'First update (min)', 'thickgrass' ),
			'resolution_minutes'   => __( 'Resolution (min)', 'thickgrass' ),
			'escalate_to'          => __( 'Escalates to', 'thickgrass' ),
			'is_default'           => __( 'Default', 'thickgrass' ),
			'is_active'            => __( 'Active', 'thickgrass' ),
		];
	}

	/**
	 * The default rule always has to exist (used as the universal fallback
	 * when nothing more specific matches - see Sla::find_definition() and
	 * Activator::maybe_seed_default_sla()), so it can be edited but never
	 * deleted, same pattern as the default Organization.
	 *
	 * Checks `is_default_raw` first: get_display_rows() below overwrites the
	 * real `is_default` column with a translated "Yes"/"No" string for the
	 * list table, and "No" is a non-empty (truthy) string in PHP - checking
	 * `is_default` directly on a display row made every non-default rule
	 * register as protected too, hiding "Delete" for everything. Rows coming
	 * from the plain DB (e.g. the delete handler's get_row()) have no
	 * `is_default_raw` property, so the `??` falls back to the real column.
	 */
	protected function is_row_protected( object $item ): bool {
		return ! empty( $item->is_default_raw ?? $item->is_default );
	}

	protected function get_display_rows(): array {
		$rows          = parent::get_display_rows();
		$organizations = Admin_Helpers::organization_options();
		$ticket_types  = Admin_Helpers::choice_options( 'ticket_type' );
		$categories    = Admin_Helpers::choice_options( 'category' );
		$priorities    = Admin_Helpers::choice_options( 'priority' );

		foreach ( $rows as $row ) {
			$row->organization  = $row->organization_id ? ( $organizations[ (int) $row->organization_id ] ?? '—' ) : __( 'Any', 'thickgrass' );
			$row->ticket_type   = $row->ticket_type_id ? ( $ticket_types[ (int) $row->ticket_type_id ] ?? '—' ) : __( 'Any', 'thickgrass' );
			$row->category      = $row->category_id ? ( $categories[ (int) $row->category_id ] ?? '—' ) : __( 'Any', 'thickgrass' );
			$row->priority      = $row->priority_id ? ( $priorities[ (int) $row->priority_id ] ?? '—' ) : __( 'Any', 'thickgrass' );
			$row->escalate_to   = $row->escalate_to_priority_id ? ( $priorities[ (int) $row->escalate_to_priority_id ] ?? '—' ) : '—';
			$row->is_default_raw = (bool) $row->is_default;
			$row->is_default    = $row->is_default ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
			$row->is_active     = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}
}
