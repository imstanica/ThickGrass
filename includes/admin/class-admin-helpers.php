<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers for admin screens, to avoid duplicating the same
 * "build a <select> options array" code in every page class.
 */
class Admin_Helpers {

	/**
	 * @return array<int, string> WP user id => display name
	 */
	public static function wp_users_options(): array {
		$options = [];

		foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * @return array<int, string> agent id => display name of the underlying WP user
	 */
	public static function agent_options(): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'thickgrass_agents';
		$options = [];

		foreach ( $wpdb->get_results( "SELECT id, wp_user_id FROM {$table} WHERE is_active = 1" ) as $agent ) {
			$user = get_userdata( (int) $agent->wp_user_id );

			if ( $user ) {
				$options[ (int) $agent->id ] = $user->display_name;
			}
		}

		return $options;
	}

	/**
	 * The wp_thickgrass_agents.id row for the current WP user, if they are one.
	 */
	public static function current_agent_id(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_agents';
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d", get_current_user_id() ) );

		return $id ? (int) $id : null;
	}

	/**
	 * Creates or updates the caller's `wp_thickgrass_users` row with the given
	 * organization - used when logging a Call (PLAN.md: every caller must
	 * have an organization/location on file, confirmed/set at Call-creation
	 * time rather than left for an admin to backfill later on the
	 * Organizations screen). Mirrors the upsert `Organizations_Page::after_save()`
	 * already does for the same table.
	 */
	public static function upsert_user_organization( int $wp_user_id, int $organization_id ): void {
		global $wpdb;

		$table       = $wpdb->prefix . 'thickgrass_users';
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d", $wp_user_id ) );

		if ( $existing_id ) {
			$wpdb->update( $table, [ 'organization_id' => $organization_id ], [ 'id' => (int) $existing_id ] );
		} else {
			$wpdb->insert( $table, [
				'wp_user_id'      => $wp_user_id,
				'organization_id' => $organization_id,
				'created_at'      => current_time( 'mysql' ),
			] );
		}
	}

	/**
	 * Hooked to WP core's `user_register` from Plugin::init() (registered
	 * unconditionally, not just in is_admin() - a new WP user can just as well
	 * come from a front-end registration form) - see the "Automatically
	 * assign new users to the default organization" checkbox on Users_Page.
	 * A no-op if that setting is off, or if there is no default organization
	 * (should not happen - Activator::maybe_flag_default_organization()
	 * guarantees one always exists).
	 */
	public static function maybe_auto_assign_default_organization( int $wp_user_id ): void {
		if ( ! get_option( 'thickgrass_auto_assign_default_organization' ) ) {
			return;
		}

		global $wpdb;

		$table                    = $wpdb->prefix . 'thickgrass_organizations';
		$default_organization_id = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 LIMIT 1" );

		if ( $default_organization_id ) {
			self::upsert_user_organization( $wp_user_id, (int) $default_organization_id );
		}
	}

	/**
	 * @return array<int, string> organization id => name
	 */
	public static function organization_options(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_organizations';
		$rows  = $wpdb->get_results( "SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY name ASC" );

		return array_column( $rows, 'name', 'id' );
	}

	/**
	 * Formats "Location (Organization name)" - the organization name is
	 * always included because `location` alone is not unique: two different
	 * organizations can share the same location (e.g. two branches both in
	 * "Bucuresti"), and without the name they would be indistinguishable in
	 * a checkbox list. Falls back to just the name for older organizations
	 * saved before `location` became a required field.
	 */
	public static function format_organization_location( ?string $location, string $name ): string {
		return $location ? sprintf( '%s (%s)', $location, $name ) : $name;
	}

	/**
	 * Used where an organization stands in for a physical "Location" (e.g. the
	 * Agents screen's Locations checkboxes).
	 *
	 * @return array<int, string> organization id => "Location (Name)"
	 */
	public static function organization_location_options(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_organizations';
		$rows  = $wpdb->get_results( "SELECT id, name, location FROM {$table} WHERE is_active = 1 ORDER BY location ASC, name ASC" );

		$options = [];

		foreach ( $rows as $row ) {
			$options[ $row->id ] = self::format_organization_location( $row->location, $row->name );
		}

		return $options;
	}

	/**
	 * Generic id => label options for any list in the `wp_thickgrass_choices`
	 * engine (priority, category, ticket_type, asset_type...) - avoids every
	 * page that needs one of these dropdowns writing its own near-identical loop.
	 *
	 * @return array<int, string>
	 */
	public static function choice_options( string $list_key ): array {
		$options = [];

		foreach ( \ThickGrass\Choices::get_list( $list_key ) as $choice ) {
			$options[ $choice->id ] = $choice->label;
		}

		return $options;
	}

	/**
	 * @return array<int, string> asset id => name
	 */
	public static function asset_options(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_assets';
		$rows  = $wpdb->get_results( "SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY name ASC" );

		return array_column( $rows, 'name', 'id' );
	}

	/**
	 * Assets a given requester is allowed to pick on a ticket: their own
	 * assets, plus any asset with no owner at all (treated as shared/pool
	 * equipment, e.g. a shared printer) - see PLAN.md.
	 *
	 * @return array<int, string> asset id => name
	 */
	public static function asset_options_for_requester( int $requester_wp_user_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_assets';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$table}
			WHERE is_active = 1 AND (owner_wp_user_id = %d OR owner_wp_user_id IS NULL)
			ORDER BY name ASC",
			$requester_wp_user_id
		) );

		return array_column( $rows, 'name', 'id' );
	}

	/**
	 * @return array<int, int> WP user id => organization id, for every caller
	 *                          that already has one on file - used by the Call
	 *                          form's JS to auto-fill Organization/Location the
	 *                          moment a known caller is chosen (PLAN.md).
	 */
	public static function caller_organization_map(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thickgrass_users';
		$rows  = $wpdb->get_results( "SELECT wp_user_id, organization_id FROM {$table} WHERE organization_id IS NOT NULL" );

		$map = [];

		foreach ( $rows as $row ) {
			$map[ (int) $row->wp_user_id ] = (int) $row->organization_id;
		}

		return $map;
	}

	/**
	 * The combobox widget itself (input + hidden id + dropdown list), with no
	 * surrounding label/row markup - reused as-is by render_search_select_row()
	 * below (the ticket-style `<label> + <div class="thickgrass-form-row">`
	 * layout) AND by Generic_Form's `search_select` field type (a plain
	 * `<td>` in a `wp-admin` `.form-table`, see class-generic-form.php) -
	 * `.thickgrass-combobox` is self-contained (`position: relative`), so it
	 * drops into either layout unchanged.
	 *
	 * @param array<int, string> $options
	 */
	public static function render_combobox( string $field, array $options, ?int $selected ): void {
		$current_label = $selected && isset( $options[ $selected ] ) ? $options[ $selected ] : '';

		$js_options = [];
		foreach ( $options as $id => $option_label ) {
			$js_options[] = [ 'id' => $id, 'label' => $option_label ];
		}

		printf(
			'<div class="thickgrass-combobox" id="thickgrass-combobox-%1$s" data-options="%2$s">',
			esc_attr( $field ),
			esc_attr( wp_json_encode( $js_options ) )
		);
		printf( '<input type="text" class="thickgrass-search-select" value="%1$s" autocomplete="off" />', esc_attr( $current_label ) );
		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $field ), esc_attr( (string) $selected ) );
		echo '<ul class="thickgrass-combobox-list" hidden></ul>';
		echo '</div>';
	}

	/**
	 * A small custom combobox (text input + filterable dropdown list) instead
	 * of a long <select> (PLAN.md: "Assigned to, Location si Caller ar trebui
	 * sa fie search and select, nu dropdown" - potentially hundreds of
	 * users/agents/organizations). Deliberately NOT `<input list="...">` +
	 * `<datalist>` - the native datalist popup is styled entirely by the
	 * browser/OS with no CSS hooks at all (could not be recolored to match
	 * the rest of the design), so the dropdown here is a plain `<ul>` fully
	 * under our control, driven by render_search_select_script(). A hidden
	 * input carries the actual id. Shared across every screen that needs one
	 * (ticket detail, Call creation/conversion - PLAN.md) rather than each
	 * page reimplementing its own copy.
	 */
	public static function render_search_select_row( string $label, string $field, array $options, ?int $selected, ?string $row_id = null ): void {
		printf( '<div class="thickgrass-form-row"%s>', $row_id ? ' id="' . esc_attr( $row_id ) . '"' : '' );
		echo '<label>' . esc_html( $label ) . '</label>';
		echo '<span>';

		self::render_combobox( $field, $options, $selected );

		echo '</span></div>';
	}

	/**
	 * One shared script for every render_search_select_row() on the page -
	 * filters each combobox's own option list as you type, lets you pick with
	 * the mouse or the keyboard (arrows + Enter), and keeps the hidden id
	 * input in sync. An unmatched/cleared value empties the hidden id. Also
	 * dispatches a `thickgrass:combobox-change` event on selection, so a
	 * page can react to one combobox from another (e.g. the Call form
	 * auto-filling Organization/Location when a Caller is chosen) without
	 * this generic script needing to know about that relationship itself.
	 * Safe to call unconditionally, even with zero comboboxes on the page.
	 * Runs on `DOMContentLoaded` rather than immediately - this script tag
	 * can land on the page before a combobox added by a panel rendered
	 * further down (e.g. Dashboard_Page's Approvals panel, after the point
	 * where this is called), and querySelectorAll() run too early would
	 * simply never find it.
	 */
	/**
	 * Renders both of ThickGrass's secondary admin navigation groups
	 * ("Configurable lists" and "Settings") as one shared vertical sidebar
	 * (PLAN.md 7.48: "hai sa integram si settings ... 2 meniuri, unul sub
	 * altul") - called identically from both Choices_Page::render() and
	 * Settings_Page::render() so the exact same sidebar, and the ability to
	 * jump from one screen straight into the other, appears on both, not
	 * just each page's own tabs. $current_key only ever matches an item in
	 * whichever group belongs to the page currently rendering - the two
	 * pages' key-spaces (Choices list_keys vs Settings tab keys) never
	 * overlap, so passing just one value here is safe.
	 *
	 * @param array<int, array{title: string, items: array<int, array{url: string, label: string, key: string, indent: bool}>}> $groups
	 */
	public static function render_admin_nav_groups( array $groups, string $current_key ): void {
		echo '<nav class="thickgrass-choices-nav">';

		foreach ( $groups as $group ) {
			echo '<div class="thickgrass-choices-nav-group-title">' . esc_html( $group['title'] ) . '</div>';

			foreach ( $group['items'] as $item ) {
				$class = 'thickgrass-choices-nav-item';
				$class .= $item['key'] === $current_key ? ' is-active' : '';
				$class .= $item['indent'] ? ' is-sub-item' : '';

				printf( '<a href="%1$s" class="%2$s">%3$s</a>', esc_url( $item['url'] ), esc_attr( $class ), esc_html( $item['label'] ) );
			}
		}

		echo '</nav>';
	}

	/**
	 * One shared script for every `.thickgrass-modal` on the page, opened by
	 * any button carrying a matching `data-modal-target="<modal id>"` -
	 * generic on purpose, so a new modal anywhere in the plugin needs zero
	 * new JS. Originally the ticket screen's own ("Send email"/"Request
	 * approval" popups); moved here (PLAN.md 7.50) so Setup's Assignment
	 * groups pilot could reuse the exact same popup mechanism for its
	 * Add/Edit form. Safe to call unconditionally, even with zero modals on
	 * the page.
	 */
	public static function render_modal_script(): void {
		?>
		<script>
		( function () {
			document.querySelectorAll( '[data-modal-target]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var modal = document.getElementById( btn.dataset.modalTarget );

					if ( modal ) {
						modal.classList.add( 'is-open' );
					}
				} );
			} );

			document.querySelectorAll( '.thickgrass-modal' ).forEach( function ( modal ) {
				var closeBtn = modal.querySelector( '.thickgrass-modal-close' );

				if ( closeBtn ) {
					closeBtn.addEventListener( 'click', function () {
						modal.classList.remove( 'is-open' );
					} );
				}

				modal.addEventListener( 'click', function ( e ) {
					if ( e.target === modal ) {
						modal.classList.remove( 'is-open' );
					}
				} );
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key ) {
					document.querySelectorAll( '.thickgrass-modal.is-open' ).forEach( function ( modal ) {
						modal.classList.remove( 'is-open' );
					} );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * A readonly, click-to-select + "Copy" shortcode box - used wherever a
	 * generated shortcode needs to be handed to an admin for placement on a
	 * front-end page (Knowledge Base, Custom forms - PLAN.md 7.46: "dupa
	 * generarea lui lipseste shortcodurile pentru a fi plasate in
	 * front-end"). Uses the deprecated-but-universally-supported
	 * `document.execCommand('copy')` rather than the modern Clipboard API -
	 * `navigator.clipboard` requires a secure context (https, or a real
	 * `localhost`), which a typical local dev domain like `*.local` is not.
	 */
	public static function render_shortcode_box( string $shortcode ): void {
		printf(
			'<p class="thickgrass-shortcode-box"><label>%1$s</label> <input type="text" readonly onclick="this.select()" value="%2$s" class="regular-text" /> <button type="button" class="button" onclick="this.previousElementSibling.select();document.execCommand(\'copy\');">%3$s</button></p>',
			esc_html__( 'Shortcode:', 'thickgrass' ),
			esc_attr( $shortcode ),
			esc_html__( 'Copy', 'thickgrass' )
		);
	}

	public static function render_search_select_script(): void {
		?>
		<script>
		( function () {
			function init() {
			document.querySelectorAll( '.thickgrass-combobox' ).forEach( function ( box ) {
				var options = JSON.parse( box.dataset.options );
				var input   = box.querySelector( '.thickgrass-search-select' );
				var hidden  = box.querySelector( 'input[type="hidden"]' );
				var list    = box.querySelector( '.thickgrass-combobox-list' );
				var visible = [];
				var active  = -1;

				function renderList( term ) {
					term    = ( term || '' ).toLowerCase();
					visible = options.filter( function ( o ) { return o.label.toLowerCase().indexOf( term ) !== -1; } );
					active  = -1;

					list.innerHTML = '';

					visible.forEach( function ( option ) {
						var item = document.createElement( 'li' );
						item.textContent = option.label;
						item.addEventListener( 'mousedown', function ( e ) {
							e.preventDefault();
							choose( option );
						} );
						list.appendChild( item );
					} );

					list.hidden = visible.length === 0;
				}

				function choose( option ) {
					input.value  = option.label;
					hidden.value = option.id;
					list.hidden  = true;
					box.dispatchEvent( new CustomEvent( 'thickgrass:combobox-change', { detail: option, bubbles: true } ) );
				}

				function highlight() {
					Array.prototype.forEach.call( list.children, function ( item, index ) {
						item.classList.toggle( 'is-active', index === active );
					} );
				}

				input.addEventListener( 'input', function () {
					hidden.value = '';
					renderList( input.value );
				} );

				input.addEventListener( 'focus', function () {
					renderList( input.value );
				} );

				input.addEventListener( 'blur', function () {
					setTimeout( function () { list.hidden = true; }, 150 );
				} );

				input.addEventListener( 'keydown', function ( e ) {
					if ( list.hidden && 'ArrowDown' === e.key ) {
						renderList( input.value );
						return;
					}

					if ( 'ArrowDown' === e.key ) {
						e.preventDefault();
						active = Math.min( active + 1, visible.length - 1 );
						highlight();
					} else if ( 'ArrowUp' === e.key ) {
						e.preventDefault();
						active = Math.max( active - 1, 0 );
						highlight();
					} else if ( 'Enter' === e.key && active >= 0 ) {
						e.preventDefault();
						choose( visible[ active ] );
					} else if ( 'Escape' === e.key ) {
						list.hidden = true;
					}
				} );
			} );
			}

			if ( 'loading' === document.readyState ) {
				document.addEventListener( 'DOMContentLoaded', init );
			} else {
				init();
			}
		} )();
		</script>
		<?php
	}
}
