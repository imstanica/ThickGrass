<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single generic admin screen hosting every "small configurable list" tab
 * (priority, impact, status, categories, assignment groups, ticket types...)
 * AND, folded in as extra tabs in the same order, the CRUD entities that
 * used to be separate top-level pages - Organizations, Agents, Assets, and
 * SLA definitions (see PLAN.md: "muta Assets, Organization si Agents in
 * Configurable lists" / "muta si SLA definition in Configurable lists").
 * Labeled "Setup" in the WP admin menu since PLAN.md 7.49 (renamed from
 * "Configurable lists" once Settings/Custom Forms joined it in the same
 * shared sidebar). See PLAN.md 3.1 / 4.1 for the underlying generic Choices
 * engine.
 */
class Choices_Page {

	/**
	 * CRUD pages embedded as tabs here instead of owning their own top-level
	 * admin page - render_body()/handle_actions() is delegated to them
	 * directly, keyed by the same `list_key` query arg the Choices tabs use.
	 */
	private const PAGE_TABS = [
		'organizations'      => Organizations_Page::class,
		'agents'             => Agents_Page::class,
		'users'              => Users_Page::class,
		'assets'             => Assets_Page::class,
		'sla_definitions'    => Sla_Definitions_Page::class,
		'kb_articles'        => Kb_Page::class,
		'canned_responses'   => Canned_Responses_Page::class,
		'custom_forms'       => Custom_Forms_Page::class,
	];

	/**
	 * Exact nav order requested (mixes real Choices list_keys with the
	 * PAGE_TABS keys above), re-arranged by the user directly (PLAN.md 7.48)
	 * once the sidebar existed - deliberately explicit rather than derived
	 * from Choices::get_registered_lists()'s own declaration order.
	 */
	private const TAB_ORDER = [
		'assignment_group',
		'ticket_type',
		'priority',
		'impact',
		'status',
		'on_hold_reason',
		'call_close_reason',
		'contact_type',
		'custom_forms',
		'category',
		'organizations',
		'agents',
		'users',
		'asset_type',
		'assets',
		'sla_definitions',
		'kb_articles',
		'kb_category',
		'canned_responses',
		'canned_response_category',
	];

	/**
	 * Indented under the item immediately above them in TAB_ORDER (PLAN.md
	 * 7.48) - a purely visual grouping cue, not a real parent/child
	 * relationship in the data (every key here is still a fully independent
	 * Choices list, reachable and editable on its own).
	 */
	private const SUB_ITEMS = [ 'on_hold_reason', 'call_close_reason', 'kb_category', 'canned_response_category' ];

	/**
	 * Overrides a real Choices list's own registered label (used everywhere
	 * else it appears - ticket filters, the New ticket form) ONLY for this
	 * sidebar's nav item text (PLAN.md 7.48: "category" renamed to "Report
	 * Categories" here specifically, without touching the list itself).
	 */
	private const NAV_LABEL_OVERRIDES = [ 'category' => 'Report Categories' ];

	/**
	 * Singular, lowercase form of each flat list's label, only for the
	 * "+ Add X" button text on the modern card grid (PLAN.md 7.51) - the
	 * list's own registered/nav label is plural or otherwise not what reads
	 * naturally after "+ Add" (e.g. "Statuses" -> "status", "Report
	 * Categories" -> "report category").
	 */
	private const SINGULAR_LABELS = [
		'assignment_group'         => 'assignment group',
		'ticket_type'              => 'ticket type',
		'priority'                 => 'priority',
		'impact'                   => 'impact',
		'status'                   => 'status',
		'on_hold_reason'           => 'on hold reason',
		'call_close_reason'        => 'close reason',
		'contact_type'             => 'contact type',
		'category'                 => 'report category',
		'asset_type'               => 'asset type',
		'kb_category'              => 'KB category',
		'canned_response_category' => 'canned response category',
	];

	public function render(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		$tab_key = $this->current_tab_key();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Setup', 'thickgrass' ) . '</h1>';

		$this->render_error_notice();

		// A vertical sidebar instead of WP core's horizontal `nav-tab-wrapper`
		// (PLAN.md 7.47: with 19 tabs, a wrapping row of pill buttons no
		// longer read well - "lista s-a facut destul de lunga si nu apare
		// prea ok"). Renders BOTH this page's nav group AND Settings_Page's
		// (PLAN.md 7.48: "hai sa integram si settings ... 2 meniuri, unul sub
		// altul") via the one shared helper, so the exact same sidebar - and
		// the ability to jump straight to Settings - appears here too, not
		// just on the Settings screen itself. See admin.css for `.thickgrass-choices-*`.
		echo '<div class="thickgrass-choices-layout">';
		Admin_Helpers::render_admin_nav_groups( [ self::nav_group(), Settings_Page::nav_group() ], $tab_key );

		echo '<div class="thickgrass-choices-content">';

		if ( isset( self::PAGE_TABS[ $tab_key ] ) ) {
			$page_class = self::PAGE_TABS[ $tab_key ];
			( new $page_class() )->render_body();
		} else {
			// "Modern" card grid + popup Add/Edit (PLAN.md 7.50/7.51) - piloted
			// on just Assignment groups first, now the default for every flat
			// Choices list on this page. The PAGE_TABS entries above (Organizations,
			// Agents, Users, Assets, SLA definitions, Knowledge Base, Canned
			// responses, Custom forms) keep their own table+form design - each
			// has extra nested sections (business hours, group/location
			// checkboxes, a Fields sub-manager...) that don't fit a compact
			// modal without dedicated work per page.
			$lists   = Choices::get_registered_lists();
			$editing = isset( $_GET['edit'] ) ? Choices::get( (int) $_GET['edit'] ) : null;

			$this->render_tab_hint( $tab_key );
			$this->render_modern_list( $tab_key, $lists[ $tab_key ]['hierarchical'], $editing );
		}

		echo '</div>'; // .thickgrass-choices-content
		echo '</div>'; // .thickgrass-choices-layout

		echo '</div>'; // .wrap
	}

	/**
	 * The "Request approval" button on a ticket needs BOTH its type AND its
	 * current status flagged (PLAN.md 7.36/7.38) - two independent tabs,
	 * easy to configure only one and wonder why the button never shows up.
	 * A plain reminder here, not a real dependency check between tabs.
	 */
	private function render_tab_hint( string $tab_key ): void {
		$hints = [
			'status'      => __( 'Note: the "Request approval" button on a ticket only appears when BOTH its current status has "Allows requesting an approval from this status" checked here AND its ticket type has "Enable approval requests" checked on the Ticket types tab.', 'thickgrass' ),
			'ticket_type' => __( 'Note: the "Request approval" button on a ticket only appears when BOTH this ticket type has "Enable approval requests" checked AND the ticket\'s current status has "Allows requesting an approval from this status" checked on the Statuses tab.', 'thickgrass' ),
		];

		if ( isset( $hints[ $tab_key ] ) ) {
			echo '<p class="description">' . esc_html( $hints[ $tab_key ] ) . '</p>';
		}
	}

	private function render_error_notice(): void {
		$errors = [
			'missing_prefix'  => __( 'The ticket number prefix is required.', 'thickgrass' ),
			'duplicate_prefix' => __( 'Another ticket type already uses this prefix. Choose a unique one.', 'thickgrass' ),
		];

		$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';

		if ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
		}
	}

	private function current_tab_key(): string {
		$requested = isset( $_GET['list_key'] ) ? sanitize_key( $_GET['list_key'] ) : '';

		if ( $requested && in_array( $requested, self::TAB_ORDER, true ) ) {
			return $requested;
		}

		return self::TAB_ORDER[0];
	}

	/**
	 * Handles save/delete + redirect. Must run on `load-{$hook}` (see class-menu.php),
	 * not from render(), otherwise the redirect fires after WordPress has already
	 * started sending output for the admin screen ("headers already sent").
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			return;
		}

		$tab_key = $this->current_tab_key();

		if ( isset( self::PAGE_TABS[ $tab_key ] ) ) {
			$page_class = self::PAGE_TABS[ $tab_key ];
			( new $page_class() )->handle_actions();
			return;
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			check_admin_referer( 'thickgrass_choice_delete_' . (int) $_GET['id'] );
			Choices::delete( (int) $_GET['id'] );
			wp_safe_redirect( remove_query_arg( [ 'action', 'id', '_wpnonce' ] ) );
			exit;
		}

		if ( ! isset( $_POST['thickgrass_choice_nonce'] ) || ! wp_verify_nonce( $_POST['thickgrass_choice_nonce'], 'thickgrass_choice_save' ) ) {
			return;
		}

		$list_key = sanitize_key( $_POST['list_key'] ?? '' );
		$lists    = Choices::get_registered_lists();

		if ( ! isset( $lists[ $list_key ] ) ) {
			return;
		}

		$fields = $this->get_fields( $list_key, $lists[ $list_key ]['hierarchical'] );
		$data   = Generic_Form::sanitize( $fields, wp_unslash( $_POST ) );
		$data   = $this->extract_meta( $data );

		$id = isset( $_POST['choice_id'] ) ? (int) $_POST['choice_id'] : 0;

		if ( 'ticket_type' === $list_key ) {
			$redirect_error = $this->validate_ticket_type( $data, $id );

			if ( $redirect_error ) {
				wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-choices&list_key=' . $list_key . '&error=' . $redirect_error ) );
				exit;
			}
		}

		$data['list_key'] = $list_key;

		if ( $id ) {
			Choices::update( $id, $data );
		} else {
			Choices::insert( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-choices&list_key=' . $list_key ) );
		exit;
	}

	/**
	 * Ticket type prefixes must be unique (they identify the ticket, e.g. REQ00001
	 * vs INC00001) - the generic choices engine has no DB-level uniqueness
	 * constraint per list_key, so it is enforced here at save time.
	 *
	 * @return string empty string if valid, otherwise an error key for the query arg
	 */
	private function validate_ticket_type( array &$data, int $id ): string {
		$prefix = strtoupper( trim( $data['meta']['prefix'] ?? '' ) );

		if ( '' === $prefix ) {
			return 'missing_prefix';
		}

		$data['meta']['prefix'] = $prefix;

		foreach ( Choices::get_list( 'ticket_type', false ) as $existing ) {
			$existing_prefix = strtoupper( $existing->meta['prefix'] ?? '' );

			if ( $existing_prefix === $prefix && (int) $existing->id !== $id ) {
				return 'duplicate_prefix';
			}
		}

		return '';
	}

	/**
	 * Moves every meta_* key out of a flat array into a 'meta' sub-array.
	 */
	private function extract_meta( array $data ): array {
		$meta = [];

		foreach ( $data as $key => $value ) {
			if ( 0 === strpos( $key, 'meta_' ) ) {
				$meta[ substr( $key, 5 ) ] = $value;
				unset( $data[ $key ] );
			}
		}

		if ( $meta ) {
			$data['meta'] = $meta;
		}

		return $data;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_fields( string $list_key, bool $hierarchical ): array {
		$fields = [
			[ 'key' => 'label', 'label' => __( 'Label', 'thickgrass' ), 'type' => 'text', 'required' => true ],
		];

		if ( $hierarchical ) {
			$fields[] = [
				'key'     => 'parent_id',
				'label'   => __( 'Parent (optional)', 'thickgrass' ),
				'type'    => 'select',
				'options' => static function () use ( $list_key ) {
					$options = [];
					foreach ( Choices::get_list( $list_key, false ) as $row ) {
						$options[ $row->id ] = $row->label;
					}
					return $options;
				},
			];
		}

		if ( 'status' === $list_key ) {
			$fields[] = [ 'key' => 'meta_is_resolved_state', 'label' => __( 'Treat ticket as resolved', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_is_closed_state', 'label' => __( 'Treat ticket as closed', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_is_on_hold_state', 'label' => __( 'Treat ticket as on hold (shows the "On hold reason" field)', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_allows_approval_request', 'label' => __( 'Allows requesting an approval from this status', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_auto_assign_to_actor', 'label' => __( 'Automatically assign to whoever moves a ticket into this status, if it isn\'t assigned yet', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_once_only_state', 'label' => __( 'A ticket can only be in this status once (can\'t be moved back to it after leaving)', 'thickgrass' ), 'type' => 'checkbox' ];
		}

		if ( 'assignment_group' === $list_key ) {
			$fields[] = [ 'key' => 'meta_description', 'label' => __( 'Description', 'thickgrass' ), 'type' => 'textarea' ];
		}

		if ( 'ticket_type' === $list_key ) {
			$fields[] = [ 'key' => 'meta_prefix', 'label' => __( 'Number prefix (e.g. REQ)', 'thickgrass' ), 'type' => 'text', 'required' => true ];
			$fields[] = [ 'key' => 'meta_padding', 'label' => __( 'Number padding (digits)', 'thickgrass' ), 'type' => 'number', 'default' => 5 ];
			$fields[] = [ 'key' => 'meta_next_number', 'label' => __( 'Next number', 'thickgrass' ), 'type' => 'number', 'default' => 1 ];
			$fields[] = [ 'key' => 'meta_enables_approval', 'label' => __( 'Enable approval requests for this ticket type', 'thickgrass' ), 'type' => 'checkbox' ];
			$fields[] = [ 'key' => 'meta_awaiting_approval_status_id', 'label' => __( 'Status to set while awaiting approval (also flag it "on hold" on the Status tab to pause its SLA)', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'status_options' ] ];
			$fields[] = [ 'key' => 'meta_awaiting_approval_on_hold_reason_id', 'label' => __( 'On hold reason to set while awaiting approval', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'on_hold_reason_options' ] ];
			$fields[] = [ 'key' => 'meta_approved_status_id', 'label' => __( 'Status to set once approved', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'status_options' ] ];
			$fields[] = [ 'key' => 'meta_approved_on_hold_reason_id', 'label' => __( 'On hold reason to set once approved', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'on_hold_reason_options' ] ];
			$fields[] = [ 'key' => 'meta_rejected_status_id', 'label' => __( 'Status to set once rejected', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'status_options' ] ];
			$fields[] = [ 'key' => 'meta_rejected_on_hold_reason_id', 'label' => __( 'On hold reason to set once rejected', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'on_hold_reason_options' ] ];
		}

		$fields[] = [ 'key' => 'sort_order', 'label' => __( 'Sort order', 'thickgrass' ), 'type' => 'number', 'default' => 0 ];
		$fields[] = [ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ];

		return $fields;
	}

	/**
	 * Public (not protected) - passed as an `[$this, 'status_options']`
	 * callable into Generic_Form, same reasoning as
	 * Abstract_CRUD_Page::wp_users_options().
	 *
	 * @return array<int, string> status choice id => label
	 */
	public function status_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'status' ) as $status ) {
			$options[ $status->id ] = $status->label;
		}

		return $options;
	}

	/**
	 * Public (not protected), same reasoning as status_options() above.
	 *
	 * @return array<int, string> on_hold_reason choice id => label
	 */
	public function on_hold_reason_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'on_hold_reason' ) as $reason ) {
			$options[ $reason->id ] = $reason->label;
		}

		return $options;
	}

	/**
	 * @param array<string, array{label: string, hierarchical: bool}> $lists
	 */
	private static function tab_label( string $key, array $lists ): string {
		if ( isset( self::NAV_LABEL_OVERRIDES[ $key ] ) ) {
			return self::NAV_LABEL_OVERRIDES[ $key ];
		}

		if ( isset( $lists[ $key ] ) ) {
			return $lists[ $key ]['label'];
		}

		$page_tab_labels = [
			'organizations'   => __( 'Organizations', 'thickgrass' ),
			'agents'          => __( 'Agents', 'thickgrass' ),
			'users'           => __( 'End users', 'thickgrass' ),
			'assets'          => __( 'Assets', 'thickgrass' ),
			'sla_definitions' => __( 'SLA definitions', 'thickgrass' ),
			'kb_articles'     => __( 'Knowledge Base', 'thickgrass' ),
			'canned_responses' => __( 'Canned responses', 'thickgrass' ),
			'custom_forms'    => __( 'Custom Forms', 'thickgrass' ),
		];

		return $page_tab_labels[ $key ] ?? $key;
	}

	/**
	 * This page's own nav group ("Configurable lists"), in the shared shape
	 * Admin_Helpers::render_admin_nav_groups() expects - see render().
	 *
	 * @return array{title: string, items: array<int, array{url: string, label: string, key: string, indent: bool}>}
	 */
	public static function nav_group(): array {
		$lists = Choices::get_registered_lists();
		$items = [];

		foreach ( self::TAB_ORDER as $key ) {
			$items[] = [
				'url'    => admin_url( 'admin.php?page=thickgrass-choices&list_key=' . $key ),
				'label'  => self::tab_label( $key, $lists ),
				'key'    => $key,
				'indent' => in_array( $key, self::SUB_ITEMS, true ),
			];
		}

		return [ 'title' => __( 'Setup', 'thickgrass' ), 'items' => $items ];
	}

	private function render_form( string $list_key, bool $hierarchical, ?object $editing ): void {
		$fields    = $this->get_fields( $list_key, $hierarchical );
		$form_data = $this->flatten_choice_for_form( $editing );

		echo '<h2>' . ( $editing ? esc_html__( 'Edit', 'thickgrass' ) : esc_html__( 'Add new', 'thickgrass' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'thickgrass_choice_save', 'thickgrass_choice_nonce' );
		echo '<input type="hidden" name="list_key" value="' . esc_attr( $list_key ) . '" />';
		echo '<input type="hidden" name="choice_id" value="' . esc_attr( $editing->id ?? 0 ) . '" />';

		Generic_Form::render( $fields, $form_data );

		submit_button( $editing ? __( 'Save changes', 'thickgrass' ) : __( 'Add', 'thickgrass' ) );
		echo '</form>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function flatten_choice_for_form( ?object $choice ): array {
		if ( ! $choice ) {
			return [];
		}

		$data = (array) $choice;

		if ( ! empty( $choice->meta ) && is_array( $choice->meta ) ) {
			foreach ( $choice->meta as $key => $value ) {
				$data[ 'meta_' . $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * @return array<string, string> extra list-specific columns, key => label
	 */
	private function get_extra_columns( string $list_key ): array {
		if ( 'ticket_type' === $list_key ) {
			return [ 'prefix' => __( 'Prefix', 'thickgrass' ), 'next_number' => __( 'Next number', 'thickgrass' ) ];
		}

		return [];
	}

	/**
	 * "Modern" design for every flat Choices list on this page (PLAN.md
	 * 7.50/7.51) - a responsive card grid instead of the plain `<table>`,
	 * and the Add/Edit form moved into a popup instead of always sitting
	 * above the list taking up space. Piloted on just Assignment groups
	 * first (7.50: "alt design de test... in caz ca e ok sa il implementam
	 * si in alte parti") before being generalized here to the other 11
	 * lists too (7.51: "lets try to add it to all"). Reuses the exact same
	 * `.thickgrass-modal` + Admin_Helpers::render_modal_script() the ticket
	 * screen's "Send email"/"Request approval" popups already use, and the
	 * same render_form() this page always used (so Add vs Edit, validation,
	 * and the redirect back to this tab after saving are all unchanged -
	 * only where the form visually lives is different).
	 */
	private function render_modern_list( string $list_key, bool $hierarchical, ?object $editing ): void {
		$modal_id      = 'thickgrass-modal-' . $list_key;
		$rows          = Choices::get_list( $list_key, false );
		$extra_columns = $this->get_extra_columns( $list_key );

		// Only needed for hierarchical lists (category, kb_category) - built
		// from $rows already in hand rather than a Choices::get() per row.
		$labels_by_id = [];

		if ( $hierarchical ) {
			foreach ( $rows as $row ) {
				$labels_by_id[ (int) $row->id ] = $row->label;
			}
		}

		printf(
			'<p class="thickgrass-modern-add-row"><button type="button" class="button button-primary" data-modal-target="%1$s">%2$s</button></p>',
			esc_attr( $modal_id ),
			/* translators: %s: singular list item name, e.g. "assignment group" */
			esc_html( sprintf( __( '+ Add %s', 'thickgrass' ), self::SINGULAR_LABELS[ $list_key ] ?? $list_key ) )
		);

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing here yet.', 'thickgrass' ) . '</p>';
		}

		echo '<div class="thickgrass-modern-grid">';

		foreach ( $rows as $row ) {
			$edit_url   = admin_url( 'admin.php?page=thickgrass-choices&list_key=' . $list_key . '&edit=' . $row->id );
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=thickgrass-choices&list_key=' . $list_key . '&action=delete&id=' . $row->id ),
				'thickgrass_choice_delete_' . $row->id
			);

			echo '<div class="thickgrass-modern-card">';
			printf( '<div class="thickgrass-modern-card-title">%s</div>', esc_html( $row->label ) );

			// "nu e clar daca o categorie are un parinte, cine e parintele" -
			// shown for EVERY card on a hierarchical list (not just the ones
			// with a parent), so "nothing shown" is never ambiguous with
			// "just didn't render" - it always means top-level.
			if ( $hierarchical ) {
				$parent_label = $row->parent_id ? ( $labels_by_id[ (int) $row->parent_id ] ?? __( 'Unknown', 'thickgrass' ) ) : null;

				printf(
					'<p class="thickgrass-modern-card-meta thickgrass-modern-card-parent">%s</p>',
					$parent_label
						/* translators: %s: parent category's label */
						? esc_html( sprintf( __( '↳ Parent: %s', 'thickgrass' ), $parent_label ) )
						: esc_html__( 'Top-level (no parent)', 'thickgrass' )
				);
			}

			if ( ! empty( $row->meta['description'] ) ) {
				printf( '<p class="thickgrass-modern-card-description">%s</p>', esc_html( $row->meta['description'] ) );
			}

			foreach ( $extra_columns as $column => $column_label ) {
				$value = $row->meta[ $column ] ?? '';

				if ( '' !== $value ) {
					printf( '<p class="thickgrass-modern-card-meta">%1$s: %2$s</p>', esc_html( $column_label ), esc_html( $value ) );
				}
			}

			printf(
				'<span class="thickgrass-badge %1$s">%2$s</span>',
				esc_attr( $row->is_active ? 'thickgrass-badge-green' : 'thickgrass-badge-red' ),
				$row->is_active ? esc_html__( 'Active', 'thickgrass' ) : esc_html__( 'Inactive', 'thickgrass' )
			);

			echo '<div class="thickgrass-modern-card-actions">';
			printf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'thickgrass' ) );
			printf(
				' <a href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to delete this item?', 'thickgrass' ) ),
				esc_html__( 'Delete', 'thickgrass' )
			);
			echo '</div>'; // .thickgrass-modern-card-actions

			echo '</div>'; // .thickgrass-modern-card
		}

		echo '</div>'; // .thickgrass-modern-grid

		// Opens already-expanded (`is-open`) when arriving via an Edit link
		// (?edit=<id>), so following that link doesn't land on what looks
		// like an unchanged list with no visible way in to the form.
		$modal_class = 'thickgrass-modal' . ( $editing ? ' is-open' : '' );
		printf( '<div id="%1$s" class="%2$s">', esc_attr( $modal_id ), esc_attr( $modal_class ) );
		echo '<div class="thickgrass-modal-content">';
		echo '<button type="button" class="thickgrass-modal-close" aria-label="' . esc_attr__( 'Close', 'thickgrass' ) . '">&times;</button>';
		$this->render_form( $list_key, $hierarchical, $editing );
		echo '</div>'; // .thickgrass-modal-content
		echo '</div>'; // .thickgrass-modal

		Admin_Helpers::render_modal_script();
	}
}
