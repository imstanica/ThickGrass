<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurable email notifications (PLAN.md Faza 1: "Șabloane de email
 * configurabile din admin... nu texte hardcodate în cod"). Templates live in
 * a single option (`thickgrass_email_templates`), editable from
 * `Admin\Email_Templates_Page`; the constants below are only the seeded
 * defaults, not the source of truth once an admin has saved their own.
 */
class Email_Notifications {

	private const OPTION_KEY = 'thickgrass_email_templates';

	/**
	 * @var array<string, array{label: string, subject: string, body: string}>
	 */
	public const EVENTS = [
		'ticket_created' => [
			'label'   => 'Ticket created',
			'subject' => 'New ticket {ticket_number}: {title}',
			'body'    => "A new ticket has been created.\n\nNumber: {ticket_number}\nTitle: {title}\nPriority: {priority}\nRequester: {requester_name}\n\nView it here: {url}",
		],
		'status_changed' => [
			'label'   => 'Ticket status changed',
			'subject' => 'Ticket {ticket_number} status changed to {status}',
			'body'    => "Your ticket {ticket_number} ({title}) status changed to: {status}\n\nView it here: {url}",
		],
		'new_reply' => [
			'label'   => 'New reply on a ticket',
			'subject' => 'New reply on ticket {ticket_number}',
			'body'    => "There is a new reply on ticket {ticket_number} ({title}).\n\nView it here: {url}",
		],
		'sla_breach' => [
			'label'   => 'SLA breached',
			'subject' => 'SLA breached on ticket {ticket_number}',
			'body'    => "The resolution SLA for ticket {ticket_number} ({title}) has been breached.\n\nView it here: {url}",
		],
		'approval_requested' => [
			'label'   => 'Approval requested',
			'subject' => 'Approval needed for ticket {ticket_number}: {title}',
			'body'    => "{requested_by_name} is requesting your approval on ticket {ticket_number} ({title}).\n\nReview and decide here: {url}",
		],
		'approval_decided' => [
			'label'   => 'Approval decided',
			'subject' => 'Ticket {ticket_number} approval: {decision}',
			'body'    => "{approver_name} has {decision} the approval request on ticket {ticket_number} ({title}).\n\nView it here: {url}",
		],
	];

	/**
	 * @return array<string, array{enabled: bool, subject: string, body: string}>
	 */
	public static function get_templates(): array {
		$saved     = get_option( self::OPTION_KEY, [] );
		$templates = [];

		foreach ( self::EVENTS as $key => $default ) {
			$templates[ $key ] = [
				'enabled' => $saved[ $key ]['enabled'] ?? true,
				'subject' => $saved[ $key ]['subject'] ?? $default['subject'],
				'body'    => $saved[ $key ]['body'] ?? $default['body'],
			];
		}

		return $templates;
	}

	/**
	 * @param array<string, array{enabled?: string, subject?: string, body?: string}> $posted
	 */
	public static function save_templates( array $posted ): void {
		$clean = [];

		foreach ( self::EVENTS as $key => $default ) {
			$clean[ $key ] = [
				'enabled' => ! empty( $posted[ $key ]['enabled'] ),
				'subject' => sanitize_text_field( $posted[ $key ]['subject'] ?? $default['subject'] ),
				'body'    => sanitize_textarea_field( $posted[ $key ]['body'] ?? $default['body'] ),
			];
		}

		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Seeds the option once, at activation - safe to call on every activation
	 * (Activator's usual idempotency pattern), a no-op once it already exists.
	 */
	public static function maybe_seed_defaults(): void {
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			return;
		}

		update_option( self::OPTION_KEY, self::get_templates() );
	}

	/**
	 * No-op if the event is disabled or there is nobody to send to - callers
	 * don't need to check either condition themselves.
	 *
	 * @param array<int, string|null> $recipients email addresses
	 * @param array<string, string>   $placeholders
	 */
	public static function send( string $event, int $ticket_id, array $recipients, array $placeholders, ?int $actor_wp_user_id = null ): void {
		$recipients = array_values( array_unique( array_filter( $recipients ) ) );

		if ( ! $recipients ) {
			return;
		}

		$template = self::get_templates()[ $event ] ?? null;

		if ( ! $template || empty( $template['enabled'] ) ) {
			return;
		}

		$subject = self::apply_placeholders( $template['subject'], $placeholders );
		$body    = self::apply_placeholders( $template['body'], $placeholders );

		self::dispatch( $ticket_id, $actor_wp_user_id, $recipients, $subject, $body );
	}

	/**
	 * A free-form email typed by an agent from the ticket screen (not one of
	 * the templated events above) - still tagged/logged the same way, so a
	 * reply to it threads back into the ticket exactly like a templated one.
	 */
	public static function send_manual( int $ticket_id, int $actor_wp_user_id, string $to, string $subject, string $body ): void {
		$to = sanitize_email( $to );

		if ( ! $to ) {
			return;
		}

		self::dispatch( $ticket_id, $actor_wp_user_id, [ $to ], $subject, $body );
	}

	/**
	 * Shared by every outbound path (templated events + the manual composer):
	 * appends a stable "[Ticket #N]" tag to the subject - surviving both an
	 * admin rewriting a template and a mail client prefixing "Re: " on
	 * reply - and logs the send to the ticket's activity feed. This tag is
	 * what Imap_Mailbox::poll() looks for to match an inbound reply back to
	 * its ticket.
	 *
	 * @param array<int, string> $recipients
	 */
	private static function dispatch( int $ticket_id, ?int $actor_wp_user_id, array $recipients, string $subject, string $body ): void {
		$ticket = Ticket::get( $ticket_id );
		$tag    = $ticket ? ' [Ticket #' . $ticket->ticket_number . ']' : '';

		if ( $tag && false === strpos( $subject, $tag ) ) {
			$subject .= $tag;
		}

		foreach ( $recipients as $to ) {
			wp_mail( $to, $subject, $body );
		}

		Activity_Log::record( $ticket_id, $actor_wp_user_id ?? 0, 'email_sent', null, [
			'to'      => $recipients,
			'subject' => $subject,
			'body'    => $body,
		] );
	}

	/**
	 * The common set of placeholders every event needs, built from a single
	 * ticket row - $overrides lets a caller swap in something event-specific
	 * (e.g. a different `status`, or the portal URL instead of the admin one
	 * when the recipient is the requester, not staff).
	 *
	 * @param array<string, string> $overrides
	 * @return array<string, string>
	 */
	public static function ticket_placeholders( object $ticket, array $overrides = [] ): array {
		$priority  = $ticket->priority_id ? Choices::get( (int) $ticket->priority_id ) : null;
		$status    = $ticket->status_id ? Choices::get( (int) $ticket->status_id ) : null;
		$requester = get_userdata( (int) $ticket->requester_wp_user_id );

		$base = [
			'ticket_number'  => $ticket->ticket_number,
			'title'          => $ticket->title,
			'priority'       => $priority ? $priority->label : '—',
			'status'         => $status ? $status->label : '—',
			'requester_name' => $requester ? $requester->display_name : '—',
			'url'            => admin_url( 'admin.php?page=thickgrass&view=' . $ticket->id ),
		];

		return array_merge( $base, $overrides );
	}

	/**
	 * The end-user portal's own ticket URL (not the admin one) - for
	 * placeholders in emails addressed to the requester, who has no access
	 * to wp-admin.
	 */
	public static function portal_url( int $ticket_id ): string {
		$page_id = (int) get_option( 'thickgrass_page_my_tickets' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		return add_query_arg( 'ticket', $ticket_id, $url );
	}

	public static function requester_email( int $wp_user_id ): ?string {
		return self::email_for_wp_user_id( $wp_user_id );
	}

	public static function agent_email( ?int $agent_id ): ?string {
		if ( ! $agent_id ) {
			return null;
		}

		global $wpdb;

		$table      = $wpdb->prefix . 'thickgrass_agents';
		$wp_user_id = $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE id = %d", $agent_id ) );

		return $wp_user_id ? self::email_for_wp_user_id( (int) $wp_user_id ) : null;
	}

	/**
	 * @return array<int, string> emails of every active agent in the group
	 */
	public static function group_agent_emails( ?int $assignment_group_id ): array {
		if ( ! $assignment_group_id ) {
			return [];
		}

		global $wpdb;

		$agents_table = $wpdb->prefix . 'thickgrass_agents';
		$groups_table = $wpdb->prefix . 'thickgrass_agent_groups';

		$wp_user_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT a.wp_user_id FROM {$agents_table} a
			INNER JOIN {$groups_table} g ON g.agent_id = a.id
			WHERE g.assignment_group_id = %d AND a.is_active = 1",
			$assignment_group_id
		) );

		$emails = [];

		foreach ( $wp_user_ids as $wp_user_id ) {
			$email = self::email_for_wp_user_id( (int) $wp_user_id );

			if ( $email ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	private static function email_for_wp_user_id( int $wp_user_id ): ?string {
		$user = get_userdata( $wp_user_id );

		return $user ? $user->user_email : null;
	}

	/**
	 * Public (not private) - also reused by Canned_Response insertion on the
	 * ticket screen (PLAN.md 7.44, Faza 2), which needs the exact same
	 * {ticket_number}/{title}/etc. substitution as email templates.
	 */
	/**
	 * The fixed set of merge-field tokens offered as "Insert" quick buttons on
	 * the Canned Responses editor (PLAN.md 7.46) - deliberately the same keys
	 * as ticket_placeholders()'s own base array, kept in sync by hand since
	 * there are only a handful and they rarely change.
	 *
	 * @return array<string, string> placeholder key => human label
	 */
	public static function placeholder_keys(): array {
		return [
			'ticket_number'  => __( 'Ticket number', 'thickgrass' ),
			'title'          => __( 'Title', 'thickgrass' ),
			'priority'       => __( 'Priority', 'thickgrass' ),
			'status'         => __( 'Status', 'thickgrass' ),
			'requester_name' => __( 'Requester name', 'thickgrass' ),
			'url'            => __( 'Ticket URL', 'thickgrass' ),
		];
	}

	public static function apply_placeholders( string $text, array $placeholders ): string {
		foreach ( $placeholders as $key => $value ) {
			$text = str_replace( '{' . $key . '}', (string) $value, $text );
		}

		return $text;
	}
}
