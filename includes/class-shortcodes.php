<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * End-user facing portal: `[thickgrass_new_ticket]` and `[thickgrass_my_tickets]`,
 * placed on the two pages auto-created at activation (see Activator). See PLAN.md
 * 7.4 - this is the "Faza 1 Portal End-user" item.
 */
class Shortcodes {

	private const NEW_TICKET_ACTION   = 'thickgrass_portal_new_ticket';
	private const REPLY_ACTION        = 'thickgrass_portal_reply';
	private const CUSTOM_FORM_ACTION  = 'thickgrass_portal_custom_form';

	public function __construct() {
		add_shortcode( 'thickgrass_new_ticket', [ $this, 'render_new_ticket' ] );
		add_shortcode( 'thickgrass_my_tickets', [ $this, 'render_my_tickets' ] );
		add_shortcode( 'thickgrass_approval', [ $this, 'render_approval' ] );
		add_shortcode( 'thickgrass_kb', [ $this, 'render_kb' ] );
		add_shortcode( 'thickgrass_custom_form', [ $this, 'render_custom_form' ] );
		add_action( 'template_redirect', [ $this, 'handle_form_submissions' ] );
	}

	/**
	 * Runs before any theme output, so form processing can safely wp_safe_redirect()
	 * afterwards - same "headers already sent" concern as the admin screens
	 * (see class-menu.php), just using the front-end equivalent hook.
	 */
	public function handle_form_submissions(): void {
		// Deciding an approval needs no WP login - the token in the emailed
		// link is itself the credential (see render_approval()) - so this one
		// runs before the logged-in gate below, unlike the other two.
		$this->handle_approval_decision();

		if ( ! is_user_logged_in() ) {
			return;
		}

		$this->handle_new_ticket_submission();
		$this->handle_reply_submission();
		$this->handle_custom_form_submission();
	}

	private function handle_new_ticket_submission(): void {
		if ( ! isset( $_POST[ self::NEW_TICKET_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::NEW_TICKET_ACTION . '_nonce' ], self::NEW_TICKET_ACTION )
		) {
			return;
		}

		$posted   = wp_unslash( $_POST );
		$redirect = wp_get_referer() ?: home_url( '/' );

		$title = sanitize_text_field( $posted['title'] ?? '' );

		if ( '' === $title ) {
			wp_safe_redirect( add_query_arg( 'thickgrass_error', 'missing_title', $redirect ) );
			exit;
		}

		$data = [
			'ticket_type_id'       => (int) ( $posted['ticket_type_id'] ?? 0 ),
			'title'                => $title,
			'description'          => wp_kses_post( $posted['description'] ?? '' ),
			'requester_wp_user_id' => get_current_user_id(),
			'category_id'          => $this->nullable_int( $posted['category_id'] ?? '' ),
			'impact_id'            => $this->nullable_int( $posted['impact_id'] ?? '' ),
		];

		try {
			$ticket_id = Ticket::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( add_query_arg( 'thickgrass_error', 'invalid_ticket_type', $redirect ) );
			exit;
		}

		wp_safe_redirect( $this->my_tickets_url( $ticket_id ) );
		exit;
	}

	private function handle_reply_submission(): void {
		if ( ! isset( $_POST[ self::REPLY_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::REPLY_ACTION . '_nonce' ], self::REPLY_ACTION )
		) {
			return;
		}

		$ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
		$ticket    = $ticket_id ? Ticket::get( $ticket_id ) : null;

		// A requester may only ever reply on their own ticket.
		if ( ! $ticket || (int) $ticket->requester_wp_user_id !== get_current_user_id() ) {
			return;
		}

		$body = trim( wp_unslash( $_POST['body'] ?? '' ) );

		if ( '' !== $body ) {
			Comment::create( $ticket_id, get_current_user_id(), $body, false );
		}

		wp_safe_redirect( $this->my_tickets_url( $ticket_id ) );
		exit;
	}

	/**
	 * Custom Forms submission (PLAN.md 7.45, Faza 2) - end-users only ever see
	 * the fields the admin defined (Custom_Form_Field::for_form()); the
	 * standard ticket fields (assignment group/agent/location) are hidden
	 * auto-routing fixed by the admin on the form itself (Custom_Form::meta),
	 * per explicit clarification when this was scoped, and are never posted
	 * here. Every answer is both folded into the ticket's own `description`
	 * (so it shows up in the existing ticket UI with zero new admin screens)
	 * AND persisted as a structured Custom_Form_Field_Value row for reporting.
	 */
	private function handle_custom_form_submission(): void {
		if ( ! isset( $_POST[ self::CUSTOM_FORM_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::CUSTOM_FORM_ACTION . '_nonce' ], self::CUSTOM_FORM_ACTION )
		) {
			return;
		}

		$redirect = wp_get_referer() ?: home_url( '/' );
		$form_id  = (int) ( $_POST['custom_form_id'] ?? 0 );
		$form     = $form_id ? Custom_Form::get( $form_id ) : null;

		if ( ! $form || ! $form->is_active ) {
			wp_safe_redirect( add_query_arg( 'thickgrass_error', 'invalid_form', $redirect ) );
			exit;
		}

		$fields  = Custom_Form_Field::for_form( $form_id );
		$answers = [];

		// Validate everything up-front, before any DB write happens - a
		// missing required field must not leave behind a half-created ticket.
		foreach ( $fields as $field ) {
			$post_key = 'field_' . $field->id;

			if ( 'file' === $field->field_type ) {
				if ( $field->is_required && empty( $_FILES[ $post_key ]['name'] ) ) {
					wp_safe_redirect( add_query_arg( 'thickgrass_error', 'missing_required_field', $redirect ) );
					exit;
				}
				continue;
			}

			$raw = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';

			if ( 'checkbox' === $field->field_type ) {
				$answers[ (int) $field->id ] = empty( $raw ) ? __( 'No', 'thickgrass' ) : __( 'Yes', 'thickgrass' );
				continue;
			}

			if ( $field->is_required && '' === trim( (string) $raw ) ) {
				wp_safe_redirect( add_query_arg( 'thickgrass_error', 'missing_required_field', $redirect ) );
				exit;
			}

			$answers[ (int) $field->id ] = sanitize_textarea_field( $raw );
		}

		$description_lines = [];

		foreach ( $fields as $field ) {
			if ( 'file' === $field->field_type ) {
				$file_name            = empty( $_FILES[ 'field_' . $field->id ]['name'] ) ? '—' : sanitize_file_name( $_FILES[ 'field_' . $field->id ]['name'] );
				$description_lines[] = $field->label . ': ' . $file_name;
				continue;
			}

			$description_lines[] = $field->label . ': ' . ( $answers[ (int) $field->id ] ?? '—' );
		}

		try {
			$ticket_id = Ticket::create( [
				'ticket_type_id'           => (int) $form->ticket_type_id,
				'title'                    => $form->title,
				'description'              => implode( "\n", $description_lines ),
				'requester_wp_user_id'     => get_current_user_id(),
				'assignment_group_id'      => $this->nullable_int( $form->meta['assignment_group_id'] ?? '' ),
				'assigned_agent_id'        => $this->nullable_int( $form->meta['assigned_agent_id'] ?? '' ),
				'location_organization_id' => $this->nullable_int( $form->meta['location_organization_id'] ?? '' ),
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( add_query_arg( 'thickgrass_error', 'invalid_form', $redirect ) );
			exit;
		}

		foreach ( $fields as $field ) {
			$post_key = 'field_' . $field->id;

			if ( 'file' === $field->field_type ) {
				if ( ! empty( $_FILES[ $post_key ]['name'] ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';

					$uploaded = wp_handle_upload( $_FILES[ $post_key ], [ 'test_form' => false ] );

					if ( ! empty( $uploaded['file'] ) ) {
						$attachment_id = Attachment::create( $ticket_id, get_current_user_id(), $uploaded['file'], sanitize_file_name( $_FILES[ $post_key ]['name'] ) );
						Custom_Form_Field_Value::create( $ticket_id, (int) $field->id, (string) $attachment_id );
					}
				}
				continue;
			}

			Custom_Form_Field_Value::create( $ticket_id, (int) $field->id, (string) ( $answers[ (int) $field->id ] ?? '' ) );
		}

		wp_safe_redirect( $this->my_tickets_url( $ticket_id ) );
		exit;
	}

	/**
	 * No nonce here on purpose - the approval's own `token` (a long random
	 * secret only the approver received, by email) already serves as the
	 * credential, and requiring a WP nonce would force a login the approver
	 * shouldn't need. `thickgrass_approval_submit` just distinguishes this
	 * POST from any other form on the same page load.
	 */
	private function handle_approval_decision(): void {
		if ( empty( $_POST['thickgrass_approval_submit'] ) ) {
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$decision = sanitize_key( $_POST['decision'] ?? '' );

		if ( $token && in_array( $decision, [ 'approve', 'reject' ], true ) ) {
			$approval = Approval::get_by_token( $token );

			if ( $approval ) {
				Approval::decide( (int) $approval->id, 'approve' === $decision ? 'approved' : 'rejected' );
			}
		}

		wp_safe_redirect( add_query_arg( 'token', $token, $this->approval_url() ) );
		exit;
	}

	private function approval_url(): string {
		$page_id = (int) get_option( 'thickgrass_page_approval' );

		return $page_id ? get_permalink( $page_id ) : home_url( '/' );
	}

	/**
	 * Public, logged-out-friendly page an approver lands on from the emailed
	 * link - see handle_approval_decision(). GET only shows the ticket +
	 * Approve/Reject buttons (safe even if an email security scanner
	 * prefetches the link); the actual decision only happens on POST.
	 */
	public function render_approval(): string {
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$approval = $token ? Approval::get_by_token( $token ) : null;

		if ( ! $approval ) {
			return '<p>' . esc_html__( 'This approval link is invalid.', 'thickgrass' ) . '</p>';
		}

		$ticket = Ticket::get( (int) $approval->ticket_id );

		if ( ! $ticket ) {
			return '<p>' . esc_html__( 'This approval link is invalid.', 'thickgrass' ) . '</p>';
		}

		ob_start();

		echo '<h2>' . esc_html( $ticket->ticket_number . ' — ' . $ticket->title ) . '</h2>';

		if ( $ticket->description ) {
			echo '<div>' . wp_kses_post( wpautop( $ticket->description ) ) . '</div>';
		}

		if ( $approval->comment ) {
			echo '<p><em>' . esc_html( $approval->comment ) . '</em></p>';
		}

		if ( 'pending' !== $approval->status ) {
			printf(
				'<p>%s</p>',
				esc_html( sprintf(
					/* translators: 1: "approved" or "rejected", 2: date decided */
					__( 'This request was already %1$s on %2$s.', 'thickgrass' ),
					$approval->status,
					$approval->decided_at
				) )
			);
		} else {
			echo '<form method="post">';
			echo '<input type="hidden" name="thickgrass_approval_submit" value="1" />';
			echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
			printf( '<button type="submit" name="decision" value="approve">%s</button> ', esc_html__( 'Approve', 'thickgrass' ) );
			printf( '<button type="submit" name="decision" value="reject">%s</button>', esc_html__( 'Reject', 'thickgrass' ) );
			echo '</form>';
		}

		return ob_get_clean();
	}

	/**
	 * Knowledge Base browsing/search (PLAN.md 7.43, Faza 2) - deliberately
	 * public, unlike every other shortcode here: no login check at all. A
	 * plain GET-based search (no nonce needed, it's read-only) with an
	 * optional category filter; `?kb_article=<id>` shows one article, only
	 * ever a published one (see Kb_Article::get_published()) - an
	 * unpublished/nonexistent id gets the same "not found" message either way.
	 */
	public function render_kb(): string {
		ob_start();

		if ( isset( $_GET['kb_article'] ) ) {
			$this->render_kb_article( (int) $_GET['kb_article'] );
		} else {
			$this->render_kb_search();
		}

		return ob_get_clean();
	}

	private function render_kb_article( int $article_id ): void {
		$article = Kb_Article::get_published( $article_id );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $this->kb_url() ), esc_html__( 'Back to Knowledge Base', 'thickgrass' ) );

		if ( ! $article ) {
			echo '<p>' . esc_html__( 'This article could not be found.', 'thickgrass' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html( $article->title ) . '</h2>';
		echo '<div>' . wp_kses_post( $article->content ) . '</div>';
	}

	private function render_kb_search(): void {
		$term        = isset( $_GET['kb_search'] ) ? sanitize_text_field( wp_unslash( $_GET['kb_search'] ) ) : '';
		$category_id = isset( $_GET['kb_category'] ) ? (int) $_GET['kb_category'] : null;

		echo '<form method="get">';
		printf( '<input type="text" name="kb_search" value="%s" placeholder="%s" />', esc_attr( $term ), esc_attr__( 'Search the Knowledge Base…', 'thickgrass' ) );
		echo '<select name="kb_category"><option value="">' . esc_html__( 'All categories', 'thickgrass' ) . '</option>';
		foreach ( Choices::get_list( 'kb_category' ) as $category ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', $category->id, selected( $category_id, (int) $category->id, false ), esc_html( $category->label ) );
		}
		echo '</select> ';
		echo '<button type="submit">' . esc_html__( 'Search', 'thickgrass' ) . '</button>';
		echo '</form>';

		$articles = Kb_Article::search( $term, $category_id );

		if ( ! $articles ) {
			echo '<p>' . esc_html__( 'No articles found.', 'thickgrass' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $articles as $article ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( add_query_arg( 'kb_article', $article->id, $this->kb_url() ) ),
				esc_html( $article->title )
			);
		}
		echo '</ul>';
	}

	/**
	 * `[thickgrass_custom_form slug="..."]` - requires login, same as
	 * `[thickgrass_new_ticket]` (ticket creation inherently needs an
	 * identified requester). Only the fields defined on the form
	 * (Custom_Form_Field::for_form()) are shown - no standard ticket fields
	 * (assignment group, assigned to...) ever appear here, per the "hidden
	 * routing" decision (see handle_custom_form_submission()).
	 */
	public function render_custom_form( $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_prompt();
		}

		if ( ! current_user_can( 'thickgrass_enduser' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to submit this form.', 'thickgrass' ) . '</p>';
		}

		$atts = shortcode_atts( [ 'slug' => '' ], (array) $atts, 'thickgrass_custom_form' );
		$form = $atts['slug'] ? Custom_Form::get_active_by_slug( sanitize_title( $atts['slug'] ) ) : null;

		if ( ! $form ) {
			return '<p>' . esc_html__( 'This form could not be found.', 'thickgrass' ) . '</p>';
		}

		ob_start();

		echo $this->error_notice();

		if ( $form->description ) {
			echo '<div>' . wp_kses_post( wpautop( $form->description ) ) . '</div>';
		}

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::CUSTOM_FORM_ACTION, self::CUSTOM_FORM_ACTION . '_nonce' );
		echo '<input type="hidden" name="custom_form_id" value="' . esc_attr( $form->id ) . '" />';

		foreach ( Custom_Form_Field::for_form( (int) $form->id ) as $field ) {
			$this->render_custom_form_field( $field );
		}

		echo '<p><button type="submit">' . esc_html__( 'Submit', 'thickgrass' ) . '</button></p>';
		echo '</form>';

		return ob_get_clean();
	}

	private function render_custom_form_field( object $field ): void {
		$required = $field->is_required ? ' required' : '';

		echo '<p><label>' . esc_html( $field->label ) . ( $field->is_required ? ' *' : '' ) . '<br />';

		switch ( $field->field_type ) {
			case 'textarea':
				printf( '<textarea name="field_%1$d" rows="4" style="width:100%%;max-width:480px;"%2$s></textarea>', $field->id, $required );
				break;

			case 'select':
				printf( '<select name="field_%1$d"%2$s><option value="">—</option>', $field->id, $required );
				foreach ( Custom_Form_Field::options_list( $field ) as $option ) {
					printf( '<option value="%1$s">%1$s</option>', esc_attr( $option ) );
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf( '<input type="checkbox" name="field_%1$d" value="1" />', $field->id );
				break;

			case 'file':
				printf( '<input type="file" name="field_%1$d"%2$s />', $field->id, $required );
				break;

			default:
				printf( '<input type="text" name="field_%1$d" style="width:100%%;max-width:480px;"%2$s />', $field->id, $required );
		}

		echo '</label></p>';
	}

	private function kb_url(): string {
		$page_id = (int) get_option( 'thickgrass_page_kb' );

		return $page_id ? get_permalink( $page_id ) : home_url( '/' );
	}

	private function nullable_int( $value ): ?int {
		return ( '' === $value || null === $value ) ? null : (int) $value;
	}

	private function my_tickets_url( ?int $ticket_id = null ): string {
		$page_id = (int) get_option( 'thickgrass_page_my_tickets' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		return $ticket_id ? add_query_arg( 'ticket', $ticket_id, $url ) : $url;
	}

	private function new_ticket_url(): string {
		$page_id = (int) get_option( 'thickgrass_page_new_ticket' );

		return $page_id ? get_permalink( $page_id ) : home_url( '/' );
	}

	public function render_new_ticket(): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_prompt();
		}

		if ( ! current_user_can( 'thickgrass_enduser' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to open tickets.', 'thickgrass' ) . '</p>';
		}

		ob_start();

		echo $this->error_notice();

		echo '<form method="post">';
		wp_nonce_field( self::NEW_TICKET_ACTION, self::NEW_TICKET_ACTION . '_nonce' );

		echo '<p><label>' . esc_html__( 'Type', 'thickgrass' ) . '<br />';
		$this->render_choice_select( 'ticket_type_id', 'ticket_type' );
		echo '</label></p>';

		echo '<p><label>' . esc_html__( 'Title', 'thickgrass' ) . '<br /><input type="text" name="title" required style="width:100%;max-width:480px;" /></label></p>';
		echo '<p><label>' . esc_html__( 'Description', 'thickgrass' ) . '<br /><textarea name="description" rows="5" style="width:100%;max-width:480px;"></textarea></label></p>';

		echo '<p><label>' . esc_html__( 'Category', 'thickgrass' ) . '<br />';
		$this->render_choice_select( 'category_id', 'category' );
		echo '</label></p>';

		echo '<p><label>' . esc_html__( 'How much is this affecting you?', 'thickgrass' ) . '<br />';
		$this->render_choice_select( 'impact_id', 'impact' );
		echo '</label></p>';

		echo '<p><button type="submit">' . esc_html__( 'Submit ticket', 'thickgrass' ) . '</button></p>';
		echo '</form>';

		return ob_get_clean();
	}

	public function render_my_tickets(): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_prompt();
		}

		if ( ! current_user_can( 'thickgrass_enduser' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view tickets.', 'thickgrass' ) . '</p>';
		}

		ob_start();

		if ( isset( $_GET['ticket'] ) ) {
			$this->render_ticket_detail( (int) $_GET['ticket'] );
		} else {
			$this->render_ticket_list();
		}

		return ob_get_clean();
	}

	private function render_ticket_list(): void {
		$tickets = Ticket::get_for_requester( get_current_user_id() );

		printf( '<p><a href="%s">%s</a></p>', esc_url( $this->new_ticket_url() ), esc_html__( '+ Open a new ticket', 'thickgrass' ) );

		if ( ! $tickets ) {
			echo '<p>' . esc_html__( "You haven't opened any tickets yet.", 'thickgrass' ) . '</p>';
			return;
		}

		$statuses = $this->index_choices_by_id( 'status' );

		echo '<table style="width:100%;border-collapse:collapse;"><thead><tr>';
		echo '<th style="text-align:left;">' . esc_html__( 'Number', 'thickgrass' ) . '</th>';
		echo '<th style="text-align:left;">' . esc_html__( 'Title', 'thickgrass' ) . '</th>';
		echo '<th style="text-align:left;">' . esc_html__( 'Status', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $tickets as $ticket ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
				esc_url( $this->my_tickets_url( $ticket->id ) ),
				esc_html( $ticket->ticket_number ),
				esc_html( $ticket->title ),
				esc_html( $statuses[ (int) $ticket->status_id ]->label ?? '—' )
			);
		}

		echo '</tbody></table>';
	}

	private function render_ticket_detail( int $ticket_id ): void {
		$ticket = Ticket::get( $ticket_id );

		// A requester may only ever view their own ticket - guards against
		// guessing ?ticket=<id> for someone else's ticket.
		if ( ! $ticket || (int) $ticket->requester_wp_user_id !== get_current_user_id() ) {
			echo '<p>' . esc_html__( 'Ticket not found.', 'thickgrass' ) . '</p>';
			return;
		}

		$status = Choices::get( (int) $ticket->status_id );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $this->my_tickets_url() ), esc_html__( 'Back to my tickets', 'thickgrass' ) );
		echo '<h2>' . esc_html( $ticket->ticket_number . ' — ' . $ticket->title ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Status', 'thickgrass' ) . ':</strong> ' . esc_html( $status ? $status->label : '—' ) . '</p>';

		if ( $ticket->description ) {
			echo '<div>' . wp_kses_post( wpautop( $ticket->description ) ) . '</div>';
		}

		echo '<h3>' . esc_html__( 'Conversation', 'thickgrass' ) . '</h3>';

		// `false` = never include work notes - those are internal-only, see Comment::for_ticket().
		foreach ( Comment::for_ticket( $ticket_id, false ) as $comment ) {
			$author = get_userdata( (int) $comment->author_wp_user_id );

			printf(
				'<div style="background:#f0f6fc;border-left:4px solid #72aee6;padding:8px 12px;margin-bottom:8px;">
					<p style="margin:0 0 4px;"><strong>%1$s</strong> — %2$s</p>
					<div>%3$s</div>
				</div>',
				esc_html( $author ? $author->display_name : '—' ),
				esc_html( $comment->created_at ),
				wp_kses_post( wpautop( $comment->body ) )
			);
		}

		echo '<form method="post">';
		wp_nonce_field( self::REPLY_ACTION, self::REPLY_ACTION . '_nonce' );
		echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket_id ) . '" />';
		echo '<textarea name="body" rows="3" style="width:100%;max-width:480px;" required></textarea>';
		echo '<p><button type="submit">' . esc_html__( 'Reply', 'thickgrass' ) . '</button></p>';
		echo '</form>';
	}

	private function render_choice_select( string $field, string $list_key ): void {
		printf( '<select name="%s" required>', esc_attr( $field ) );
		echo '<option value="">—</option>';

		foreach ( Choices::get_list( $list_key ) as $choice ) {
			printf( '<option value="%1$d">%2$s</option>', $choice->id, esc_html( $choice->label ) );
		}

		echo '</select>';
	}

	private function error_notice(): string {
		$errors = [
			'missing_title'          => __( 'Please enter a title.', 'thickgrass' ),
			'invalid_ticket_type'    => __( 'Please choose a valid ticket type.', 'thickgrass' ),
			'invalid_form'           => __( 'This form is not available.', 'thickgrass' ),
			'missing_required_field' => __( 'Please fill in all required fields.', 'thickgrass' ),
		];

		$error = isset( $_GET['thickgrass_error'] ) ? sanitize_key( $_GET['thickgrass_error'] ) : '';

		if ( ! isset( $errors[ $error ] ) ) {
			return '';
		}

		return '<p style="color:#b32d2e;">' . esc_html( $errors[ $error ] ) . '</p>';
	}

	private function login_prompt(): string {
		return '<p>' . sprintf(
			/* translators: %s: "log in" link */
			esc_html__( 'Please %s to continue.', 'thickgrass' ),
			'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'thickgrass' ) . '</a>'
		) . '</p>';
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
}
