<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register(): void {
		$dashboard = new Dashboard_Page();

		// The top-level menu's own slug ('thickgrass') is reused as the first
		// submenu's slug, so WordPress collapses them into one entry - clicking
		// "ThickGrass" in the sidebar goes straight to the Dashboard instead of
		// a separate, empty landing page.
		add_menu_page(
			__( 'ThickGrass', 'thickgrass' ),
			'ThickGrass',
			'thickgrass_agent',
			'thickgrass',
			[ $dashboard, 'render' ],
			'dashicons-tickets',
			30
		);

		// Order here is the literal order requested for the main menu
		// (PLAN.md 7.50): Dashboard, Record new Call, SLA reports, Setup -
		// WordPress lists submenu items in registration order, not
		// alphabetically or by slug.
		$this->register_page( __( 'Dashboard', 'thickgrass' ), 'thickgrass', $dashboard, 'thickgrass_agent' );
		$this->register_page( __( 'Record new Call', 'thickgrass' ), 'thickgrass-calls', new Calls_Page(), 'thickgrass_agent' );
		$this->register_page( __( 'SLA reports', 'thickgrass' ), 'thickgrass-sla-reports', new Sla_Reports_Page() );

		// Assignment groups, ticket types, Knowledge Base articles, Custom
		// Forms, canned response categories, and the Organizations/Agents/
		// Assets/SLA definitions/Canned responses CRUD screens are all tabs
		// of the single "Setup" page (see class-choices-page.php) - they
		// don't get their own menu item.
		$this->register_page( __( 'Setup', 'thickgrass' ), 'thickgrass-choices', new Choices_Page() );

		// Settings is reachable only via the "Settings" group at the bottom
		// of the shared Setup sidebar (PLAN.md 7.49: "scoate Settings din
		// meniul principal si lasa-l doar in cel de jos"), not its own
		// top-level menu item - `$hidden = true` registers it with an empty
		// parent slug instead of 'thickgrass', WordPress's own documented way
		// to keep an admin page fully reachable (its `load-{$hook}` handling
		// intact) while omitting it from any visible menu.
		//
		// IMPORTANT: do NOT "hide" a page by calling remove_submenu_page()
		// after add_submenu_page( 'thickgrass', ... ) - that was tried first
		// and broke direct access with "Sorry, you are not allowed to access
		// this page." (PLAN.md 7.50 bug report). WordPress's own
		// user_can_access_admin_page() (called by wp-admin/admin.php on
		// every request) looks up the page's capability by searching
		// $submenu['thickgrass'] for it; remove_submenu_page() deletes that
		// entry entirely, so the lookup fails and access is denied for
		// EVERYONE, menu or no menu. Registering with an empty parent slug
		// instead makes get_admin_page_parent() resolve to empty for this
		// page, which takes user_can_access_admin_page()'s "no parent"
		// branch - permissive by default, and still fully guarded by this
		// page's own current_user_can() checks in render()/handle_actions().
		$this->register_page( __( 'Settings', 'thickgrass' ), 'thickgrass-settings', new Settings_Page(), 'thickgrass_manage', true );
	}

	/**
	 * Registers a submenu page and wires its form handling on `load-{$hook}`,
	 * which runs before any HTML output for that page - see class-choices-page.php
	 * for why this must not run from the render callback ("headers already sent").
	 * The menu capability must match what the page itself checks in render()/
	 * handle_actions(), otherwise WordPress hides the menu item from users who
	 * do have that page-level capability but not the default 'thickgrass_manage'.
	 * $hidden registers the page with no parent slug at all (see the comment
	 * in register() above for why) instead of under 'thickgrass', keeping it
	 * reachable by URL/link without a visible menu entry anywhere.
	 */
	private function register_page( string $title, string $slug, object $page, string $capability = 'thickgrass_manage', bool $hidden = false ): void {
		$hook = add_submenu_page(
			$hidden ? '' : 'thickgrass',
			$title,
			$title,
			$capability,
			$slug,
			[ $page, 'render' ]
		);

		add_action( "load-{$hook}", [ $page, 'handle_actions' ] );
	}

	/**
	 * Loads the shared design-system stylesheet (see PLAN.md 8) only on
	 * ThickGrass's own admin screens, never globally.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'thickgrass' ) ) {
			return;
		}

		wp_enqueue_style( 'thickgrass-admin', THICKGRASS_URL . 'assets/admin.css', [], THICKGRASS_VERSION );
	}
}
