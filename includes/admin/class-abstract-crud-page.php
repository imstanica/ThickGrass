<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for a simple "one table, one admin screen" CRUD page.
 * Concrete pages only declare table/fields/columns - the save/delete/list/form
 * plumbing is written once here and reused (see PLAN.md 4.1).
 */
abstract class Abstract_CRUD_Page {

	abstract protected function table_suffix(): string;

	abstract protected function capability(): string;

	abstract protected function page_slug(): string;

	abstract protected function page_title(): string;

	/** @return array<int, array<string, mixed>> Generic_Form field definitions */
	abstract protected function fields(): array;

	/** @return array<string, string> column key => label for the list table */
	abstract protected function list_columns(): array;

	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->page_title() ) . '</h1>';
		$this->render_body();
		echo '</div>';
	}

	/**
	 * Form + extra sections + list table, without the surrounding wrap/h1 -
	 * split out so a page can also be embedded as a tab inside another screen
	 * (e.g. Organizations/Agents/Assets living under "Configurable lists",
	 * see PLAN.md) instead of always owning its own top-level admin page.
	 */
	public function render_body(): void {
		$editing = isset( $_GET['edit'] ) ? $this->get_row( (int) $_GET['edit'] ) : null;

		$this->render_form( $editing );
		$this->render_extra( $editing );
		$this->render_table();
	}

	/**
	 * Handles save/delete + redirect. Must run on `load-{$hook}` (see class-menu.php),
	 * not from render(), otherwise the redirect fires after WordPress has already
	 * started sending output for the admin screen ("headers already sent").
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$this->handle_extra_actions();

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			check_admin_referer( $this->delete_nonce_action( (int) $_GET['id'] ) );

			$row = $this->get_row( (int) $_GET['id'] );

			if ( ! $row || ! $this->is_row_protected( $row ) ) {
				$this->delete_row( (int) $_GET['id'] );
			}

			wp_safe_redirect( remove_query_arg( [ 'action', 'id', '_wpnonce' ] ) );
			exit;
		}

		$nonce_field = $this->save_nonce_action() . '_nonce';

		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( $_POST[ $nonce_field ], $this->save_nonce_action() ) ) {
			return;
		}

		$data = Generic_Form::sanitize( $this->fields(), wp_unslash( $_POST ) );
		$id   = isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0;

		if ( $id ) {
			$this->update_row( $id, $data );
		} else {
			$id = $this->insert_row( $data );
		}

		$this->after_save( $id );

		wp_safe_redirect( $this->build_url( [ 'edit' => $id ] ) );
		exit;
	}

	/**
	 * The query args every URL for this page is built from - overridden by
	 * pages embedded as a tab inside another screen (see render_body()),
	 * which need e.g. `page=thickgrass-choices&list_key=organizations`
	 * instead of their own dedicated `page_slug()`.
	 *
	 * @return array<string, string>
	 */
	protected function base_url_args(): array {
		return [ 'page' => $this->page_slug() ];
	}

	protected function build_url( array $extra_args = [] ): string {
		return add_query_arg( array_merge( $this->base_url_args(), $extra_args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Extension point for extra forms on the same screen (e.g. business hours,
	 * assignment group membership) that are not plain columns on the main table.
	 */
	protected function handle_extra_actions(): void {}

	/**
	 * Extension point called after a successful insert/update, e.g. to sync a
	 * many-to-many junction table based on the current $_POST.
	 */
	protected function after_save( int $id ): void {}

	/**
	 * Extension point: return true to hide the "Delete" row action and block
	 * deletion for a specific row (e.g. a default record that must always
	 * exist, like the default Organization - see PLAN.md).
	 */
	protected function is_row_protected( object $item ): bool {
		return false;
	}

	/**
	 * Extension point to render extra sections below the main form.
	 */
	protected function render_extra( ?object $editing ): void {}

	protected function table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->table_suffix();
	}

	protected function save_nonce_action(): string {
		return 'thickgrass_' . $this->table_suffix() . '_save';
	}

	protected function delete_nonce_action( int $id ): string {
		return 'thickgrass_' . $this->table_suffix() . '_delete_' . $id;
	}

	protected function get_row( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	protected function insert_row( array $data ): int {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	protected function update_row( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	protected function delete_row( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $id ] );
	}

	/**
	 * Public (not protected): passed as an `[$this, 'wp_users_options']`
	 * callable into Generic_Form, which is a different, unrelated class - a
	 * protected method there is not just inaccessible, it's fatal, because
	 * `is_callable()` returns false and Generic_Form's fallback path tries to
	 * use the raw `[$object, 'method']` array as the options list itself,
	 * which crashes when it hits `esc_html($an_object)`. Confirmed with an
	 * isolated repro before this fix - see PLAN.md 7.x.
	 *
	 * @return array<int, string> WP user id => display name, for select fields.
	 */
	public function wp_users_options(): array {
		return Admin_Helpers::wp_users_options();
	}

	protected function render_form( ?object $editing ): void {
		echo '<h2>' . ( $editing ? esc_html__( 'Edit', 'thickgrass' ) : esc_html__( 'Add new', 'thickgrass' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( $this->save_nonce_action(), $this->save_nonce_action() . '_nonce' );
		echo '<input type="hidden" name="row_id" value="' . esc_attr( $editing->id ?? 0 ) . '" />';

		Generic_Form::render( $this->fields(), $editing ?? [] );
		$this->extra_form_fields( $editing );

		submit_button( $editing ? __( 'Save changes', 'thickgrass' ) : __( 'Add', 'thickgrass' ) );
		echo '</form>';

		// Safe to call unconditionally, even for pages with zero `search_select`
		// fields - see Admin_Helpers::render_search_select_script().
		Admin_Helpers::render_search_select_script();
	}

	/**
	 * Extension point to render extra fields inside the SAME <form> as the main
	 * fields (e.g. a many-to-many checkbox list). Keeping it in the same form
	 * avoids a separate submit silently wiping data the other form doesn't send
	 * (e.g. saving the main fields would clear a relation only the second form knows about).
	 */
	protected function extra_form_fields( ?object $editing ): void {}

	protected function render_table(): void {
		$table = new Generic_List_Table( [
			'singular'      => $this->table_suffix(),
			'plural'        => $this->table_suffix() . 's',
			'primary_key'   => 'id',
			'columns'       => $this->list_columns(),
			'data_provider' => function () {
				return $this->get_display_rows();
			},
			'row_actions'   => function ( $item ) {
				$edit_url = $this->build_url( [ 'edit' => $item->id ] );
				$actions  = [
					'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'thickgrass' ) ),
				];

				if ( $this->is_row_protected( $item ) ) {
					return $actions;
				}

				$delete_url = wp_nonce_url(
					$this->build_url( [ 'action' => 'delete', 'id' => $item->id ] ),
					$this->delete_nonce_action( $item->id )
				);

				$actions['delete'] = sprintf(
					'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
					esc_url( $delete_url ),
					esc_js( __( 'Are you sure you want to delete this item?', 'thickgrass' ) ),
					esc_html__( 'Delete', 'thickgrass' )
				);

				return $actions;
			},
		] );

		echo '<h2>' . esc_html__( 'Existing records', 'thickgrass' ) . '</h2>';
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Rows for the list table. Override to reshape raw DB values (e.g. turn a
	 * wp_user_id into a display name, or 1/0 into Yes/No) before display.
	 *
	 * @return array<int, object>
	 */
	protected function get_display_rows(): array {
		global $wpdb;

		return $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" );
	}
}
