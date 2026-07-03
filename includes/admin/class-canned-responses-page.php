<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;
use ThickGrass\Email_Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embedded as a tab inside "Configurable lists" (PLAN.md 7.44, Faza 2),
 * same pattern as Agents_Page: main fields + a many-to-many checkbox list
 * (here, two of them - assignment groups and locations) saved in the same
 * <form> as the main fields.
 */
class Canned_Responses_Page extends Abstract_CRUD_Page {

	protected function table_suffix(): string {
		return 'thickgrass_canned_responses';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-canned-responses';
	}

	protected function page_title(): string {
		return __( 'Canned responses', 'thickgrass' );
	}

	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'canned_responses' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'title', 'label' => __( 'Title', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'body', 'label' => __( 'Body', 'thickgrass' ), 'type' => 'wysiwyg' ],
			[ 'key' => 'category_id', 'label' => __( 'Category', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'category_options' ] ],
			[ 'key' => 'sort_order', 'label' => __( 'Sort order', 'thickgrass' ), 'type' => 'number', 'default' => 0 ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'title'      => __( 'Title', 'thickgrass' ),
			'category'   => __( 'Category', 'thickgrass' ),
			'groups'     => __( 'Assignment groups', 'thickgrass' ),
			'locations'  => __( 'Locations', 'thickgrass' ),
			'sort_order' => __( 'Order', 'thickgrass' ),
			'is_active'  => __( 'Active', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$row->category  = $row->category_id ? ( $this->category_options()[ (int) $row->category_id ] ?? '—' ) : '—';
			$row->groups    = implode( ', ', wp_list_pluck( $this->get_response_groups( (int) $row->id ), 'label' ) ) ?: __( 'All groups', 'thickgrass' );
			$row->locations = implode( ', ', array_map(
				static fn( $org ) => Admin_Helpers::format_organization_location( $org->location, $org->name ),
				$this->get_response_organizations( (int) $row->id )
			) ) ?: __( 'All locations', 'thickgrass' );
			$row->is_active = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}

	/**
	 * Public (not protected) - passed as an `[$this, 'category_options']`
	 * callable into Generic_Form, same reasoning as Kb_Page::category_options().
	 *
	 * @return array<int, string> canned_response_category choice id => label
	 */
	public function category_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'canned_response_category' ) as $category ) {
			$options[ $category->id ] = $category->label;
		}

		return $options;
	}

	/**
	 * Same reasoning as Agents_Page::extra_form_fields() - kept in the same
	 * <form> as the main fields so saving never silently wipes the other
	 * form's data. Leaving both checkbox lists untouched (no rows checked)
	 * means "available everywhere on that dimension" - see Canned_Response::for_context().
	 */
	protected function extra_form_fields( ?object $editing ): void {
		$this->render_merge_field_buttons();

		if ( ! $editing ) {
			echo '<p>' . esc_html__( 'Assignment groups and locations can be set after the response is created.', 'thickgrass' ) . '</p>';
			return;
		}

		$all_groups   = Choices::get_list( 'assignment_group' );
		$assigned_ids = wp_list_pluck( $this->get_response_groups( (int) $editing->id ), 'id' );

		echo '<h3>' . esc_html__( 'Assignment groups (leave all unchecked to allow every group)', 'thickgrass' ) . '</h3>';

		foreach ( $all_groups as $group ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="assignment_groups[]" value="%1$d" %2$s /> %3$s</label>',
				$group->id,
				checked( in_array( (int) $group->id, $assigned_ids, true ), true, false ),
				esc_html( $group->label )
			);
		}

		$this->render_locations_checkboxes( $editing );
	}

	/**
	 * "Insert" quick buttons for the same {ticket_number}/{title}/etc. tokens
	 * email templates use (PLAN.md 7.46: "sa existe functii de genu 'Hello
	 * <user>' ... ca shortcoduri") - inserts at the Body editor's cursor,
	 * whether it's currently in TinyMCE Visual mode or plain Text mode.
	 */
	private function render_merge_field_buttons(): void {
		echo '<p><strong>' . esc_html__( 'Insert into Body:', 'thickgrass' ) . '</strong> ';

		foreach ( Email_Notifications::placeholder_keys() as $key => $label ) {
			printf(
				'<button type="button" class="button button-small thickgrass-merge-field-btn" data-key="%1$s">%2$s</button> ',
				esc_attr( $key ),
				esc_html( $label )
			);
		}

		echo '</p>';
		?>
		<script>
		( function () {
			document.querySelectorAll( '.thickgrass-merge-field-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var token  = '{' + btn.dataset.key + '}';
					var editor = window.tinymce && tinymce.get( 'tg_body' );

					if ( editor && ! editor.isHidden() ) {
						editor.execCommand( 'mceInsertContent', false, token );
						return;
					}

					var textarea = document.getElementById( 'tg_body' );

					if ( textarea ) {
						var pos = textarea.selectionStart || textarea.value.length;
						textarea.value = textarea.value.slice( 0, pos ) + token + textarea.value.slice( pos );
						textarea.focus();
					}
				} );
			} );
		} )();
		</script>
		<?php
	}

	private function render_locations_checkboxes( object $editing ): void {
		$all_locations = Admin_Helpers::organization_location_options();
		$assigned_ids  = wp_list_pluck( $this->get_response_organizations( (int) $editing->id ), 'id' );

		echo '<h3>' . esc_html__( 'Locations (leave all unchecked to allow every location)', 'thickgrass' ) . '</h3>';

		foreach ( $all_locations as $organization_id => $location ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="response_organizations[]" value="%1$d" %2$s /> %3$s</label>',
				$organization_id,
				checked( in_array( (int) $organization_id, $assigned_ids, true ), true, false ),
				esc_html( $location )
			);
		}
	}

	protected function after_save( int $id ): void {
		global $wpdb;

		$groups_table = $wpdb->prefix . 'thickgrass_canned_response_groups';
		$wpdb->delete( $groups_table, [ 'canned_response_id' => $id ] );

		foreach ( array_map( 'intval', wp_unslash( $_POST['assignment_groups'] ?? [] ) ) as $group_id ) {
			$wpdb->insert( $groups_table, [ 'canned_response_id' => $id, 'assignment_group_id' => $group_id ] );
		}

		$organizations_table = $wpdb->prefix . 'thickgrass_canned_response_organizations';
		$wpdb->delete( $organizations_table, [ 'canned_response_id' => $id ] );

		foreach ( array_map( 'intval', wp_unslash( $_POST['response_organizations'] ?? [] ) ) as $organization_id ) {
			$wpdb->insert( $organizations_table, [ 'canned_response_id' => $id, 'organization_id' => $organization_id ] );
		}
	}

	/**
	 * @return array<int, object>
	 */
	private function get_response_groups( int $response_id ): array {
		global $wpdb;

		$choices_table = Choices::table();
		$junction      = $wpdb->prefix . 'thickgrass_canned_response_groups';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.label FROM {$choices_table} c
				INNER JOIN {$junction} j ON j.assignment_group_id = c.id
				WHERE j.canned_response_id = %d AND c.list_key = 'assignment_group'",
				$response_id
			)
		);
	}

	/**
	 * @return array<int, object> objects with ->id, ->name, ->location
	 */
	private function get_response_organizations( int $response_id ): array {
		global $wpdb;

		$organizations_table = $wpdb->prefix . 'thickgrass_organizations';
		$junction             = $wpdb->prefix . 'thickgrass_canned_response_organizations';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.name, o.location FROM {$organizations_table} o
				INNER JOIN {$junction} j ON j.organization_id = o.id
				WHERE j.canned_response_id = %d",
				$response_id
			)
		);
	}
}
