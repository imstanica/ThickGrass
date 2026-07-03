<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable form rendering + sanitization for any ThickGrass entity,
 * based on an array of field definitions (see PLAN.md 4.1).
 *
 * Field definition:
 * [
 *   'key'      => 'label',
 *   'label'    => 'Label',
 *   'type'     => 'text'|'textarea'|'number'|'checkbox'|'select'|'search_select'|'wysiwyg',
 *   'options'  => array|callable (select only),
 *   'required' => bool,
 * ]
 */
class Generic_Form {

	/**
	 * @param array<int, array<string, mixed>> $fields
	 * @param object|array                      $data
	 */
	public static function render( array $fields, $data = [] ): void {
		echo '<table class="form-table"><tbody>';

		foreach ( $fields as $field ) {
			$key   = $field['key'];
			$value = self::get_field_value( $data, $key, $field['default'] ?? '' );

			echo '<tr>';
			echo '<th scope="row"><label for="tg-' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			echo '<td>';
			self::render_input( $field, $value );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_input( array $field, $value ): void {
		$key      = $field['key'];
		$id_attr  = 'tg-' . esc_attr( $key );
		$required = ! empty( $field['required'] ) ? ' required' : '';

		switch ( $field['type'] ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" class="large-text" rows="4"%3$s>%4$s</textarea>',
					$id_attr,
					esc_attr( $key ),
					$required,
					esc_textarea( $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s />',
					$id_attr,
					esc_attr( $key ),
					esc_attr( $value ),
					$required
				);
				break;

			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s />',
					$id_attr,
					esc_attr( $key ),
					checked( (bool) $value, true, false )
				);
				break;

			case 'select':
				$options = is_callable( $field['options'] ) ? call_user_func( $field['options'] ) : ( $field['options'] ?? [] );

				printf( '<select id="%1$s" name="%2$s"%3$s>', $id_attr, esc_attr( $key ), $required );

				if ( empty( $field['required'] ) ) {
					echo '<option value="">—</option>';
				}

				foreach ( $options as $option_value => $option_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;

			// A combobox (text input + filterable dropdown) instead of a plain
			// <select> for option lists that can get long (users, organizations)
			// - see Admin_Helpers::render_combobox(). Needs
			// Admin_Helpers::render_search_select_script() once per page,
			// added unconditionally in Abstract_CRUD_Page::render_form().
			case 'search_select':
				$options = is_callable( $field['options'] ) ? call_user_func( $field['options'] ) : ( $field['options'] ?? [] );

				Admin_Helpers::render_combobox( $key, $options, $value ? (int) $value : null );
				break;

			// WP's own TinyMCE editor (Knowledge Base article content, PLAN.md
			// 7.43) - the editor id CANNOT contain hyphens (breaks TinyMCE),
			// unlike every other field's `tg-<key>` id, hence the str_replace.
			case 'wysiwyg':
				wp_editor( $value, str_replace( '-', '_', $id_attr ), [
					'textarea_name' => $key,
					'textarea_rows' => 12,
				] );
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text"%4$s />',
					$id_attr,
					esc_attr( $key ),
					esc_attr( $value ),
					$required
				);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 * @param array<string, mixed>              $posted typically $_POST
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $fields, array $posted ): array {
		$clean = [];

		foreach ( $fields as $field ) {
			$key = $field['key'];

			switch ( $field['type'] ) {
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( $posted[ $key ] ?? '' );
					break;

				case 'number':
					$clean[ $key ] = isset( $posted[ $key ] ) ? (int) $posted[ $key ] : 0;
					break;

				case 'checkbox':
					$clean[ $key ] = ! empty( $posted[ $key ] );
					break;

				case 'wysiwyg':
					$clean[ $key ] = wp_kses_post( $posted[ $key ] ?? '' );
					break;

				case 'select':
				case 'search_select':
					// An empty selection must become NULL, not '' - every FK column
					// backing a "select" field is nullable on purpose (e.g. SLA
					// definitions' "leave empty for any"), and inserting '' into a
					// bigint column silently coerces to 0, which is not the same
					// thing as "no value" once code starts matching on it.
					$raw           = $posted[ $key ] ?? '';
					$clean[ $key ] = '' === $raw ? null : sanitize_text_field( $raw );
					break;

				default:
					$clean[ $key ] = sanitize_text_field( $posted[ $key ] ?? '' );
			}
		}

		return $clean;
	}

	/**
	 * @param object|array $data
	 * @return mixed
	 */
	private static function get_field_value( $data, string $key, $default ) {
		if ( is_object( $data ) ) {
			return $data->$key ?? $default;
		}

		return $data[ $key ] ?? $default;
	}
}
