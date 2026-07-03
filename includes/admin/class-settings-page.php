<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single "Settings" admin screen hosting the plugin's global (not
 * per-ticket) configuration screens as tabs - same pattern as
 * class-choices-page.php's PAGE_TABS, just for a different set of pages.
 */
class Settings_Page {

	private const PAGE_TABS = [
		'email-inbox'     => Email_Pipe_Page::class,
		'email-templates' => Email_Templates_Page::class,
	];

	// Order + indentation re-arranged by the user directly once the shared
	// sidebar existed (PLAN.md 7.48) - "Email templates" nested under "Email
	// inbox (IMAP)" is a visual grouping choice, not a real dependency
	// between the two (each is independently reachable/editable).
	private const TAB_ORDER = [ 'email-inbox', 'email-templates' ];

	private const SUB_ITEMS = [ 'email-templates' ];

	private const TAB_LABELS = [
		'email-templates' => 'Email templates',
		'email-inbox'     => 'Email inbox (IMAP)',
	];

	public function render(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		$tab_key = $this->current_tab_key();

		echo '<div class="wrap thickgrass-wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'thickgrass' ) . '</h1>';

		// Same shared sidebar as Choices_Page, showing both nav groups
		// together (PLAN.md 7.48) - see Admin_Helpers::render_admin_nav_groups().
		echo '<div class="thickgrass-choices-layout">';
		Admin_Helpers::render_admin_nav_groups( [ Choices_Page::nav_group(), self::nav_group() ], $tab_key );

		echo '<div class="thickgrass-choices-content">';

		$page_class = self::PAGE_TABS[ $tab_key ];
		( new $page_class() )->render_body();

		echo '</div>'; // .thickgrass-choices-content
		echo '</div>'; // .thickgrass-choices-layout

		echo '</div>'; // .wrap
	}

	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			return;
		}

		$page_class = self::PAGE_TABS[ $this->current_tab_key() ];
		( new $page_class() )->handle_actions();
	}

	private function current_tab_key(): string {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		return in_array( $requested, self::TAB_ORDER, true ) ? $requested : self::TAB_ORDER[0];
	}

	/**
	 * This page's own nav group ("Settings"), in the shared shape
	 * Admin_Helpers::render_admin_nav_groups() expects - see render().
	 *
	 * @return array{title: string, items: array<int, array{url: string, label: string, key: string, indent: bool}>}
	 */
	public static function nav_group(): array {
		$items = [];

		foreach ( self::TAB_ORDER as $key ) {
			$items[] = [
				'url'    => admin_url( 'admin.php?page=thickgrass-settings&tab=' . $key ),
				'label'  => __( self::TAB_LABELS[ $key ], 'thickgrass' ),
				'key'    => $key,
				'indent' => in_array( $key, self::SUB_ITEMS, true ),
			];
		}

		return [ 'title' => __( 'Settings', 'thickgrass' ), 'items' => $items ];
	}
}
