<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agents_Page extends Abstract_CRUD_Page {

	protected function table_suffix(): string {
		return 'thickgrass_agents';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-agents';
	}

	protected function page_title(): string {
		return __( 'Agents', 'thickgrass' );
	}

	/**
	 * Embedded as a tab inside "Configurable lists" (PLAN.md) rather than
	 * owning its own top-level admin page - see class-choices-page.php.
	 */
	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'agents' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'wp_user_id', 'label' => __( 'WordPress user', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'wp_users_options' ], 'required' => true ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'id'        => __( 'ID', 'thickgrass' ),
			'user'      => __( 'User', 'thickgrass' ),
			'groups'    => __( 'Assignment groups', 'thickgrass' ),
			'locations' => __( 'Locations', 'thickgrass' ),
			'is_active' => __( 'Active', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$user           = get_userdata( (int) $row->wp_user_id );
			$row->user      = $user ? $user->display_name : '—';
			$row->groups    = implode( ', ', wp_list_pluck( $this->get_agent_groups( (int) $row->id ), 'label' ) );
			$row->locations = implode( ', ', array_map(
				static fn( $org ) => Admin_Helpers::format_organization_location( $org->location, $org->name ),
				$this->get_agent_organizations( (int) $row->id )
			) );
			$row->is_active = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}

	/**
	 * Rendered inside the same <form> as the main fields (see Abstract_CRUD_Page::render_form)
	 * so that saving the agent always submits the current group selection too - a
	 * separate form would silently wipe groups whenever only the main fields are saved.
	 */
	protected function extra_form_fields( ?object $editing ): void {
		if ( ! $editing ) {
			echo '<p>' . esc_html__( 'Assignment groups can be set after the agent is created.', 'thickgrass' ) . '</p>';
			return;
		}

		$all_groups   = $this->get_all_groups();
		$assigned_ids = wp_list_pluck( $this->get_agent_groups( (int) $editing->id ), 'id' );

		echo '<h3>' . esc_html__( 'Assignment groups', 'thickgrass' ) . '</h3>';

		if ( ! $all_groups ) {
			echo '<p>' . esc_html__( 'No assignment groups defined yet.', 'thickgrass' ) . '</p>';
			return;
		}

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

	private function render_locations_checkboxes( object $editing ): void {
		$all_locations = Admin_Helpers::organization_location_options();
		$assigned_ids  = wp_list_pluck( $this->get_agent_organizations( (int) $editing->id ), 'id' );

		echo '<h3>' . esc_html__( 'Locations', 'thickgrass' ) . '</h3>';

		if ( ! $all_locations ) {
			echo '<p>' . esc_html__( 'No organizations defined yet.', 'thickgrass' ) . '</p>';
			return;
		}

		foreach ( $all_locations as $organization_id => $location ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="agent_organizations[]" value="%1$d" %2$s /> %3$s</label>',
				$organization_id,
				checked( in_array( (int) $organization_id, $assigned_ids, true ), true, false ),
				esc_html( $location )
			);
		}
	}

	protected function after_save( int $id ): void {
		global $wpdb;

		$groups_table = $wpdb->prefix . 'thickgrass_agent_groups';
		$wpdb->delete( $groups_table, [ 'agent_id' => $id ] );

		$group_ids = array_map( 'intval', wp_unslash( $_POST['assignment_groups'] ?? [] ) );

		foreach ( $group_ids as $group_id ) {
			$wpdb->insert( $groups_table, [ 'agent_id' => $id, 'assignment_group_id' => $group_id ] );
		}

		$organizations_table = $wpdb->prefix . 'thickgrass_agent_organizations';
		$wpdb->delete( $organizations_table, [ 'agent_id' => $id ] );

		$organization_ids = array_map( 'intval', wp_unslash( $_POST['agent_organizations'] ?? [] ) );

		foreach ( $organization_ids as $organization_id ) {
			$wpdb->insert( $organizations_table, [ 'agent_id' => $id, 'organization_id' => $organization_id ] );
		}
	}

	/**
	 * Assignment groups are rows in `wp_thickgrass_choices` (list_key = 'assignment_group'),
	 * not a dedicated table - see PLAN.md 3.1.
	 *
	 * @return array<int, object>
	 */
	private function get_agent_groups( int $agent_id ): array {
		global $wpdb;

		$choices_table = Choices::table();
		$junction      = $wpdb->prefix . 'thickgrass_agent_groups';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.label FROM {$choices_table} c
				INNER JOIN {$junction} j ON j.assignment_group_id = c.id
				WHERE j.agent_id = %d AND c.list_key = 'assignment_group'",
				$agent_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	private function get_all_groups(): array {
		return Choices::get_list( 'assignment_group' );
	}

	/**
	 * @return array<int, object> objects with ->id, ->name, ->location - format
	 * with Admin_Helpers::format_organization_location() before display, since
	 * `location` alone is not unique across organizations.
	 */
	private function get_agent_organizations( int $agent_id ): array {
		global $wpdb;

		$organizations_table = $wpdb->prefix . 'thickgrass_organizations';
		$junction             = $wpdb->prefix . 'thickgrass_agent_organizations';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.name, o.location FROM {$organizations_table} o
				INNER JOIN {$junction} j ON j.organization_id = o.id
				WHERE j.agent_id = %d",
				$agent_id
			)
		);
	}
}
