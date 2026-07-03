<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;
use ThickGrass\Custom_Form_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form builder admin screen - a tab under "Setup" (PLAN.md 7.49: "muta si
 * Custom forms tot in Setup sub Contact type"), same embedding pattern as
 * Kb_Page/Canned_Responses_Page (`base_url_args()` override). Editing a form
 * reveals a second, nested list+form for its Fields (render_extra()),
 * reusing Generic_Form/Generic_List_Table directly rather than forcing a
 * second Abstract_CRUD_Page into the same screen - see handle_extra_actions()
 * for why: the nested entity needs its own query-arg names (`field_edit`,
 * `field_action`/`field_id`) to avoid colliding with the parent form's own
 * `edit`/`action`/`id`.
 */
class Custom_Forms_Page extends Abstract_CRUD_Page {

	private const FIELD_SAVE_ACTION = 'thickgrass_custom_form_field_save';

	protected function table_suffix(): string {
		return 'thickgrass_custom_forms';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-custom-forms';
	}

	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'custom_forms' ];
	}

	protected function page_title(): string {
		return __( 'Custom forms', 'thickgrass' );
	}

	protected function fields(): array {
		return [
			[ 'key' => 'title', 'label' => __( 'Title', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'slug', 'label' => __( 'Slug (used as [thickgrass_custom_form slug="..."])', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'ticket_type_id', 'label' => __( 'Ticket type created on submission', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'ticket_type_options' ], 'required' => true ],
			[ 'key' => 'description', 'label' => __( 'Description (shown to the end-user above the form)', 'thickgrass' ), 'type' => 'textarea' ],
			[ 'key' => 'meta_assignment_group_id', 'label' => __( 'Default assignment group (hidden routing - not shown to the end-user)', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'assignment_group_options' ] ],
			[ 'key' => 'meta_assigned_agent_id', 'label' => __( 'Default assigned agent (hidden routing - not shown to the end-user)', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'agent_options' ] ],
			[ 'key' => 'meta_location_organization_id', 'label' => __( 'Default location (hidden routing - not shown to the end-user)', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'location_options' ] ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'title'       => __( 'Title', 'thickgrass' ),
			'slug'        => __( 'Slug', 'thickgrass' ),
			'ticket_type' => __( 'Ticket type', 'thickgrass' ),
			'fields'      => __( 'Fields', 'thickgrass' ),
			'is_active'   => __( 'Active', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$type              = $row->ticket_type_id ? Choices::get( (int) $row->ticket_type_id ) : null;
			$row->ticket_type  = $type ? $type->label : '—';
			$row->fields       = count( Custom_Form_Field::for_form( (int) $row->id ) );
			$row->is_active    = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}

	protected function insert_row( array $data ): int {
		return parent::insert_row( $this->pack_meta( $data ) );
	}

	protected function update_row( int $id, array $data ): void {
		parent::update_row( $id, $this->pack_meta( $data ) );
	}

	/**
	 * Moves every meta_* key posted by fields() into the `meta` JSON column -
	 * same convention as Choices_Page::extract_meta(), duplicated rather than
	 * shared since that one is private and tied to the Choices flat-array shape.
	 */
	private function pack_meta( array $data ): array {
		$meta = [];

		foreach ( $data as $key => $value ) {
			if ( 0 === strpos( $key, 'meta_' ) ) {
				if ( null !== $value && '' !== $value ) {
					$meta[ substr( $key, 5 ) ] = (int) $value;
				}
				unset( $data[ $key ] );
			}
		}

		$data['meta'] = wp_json_encode( $meta );

		if ( isset( $data['slug'] ) ) {
			$data['slug'] = sanitize_title( $data['slug'] );
		}

		return $data;
	}

	/**
	 * Public (not protected) - passed as an `[$this, 'ticket_type_options']`
	 * callable into Generic_Form, same reasoning as
	 * Abstract_CRUD_Page::wp_users_options().
	 *
	 * @return array<int, string>
	 */
	public function ticket_type_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'ticket_type' ) as $type ) {
			$options[ $type->id ] = $type->label;
		}

		return $options;
	}

	/** @return array<int, string> */
	public function assignment_group_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'assignment_group' ) as $group ) {
			$options[ $group->id ] = $group->label;
		}

		return $options;
	}

	/** @return array<int, string> */
	public function agent_options(): array {
		return Admin_Helpers::agent_options();
	}

	/** @return array<int, string> */
	public function location_options(): array {
		return Admin_Helpers::organization_location_options();
	}

	protected function render_extra( ?object $editing ): void {
		if ( ! $editing ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Save the form above first - its Fields and shortcode appear here once it exists.', 'thickgrass' ) . '</p></div>';
			return;
		}

		echo '<h2>' . esc_html__( 'Shortcode', 'thickgrass' ) . '</h2>';
		Admin_Helpers::render_shortcode_box( '[thickgrass_custom_form slug="' . $editing->slug . '"]' );

		$this->render_fields_manager( (int) $editing->id );
	}

	private function render_fields_manager( int $form_id ): void {
		$editing_field_id = isset( $_GET['field_edit'] ) ? (int) $_GET['field_edit'] : 0;
		$editing_field    = $editing_field_id ? Custom_Form_Field::get( $editing_field_id ) : null;

		// Only ever operate on a field that actually belongs to this form -
		// guards against a forged `field_edit` id pointing at another form.
		if ( $editing_field && (int) $editing_field->custom_form_id !== $form_id ) {
			$editing_field = null;
		}

		echo '<h2>' . esc_html__( 'Fields', 'thickgrass' ) . '</h2>';

		$form_data = $editing_field ? (array) $editing_field : [];

		if ( $editing_field ) {
			$form_data['options'] = implode( "\n", Custom_Form_Field::options_list( $editing_field ) );
		}

		echo '<form method="post">';
		wp_nonce_field( self::FIELD_SAVE_ACTION, self::FIELD_SAVE_ACTION . '_nonce' );
		echo '<input type="hidden" name="custom_form_id" value="' . esc_attr( $form_id ) . '" />';
		echo '<input type="hidden" name="field_id" value="' . esc_attr( $editing_field->id ?? 0 ) . '" />';

		Generic_Form::render( $this->field_row_defs(), $form_data );

		submit_button( $editing_field ? __( 'Save field', 'thickgrass' ) : __( 'Add field', 'thickgrass' ) );
		echo '</form>';

		$this->render_field_type_toggle_script();

		$table = new Generic_List_Table( [
			'singular'      => 'custom_form_field',
			'plural'        => 'custom_form_fields',
			'primary_key'   => 'id',
			'columns'       => [
				'label'       => __( 'Label', 'thickgrass' ),
				'field_key'   => __( 'Key', 'thickgrass' ),
				'field_type'  => __( 'Type', 'thickgrass' ),
				'is_required' => __( 'Required', 'thickgrass' ),
				'sort_order'  => __( 'Order', 'thickgrass' ),
			],
			'data_provider' => static function () use ( $form_id ) {
				$rows = Custom_Form_Field::for_form( $form_id );

				foreach ( $rows as $row ) {
					$row->is_required = $row->is_required ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
				}

				return $rows;
			},
			'row_actions'   => function ( $item ) use ( $form_id ) {
				$edit_url   = $this->build_url( [ 'edit' => $form_id, 'field_edit' => $item->id ] );
				$delete_url = wp_nonce_url(
					$this->build_url( [ 'edit' => $form_id, 'field_action' => 'delete', 'field_id' => $item->id ] ),
					'thickgrass_custom_form_field_delete_' . $item->id
				);

				return [
					'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'thickgrass' ) ),
					'delete' => sprintf(
						'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
						esc_url( $delete_url ),
						esc_js( __( 'Are you sure you want to delete this field?', 'thickgrass' ) ),
						esc_html__( 'Delete', 'thickgrass' )
					),
				];
			},
		] );

		$table->prepare_items();
		$table->display();
	}

	/**
	 * Hides the "Choices for the Choice type" row unless the Choice type is
	 * actually selected (PLAN.md 7.46: "sa ne concentram mai mult pe design
	 * si asezarea fieldurilor") - it only ever means something for that one
	 * type, and was previously shown for every field regardless.
	 */
	private function render_field_type_toggle_script(): void {
		?>
		<script>
		( function () {
			var typeSelect = document.getElementById( 'tg-field_type' );
			var optionsRow = document.getElementById( 'tg-options' ) ? document.getElementById( 'tg-options' ).closest( 'tr' ) : null;

			if ( ! typeSelect || ! optionsRow ) {
				return;
			}

			function toggle() {
				optionsRow.style.display = 'select' === typeSelect.value ? '' : 'none';
			}

			typeSelect.addEventListener( 'change', toggle );
			toggle();
		} )();
		</script>
		<?php
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function field_row_defs(): array {
		return [
			[ 'key' => 'label', 'label' => __( 'Label', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'field_key', 'label' => __( 'Field key (unique within this form)', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[
				'key'      => 'field_type',
				'label'    => __( 'Type', 'thickgrass' ),
				'type'     => 'select',
				'required' => true,
				'options'  => [
					'text'     => __( 'Text', 'thickgrass' ),
					'textarea' => __( 'Text area', 'thickgrass' ),
					'select'   => __( 'Choice (dropdown)', 'thickgrass' ),
					'checkbox' => __( 'Checkbox', 'thickgrass' ),
					'file'     => __( 'File upload', 'thickgrass' ),
				],
			],
			[ 'key' => 'options', 'label' => __( 'Choices for the Choice type (one per line)', 'thickgrass' ), 'type' => 'textarea' ],
			[ 'key' => 'is_required', 'label' => __( 'Required', 'thickgrass' ), 'type' => 'checkbox' ],
			[ 'key' => 'sort_order', 'label' => __( 'Sort order', 'thickgrass' ), 'type' => 'number', 'default' => 0 ],
		];
	}

	/**
	 * Runs BEFORE the main form's save/delete (see Abstract_CRUD_Page::handle_actions()) -
	 * handles the nested Fields sub-form under its own query args/nonce so it
	 * never collides with the parent Custom Form's own edit/delete.
	 */
	protected function handle_extra_actions(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_custom_form_fields';

		if ( isset( $_GET['field_action'], $_GET['field_id'], $_GET['edit'] ) && 'delete' === $_GET['field_action'] ) {
			$field_id = (int) $_GET['field_id'];
			check_admin_referer( 'thickgrass_custom_form_field_delete_' . $field_id );

			// Only ever delete a field that actually belongs to the form being
			// edited - same guard already applied to field_edit above, closes
			// the same forged-id gap for the delete action.
			$field = Custom_Form_Field::get( $field_id );

			if ( $field && (int) $field->custom_form_id === (int) $_GET['edit'] ) {
				$wpdb->delete( $table, [ 'id' => $field_id ] );
			}

			wp_safe_redirect( $this->build_url( [ 'edit' => (int) $_GET['edit'] ] ) );
			exit;
		}

		if ( ! isset( $_POST[ self::FIELD_SAVE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::FIELD_SAVE_ACTION . '_nonce' ], self::FIELD_SAVE_ACTION )
		) {
			return;
		}

		$data                   = Generic_Form::sanitize( $this->field_row_defs(), wp_unslash( $_POST ) );
		$data['field_key']      = sanitize_key( $data['field_key'] );
		$data['custom_form_id'] = (int) $_POST['custom_form_id'];

		$lines           = array_filter( array_map( 'trim', explode( "\n", wp_unslash( $_POST['options'] ?? '' ) ) ) );
		$data['options'] = wp_json_encode( array_values( $lines ) );

		$field_id = isset( $_POST['field_id'] ) ? (int) $_POST['field_id'] : 0;

		if ( $field_id ) {
			$wpdb->update( $table, $data, [ 'id' => $field_id ] );
		} else {
			$wpdb->insert( $table, $data );
		}

		wp_safe_redirect( $this->build_url( [ 'edit' => $data['custom_form_id'] ] ) );
		exit;
	}
}
