<?php

namespace ThickGrass\Admin;

use ThickGrass\Call;
use ThickGrass\Choices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick call logging + conversion into a ticket, or closing without one.
 * See PLAN.md 7.6 - the "Calls" Faza 1 feature.
 */
class Calls_Page {

	private const CREATE_ACTION  = 'thickgrass_call_create';
	private const CONVERT_ACTION = 'thickgrass_call_convert';
	private const CLOSE_ACTION   = 'thickgrass_call_close';

	public function render(): void {
		if ( ! current_user_can( 'thickgrass_agent' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		echo '<div class="wrap thickgrass-wrap">';
		echo '<h1>' . esc_html__( 'Calls', 'thickgrass' ) . '</h1>';

		$this->render_error_notice();

		if ( isset( $_GET['view'] ) ) {
			$this->render_detail( (int) $_GET['view'] );
		} else {
			$this->render_create_form();
			$this->render_list();
		}

		// Shared by every render_search_select_row() combobox on this page
		// (Caller/Organization/Location/Assignment group on create, Requester/
		// Assignment group on convert) - harmless no-op if none were rendered
		// (e.g. a closed call's read-only detail view).
		Admin_Helpers::render_search_select_script();

		echo '</div>';
	}

	/**
	 * Handles create/convert/close + redirect. Must run on `load-{$hook}`, before
	 * any HTML output - see class-choices-page.php for why.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_agent' ) ) {
			return;
		}

		$this->handle_create();
		$this->handle_convert();
		$this->handle_close();
	}

	private function handle_create(): void {
		if ( ! isset( $_POST[ self::CREATE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::CREATE_ACTION . '_nonce' ], self::CREATE_ACTION )
		) {
			return;
		}

		$posted             = wp_unslash( $_POST );
		$short_description  = sanitize_text_field( $posted['short_description'] ?? '' );

		if ( '' === $short_description ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&error=missing_description' ) );
			exit;
		}

		// A call can only be logged for an existing site user - "unknown
		// caller" (name/email typed in) is no longer supported (PLAN.md: a
		// ticket must always trace back to a real caller so its Organization/
		// Location can be resolved for agent-scoped visibility - see
		// Ticket::query()).
		$caller_wp_user_id = ! empty( $posted['caller_wp_user_id'] ) ? (int) $posted['caller_wp_user_id'] : 0;

		if ( ! $caller_wp_user_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&error=missing_caller' ) );
			exit;
		}

		$assignment_group_id = $this->nullable_int( $posted['assignment_group_id'] ?? '' );

		if ( ! $assignment_group_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&error=missing_assignment_group' ) );
			exit;
		}

		$organization_id = $this->nullable_int( $posted['organization_id'] ?? '' );

		if ( ! $organization_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&error=missing_organization' ) );
			exit;
		}

		// Location is its own field (PLAN.md: "Organization / Location trebuie
		// sa fie 2 fielduri separate") - it usually matches Organization (the
		// JS auto-fills it the moment a caller is chosen - see
		// render_create_form()) but can be pointed at a different site the
		// caller is reporting from for this specific call, same distinction
		// Dashboard_Page::effective_location_organization_id() makes on a ticket.
		$location_organization_id = $this->nullable_int( $posted['location_organization_id'] ?? '' );

		if ( ! $location_organization_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&error=missing_location' ) );
			exit;
		}

		// Confirmed/updated every time a call is logged, not just the first
		// time - keeps the caller's own Organization current if it ever
		// changes, and backfills it the first time for a caller who never had
		// a `wp_thickgrass_users` row at all.
		Admin_Helpers::upsert_user_organization( $caller_wp_user_id, $organization_id );

		$call_id = Call::create( [
			'short_description'        => $short_description,
			'notes'                    => $posted['notes'] ?? '',
			'contact_type_id'          => $this->nullable_int( $posted['contact_type_id'] ?? '' ),
			'caller_wp_user_id'        => $caller_wp_user_id,
			'assignment_group_id'      => $assignment_group_id,
			'location_organization_id' => $location_organization_id,
			'created_by_agent_id'      => Admin_Helpers::current_agent_id(),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&view=' . $call_id ) );
		exit;
	}

	private function handle_convert(): void {
		if ( ! isset( $_POST[ self::CONVERT_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::CONVERT_ACTION . '_nonce' ], self::CONVERT_ACTION )
		) {
			return;
		}

		$call_id = (int) ( $_POST['call_id'] ?? 0 );
		$call    = $call_id ? Call::get( $call_id ) : null;

		if ( ! $call || 'open' !== $call->status ) {
			return;
		}

		$posted             = wp_unslash( $_POST );
		$requester_wp_user_id = (int) ( $posted['requester_wp_user_id'] ?? 0 );

		if ( ! $requester_wp_user_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&view=' . $call_id . '&error=missing_requester' ) );
			exit;
		}

		// Required here too (PLAN.md), even though the Call already has one -
		// the agent can still change it before the ticket actually exists.
		$assignment_group_id = $this->nullable_int( $posted['assignment_group_id'] ?? '' );

		if ( ! $assignment_group_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&view=' . $call_id . '&error=missing_assignment_group' ) );
			exit;
		}

		$ticket_data = [
			'ticket_type_id'           => (int) ( $posted['ticket_type_id'] ?? 0 ),
			'title'                    => sanitize_text_field( $posted['title'] ?? $call->short_description ),
			'description'              => wp_kses_post( $posted['description'] ?? $call->notes ),
			'requester_wp_user_id'     => $requester_wp_user_id,
			'assignment_group_id'      => $assignment_group_id,
			'location_organization_id' => $call->location_organization_id ? (int) $call->location_organization_id : null,
			'category_id'              => $this->nullable_int( $posted['category_id'] ?? '' ),
			'priority_id'              => $this->nullable_int( $posted['priority_id'] ?? '' ),
			'impact_id'                => $this->nullable_int( $posted['impact_id'] ?? '' ),
		];

		try {
			$ticket_id = Call::convert_to_ticket( $call_id, $ticket_data );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&view=' . $call_id . '&error=invalid_ticket_type' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id ) );
		exit;
	}

	private function handle_close(): void {
		if ( ! isset( $_POST[ self::CLOSE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::CLOSE_ACTION . '_nonce' ], self::CLOSE_ACTION )
		) {
			return;
		}

		$call_id         = (int) ( $_POST['call_id'] ?? 0 );
		$close_reason_id = (int) ( $_POST['close_reason_id'] ?? 0 );

		if ( ! $call_id || ! $close_reason_id ) {
			return;
		}

		Call::close_without_ticket( $call_id, $close_reason_id );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-calls&view=' . $call_id ) );
		exit;
	}

	private function nullable_int( $value ): ?int {
		return ( '' === $value || null === $value ) ? null : (int) $value;
	}

	/**
	 * Caller, Assignment group, Organization, and Location are all required
	 * (PLAN.md: a call must always trace back to a real caller with a known
	 * Organization/Location and a routing group, so downstream agent-scoped
	 * visibility - see Ticket::query() - always has
	 * something to match against). "Unknown caller" (free-text name/email) is
	 * intentionally no longer offered here - only an existing site user can
	 * be a caller. Same panel/form-row design as the ticket screen (PLAN.md
	 * section 8), and the same search-select combobox for the long-list
	 * fields (Caller, Assignment group, Organization, Location) instead of a
	 * plain dropdown.
	 */
	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'New call', 'thickgrass' ) . '</h2>';
		echo '<div class="thickgrass-card">';
		echo '<form method="post">';
		wp_nonce_field( self::CREATE_ACTION, self::CREATE_ACTION . '_nonce' );

		Admin_Helpers::render_search_select_row( __( 'Caller', 'thickgrass' ), 'caller_wp_user_id', Admin_Helpers::wp_users_options(), null );
		Admin_Helpers::render_search_select_row( __( 'Organization', 'thickgrass' ), 'organization_id', Admin_Helpers::organization_options(), null );
		Admin_Helpers::render_search_select_row( __( 'Location', 'thickgrass' ), 'location_organization_id', Admin_Helpers::organization_location_options(), null );
		Admin_Helpers::render_search_select_row( __( 'Assignment group', 'thickgrass' ), 'assignment_group_id', Admin_Helpers::choice_options( 'assignment_group' ), null );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Contact type', 'thickgrass' ) . '</label><span>';
		$this->render_choice_select( 'contact_type_id', 'contact_type' );
		echo '</span></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Short description', 'thickgrass' ) . '</label>';
		echo '<input type="text" name="short_description" class="large-text" required /></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Notes', 'thickgrass' ) . '</label>';
		echo '<textarea name="notes" class="large-text" rows="3"></textarea></div>';

		submit_button( __( 'Log call', 'thickgrass' ) );
		echo '</form>';
		echo '</div>';

		$this->render_caller_autofill_script();
	}

	/**
	 * Organization and Location both default from whatever the chosen Caller
	 * already has on file (PLAN.md: "ambele trebuie sa se completeze automat
	 * in functie de caller") - both stay editable afterward, e.g. to log a
	 * call for a caller reporting from a different site than usual. Listens
	 * for the `thickgrass:combobox-change` event the Caller combobox fires
	 * (see Admin_Helpers::render_search_select_script()) rather than
	 * duplicating that generic script.
	 */
	private function render_caller_autofill_script(): void {
		?>
		<script>
		( function () {
			var callerBox   = document.getElementById( 'thickgrass-combobox-caller_wp_user_id' );
			var orgBox      = document.getElementById( 'thickgrass-combobox-organization_id' );
			var locationBox = document.getElementById( 'thickgrass-combobox-location_organization_id' );
			var callerOrgs  = <?php echo wp_json_encode( Admin_Helpers::caller_organization_map() ); ?>;

			if ( ! callerBox ) { return; }

			function fill( box, organizationId ) {
				if ( ! box ) { return; }

				var options = JSON.parse( box.dataset.options );
				var match   = options.filter( function ( o ) { return String( o.id ) === String( organizationId ); } )[0];

				if ( ! match ) { return; }

				box.querySelector( '.thickgrass-search-select' ).value = match.label;
				box.querySelector( 'input[type="hidden"]' ).value = match.id;
			}

			callerBox.addEventListener( 'thickgrass:combobox-change', function ( e ) {
				var organizationId = callerOrgs[ e.detail.id ];

				if ( ! organizationId ) { return; }

				fill( orgBox, organizationId );
				fill( locationBox, organizationId );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Only calls the current agent logged themselves (PLAN.md: "tabelul Calls
	 * trebuie sa arate doar call-urile create de agentul respectiv") -
	 * filtered in SQL via Call::get_all( $agent_id ), not fetched in full and
	 * pared down here. Same "not configured as an Agent yet" fallback as
	 * Dashboard_Page::render_list() for consistency.
	 */
	private function render_list(): void {
		$agent_id = Admin_Helpers::current_agent_id();

		echo '<h2>' . esc_html__( 'Calls', 'thickgrass' ) . '</h2>';

		if ( null === $agent_id ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your account is not set up as an Agent yet, so no calls are shown. Ask a manager to add you under Configurable lists → Agents.', 'thickgrass' ) . '</p></div>';
			return;
		}

		$calls = Call::get_all( $agent_id );

		if ( ! $calls ) {
			echo '<p>' . esc_html__( 'No calls logged yet.', 'thickgrass' ) . '</p>';
			return;
		}

		$status_labels = [
			'open'             => __( 'Open', 'thickgrass' ),
			'converted'        => __( 'Converted', 'thickgrass' ),
			'closed_no_ticket' => __( 'Closed (no ticket)', 'thickgrass' ),
		];

		$contact_types = $this->index_choices_by_id( 'contact_type' );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Description', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Caller', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Contact type', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $calls as $call ) {
			$view_url = admin_url( 'admin.php?page=thickgrass-calls&view=' . $call->id );

			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( $view_url ),
				esc_html( $call->short_description ),
				esc_html( $this->caller_label( $call ) ),
				esc_html( $contact_types[ (int) ( $call->contact_type_id ?? 0 ) ]->label ?? '—' ),
				esc_html( $status_labels[ $call->status ] ?? $call->status ),
				esc_html( $call->created_at )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @return array<int, object> choice rows indexed by id
	 */
	private function index_choices_by_id( string $list_key ): array {
		$indexed = [];

		foreach ( Choices::get_list( $list_key, false ) as $choice ) {
			$indexed[ (int) $choice->id ] = $choice;
		}

		return $indexed;
	}

	private function render_detail( int $call_id ): void {
		$call = Call::get( $call_id );

		if ( ! $call ) {
			echo '<p>' . esc_html__( 'Call not found.', 'thickgrass' ) . '</p>';
			return;
		}

		$contact_type      = ! empty( $call->contact_type_id ) ? Choices::get( (int) $call->contact_type_id ) : null;
		$assignment_group  = ! empty( $call->assignment_group_id ) ? Choices::get( (int) $call->assignment_group_id ) : null;
		$location          = ! empty( $call->location_organization_id ) ? Admin_Helpers::organization_location_options()[ (int) $call->location_organization_id ] ?? null : null;

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=thickgrass-calls' ) ) . '">&larr; ' . esc_html__( 'Back to calls', 'thickgrass' ) . '</a></p>';
		echo '<h2>' . esc_html( $call->short_description ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Caller', 'thickgrass' ) . ':</strong> ' . esc_html( $this->caller_label( $call ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Location', 'thickgrass' ) . ':</strong> ' . esc_html( $location ?? '—' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Assignment group', 'thickgrass' ) . ':</strong> ' . esc_html( $assignment_group ? $assignment_group->label : '—' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Contact type', 'thickgrass' ) . ':</strong> ' . esc_html( $contact_type ? $contact_type->label : '—' ) . '</p>';

		if ( $call->notes ) {
			echo '<div>' . wp_kses_post( wpautop( $call->notes ) ) . '</div>';
		}

		if ( 'open' === $call->status ) {
			$this->render_convert_form( $call );
			$this->render_close_form( $call );
		} elseif ( 'converted' === $call->status ) {
			$ticket_url = admin_url( 'admin.php?page=thickgrass&view=' . $call->converted_ticket_id );
			echo '<p>' . esc_html__( 'Converted to ticket:', 'thickgrass' ) . ' <a href="' . esc_url( $ticket_url ) . '">' . esc_html__( 'View ticket', 'thickgrass' ) . '</a></p>';
		} else {
			$reason = $call->close_reason_id ? Choices::get( (int) $call->close_reason_id ) : null;
			echo '<p>' . esc_html__( 'Closed without a ticket.', 'thickgrass' ) . ' ' . esc_html__( 'Reason:', 'thickgrass' ) . ' ' . esc_html( $reason ? $reason->label : '—' ) . '</p>';
		}
	}

	/**
	 * Same panel/form-row design as the ticket screen, and the same
	 * search-select combobox for Requester and Assignment group (PLAN.md).
	 */
	private function render_convert_form( object $call ): void {
		echo '<h3>' . esc_html__( 'Convert to ticket', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';
		echo '<form method="post">';
		wp_nonce_field( self::CONVERT_ACTION, self::CONVERT_ACTION . '_nonce' );
		echo '<input type="hidden" name="call_id" value="' . esc_attr( $call->id ) . '" />';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Ticket type', 'thickgrass' ) . '</label><span>';
		$this->render_choice_select( 'ticket_type_id', 'ticket_type' );
		echo '</span></div>';

		Admin_Helpers::render_search_select_row( __( 'Requester', 'thickgrass' ), 'requester_wp_user_id', Admin_Helpers::wp_users_options(), $call->caller_wp_user_id ? (int) $call->caller_wp_user_id : null );

		// Carried over from the Call (PLAN.md: required there too), but still
		// confirmable/changeable here before the ticket is actually created.
		Admin_Helpers::render_search_select_row( __( 'Assignment group', 'thickgrass' ), 'assignment_group_id', Admin_Helpers::choice_options( 'assignment_group' ), $call->assignment_group_id ? (int) $call->assignment_group_id : null );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Title', 'thickgrass' ) . '</label>';
		echo '<input type="text" name="title" class="large-text" value="' . esc_attr( $call->short_description ) . '" required /></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Description', 'thickgrass' ) . '</label>';
		echo '<textarea name="description" class="large-text" rows="4">' . esc_textarea( $call->notes ) . '</textarea></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Category', 'thickgrass' ) . '</label><span>';
		$this->render_choice_select( 'category_id', 'category' );
		echo '</span></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Priority', 'thickgrass' ) . '</label><span>';
		$this->render_choice_select( 'priority_id', 'priority' );
		echo '</span></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Impact', 'thickgrass' ) . '</label><span>';
		$this->render_choice_select( 'impact_id', 'impact' );
		echo '</span></div>';

		submit_button( __( 'Convert to ticket', 'thickgrass' ) );
		echo '</form>';
		echo '</div>';
	}

	private function render_close_form( object $call ): void {
		echo '<h3>' . esc_html__( 'Close without a ticket', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';
		echo '<form method="post">';
		wp_nonce_field( self::CLOSE_ACTION, self::CLOSE_ACTION . '_nonce' );
		echo '<input type="hidden" name="call_id" value="' . esc_attr( $call->id ) . '" />';

		$this->render_choice_select( 'close_reason_id', 'call_close_reason' );
		echo ' ';
		submit_button( __( 'Close call', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * No blank "—" placeholder (PLAN.md, same reasoning already applied to
	 * the ticket screen's plain selects) - every one of these is a create-once
	 * field (a new Call or a new Ticket via conversion), so there is no
	 * pre-existing value that could get silently overwritten the way an
	 * edit-screen select risked (see Dashboard_Page::render_select() for that
	 * more delicate case).
	 */
	private function render_choice_select( string $field, string $list_key, ?int $selected = null ): void {
		printf( '<select name="%s" required>', esc_attr( $field ) );

		foreach ( Choices::get_list( $list_key ) as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$choice->id,
				selected( $selected, (int) $choice->id, false ),
				esc_html( $choice->label )
			);
		}

		echo '</select>';
	}

	private function render_error_notice(): void {
		$errors = [
			'missing_description'      => __( 'Please enter a short description.', 'thickgrass' ),
			'missing_caller'           => __( 'Please choose a caller.', 'thickgrass' ),
			'missing_assignment_group' => __( 'Please choose an assignment group.', 'thickgrass' ),
			'missing_organization'     => __( 'Please choose the caller\'s organization.', 'thickgrass' ),
			'missing_location'         => __( 'Please choose a location.', 'thickgrass' ),
			'missing_requester'        => __( 'Please choose a requester before converting to a ticket.', 'thickgrass' ),
			'invalid_ticket_type'      => __( 'Please choose a valid ticket type.', 'thickgrass' ),
		];

		$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';

		if ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
		}
	}

	private function caller_label( object $call ): string {
		if ( $call->caller_wp_user_id ) {
			$user = get_userdata( (int) $call->caller_wp_user_id );

			if ( $user ) {
				return $user->display_name;
			}
		}

		return $call->caller_name ?: ( $call->caller_email ?: __( 'Unknown', 'thickgrass' ) );
	}
}
