<?php

namespace ThickGrass\Admin;

use ThickGrass\Activity_Log;
use ThickGrass\Approval;
use ThickGrass\Attachment;
use ThickGrass\Canned_Response;
use ThickGrass\Choices;
use ThickGrass\Comment;
use ThickGrass\Custom_Form_Field_Value;
use ThickGrass\Email_Notifications;
use ThickGrass\Kb_Article;
use ThickGrass\Sla;
use ThickGrass\Ticket;
use ThickGrass\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent workbench and landing page (see class-menu.php - this is what
 * "ThickGrass" in the sidebar opens directly into): stat boxes for unassigned
 * tickets per type, the ticket list grouped by type with filters/saved views,
 * and the single-ticket detail/edit screen (status, priority, assignment,
 * comments, activity feed). Tickets are NOT created here - staff can only
 * open one by converting a Call (see Calls_Page::handle_convert()); this
 * screen is read/update only by design.
 */
class Dashboard_Page {

	private const SAVE_ACTION       = 'thickgrass_ticket_save';
	private const SAVE_VIEW_ACTION  = 'thickgrass_ticket_save_view';
	private const UPLOAD_ACTION     = 'thickgrass_attachment_upload';
	private const SEND_EMAIL_ACTION = 'thickgrass_ticket_send_email';
	private const REQUEST_APPROVAL_ACTION = 'thickgrass_ticket_request_approval';
	private const EMAIL_MODAL_ID    = 'thickgrass-email-modal';
	private const APPROVAL_MODAL_ID = 'thickgrass-approval-modal';

	/** ticket list query args that can be used both as a filter and stored in a saved view */
	private const FILTER_FIELDS = [
		'status_id'           => 'status',
		'priority_id'         => 'priority',
		'category_id'         => 'category',
		'assignment_group_id' => 'assignment_group',
	];

	private const EDITABLE_SELECT_FIELDS = [
		'status_id'          => 'status',
		'on_hold_reason_id'  => 'on_hold_reason',
		'priority_id'        => 'priority',
		'impact_id'          => 'impact',
		'category_id'        => 'category',
		'close_reason_id'    => 'call_close_reason',
	];

	/** field_changed value (as stored in the activity log) => human label */
	private const FIELD_LABELS = [
		'status_id'            => 'State',
		'on_hold_reason_id'    => 'On hold reason',
		'priority_id'          => 'Priority',
		'impact_id'            => 'Impact',
		'category_id'          => 'Category',
		'asset_id'             => 'Asset',
		'assigned_agent_id'    => 'Assigned to',
		'assignment_group_id'  => 'Assignment group',
		'title'                => 'Title',
		'description'          => 'Description',
		'requester_wp_user_id' => 'Caller',
		'location_organization_id' => 'Location',
		'created'              => 'Created',
		'sla_escalation'       => 'SLA escalation',
		'sla_change'           => 'SLA change',
		'approval_decision'    => 'Approval',
		'close_reason_id'      => 'Close reason',
		'close_notes'          => 'Close notes',
	];

	/** fields whose old/new value is a wp_thickgrass_choices id, not a raw string */
	private const CHOICE_FIELDS = [ 'status_id', 'on_hold_reason_id', 'priority_id', 'impact_id', 'category_id', 'assignment_group_id', 'close_reason_id' ];

	public function render(): void {
		if ( ! current_user_can( 'thickgrass_agent' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		echo '<div class="wrap thickgrass-wrap">';
		echo '<h1>' . esc_html__( 'Dashboard', 'thickgrass' ) . '</h1>';

		$this->render_error_notice();

		if ( isset( $_GET['view'] ) ) {
			$this->render_detail( (int) $_GET['view'] );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Handles update + redirect. Must run on `load-{$hook}`, before any
	 * HTML output - see class-choices-page.php for why.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_agent' ) ) {
			return;
		}

		$this->handle_save();
		$this->handle_save_view();
		$this->handle_delete_view();
		$this->handle_attachment_upload();
		$this->handle_attachment_delete();
		$this->handle_send_email();
		$this->handle_request_approval();
		$this->handle_cancel_approval();
	}

	private function render_error_notice(): void {
		$errors = [
			'missing_close_fields' => __( 'This status requires a Close reason and Close notes before the ticket can be closed.', 'thickgrass' ),
		];

		$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';

		if ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
		} elseif ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Something went wrong.', 'thickgrass' ) . '</p></div>';
		}
	}

	/**
	 * A single form on the detail screen both updates the ticket's fields and,
	 * optionally, adds a comment/work note - one nonce, one submit button.
	 */
	private function handle_save(): void {
		if ( ! isset( $_POST[ self::SAVE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::SAVE_ACTION . '_nonce' ], self::SAVE_ACTION )
		) {
			return;
		}

		$ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );

		if ( ! $ticket_id ) {
			return;
		}

		$ticket = Ticket::get( $ticket_id );

		if ( ! $ticket || ! $this->agent_can_access_ticket( $ticket ) ) {
			return;
		}

		$posted   = wp_unslash( $_POST );
		$actor_id = get_current_user_id();

		// The ticket's fields are locked in two cases (PLAN.md 7.39/7.42): an
		// approval is pending, or the ticket is already in a "Closed" status
		// (permanent - "Resolved" alone stays editable/reopenable, see
		// is_closed_status()). The fieldset is disabled client-side too (see
		// render_detail()), but that's just UX; this is what actually
		// enforces it, since a disabled fieldset alone doesn't stop a direct
		// POST. Comments/work notes still go through below either way - only
		// the field changes are blocked.
		$locked = Approval::has_pending( $ticket_id ) || $this->is_closed_status( (int) $ticket->status_id );

		if ( ! $locked ) {
			// A ticket can't be moved into a Resolved or Closed status
			// (whichever checkbox applies, PLAN.md 7.42) without both a
			// Close reason and Close notes (7.41) - checked before anything
			// is saved, so a rejected save doesn't silently apply other
			// field changes from the same submission while leaving the
			// status untouched (confusing half-applied state). The fields
			// stay disabled/required only while visible client-side (see
			// render_state_dependent_rows_script()), but that's just UX -
			// this is what actually enforces it.
			if ( $this->requires_close_fields( $this->nullable_int( $posted['status_id'] ?? null ) )
				&& ( ! $this->nullable_int( $posted['close_reason_id'] ?? null ) || '' === trim( $posted['close_notes'] ?? '' ) )
			) {
				wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id . '&error=missing_close_fields' ) );
				exit;
			}

			$this->handle_save_fields( $ticket_id, $posted, $actor_id );
		}

		$body = trim( $posted['body'] ?? '' );

		if ( '' !== $body ) {
			// Comments default to internal work notes unless the agent explicitly
			// opts in to make them visible to the requester (PLAN.md - safer
			// default than the previous "check this box to hide it" phrasing).
			Comment::create( $ticket_id, $actor_id, $body, empty( $posted['visible_to_requester'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id ) );
		exit;
	}

	/**
	 * The field-changes half of handle_save() - split out so it can be
	 * skipped entirely while the ticket is locked (a pending approval,
	 * PLAN.md 7.39), without touching the comment/redirect logic around it.
	 *
	 * @param array<string, mixed> $posted
	 */
	private function handle_save_fields( int $ticket_id, array $posted, int $actor_id ): void {
		$timestamp = current_time( 'mysql' );

		// status_id MUST be processed LAST: its auto-assign side effect
		// (meta.auto_assign_to_actor, PLAN.md 7.38) reads the ticket's
		// CURRENT assigned_agent_id, which needs to already reflect any
		// reassignment from this very same submission - not the value the
		// page happened to load with, or it would auto-assign and then
		// immediately overwrite that with the (stale) posted value, logging
		// two contradictory "Assigned to" changes for one save (confirmed bug).
		$fields    = array_merge(
			[ 'assigned_agent_id', 'assignment_group_id', 'asset_id', 'requester_wp_user_id', 'location_organization_id' ],
			array_diff( array_keys( self::EDITABLE_SELECT_FIELDS ), [ 'status_id' ] ),
			[ 'status_id' ]
		);

		foreach ( $fields as $field ) {
			if ( ! isset( $posted[ $field ] ) ) {
				continue;
			}

			$value = $this->nullable_int( $posted[ $field ] );

			// Caller is a NOT NULL column - the search-select (see
			// Admin_Helpers::render_search_select_row()) submits an empty hidden
			// value when what was typed doesn't match any option, which must
			// never clear it.
			if ( 'requester_wp_user_id' === $field && ! $value ) {
				continue;
			}

			// on_hold_reason_id only means something while the ticket is
			// actually On Hold (the row is hidden otherwise - see
			// render_state_dependent_rows_script()). Its <select> has no blank
			// placeholder (PLAN.md: no decorative "—" options), so with
			// nothing chosen it visually defaults to its first real option -
			// which must never get silently saved for a ticket that was
			// never on hold. Forcing NULL here also clears any reason left
			// over from a *previous* hold period once the ticket leaves it.
			if ( 'on_hold_reason_id' === $field && ! $this->is_on_hold_status( $this->nullable_int( $posted['status_id'] ?? null ) ) ) {
				$value = null;
			}

			// Same reasoning as on_hold_reason_id above, just for the
			// Resolved/Closed side (PLAN.md 7.42) - the row is hidden unless
			// the chosen status is one of those, so a leftover/stray value
			// must never get silently saved or carried over once the ticket
			// leaves that state.
			if ( 'close_reason_id' === $field && ! $this->requires_close_fields( $this->nullable_int( $posted['status_id'] ?? null ) ) ) {
				$value = null;
			}

			Ticket::update_field( $ticket_id, $field, $value, $actor_id, $timestamp );
		}

		// Title is editable, description is not (PLAN.md) - so it's handled
		// separately from the FK-id $fields above, which all go through nullable_int().
		if ( isset( $posted['title'] ) ) {
			$title = sanitize_text_field( $posted['title'] );

			if ( '' !== $title ) {
				Ticket::update_field( $ticket_id, 'title', $title, $actor_id, $timestamp );
			}
		}

		// Close notes: free text, so handled like title rather than through
		// nullable_int() - but cleared the same way close_reason_id is above
		// once the ticket isn't in a Resolved/Closed status (the field is
		// hidden then).
		if ( isset( $posted['close_notes'] ) ) {
			$close_notes = $this->requires_close_fields( $this->nullable_int( $posted['status_id'] ?? null ) )
				? sanitize_textarea_field( $posted['close_notes'] )
				: '';

			Ticket::update_field( $ticket_id, 'close_notes', $close_notes, $actor_id, $timestamp );
		}
	}

	/**
	 * Saves the currently applied filters as a named view. Managers may mark it
	 * "shared" (agent_wp_user_id = null, visible to every agent); anyone else's
	 * view is private to them.
	 */
	private function handle_save_view(): void {
		if ( ! isset( $_POST[ self::SAVE_VIEW_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::SAVE_VIEW_ACTION . '_nonce' ], self::SAVE_VIEW_ACTION )
		) {
			return;
		}

		$posted = wp_unslash( $_POST );
		$name   = sanitize_text_field( $posted['view_name'] ?? '' );

		if ( '' === $name ) {
			return;
		}

		$filters = [];

		foreach ( (array) ( $posted['filters'] ?? [] ) as $field => $value ) {
			if ( '' !== $value ) {
				$filters[ sanitize_key( $field ) ] = (int) $value;
			}
		}

		$shared = ! empty( $posted['shared'] ) && current_user_can( 'thickgrass_manage' );

		View::create( [
			'agent_wp_user_id' => $shared ? null : get_current_user_id(),
			'name'             => $name,
			'filters'          => $filters,
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&' . http_build_query( $filters ) ) );
		exit;
	}

	private function handle_delete_view(): void {
		if ( ! isset( $_GET['delete_view'] ) ) {
			return;
		}

		$view_id = (int) $_GET['delete_view'];
		check_admin_referer( 'thickgrass_view_delete_' . $view_id );

		$view            = View::get( $view_id );
		$owns_it         = $view && (int) $view->agent_wp_user_id === get_current_user_id();
		$is_shared_admin = $view && null === $view->agent_wp_user_id && current_user_can( 'thickgrass_manage' );

		if ( $owns_it || $is_shared_admin ) {
			View::delete( $view_id );
		}

		wp_safe_redirect( remove_query_arg( [ 'delete_view', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Ticket-level only (PLAN.md: comments already work well and stay as-is,
	 * this doesn't touch them) - a separate small form from the main save
	 * form, since uploads need `enctype="multipart/form-data"` and the main
	 * form doesn't (and doesn't need to just for this).
	 */
	private function handle_attachment_upload(): void {
		if ( ! isset( $_POST[ self::UPLOAD_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::UPLOAD_ACTION . '_nonce' ], self::UPLOAD_ACTION )
		) {
			return;
		}

		$ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
		$ticket    = $ticket_id ? Ticket::get( $ticket_id ) : null;

		if ( ! $ticket || ! $this->agent_can_access_ticket( $ticket ) || empty( $_FILES['attachment']['name'] ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// wp_handle_upload() applies WP's own allowed-mime-type/size checks
		// (get_allowed_mime_types(), upload_max_filesize) - no custom
		// whitelist to hardcode/maintain here.
		$uploaded = wp_handle_upload( $_FILES['attachment'], [ 'test_form' => false ] );

		if ( ! empty( $uploaded['file'] ) ) {
			Attachment::create( $ticket_id, get_current_user_id(), $uploaded['file'], sanitize_file_name( $_FILES['attachment']['name'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id ) );
		exit;
	}

	private function handle_attachment_delete(): void {
		if ( ! isset( $_GET['delete_attachment'] ) ) {
			return;
		}

		$attachment_id = (int) $_GET['delete_attachment'];
		check_admin_referer( 'thickgrass_attachment_delete_' . $attachment_id );

		$attachment = Attachment::get( $attachment_id );
		$ticket     = $attachment ? Ticket::get( (int) $attachment->ticket_id ) : null;

		if ( $ticket && $this->agent_can_access_ticket( $ticket ) ) {
			Attachment::delete( $attachment_id );
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $attachment->ticket_id ) );
			exit;
		}

		wp_safe_redirect( remove_query_arg( [ 'delete_attachment', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * A free-form email an agent can send straight from the ticket screen (not
	 * one of the templated events in Email_Notifications::EVENTS) - still
	 * tagged/logged the same way (see Email_Notifications::send_manual()), so
	 * a reply to it threads back into the ticket exactly like a templated one.
	 */
	private function handle_send_email(): void {
		if ( ! isset( $_POST[ self::SEND_EMAIL_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::SEND_EMAIL_ACTION . '_nonce' ], self::SEND_EMAIL_ACTION )
		) {
			return;
		}

		$ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
		$ticket    = $ticket_id ? Ticket::get( $ticket_id ) : null;
		$posted    = wp_unslash( $_POST );
		$to        = sanitize_email( $posted['to'] ?? '' );
		$subject   = sanitize_text_field( $posted['subject'] ?? '' );
		$body      = sanitize_textarea_field( $posted['body'] ?? '' );

		if ( $ticket && $this->agent_can_access_ticket( $ticket ) && $to && $subject && $body ) {
			Email_Notifications::send_manual( $ticket_id, get_current_user_id(), $to, $subject, $body );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id ) );
		exit;
	}

	/**
	 * Asks a specific WP user to Approve/Reject the ticket - see
	 * ThickGrass\Approval. The actual decision happens off-site, from the
	 * emailed link (see Shortcodes::render_approval()), not here.
	 */
	private function handle_request_approval(): void {
		if ( ! isset( $_POST[ self::REQUEST_APPROVAL_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::REQUEST_APPROVAL_ACTION . '_nonce' ], self::REQUEST_APPROVAL_ACTION )
		) {
			return;
		}

		$ticket_id           = (int) ( $_POST['ticket_id'] ?? 0 );
		$approver_wp_user_id = (int) ( $_POST['approver_wp_user_id'] ?? 0 );
		$comment             = sanitize_textarea_field( wp_unslash( $_POST['approval_comment'] ?? '' ) );
		$ticket              = $ticket_id ? Ticket::get( $ticket_id ) : null;

		// Re-checked server-side, not just hidden in the UI - the button/modal
		// only appear when this is true, but nothing stops a direct POST.
		if ( $ticket && $this->agent_can_access_ticket( $ticket ) && $approver_wp_user_id && Approval::can_request( $ticket ) ) {
			Approval::create( $ticket_id, $approver_wp_user_id, get_current_user_id(), $comment );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $ticket_id ) );
		exit;
	}

	/**
	 * Lets an agent force-reject a still-`pending` request from the ticket
	 * screen itself (PLAN.md 7.38) - for when the designated approver never
	 * responds, the ticket shouldn't stay stuck waiting forever. Goes through
	 * the same Approval::decide() as a real decision from the emailed link,
	 * so it triggers the exact same notification + status transition.
	 */
	private function handle_cancel_approval(): void {
		if ( ! isset( $_GET['reject_approval'] ) ) {
			return;
		}

		$approval_id = (int) $_GET['reject_approval'];
		check_admin_referer( 'thickgrass_approval_reject_' . $approval_id );

		$approval = Approval::get( $approval_id );
		$ticket   = $approval ? Ticket::get( (int) $approval->ticket_id ) : null;

		if ( $ticket && $this->agent_can_access_ticket( $ticket ) ) {
			Approval::decide( $approval_id, 'rejected' );
			wp_safe_redirect( admin_url( 'admin.php?page=thickgrass&view=' . $approval->ticket_id ) );
			exit;
		}

		wp_safe_redirect( remove_query_arg( [ 'reject_approval', '_wpnonce' ] ) );
		exit;
	}

	private function is_on_hold_status( ?int $status_id ): bool {
		if ( ! $status_id ) {
			return false;
		}

		$status = Choices::get( $status_id );

		return $status && ! empty( $status->meta['is_on_hold_state'] );
	}

	private function is_closed_status( ?int $status_id ): bool {
		if ( ! $status_id ) {
			return false;
		}

		$status = Choices::get( $status_id );

		return $status && ! empty( $status->meta['is_closed_state'] );
	}

	/**
	 * Whether the Close reason/notes fields apply to this status - PLAN.md
	 * 7.42: reuses the two EXISTING Status checkboxes ("Treat ticket as
	 * resolved" / "Treat ticket as closed") instead of a dedicated new flag,
	 * so both Resolved and Closed statuses ask for a reason, not just Closed.
	 */
	private function requires_close_fields( ?int $status_id ): bool {
		if ( ! $status_id ) {
			return false;
		}

		$status = Choices::get( $status_id );

		return $status && ( ! empty( $status->meta['is_resolved_state'] ) || ! empty( $status->meta['is_closed_state'] ) );
	}

	private function nullable_int( $value ): ?int {
		return ( '' === $value || null === $value ) ? null : (int) $value;
	}

	private function render_choice_select( string $field, string $list_key, ?int $selected = null, ?string $id = null ): void {
		$options = [];

		foreach ( Choices::get_list( $list_key ) as $choice ) {
			$options[ $choice->id ] = $choice->label;
		}

		$this->render_select( $field, $options, $selected, $id );
	}

	/**
	 * No blank "—" placeholder option (PLAN.md: a ticket's own field should
	 * only ever offer real values to pick from, not a decorative non-choice).
	 * `on_hold_reason_id` is the one field this actually matters for in
	 * practice - it is commonly NULL, and a plain <select> with nothing
	 * selected just visually defaults to its first real option, which would
	 * then get silently saved as the value on the next unrelated save. See
	 * handle_save(), which forces it back to NULL whenever the ticket isn't
	 * actually in an on-hold status, regardless of what this select submits.
	 */
	private function render_select( string $field, array $options, ?int $selected = null, ?string $id = null ): void {
		printf( '<select name="%1$s"%2$s>', esc_attr( $field ), $id ? ' id="' . esc_attr( $id ) . '"' : '' );

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$value,
				selected( $selected, (int) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Renders as a real <input readonly>, not plain text, so every row in the
	 * two-column ticket form (Number, Organization, Created) has the same
	 * visual weight as the actually-editable rows next to it (PLAN.md:
	 * "sa fie toate la fel").
	 */
	private function render_readonly_row( string $label, string $value ): void {
		printf(
			'<div class="thickgrass-form-row"><label>%1$s</label><input type="text" class="thickgrass-form-value" value="%2$s" readonly /></div>',
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	private function render_choice_select_row( string $label, string $field, string $list_key, ?int $selected, ?string $row_id = null, ?string $select_id = null ): void {
		printf( '<div class="thickgrass-form-row"%s>', $row_id ? ' id="' . esc_attr( $row_id ) . '"' : '' );
		echo '<label>' . esc_html( $label ) . '</label><span>';
		$this->render_choice_select( $field, $list_key, $selected, $select_id );
		echo '</span></div>';
	}

	/**
	 * Close reason + Close notes - own panel below Title/Description
	 * (PLAN.md 7.40), shown only while the chosen status is a "Closed" one
	 * (meta.is_closed_state, PLAN.md 7.38) - same on/off pattern as the On
	 * hold reason row (see render_state_dependent_rows_script()). Heading +
	 * card share one wrapper id so both toggle together as a unit. Both
	 * fields are mandatory before a ticket can actually be closed (PLAN.md
	 * 7.41) - `required` is toggled by the same script, not hardcoded here,
	 * since a hidden-but-required field blocks the whole form from
	 * submitting in most browsers; the real enforcement is server-side, in
	 * handle_save().
	 */
	private function render_close_fields( object $ticket ): void {
		echo '<div id="thickgrass-close-fields-row">';
		echo '<h3>' . esc_html__( 'Close reason', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';

		$this->render_choice_select_row(
			__( 'Close reason', 'thickgrass' ),
			'close_reason_id',
			'call_close_reason',
			$ticket->close_reason_id ? (int) $ticket->close_reason_id : null,
			null,
			'thickgrass-close-reason-select'
		);

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Close notes', 'thickgrass' ) . '</label>';
		printf(
			'<span><textarea name="close_notes" id="thickgrass-close-notes" class="large-text" rows="3">%s</textarea></span></div>',
			esc_textarea( $ticket->close_notes ?? '' )
		);

		echo '</div>'; // .thickgrass-card
		echo '</div>'; // #thickgrass-close-fields-row
	}

	/**
	 * Categories can be nested arbitrarily deep (parent_id chains within the
	 * generic Choices engine, see PLAN.md 3.1) - so instead of one flat
	 * dropdown, this renders one <select> per depth level, each one revealing
	 * the next once a value with children is chosen ("secvential fiecare
	 * categorie in functie de care este selectata"), up to MAX_LEVELS - a
	 * category nested deeper than that simply stops offering further
	 * sub-levels in the UI (PLAN.md: "maxim de 4 sub levele posibile"). Only
	 * the deepest chosen id is ever submitted, via the hidden `category_id`
	 * input; the visible <select> elements themselves are never submitted
	 * (no `name`). Each level's own blank "—" option is kept (unlike the
	 * plain choice selects - see render_select()) because it is the only way
	 * to back out of a deeper selection back to a shorter category path.
	 */
	private function render_category_cascade_row( ?int $selected_category_id ): void {
		$categories = array_map(
			static fn( $c ) => [ 'id' => (int) $c->id, 'label' => $c->label, 'parent_id' => $c->parent_id ? (int) $c->parent_id : null ],
			Choices::get_list( 'category' )
		);

		echo '<div class="thickgrass-form-row">';
		echo '<label>' . esc_html__( 'Category', 'thickgrass' ) . '</label>';
		echo '<span>';
		echo '<input type="hidden" name="category_id" id="thickgrass-category-id" value="' . esc_attr( (string) $selected_category_id ) . '" />';
		echo '<span id="thickgrass-category-levels"></span>';
		echo '</span>';
		echo '</div>';
		?>
		<script>
		( function () {
			var MAX_LEVELS   = 4;
			var categories   = <?php echo wp_json_encode( $categories ); ?>;
			var selectedId   = <?php echo (int) $selected_category_id; ?>;
			var hiddenInput  = document.getElementById( 'thickgrass-category-id' );
			var levels       = document.getElementById( 'thickgrass-category-levels' );

			function children( parentId ) {
				return categories.filter( function ( c ) { return c.parent_id === parentId; } );
			}

			function ancestorPath( id ) {
				var path = [];
				while ( id ) {
					var found = categories.filter( function ( c ) { return c.id === id; } )[0];
					if ( ! found ) { break; }
					path.unshift( found.id );
					id = found.parent_id;
				}
				return path;
			}

			function addLevel( parentId, levelNumber ) {
				if ( levelNumber > MAX_LEVELS ) { return null; }

				var options = children( parentId );
				if ( ! options.length ) { return null; }

				var select = document.createElement( 'select' );
				select.appendChild( new Option( '—', '' ) );
				options.forEach( function ( c ) { select.appendChild( new Option( c.label, c.id ) ); } );

				select.addEventListener( 'change', function () {
					var idx = Array.prototype.indexOf.call( levels.children, select );
					while ( levels.children.length > idx + 1 ) { levels.removeChild( levels.lastChild ); }

					var value = select.value ? parseInt( select.value, 10 ) : null;
					hiddenInput.value = value || parentId || '';
					if ( value ) { addLevel( value, levelNumber + 1 ); }
				} );

				levels.appendChild( select );
				return select;
			}

			var parentId    = null;
			var levelNumber = 1;

			ancestorPath( selectedId ).forEach( function ( id ) {
				var select = addLevel( parentId, levelNumber );
				if ( select ) { select.value = id; }
				parentId = id;
				levelNumber++;
			} );

			addLevel( parentId, levelNumber );
		} )();
		</script>
		<?php
	}

	/**
	 * The caller's organization is derived from their end-user profile
	 * (wp_thickgrass_users.organization_id), not stored on the ticket itself -
	 * shown read-only on the ticket form (see PLAN.md: "Company (caller's company)",
	 * later renamed to Organization).
	 */
	private function caller_organization_name( int $requester_wp_user_id ): string {
		$organization_id = $this->requester_organization_id( $requester_wp_user_id );

		if ( ! $organization_id ) {
			return '—';
		}

		return Admin_Helpers::organization_options()[ $organization_id ] ?? '—';
	}

	/**
	 * Location defaults to the caller's own organization (PLAN.md 7.14: the
	 * `location` field, mandatory on every Organization), but can be
	 * overridden per-ticket via `location_organization_id` (PLAN.md: "Location
	 * ar trebui sa fie search and select" - a caller reporting from a
	 * different site than their usual one). Both resolve to the same kind of
	 * value - an organization id - which is why Location is edited by picking
	 * an organization's location, not free text.
	 */
	private function effective_location_organization_id( object $ticket ): ?int {
		return Ticket::effective_location_organization_id( $ticket );
	}

	/**
	 * The same "both assignment group AND location" scoping already enforced
	 * on the ticket list (Ticket::query()) - now also checked here, against
	 * one already-loaded ticket, before allowing any view/edit/comment/
	 * attachment/email/approval action by id (PLAN.md: closes a gap where
	 * direct access - e.g. `?view=<id>` - only checked the generic
	 * `thickgrass_agent` capability, not this specific ticket's scope).
	 */
	private function agent_can_access_ticket( object $ticket ): bool {
		$agent_id = Admin_Helpers::current_agent_id();

		return $agent_id && Ticket::agent_can_view( $agent_id, $ticket );
	}

	private function requester_organization_id( int $requester_wp_user_id ): ?int {
		global $wpdb;

		$users_table     = $wpdb->prefix . 'thickgrass_users';
		$organization_id = $wpdb->get_var( $wpdb->prepare( "SELECT organization_id FROM {$users_table} WHERE wp_user_id = %d", $requester_wp_user_id ) );

		return $organization_id ? (int) $organization_id : null;
	}

	/**
	 * Shows/hides the "On hold reason" row based on the selected State, using
	 * the `is_on_hold_state` meta flag on the status choice (configurable, not
	 * a hardcoded "On Hold" string match - see Activator::maybe_flag_default_on_hold_status()).
	 */
	/**
	 * Shows/hides whichever rows only make sense for certain statuses -
	 * On hold reason (meta.is_on_hold_state) and Close reason/notes
	 * (meta.is_closed_state, PLAN.md 7.38) - generic on a (row id, meta key)
	 * pair so a third one later needs zero new JS.
	 */
	private function render_state_dependent_rows_script(): void {
		// Close fields respond to EITHER checkbox (PLAN.md 7.42 - "Resolved"
		// and "Closed" both need a reason, not just "Closed"), so this row
		// lists two meta keys instead of one; a status matching any of them
		// counts.
		$rows = [
			'thickgrass-on-hold-row'      => [ 'is_on_hold_state' ],
			'thickgrass-close-fields-row' => [ 'is_resolved_state', 'is_closed_state' ],
		];

		$ids_by_row = [];

		foreach ( $rows as $row_id => $meta_keys ) {
			$ids_by_row[ $row_id ] = [];
		}

		foreach ( Choices::get_list( 'status', false ) as $status ) {
			foreach ( $rows as $row_id => $meta_keys ) {
				foreach ( $meta_keys as $meta_key ) {
					if ( ! empty( $status->meta[ $meta_key ] ) ) {
						$ids_by_row[ $row_id ][] = (int) $status->id;
						break;
					}
				}
			}
		}
		?>
		<script>
		( function () {
			var stateSelect = document.getElementById( 'thickgrass-state-select' );

			if ( ! stateSelect ) {
				return;
			}

			var idsByRow = <?php echo wp_json_encode( $ids_by_row ); ?>;

			function toggle() {
				var current = parseInt( stateSelect.value, 10 );

				Object.keys( idsByRow ).forEach( function ( rowId ) {
					var row = document.getElementById( rowId );

					if ( row ) {
						row.style.display = idsByRow[ rowId ].indexOf( current ) !== -1 ? '' : 'none';
					}
				} );

				// Close reason/notes are mandatory only while their row is
				// visible (PLAN.md 7.41) - a hidden `required` field blocks
				// the whole form from submitting in most browsers, so this
				// can't just be a static HTML attribute. The real
				// enforcement is server-side, in handle_save().
				var closeFieldsVisible = !! idsByRow[ 'thickgrass-close-fields-row' ] &&
					idsByRow[ 'thickgrass-close-fields-row' ].indexOf( current ) !== -1;

				[ 'thickgrass-close-reason-select', 'thickgrass-close-notes' ].forEach( function ( id ) {
					var field = document.getElementById( id );

					if ( field ) {
						field.required = closeFieldsVisible;
					}
				} );
			}

			stateSelect.addEventListener( 'change', toggle );
			toggle();
		} )();
		</script>
		<?php
	}

	/**
	 * Big colored counters for unassigned, still-open tickets per ticket type
	 * (e.g. "Unassigned Incident" / "Unassigned Request") - a dashboard widget
	 * row. Each box links straight to the filtered list.
	 * $agent_id scopes the counts the same way the list below it is scoped -
	 * see render_list().
	 */
	private function render_stats( ?int $agent_id ): void {
		$types  = Choices::get_list( 'ticket_type' );
		$counts = Ticket::count_unassigned_by_type( $agent_id );

		if ( ! $types ) {
			return;
		}

		echo '<div class="thickgrass-stats">';

		foreach ( $types as $type ) {
			$count     = $counts[ (int) $type->id ] ?? 0;
			$box_class = 'thickgrass-stat-box' . ( 0 === $count ? ' is-zero' : '' );
			$url       = admin_url( 'admin.php?page=thickgrass&ticket_type_id=' . $type->id . '&assigned_agent_id=none' );

			printf(
				'<a class="%1$s" href="%2$s"><span class="thickgrass-stat-label">%3$s</span><span class="thickgrass-stat-number">%4$d</span></a>',
				esc_attr( $box_class ),
				esc_url( $url ),
				/* translators: %s: ticket type label, e.g. "Incident" */
				esc_html( sprintf( __( 'Unassigned %s', 'thickgrass' ), $type->label ) ),
				$count
			);
		}

		echo '</div>';
	}

	/**
	 * Every agent (managers included - PLAN.md, no capability-based bypass)
	 * only sees tickets matching BOTH their own assignment groups AND their
	 * own locations (Agents_Page). An agent with no Agents-table row at all
	 * has nothing to scope by and sees no tickets until one is created for
	 * them (PLAN.md: explicit choice, not a fail-open default).
	 */
	private function render_list(): void {
		$agent_id = Admin_Helpers::current_agent_id();

		$this->render_stats( $agent_id );

		if ( null === $agent_id ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your account is not set up as an Agent yet (no assignment groups or locations configured), so no tickets are shown. Ask a manager to add you under Configurable lists → Agents.', 'thickgrass' ) . '</p></div>';
			return;
		}

		$filters = $this->current_filters();
		$tickets = Ticket::query( $filters, $agent_id );

		echo '<h2>' . esc_html__( 'All tickets', 'thickgrass' ) . '</h2>';

		$this->render_filter_bar( $filters );
		$this->render_ticket_groups( $tickets );
	}

	/**
	 * Splits the (already filtered) tickets into one table per ticket type,
	 * with a section heading - keeps Requests, Incidents etc. visually apart
	 * instead of one long mixed list.
	 *
	 * @param array<int, object> $tickets
	 */
	private function render_ticket_groups( array $tickets ): void {
		if ( ! $tickets ) {
			echo '<p>' . esc_html__( 'No tickets match this view.', 'thickgrass' ) . '</p>';
			return;
		}

		$types  = $this->index_choices_by_id( 'ticket_type' );
		$groups = [];

		foreach ( $tickets as $ticket ) {
			$groups[ (int) $ticket->ticket_type_id ][] = $ticket;
		}

		foreach ( $groups as $type_id => $group_tickets ) {
			$label = $types[ $type_id ]->label ?? __( 'Other', 'thickgrass' );

			printf( '<h3 class="thickgrass-section-title">%1$s (%2$d)</h3>', esc_html( $label ), count( $group_tickets ) );
			$this->render_ticket_table( $group_tickets );
		}
	}

	/**
	 * @param array<int, object> $tickets
	 */
	private function render_ticket_table( array $tickets ): void {
		$statuses   = $this->index_choices_by_id( 'status' );
		$priorities = $this->index_choices_by_id( 'priority' );
		$agents     = Admin_Helpers::agent_options();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Number', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Priority', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Requester', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Assigned agent', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'SLA', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $tickets as $ticket ) {
			$requester = get_userdata( (int) $ticket->requester_wp_user_id );
			$view_url  = admin_url( 'admin.php?page=thickgrass&view=' . $ticket->id );

			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
				esc_url( $view_url ),
				esc_html( $ticket->ticket_number ),
				esc_html( $ticket->title ),
				esc_html( $statuses[ (int) $ticket->status_id ]->label ?? '—' ),
				esc_html( $priorities[ (int) $ticket->priority_id ]->label ?? '—' ),
				esc_html( $requester ? $requester->display_name : '—' ),
				esc_html( $agents[ (int) $ticket->assigned_agent_id ] ?? '—' ),
				$this->sla_cell_html( $ticket )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * One plain-language badge per ticket ("Breached"/"On Time"/"Met"/"N/A" -
	 * see Sla::overall_status()) instead of raw due-date timestamps, which
	 * were hard to scan at a glance across a whole list (PLAN.md: "ceva mai
	 * simplu, breached, on time, etc"). The full per-target breakdown with
	 * actual dates lives on the ticket's own SLA Stats panel instead.
	 */
	private function sla_cell_html( object $ticket ): string {
		$status = Sla::overall_status( $ticket );

		return sprintf( '<span class="%1$s">%2$s</span>', esc_attr( $status['class'] ), esc_html( $status['label'] ) );
	}

	/**
	 * @return array<string, int|string>
	 */
	private function current_filters(): array {
		$filters = [];

		foreach ( array_keys( self::FILTER_FIELDS ) as $field ) {
			$value = $this->get_filter( $field );

			if ( null !== $value ) {
				$filters[ $field ] = $value;
			}
		}

		if ( isset( $_GET['ticket_type_id'] ) && '' !== $_GET['ticket_type_id'] ) {
			$filters['ticket_type_id'] = (int) $_GET['ticket_type_id'];
		}

		if ( isset( $_GET['assigned_agent_id'] ) && '' !== $_GET['assigned_agent_id'] ) {
			$filters['assigned_agent_id'] = 'none' === $_GET['assigned_agent_id'] ? 'none' : (int) $_GET['assigned_agent_id'];
		}

		return $filters;
	}

	private function get_filter( string $field ): ?int {
		return isset( $_GET[ $field ] ) && '' !== $_GET[ $field ] ? (int) $_GET[ $field ] : null;
	}

	/**
	 * @param array<string, int|string> $filters
	 */
	private function render_filter_bar( array $filters ): void {
		echo '<form method="get" style="margin:8px 0;">';
		echo '<input type="hidden" name="page" value="thickgrass" />';

		if ( isset( $filters['ticket_type_id'] ) ) {
			printf( '<input type="hidden" name="ticket_type_id" value="%d" />', (int) $filters['ticket_type_id'] );
		}

		foreach ( self::FILTER_FIELDS as $field => $list_key ) {
			$this->render_filter_select( $field, $list_key, is_int( $filters[ $field ] ?? null ) ? $filters[ $field ] : null );
		}

		echo '<select name="assigned_agent_id">';
		echo '<option value="">' . esc_html__( 'Any agent', 'thickgrass' ) . '</option>';
		echo '<option value="none" ' . selected( $filters['assigned_agent_id'] ?? null, 'none', false ) . '>' . esc_html__( 'Unassigned', 'thickgrass' ) . '</option>';

		foreach ( Admin_Helpers::agent_options() as $agent_id => $label ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$agent_id,
				selected( $filters['assigned_agent_id'] ?? null, $agent_id, false ),
				esc_html( $label )
			);
		}

		echo '</select> ';

		submit_button( __( 'Filter', 'thickgrass' ), 'secondary', '', false );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=thickgrass' ) ) . '">' . esc_html__( 'Reset', 'thickgrass' ) . '</a>';
		echo '</form>';

		$this->render_views_bar( $filters );
	}

	private function render_filter_select( string $field, string $list_key, ?int $selected ): void {
		$list_label = Choices::get_registered_lists()[ $list_key ]['label'];

		printf( '<select name="%1$s" title="%2$s">', esc_attr( $field ), esc_attr( $list_label ) );
		printf( '<option value="">%s: —</option>', esc_html( $list_label ) );

		foreach ( Choices::get_list( $list_key ) as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$choice->id,
				selected( $selected, (int) $choice->id, false ),
				esc_html( $choice->label )
			);
		}

		echo '</select> ';
	}

	/**
	 * Lists the agent's saved views (+ shared ones) as quick links, and offers
	 * to save the filters currently applied to the list as a new named view.
	 *
	 * @param array<string, int|string> $filters
	 */
	private function render_views_bar( array $filters ): void {
		$views = View::for_agent( get_current_user_id() );

		if ( $views ) {
			echo '<p>' . esc_html__( 'Saved views:', 'thickgrass' ) . ' ';

			foreach ( $views as $view ) {
				$url        = admin_url( 'admin.php?page=thickgrass&' . http_build_query( $view->filters ) );
				$delete_url = wp_nonce_url(
					admin_url( 'admin.php?page=thickgrass&delete_view=' . $view->id ),
					'thickgrass_view_delete_' . $view->id
				);

				printf(
					'<span style="margin-right:12px;"><a href="%1$s">%2$s</a> (<a href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>)</span>',
					esc_url( $url ),
					esc_html( $view->name ),
					esc_url( $delete_url ),
					esc_js( __( 'Delete this view?', 'thickgrass' ) ),
					esc_html__( 'delete', 'thickgrass' )
				);
			}

			echo '</p>';
		}

		// Saved views only make sense for the simple choice-based filters, not
		// the ticket_type_id/assigned_agent_id=none combo used by stat boxes.
		$savable = array_intersect_key( $filters, self::FILTER_FIELDS );

		if ( ! $savable ) {
			return;
		}

		echo '<form method="post" style="margin-bottom:8px;">';
		wp_nonce_field( self::SAVE_VIEW_ACTION, self::SAVE_VIEW_ACTION . '_nonce' );

		foreach ( $savable as $field => $value ) {
			printf( '<input type="hidden" name="filters[%1$s]" value="%2$d" />', esc_attr( $field ), (int) $value );
		}

		echo '<input type="text" name="view_name" placeholder="' . esc_attr__( 'View name', 'thickgrass' ) . '" required />';

		if ( current_user_can( 'thickgrass_manage' ) ) {
			echo ' <label><input type="checkbox" name="shared" value="1" /> ' . esc_html__( 'Shared (visible to all agents)', 'thickgrass' ) . '</label>';
		}

		echo ' ';
		submit_button( __( 'Save as view', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Two-column layout (Number/Caller/Organization/Location/Asset/Category on
	 * the left, Created/State/On hold reason/Impact/Priority/Assignment
	 * group/Assigned to on the right), matching the requested ticket form.
	 * Watch list is intentionally not here yet - a planned
	 * future entity, not built yet (see PLAN.md).
	 */
	private function render_detail( int $ticket_id ): void {
		$ticket = Ticket::get( $ticket_id );

		// Same message either way (not found vs. out of scope) - an agent
		// outside this ticket's group/location must not be able to tell the
		// two apart, which would leak that the ticket exists at all.
		if ( ! $ticket || ! $this->agent_can_access_ticket( $ticket ) ) {
			echo '<p>' . esc_html__( 'Ticket not found.', 'thickgrass' ) . '</p>';
			return;
		}

		// The ticket's own fields are locked in two cases: an approval is
		// pending (PLAN.md 7.39), or the ticket is in a "Closed" status,
		// which is PERMANENT and never reopenable (PLAN.md 7.42 - "Resolved"
		// alone stays fully editable/reopenable, only "Closed" locks for
		// good; this is exactly what distinguishes the two checkboxes in
		// practice, not just their label). Comments/work notes stay editable
		// either way, so agents can still communicate. Enforced here
		// (fieldset + no Save button) AND server-side in handle_save(),
		// which is what actually protects the data - see there for why. The
		// "Request approval" button is already hidden automatically once a
		// request is pending (Approval::can_request() checks has_pending()
		// itself), so it needs no separate check here.
		$pending_approval   = Approval::has_pending( (int) $ticket->id );
		$permanently_closed = $this->is_closed_status( (int) $ticket->status_id );
		$locked             = $pending_approval || $permanently_closed;

		echo '<div class="thickgrass-detail-header">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=thickgrass' ) ) . '">&larr; ' . esc_html__( 'Back to list', 'thickgrass' ) . '</a>';
		echo '<div class="thickgrass-detail-actions">';
		printf( '<button type="button" class="button" data-modal-target="%s">%s</button> ', esc_attr( self::EMAIL_MODAL_ID ), esc_html__( 'Send email', 'thickgrass' ) );

		if ( Approval::can_request( $ticket ) ) {
			printf( '<button type="button" class="button" data-modal-target="%s">%s</button> ', esc_attr( self::APPROVAL_MODAL_ID ), esc_html__( 'Request approval', 'thickgrass' ) );
		}

		if ( ! $locked ) {
			submit_button( __( 'Save', 'thickgrass' ), 'primary', 'submit', false, [ 'form' => 'thickgrass-ticket-form' ] );
		}

		echo '</div>';
		echo '</div>';

		if ( $permanently_closed ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This ticket is Closed and permanently locked - it cannot be edited or reopened.', 'thickgrass' ) . '</p></div>';
		} elseif ( $pending_approval ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This ticket is locked while an approval request is pending - reject it (see Approvals below) if it needs to be edited before a decision comes in.', 'thickgrass' ) . '</p></div>';
		}

		echo '<form method="post" id="thickgrass-ticket-form">';
		wp_nonce_field( self::SAVE_ACTION, self::SAVE_ACTION . '_nonce' );
		echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket->id ) . '" />';
		printf( '<fieldset%s>', $locked ? ' disabled' : '' );

		// Both columns live inside one panel (PLAN.md section 8: "Panou
		// generic", the same .thickgrass-card design as Activity/SLA Stats).
		echo '<div class="thickgrass-card">';
		echo '<div class="thickgrass-form-grid">';

		echo '<div>';
		$this->render_readonly_row( __( 'Number', 'thickgrass' ), $ticket->ticket_number );
		Admin_Helpers::render_search_select_row( __( 'Caller', 'thickgrass' ), 'requester_wp_user_id', Admin_Helpers::wp_users_options(), (int) $ticket->requester_wp_user_id );
		$this->render_readonly_row( __( 'Organization', 'thickgrass' ), $this->caller_organization_name( (int) $ticket->requester_wp_user_id ) );
		Admin_Helpers::render_search_select_row( __( 'Location', 'thickgrass' ), 'location_organization_id', Admin_Helpers::organization_location_options(), $this->effective_location_organization_id( $ticket ) );
		$caller_assets = Admin_Helpers::asset_options_for_requester( (int) $ticket->requester_wp_user_id );
		Admin_Helpers::render_search_select_row( __( 'Asset', 'thickgrass' ), 'asset_id', $caller_assets, $ticket->asset_id ? (int) $ticket->asset_id : null );
		$this->render_category_cascade_row( $ticket->category_id ? (int) $ticket->category_id : null );
		echo '</div>';

		echo '<div>';
		$this->render_readonly_row( __( 'Created', 'thickgrass' ), $ticket->created_at );
		$this->render_choice_select_row( __( 'State', 'thickgrass' ), 'status_id', 'status', (int) $ticket->status_id, null, 'thickgrass-state-select' );
		$this->render_choice_select_row( __( 'On hold reason', 'thickgrass' ), 'on_hold_reason_id', 'on_hold_reason', $ticket->on_hold_reason_id ? (int) $ticket->on_hold_reason_id : null, 'thickgrass-on-hold-row' );
		$this->render_choice_select_row( __( 'Impact', 'thickgrass' ), 'impact_id', 'impact', $ticket->impact_id ? (int) $ticket->impact_id : null );
		$this->render_choice_select_row( __( 'Priority', 'thickgrass' ), 'priority_id', 'priority', $ticket->priority_id ? (int) $ticket->priority_id : null );
		Admin_Helpers::render_search_select_row( __( 'Assignment group', 'thickgrass' ), 'assignment_group_id', Admin_Helpers::choice_options( 'assignment_group' ), $ticket->assignment_group_id ? (int) $ticket->assignment_group_id : null );
		Admin_Helpers::render_search_select_row( __( 'Assigned to', 'thickgrass' ), 'assigned_agent_id', Admin_Helpers::agent_options(), $ticket->assigned_agent_id ? (int) $ticket->assigned_agent_id : null );
		echo '</div>';

		echo '</div>'; // .thickgrass-form-grid
		echo '</div>'; // .thickgrass-card

		// Title and Description get the same input-shaped treatment (PLAN.md:
		// "sa fie la fel") - Title is a real editable text input, Description
		// is a read-only multiline textarea (never editable), both inside
		// their own panel, positioned so Title sits directly above Description.
		echo '<div class="thickgrass-card">';

		echo '<div class="thickgrass-form-row">';
		echo '<label for="thickgrass-title">' . esc_html__( 'Title', 'thickgrass' ) . '</label>';
		echo '<input type="text" id="thickgrass-title" name="title" value="' . esc_attr( $ticket->title ) . '" class="large-text" />';
		echo '</div>';

		echo '<div class="thickgrass-form-row">';
		echo '<label for="thickgrass-description">' . esc_html__( 'Description', 'thickgrass' ) . '</label>';
		printf(
			'<textarea id="thickgrass-description" class="large-text thickgrass-readonly-field" rows="4" readonly>%s</textarea>',
			esc_textarea( wp_strip_all_tags( $ticket->description ) )
		);
		echo '</div>';

		echo '</div>'; // .thickgrass-card

		$this->render_close_fields( $ticket );

		echo '</fieldset>';

		echo '<h3>' . esc_html__( 'Comment', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';
		echo '<div class="thickgrass-comment-layout">';

		echo '<div class="thickgrass-comment-main">';
		echo '<textarea name="body" id="thickgrass-comment-body" class="large-text" rows="3"></textarea>';
		echo '<p><label><input type="checkbox" name="visible_to_requester" value="1" /> ' . esc_html__( 'Visible to requester (leave unchecked to keep this as an internal work note)', 'thickgrass' ) . '</label></p>';
		echo '</div>'; // .thickgrass-comment-main

		// Both pickers insert their (placeholder-substituted, HTML-stripped)
		// text straight into the Comment box above (PLAN.md 7.46: "cand
		// selectezi un kb sau canned response acesta trebuie sa se insereze
		// automat in sectiunea de comment") - kept in a right-hand sidebar
		// next to the textarea rather than stacked above it, and as search-selects
		// (Admin_Helpers::render_search_select_row()) rather than plain
		// dropdowns, same as every other long option list on this screen.
		echo '<div class="thickgrass-comment-sidebar">';
		$this->render_canned_response_picker( $ticket );
		$this->render_kb_picker( $ticket );
		echo '</div>'; // .thickgrass-comment-sidebar

		echo '</div>'; // .thickgrass-comment-layout
		echo '</div>'; // .thickgrass-card

		echo '</form>';

		$this->render_state_dependent_rows_script();
		Admin_Helpers::render_search_select_script();
		$this->render_send_email_modal( $ticket );

		if ( Approval::can_request( $ticket ) ) {
			$this->render_request_approval_modal( $ticket );
		}

		Admin_Helpers::render_modal_script();
		$this->render_feed( $ticket_id );
		$this->render_attachment_upload_panel( $ticket );
		$this->render_approvals_panel( $ticket );
		$this->render_custom_field_values_panel( $ticket );
		$this->render_sla_stats_panel( $ticket );
	}

	/**
	 * Read-only display of a Custom Form submission's structured answers
	 * (PLAN.md 7.45, Faza 2) - the same answers are already folded into the
	 * ticket's own Description as plain text (see
	 * Shortcodes::handle_custom_form_submission()); this panel additionally
	 * shows them field-by-field, and turns a 'file' answer into a real
	 * download link instead of the raw attachment id. A ticket not created
	 * from a Custom Form has no rows here at all, so the panel is simply
	 * omitted - same pattern as render_approvals_panel().
	 */
	private function render_custom_field_values_panel( object $ticket ): void {
		$values = Custom_Form_Field_Value::for_ticket( (int) $ticket->id );

		if ( ! $values ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Custom fields', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';
		echo '<table class="widefat"><tbody>';

		foreach ( $values as $value ) {
			echo '<tr><th style="text-align:left;width:220px;">' . esc_html( $value->label ) . '</th><td>';

			if ( 'file' === $value->field_type && $value->value ) {
				$attachment = Attachment::get( (int) $value->value );

				if ( $attachment ) {
					printf( '<a href="%1$s" target="_blank">%2$s</a>', esc_url( Attachment::url( $attachment ) ), esc_html( $attachment->file_name ) );
				} else {
					echo '—';
				}
			} else {
				echo esc_html( $value->value );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>'; // .thickgrass-card
	}

	/**
	 * Predefined reply templates an agent can insert into the Comment box
	 * (PLAN.md 7.44/7.46), scoped to the ticket's own assignment group and
	 * location (Canned_Response::for_context()) - a template not applicable
	 * here never appears in the list, so there is nothing to validate
	 * server-side. Bodies are placeholder-substituted the same way as email
	 * templates (Email_Notifications::apply_placeholders()), then stripped of
	 * HTML tags since the Comment box is a plain textarea, not rich text.
	 */
	private function render_canned_response_picker( object $ticket ): void {
		$responses = Canned_Response::for_context(
			$ticket->assignment_group_id ? (int) $ticket->assignment_group_id : null,
			$this->effective_location_organization_id( $ticket )
		);

		if ( ! $responses ) {
			return;
		}

		$placeholders = Email_Notifications::ticket_placeholders( $ticket );
		$options      = [];
		$bodies       = [];

		foreach ( $responses as $response ) {
			$options[ $response->id ] = $response->title;
			$bodies[ $response->id ]  = wp_strip_all_tags( Email_Notifications::apply_placeholders( $response->body, $placeholders ) );
		}

		Admin_Helpers::render_search_select_row( __( 'Insert canned response', 'thickgrass' ), 'thickgrass_canned_response_picker', $options, null );

		printf( '<script>window.thickgrassCannedResponses = %s;</script>', wp_json_encode( $bodies ) );
		$this->render_comment_insert_script( 'thickgrass_canned_response_picker', 'thickgrassCannedResponses' );
	}

	/**
	 * Same insert-into-Comment mechanism as render_canned_response_picker()
	 * above, for Knowledge Base articles (PLAN.md 7.46: "adauga si
	 * Knwledgebase in ticket") - the full article body (plain text, HTML
	 * stripped) is inserted, per explicit clarification when this was scoped.
	 * Not scoped by group/location like canned responses - the whole KB is
	 * offered, same as the public KB search.
	 */
	private function render_kb_picker( object $ticket ): void {
		$articles = Kb_Article::search();

		if ( ! $articles ) {
			return;
		}

		$options = [];
		$bodies  = [];

		foreach ( $articles as $article ) {
			$options[ $article->id ] = $article->title;
			$bodies[ $article->id ]  = wp_strip_all_tags( $article->content );
		}

		Admin_Helpers::render_search_select_row( __( 'Insert KB article', 'thickgrass' ), 'thickgrass_kb_picker', $options, null );

		printf( '<script>window.thickgrassKbArticles = %s;</script>', wp_json_encode( $bodies ) );
		$this->render_comment_insert_script( 'thickgrass_kb_picker', 'thickgrassKbArticles' );
	}

	/**
	 * Shared by both pickers above: listens for the search-select combobox's
	 * own `thickgrass:combobox-change` event (Admin_Helpers::render_search_select_script())
	 * and inserts the matching plain-text body at the Comment textarea's
	 * current cursor position, then clears the picker so the same item can be
	 * picked again. $field/$js_var are always fixed internal literals (never
	 * user input), so they're safe to embed directly into the inline script.
	 */
	private function render_comment_insert_script( string $field, string $js_var ): void {
		?>
		<script>
		( function () {
			var box  = document.getElementById( 'thickgrass-combobox-<?php echo $field; ?>' );
			var body = document.getElementById( 'thickgrass-comment-body' );

			if ( ! box || ! body ) {
				return;
			}

			box.addEventListener( 'thickgrass:combobox-change', function ( e ) {
				var text = window.<?php echo $js_var; ?>[ e.detail.id ];

				if ( undefined === text ) {
					return;
				}

				var pos = body.selectionStart || 0;
				body.value = body.value.slice( 0, pos ) + text + body.value.slice( pos );
				body.focus();

				box.querySelector( '.thickgrass-search-select' ).value = '';
				box.querySelector( 'input[type="hidden"]' ).value = '';
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * A free-form email an agent can send straight from the ticket - see
	 * handle_send_email(). Opened from the "Send email" button in the detail
	 * screen's top-right header (see render_detail()); hidden by default,
	 * toggled by Admin_Helpers::render_modal_script().
	 */
	private function render_send_email_modal( object $ticket ): void {
		$requester = get_userdata( (int) $ticket->requester_wp_user_id );

		echo '<div id="' . esc_attr( self::EMAIL_MODAL_ID ) . '" class="thickgrass-modal">';
		echo '<div class="thickgrass-modal-content">';
		echo '<button type="button" class="thickgrass-modal-close" aria-label="' . esc_attr__( 'Close', 'thickgrass' ) . '">&times;</button>';
		echo '<h3>' . esc_html__( 'Send email', 'thickgrass' ) . '</h3>';

		echo '<form method="post">';
		wp_nonce_field( self::SEND_EMAIL_ACTION, self::SEND_EMAIL_ACTION . '_nonce' );
		echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket->id ) . '" />';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'To', 'thickgrass' ) . '</label>';
		printf( '<input type="email" name="to" class="regular-text" value="%s" required /></div>', esc_attr( $requester ? $requester->user_email : '' ) );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Subject', 'thickgrass' ) . '</label>';
		echo '<input type="text" name="subject" class="large-text" required /></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Message', 'thickgrass' ) . '</label>';
		echo '<textarea name="body" class="large-text" rows="4" required></textarea></div>';

		submit_button( __( 'Send email', 'thickgrass' ), 'primary', '', false );
		echo '</form>';

		echo '</div>'; // .thickgrass-modal-content
		echo '</div>'; // .thickgrass-modal
	}

	/**
	 * Ask a specific WP user to Approve/Reject the ticket - see
	 * handle_request_approval() and ThickGrass\Approval. Only rendered at all
	 * when Approval::can_request() says the button is shown (see
	 * render_detail()'s header) - both triggers (ticket type + status, PLAN.md
	 * 7.36) are checked there already, no need to repeat them here.
	 */
	private function render_request_approval_modal( object $ticket ): void {
		echo '<div id="' . esc_attr( self::APPROVAL_MODAL_ID ) . '" class="thickgrass-modal">';
		echo '<div class="thickgrass-modal-content">';
		echo '<button type="button" class="thickgrass-modal-close" aria-label="' . esc_attr__( 'Close', 'thickgrass' ) . '">&times;</button>';
		echo '<h3>' . esc_html__( 'Request approval', 'thickgrass' ) . '</h3>';

		echo '<form method="post">';
		wp_nonce_field( self::REQUEST_APPROVAL_ACTION, self::REQUEST_APPROVAL_ACTION . '_nonce' );
		echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket->id ) . '" />';
		Admin_Helpers::render_search_select_row( __( 'Approver', 'thickgrass' ), 'approver_wp_user_id', Admin_Helpers::wp_users_options(), null );
		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Note (optional)', 'thickgrass' ) . '</label>';
		echo '<span><textarea name="approval_comment" class="large-text" rows="2"></textarea></span></div>';
		submit_button( __( 'Request approval', 'thickgrass' ), 'primary', '', false );
		echo '</form>';

		echo '</div>'; // .thickgrass-modal-content
		echo '</div>'; // .thickgrass-modal
	}

	/**
	 * Ticket-level attachments (PLAN.md: comments already work well, left
	 * untouched - this is a separate feature). Past attachments are shown in
	 * the Activity feed below (see build_feed()), not listed here - this
	 * panel is only the upload control, drag-and-drop or the usual file
	 * picker (own nonce, `enctype="multipart/form-data"`, since the big
	 * ticket-fields form above has no reason to be multipart otherwise).
	 */
	private function render_attachment_upload_panel( object $ticket ): void {
		echo '<h3>' . esc_html__( 'Attachments', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';

		echo '<form method="post" enctype="multipart/form-data" class="thickgrass-attachment-form">';
		wp_nonce_field( self::UPLOAD_ACTION, self::UPLOAD_ACTION . '_nonce' );
		echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket->id ) . '" />';
		echo '<div id="thickgrass-dropzone" class="thickgrass-dropzone">';
		echo '<input type="file" name="attachment" id="thickgrass-attachment-input" required />';
		echo '<span>' . esc_html__( 'or drag & drop a file here', 'thickgrass' ) . '</span>';
		echo '</div>';
		submit_button( __( 'Upload', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';

		echo '</div>';

		$this->render_attachment_dropzone_script();
	}

	/**
	 * Dropping a file auto-submits the form - the click-to-browse fallback
	 * (the native file input inside the dropzone) still works with no JS at all.
	 */
	private function render_attachment_dropzone_script(): void {
		?>
		<script>
		( function () {
			var dropzone = document.getElementById( 'thickgrass-dropzone' );
			var input    = document.getElementById( 'thickgrass-attachment-input' );

			if ( ! dropzone || ! input ) {
				return;
			}

			[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
				dropzone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					dropzone.classList.add( 'is-dragover' );
				} );
			} );

			[ 'dragleave', 'drop' ].forEach( function ( evt ) {
				dropzone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					dropzone.classList.remove( 'is-dragover' );
				} );
			} );

			dropzone.addEventListener( 'drop', function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.files.length ) {
					input.files = e.dataTransfer.files;
					input.form.submit();
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Existing approval requests for the ticket (approver, status badge,
	 * requested/decided timestamps) + a small form to request a new one - the
	 * actual decision happens off-site, from the emailed link (see
	 * ThickGrass\Approval, Shortcodes::render_approval()), never here.
	 */
	/**
	 * Read-only history of past/current approval requests - requesting a NEW
	 * one happens from the "Request approval" button + popup in the header
	 * (see render_request_approval_modal()), not here. Skipped entirely if
	 * this ticket has no approval history at all - most ticket types never
	 * will, since the button is only ever offered when Approval::can_request()
	 * allows it (PLAN.md 7.36).
	 */
	private function render_approvals_panel( object $ticket ): void {
		$approvals = Approval::for_ticket( (int) $ticket->id );

		if ( ! $approvals ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Approvals', 'thickgrass' ) . '</h3>';
		echo '<div class="thickgrass-card">';

		$badge_classes = [
			'pending'  => 'thickgrass-badge-blue',
			'approved' => 'thickgrass-badge-green',
			'rejected' => 'thickgrass-badge-red',
		];

		echo '<ul class="thickgrass-approval-list">';

		foreach ( $approvals as $approval ) {
			$approver = get_userdata( (int) $approval->approver_wp_user_id );

			$reject_link = '';

			if ( 'pending' === $approval->status ) {
				$reject_url = wp_nonce_url(
					admin_url( 'admin.php?page=thickgrass&view=' . $ticket->id . '&reject_approval=' . $approval->id ),
					'thickgrass_approval_reject_' . $approval->id
				);

				$reject_link = sprintf(
					' — <a href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url( $reject_url ),
					esc_js( __( 'Reject this pending approval? Use this if the approver never responds.', 'thickgrass' ) ),
					esc_html__( 'Reject (no response)', 'thickgrass' )
				);
			}

			printf(
				'<li><strong>%1$s</strong> <span class="thickgrass-badge %2$s">%3$s</span> <span class="thickgrass-feed-meta">%4$s%5$s</span>%6$s</li>',
				esc_html( $approver ? $approver->display_name : '—' ),
				esc_attr( $badge_classes[ $approval->status ] ?? 'thickgrass-badge-blue' ),
				esc_html( ucfirst( $approval->status ) ),
				esc_html( $approval->requested_at ),
				$approval->decided_at ? ' → ' . esc_html( $approval->decided_at ) : '',
				$reject_link
			);
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Comments (public/work note) and field changes rendered as a single
	 * chronological feed, same visual structure for every entry (avatar, name,
	 * entry type + timestamp, content) - interleaving both instead of showing
	 * two separate lists.
	 */
	private function render_feed( int $ticket_id ): void {
		echo '<h3>' . esc_html__( 'Activity', 'thickgrass' ) . '</h3>';

		$feed = $this->build_feed( $ticket_id );

		if ( ! $feed ) {
			echo '<p>' . esc_html__( 'No activity yet.', 'thickgrass' ) . '</p>';
			return;
		}

		// .thickgrass-card is the canonical panel design (PLAN.md section 8);
		// .thickgrass-feed-panel only overrides its padding for feed items.
		echo '<div class="thickgrass-card thickgrass-feed-panel">';

		foreach ( $feed as $item ) {
			$this->render_feed_item( $item );
		}

		echo '</div>';
	}

	/**
	 * @return array<int, array<string, mixed>> feed items sorted newest first
	 */
	private function build_feed( int $ticket_id ): array {
		$feed = [];

		foreach ( Comment::for_ticket( $ticket_id ) as $comment ) {
			$feed[] = [
				'timestamp' => $comment->created_at,
				'actor_id'  => (int) $comment->author_wp_user_id,
				'type'      => $comment->is_work_note ? 'work_note' : 'comment',
				'body'      => $comment->body,
			];
		}

		foreach ( Attachment::for_ticket( $ticket_id ) as $attachment ) {
			$feed[] = [
				'timestamp'  => $attachment->created_at,
				'actor_id'   => (int) $attachment->uploaded_by_wp_user_id,
				'type'       => 'attachment',
				'attachment' => $attachment,
			];
		}

		// Group field changes saved in the same submission (same actor + same
		// timestamp - see Dashboard_Page::handle_save()) into one feed entry.
		// A sent email (field_changed = 'email_sent') is its own feed entry
		// instead, never grouped with real field changes - see
		// Email_Notifications::dispatch().
		$groups = [];

		foreach ( Activity_Log::for_ticket( $ticket_id ) as $entry ) {
			if ( 'email_sent' === $entry->field_changed ) {
				$feed[] = [
					'timestamp' => $entry->created_at,
					'actor_id'  => (int) $entry->actor_wp_user_id,
					'type'      => 'email',
					'email'     => json_decode( $entry->new_value, true ) ?: [],
				];
				continue;
			}

			$key = $entry->actor_wp_user_id . '|' . $entry->created_at;

			$groups[ $key ]['timestamp'] = $entry->created_at;
			$groups[ $key ]['actor_id']  = (int) $entry->actor_wp_user_id;
			$groups[ $key ]['changes'][] = $entry;
		}

		foreach ( $groups as $group ) {
			$feed[] = [
				'timestamp' => $group['timestamp'],
				'actor_id'  => $group['actor_id'],
				'type'      => 'field_changes',
				'changes'   => $group['changes'],
			];
		}

		usort( $feed, static function ( $a, $b ) {
			return strcmp( $b['timestamp'], $a['timestamp'] );
		} );

		return $feed;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_feed_item( array $item ): void {
		$actor = get_userdata( $item['actor_id'] );
		$name  = $actor ? $actor->display_name : '—';

		$type_labels = [
			'comment'       => __( 'Comment', 'thickgrass' ),
			'work_note'     => __( 'Work note', 'thickgrass' ),
			'field_changes' => __( 'Field changes', 'thickgrass' ),
			'email'         => __( 'Email', 'thickgrass' ),
			'attachment'    => __( 'Attachment', 'thickgrass' ),
		];

		echo '<div class="thickgrass-feed-item">';

		printf( '<div class="thickgrass-avatar">%s</div>', esc_html( $this->initials( $name ) ) );

		echo '<div class="thickgrass-feed-body">';
		printf(
			'<div class="thickgrass-feed-header"><strong>%1$s</strong><span class="thickgrass-feed-meta">%2$s • %3$s</span></div>',
			esc_html( $name ),
			esc_html( $type_labels[ $item['type'] ] ),
			esc_html( $item['timestamp'] )
		);

		if ( 'field_changes' === $item['type'] ) {
			echo '<div class="thickgrass-note thickgrass-note-fieldchange">';

			foreach ( $item['changes'] as $change ) {
				$label = self::FIELD_LABELS[ $change->field_changed ] ?? $change->field_changed;

				printf(
					'<div class="thickgrass-field-change">%1$s: %2$s <span class="thickgrass-feed-meta">was %3$s</span></div>',
					esc_html( $label ),
					esc_html( $this->format_field_value( $change->field_changed, $change->new_value ) ),
					esc_html( $this->format_field_value( $change->field_changed, $change->old_value ) )
				);
			}

			echo '</div>';
		} elseif ( 'email' === $item['type'] ) {
			$email = $item['email'];
			$to    = implode( ', ', (array) ( $email['to'] ?? [] ) );

			echo '<div class="thickgrass-note thickgrass-note-email">';
			printf(
				'<p><strong>%1$s</strong> %2$s<br><strong>%3$s</strong> %4$s</p>',
				esc_html__( 'To:', 'thickgrass' ),
				esc_html( $to ),
				esc_html__( 'Subject:', 'thickgrass' ),
				esc_html( $email['subject'] ?? '' )
			);
			echo '<details><summary>' . esc_html__( 'Read more', 'thickgrass' ) . '</summary>';
			// Email bodies are plain text (see Email_Notifications defaults) - esc_html()
			// already makes this fully HTML-safe, wpautop() only adds <p>/<br> around it,
			// so no further wp_kses_post() pass is needed (it was previously applied here
			// redundantly, after the content was already escaped).
			echo wpautop( esc_html( $email['body'] ?? '' ) );
			echo '</details>';
			echo '</div>';
		} elseif ( 'attachment' === $item['type'] ) {
			$attachment = $item['attachment'];
			$url        = Attachment::url( $attachment );
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=thickgrass&view=' . $attachment->ticket_id . '&delete_attachment=' . $attachment->id ),
				'thickgrass_attachment_delete_' . $attachment->id
			);

			echo '<div class="thickgrass-note thickgrass-note-attachment">';

			if ( Attachment::is_image( $attachment->file_name ) ) {
				printf(
					'<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="%2$s" class="thickgrass-attachment-image" /></a>',
					esc_url( $url ),
					esc_attr( $attachment->file_name )
				);
			} else {
				printf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( $attachment->file_name ) );
			}

			printf(
				' — <a href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this attachment?', 'thickgrass' ) ),
				esc_html__( 'Delete', 'thickgrass' )
			);

			echo '</div>';
		} else {
			$note_class = 'work_note' === $item['type'] ? 'thickgrass-note thickgrass-note-worknote' : 'thickgrass-note thickgrass-note-comment';

			printf( '<div class="%1$s">%2$s</div>', esc_attr( $note_class ), wp_kses_post( wpautop( $item['body'] ) ) );
		}

		echo '</div></div>';
	}

	/**
	 * Resolves a stored old/new value to something human-readable: choice ids
	 * become their label, assigned_agent_id becomes the agent's display name,
	 * everything else (title, description, the ticket number on "created") is
	 * shown as-is.
	 */
	private function format_field_value( string $field, ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		if ( in_array( $field, self::CHOICE_FIELDS, true ) ) {
			$choice = Choices::get( (int) $value );

			return $choice ? $choice->label : $value;
		}

		if ( 'assigned_agent_id' === $field ) {
			$agent_name = Admin_Helpers::agent_options()[ (int) $value ] ?? null;

			return $agent_name ?? $value;
		}

		if ( 'asset_id' === $field ) {
			$asset_name = Admin_Helpers::asset_options()[ (int) $value ] ?? null;

			return $asset_name ?? $value;
		}

		if ( 'requester_wp_user_id' === $field ) {
			$user = get_userdata( (int) $value );

			return $user ? $user->display_name : $value;
		}

		if ( 'location_organization_id' === $field ) {
			$location_name = Admin_Helpers::organization_location_options()[ (int) $value ] ?? null;

			return $location_name ?? $value;
		}

		return $value;
	}

	/**
	 * First letter of the first word + first letter of the last word (e.g.
	 * "Dinulescu Cosmin Ovidiu" -> "DO") for the round avatar initials.
	 */
	private function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$parts = array_filter( $parts );

		if ( ! $parts ) {
			return '?';
		}

		if ( 1 === count( $parts ) ) {
			return strtoupper( substr( $parts[0], 0, 2 ) );
		}

		return strtoupper( substr( reset( $parts ), 0, 1 ) . substr( end( $parts ), 0, 1 ) );
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

	/**
	 * SLA Stats panel for the bottom of the ticket screen (PLAN.md: "pune la
	 * baza ticketului un panou cu Stats SLA"): one row per target (Assignment,
	 * First Response, First Update, Resolution) with a plain-language status
	 * plus the actual due/completed timestamps for reference, followed by a
	 * history of every time this ticket's SLA targets changed (recalculated,
	 * extended by an On Hold period, or auto-escalated - see Sla::calculate_due_dates()/
	 * due_dates_after_hold()/run_escalations(), all of which log a 'sla_change'
	 * or 'sla_escalation' activity entry for exactly this purpose).
	 */
	private function render_sla_stats_panel( object $ticket ): void {
		if ( ! $ticket->sla_id ) {
			return;
		}

		echo '<h3>' . esc_html__( 'SLA Stats', 'thickgrass' ) . '</h3>';

		// Same panel design as Activity/the ticket form grid (PLAN.md section 8).
		echo '<div class="thickgrass-card">';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Target', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Due', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Completed', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Sla::TARGETS as $key => $target ) {
			$status = Sla::target_status( $ticket, $key );

			printf(
				'<tr><td>%1$s</td><td><span class="%2$s">%3$s</span></td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( $target['label'] ),
				esc_attr( $status['class'] ),
				esc_html( $status['label'] ),
				esc_html( $ticket->{$target['due']} ?: '—' ),
				esc_html( $ticket->{$target['completed']} ?: '—' )
			);
		}

		echo '</tbody></table>';

		$this->render_sla_history( (int) $ticket->id );

		echo '</div>'; // .thickgrass-card
	}

	/**
	 * @param int $ticket_id
	 */
	private function render_sla_history( int $ticket_id ): void {
		$entries = array_values( array_filter(
			Activity_Log::for_ticket( $ticket_id ),
			static fn( $entry ) => in_array( $entry->field_changed, [ 'sla_change', 'sla_escalation' ], true )
		) );

		echo '<h4>' . esc_html__( 'SLA History', 'thickgrass' ) . '</h4>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No SLA changes recorded yet.', 'thickgrass' ) . '</p>';
			return;
		}

		echo '<ul class="thickgrass-sla-history">';

		foreach ( array_reverse( $entries ) as $entry ) {
			printf(
				'<li><span class="thickgrass-feed-meta">%1$s</span> — %2$s</li>',
				esc_html( $entry->created_at ),
				esc_html( $entry->new_value )
			);
		}

		echo '</ul>';
	}
}
