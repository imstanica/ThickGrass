<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * End-users (`wp_thickgrass_users`) - the profile ThickGrass keeps on top of
 * a WP user for end-users/customers (organization + personal manager), as
 * distinct from Agents (see PLAN.md 3.2). Before this screen, a user's
 * organization could only be set in bulk from the "Members" panel on
 * Organizations_Page (or auto-set when an agent confirms a caller's
 * organization on a Call - see Admin_Helpers::upsert_user_organization()) -
 * neither path lets you look up or edit a single end-user directly, and
 * `manager_wp_user_id` had no UI at all.
 */
class Users_Page extends Abstract_CRUD_Page {

	private const BULK_DEFAULT_ORG_ACTION  = 'thickgrass_users_assign_default_org';
	private const AUTO_ASSIGN_SAVE_ACTION  = 'thickgrass_users_auto_assign_save';
	private const AUTO_ASSIGN_OPTION       = 'thickgrass_auto_assign_default_organization';

	protected function table_suffix(): string {
		return 'thickgrass_users';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-users';
	}

	protected function page_title(): string {
		return __( 'End users', 'thickgrass' );
	}

	/**
	 * Embedded as a tab inside "Configurable lists" (PLAN.md) rather than
	 * owning its own top-level admin page - see class-choices-page.php.
	 */
	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'users' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'wp_user_id', 'label' => __( 'WordPress user', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'wp_users_options' ], 'required' => true ],
			[ 'key' => 'organization_id', 'label' => __( 'Organization', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'organization_options' ] ],
			[ 'key' => 'manager_wp_user_id', 'label' => __( 'Manager', 'thickgrass' ), 'type' => 'search_select', 'options' => [ $this, 'wp_users_options' ] ],
		];
	}

	protected function list_columns(): array {
		return [
			'id'           => __( 'ID', 'thickgrass' ),
			'user'         => __( 'User', 'thickgrass' ),
			'organization' => __( 'Organization', 'thickgrass' ),
			'manager'      => __( 'Manager', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$user             = get_userdata( (int) $row->wp_user_id );
			$row->user        = $user ? $user->display_name : '—';
			$organization     = $row->organization_id ? $this->organization_options()[ (int) $row->organization_id ] ?? null : null;
			$row->organization = $organization ?: '—';
			$manager          = $row->manager_wp_user_id ? get_userdata( (int) $row->manager_wp_user_id ) : false;
			$row->manager     = $manager ? $manager->display_name : '—';
		}

		return $rows;
	}

	/**
	 * Public (not protected) for the same reason as wp_users_options() on
	 * Abstract_CRUD_Page - passed as a callable into Generic_Form.
	 *
	 * @return array<int, string> organization id => name
	 */
	public function organization_options(): array {
		return Admin_Helpers::organization_options();
	}

	protected function handle_extra_actions(): void {
		$this->handle_auto_assign_setting();
		$this->handle_bulk_assign();
	}

	/**
	 * The ongoing setting (see maybe_auto_assign_default_organization() on
	 * Admin_Helpers, hooked to `user_register`) - separate from the one-off
	 * bulk button below, which only fixes up users who already exist.
	 */
	private function handle_auto_assign_setting(): void {
		if ( ! isset( $_POST[ self::AUTO_ASSIGN_SAVE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::AUTO_ASSIGN_SAVE_ACTION . '_nonce' ], self::AUTO_ASSIGN_SAVE_ACTION )
		) {
			return;
		}

		update_option( self::AUTO_ASSIGN_OPTION, ! empty( $_POST['auto_assign'] ) );

		wp_safe_redirect( $this->build_url( [ 'settings_updated' => 1 ] ) );
		exit;
	}

	private function handle_bulk_assign(): void {
		if ( ! isset( $_POST[ self::BULK_DEFAULT_ORG_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::BULK_DEFAULT_ORG_ACTION . '_nonce' ], self::BULK_DEFAULT_ORG_ACTION )
		) {
			return;
		}

		$assigned = $this->assign_users_without_organization_to_default();

		wp_safe_redirect( $this->build_url( [ 'assigned' => $assigned ] ) );
		exit;
	}

	/**
	 * For sites that don't want to bother with multiple organizations/locations
	 * (PLAN.md) - one click puts every WP user who doesn't already have an
	 * organization into the default one, instead of adding each end-user by
	 * hand through the form above. Never overwrites a user who already has an
	 * organization set, so it's safe to click more than once.
	 */
	private function assign_users_without_organization_to_default(): int {
		global $wpdb;

		$default_organization_id = $this->default_organization_id();

		if ( ! $default_organization_id ) {
			return 0;
		}

		$assigned = 0;

		foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $user ) {
			$has_organization = $wpdb->get_var( $wpdb->prepare( "SELECT organization_id FROM {$this->table()} WHERE wp_user_id = %d", $user->ID ) );

			if ( $has_organization ) {
				continue;
			}

			Admin_Helpers::upsert_user_organization( $user->ID, $default_organization_id );
			$assigned++;
		}

		return $assigned;
	}

	private function default_organization_id(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_organizations';
		$id    = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 LIMIT 1" );

		return $id ? (int) $id : null;
	}

	protected function render_extra( ?object $editing ): void {
		if ( isset( $_GET['settings_updated'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'thickgrass' ) . '</p></div>';
		}

		if ( isset( $_GET['assigned'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of users assigned to the default organization */
					_n( '%d user assigned to the default organization.', '%d users assigned to the default organization.', (int) $_GET['assigned'], 'thickgrass' ),
					(int) $_GET['assigned']
				) )
			);
		}

		echo '<h2>' . esc_html__( 'Default organization', 'thickgrass' ) . '</h2>';
		echo '<div class="thickgrass-card">';

		echo '<form method="post">';
		wp_nonce_field( self::AUTO_ASSIGN_SAVE_ACTION, self::AUTO_ASSIGN_SAVE_ACTION . '_nonce' );
		printf(
			'<p><label><input type="checkbox" name="auto_assign" value="1" %s /> %s</label></p>',
			checked( (bool) get_option( self::AUTO_ASSIGN_OPTION ), true, false ),
			esc_html__( "Automatically put every future WordPress user into the default organization (for sites that don't need multiple organizations/locations).", 'thickgrass' )
		);
		submit_button( __( 'Save', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';

		echo '<p>' . esc_html__( 'This only applies going forward. To also fix up users who already registered:', 'thickgrass' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::BULK_DEFAULT_ORG_ACTION, self::BULK_DEFAULT_ORG_ACTION . '_nonce' );
		submit_button( __( 'Assign all existing users without an organization to the default one', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';

		echo '</div>';
	}
}
