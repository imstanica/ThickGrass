<?php

namespace ThickGrass\Admin;

use ThickGrass\Business_Hours;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Organizations_Page extends Abstract_CRUD_Page {

	private const BUSINESS_HOURS_ACTION = 'thickgrass_business_hours_save';

	protected function table_suffix(): string {
		return 'thickgrass_organizations';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-organizations';
	}

	protected function page_title(): string {
		return __( 'Organizations', 'thickgrass' );
	}

	/**
	 * Embedded as a tab inside "Configurable lists" (PLAN.md) rather than
	 * owning its own top-level admin page - see class-choices-page.php.
	 */
	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'organizations' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'name', 'label' => __( 'Name', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'address', 'label' => __( 'Address', 'thickgrass' ), 'type' => 'text' ],
			[ 'key' => 'phone', 'label' => __( 'Phone', 'thickgrass' ), 'type' => 'text' ],
			[ 'key' => 'location', 'label' => __( 'Location', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'manager_wp_user_id', 'label' => __( 'Manager', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'wp_users_options' ] ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'name'       => __( 'Name', 'thickgrass' ),
			'location'   => __( 'Location', 'thickgrass' ),
			'phone'      => __( 'Phone', 'thickgrass' ),
			'manager'    => __( 'Manager', 'thickgrass' ),
			'is_default' => __( 'Default', 'thickgrass' ),
			'is_active'  => __( 'Active', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$manager          = $row->manager_wp_user_id ? get_userdata( (int) $row->manager_wp_user_id ) : false;
			$row->manager     = $manager ? $manager->display_name : '—';
			$row->is_default_raw = (bool) $row->is_default;
			$row->is_default  = $row->is_default ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
			$row->is_active   = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}

	/**
	 * The default organization always has to exist (used as a fallback), so
	 * it can be edited but never deleted - see PLAN.md "2.1 organizatie default".
	 *
	 * Checks `is_default_raw` first: get_display_rows() above overwrites the
	 * real `is_default` column with a translated "Yes"/"No" string for the
	 * list table, and "No" is a non-empty (truthy) string in PHP - checking
	 * `is_default` directly on a display row made every non-default
	 * organization register as protected too, hiding "Delete" for all of
	 * them. Rows from the plain DB (e.g. the delete handler's get_row()) have
	 * no `is_default_raw` property, so the `??` falls back to the real column.
	 */
	protected function is_row_protected( object $item ): bool {
		return ! empty( $item->is_default_raw ?? $item->is_default );
	}

	/**
	 * Rendered inside the same <form> as the main fields, for the same reason
	 * assignment groups are on Agents_Page: a separate form would silently
	 * wipe membership whenever only the main fields are saved.
	 */
	protected function extra_form_fields( ?object $editing ): void {
		if ( ! $editing ) {
			echo '<p>' . esc_html__( 'Members can be added after the organization is created.', 'thickgrass' ) . '</p>';
			return;
		}

		$member_ids = $this->get_member_wp_user_ids( (int) $editing->id );

		echo '<h3>' . esc_html__( 'Members (WordPress users)', 'thickgrass' ) . '</h3>';

		foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="organization_users[]" value="%1$d" %2$s /> %3$s</label>',
				$user->ID,
				checked( in_array( (int) $user->ID, $member_ids, true ), true, false ),
				esc_html( $user->display_name )
			);
		}
	}

	protected function after_save( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_users';

		// Clear membership for anyone currently pointing at this organization,
		// then re-apply it only for the users checked in this submission -
		// simplest way to handle both newly added and newly removed members.
		$wpdb->update( $table, [ 'organization_id' => null ], [ 'organization_id' => $id ] );

		$wp_user_ids = array_map( 'intval', wp_unslash( $_POST['organization_users'] ?? [] ) );

		foreach ( $wp_user_ids as $wp_user_id ) {
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d", $wp_user_id ) );

			if ( $existing_id ) {
				$wpdb->update( $table, [ 'organization_id' => $id ], [ 'id' => (int) $existing_id ] );
			} else {
				$wpdb->insert( $table, [
					'wp_user_id'       => $wp_user_id,
					'organization_id'  => $id,
					'created_at'       => current_time( 'mysql' ),
				] );
			}
		}
	}

	/**
	 * @return array<int, int>
	 */
	private function get_member_wp_user_ids( int $organization_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_users';
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE organization_id = %d", $organization_id ) );

		return array_map( 'intval', $ids );
	}

	protected function handle_extra_actions(): void {
		if ( ! isset( $_POST[ self::BUSINESS_HOURS_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::BUSINESS_HOURS_ACTION . '_nonce' ], self::BUSINESS_HOURS_ACTION )
		) {
			return;
		}

		$organization_id = isset( $_POST['organization_id'] ) ? (int) $_POST['organization_id'] : 0;

		if ( ! $organization_id ) {
			return;
		}

		$posted = wp_unslash( $_POST['business_hours'] ?? [] );
		$days   = [];

		foreach ( $posted as $weekday => $day ) {
			$days[ (int) $weekday ] = [
				'is_working_day' => ! empty( $day['is_working_day'] ),
				'start_time'     => sanitize_text_field( $day['start_time'] ?? '' ),
				'end_time'       => sanitize_text_field( $day['end_time'] ?? '' ),
			];
		}

		Business_Hours::save_for_organization( $organization_id, $days );

		wp_safe_redirect( $this->build_url( [ 'edit' => $organization_id ] ) );
		exit;
	}

	protected function render_extra( ?object $editing ): void {
		if ( ! $editing ) {
			return;
		}

		global $wp_locale;

		$business_hours = Business_Hours::get_for_organization( (int) $editing->id );

		echo '<h2>' . esc_html__( 'Working hours (used for SLA calculations)', 'thickgrass' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::BUSINESS_HOURS_ACTION, self::BUSINESS_HOURS_ACTION . '_nonce' );
		echo '<input type="hidden" name="organization_id" value="' . esc_attr( $editing->id ) . '" />';

		echo '<table class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'Day', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Working day', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Start', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'End', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $business_hours as $weekday => $day ) {
			$label = $wp_locale->get_weekday( $weekday );

			printf(
				'<tr>
					<td>%1$s</td>
					<td><input type="checkbox" name="business_hours[%2$d][is_working_day]" value="1" %3$s /></td>
					<td><input type="time" name="business_hours[%2$d][start_time]" value="%4$s" /></td>
					<td><input type="time" name="business_hours[%2$d][end_time]" value="%5$s" /></td>
				</tr>',
				esc_html( $label ),
				$weekday,
				checked( (bool) $day->is_working_day, true, false ),
				esc_attr( substr( $day->start_time, 0, 5 ) ),
				esc_attr( substr( $day->end_time, 0, 5 ) )
			);
		}

		echo '</tbody></table>';
		submit_button( __( 'Save working hours', 'thickgrass' ) );
		echo '</form>';
	}
}
