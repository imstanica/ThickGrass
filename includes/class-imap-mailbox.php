<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads replies out of a single IMAP mailbox and threads them back onto the
 * ticket they belong to, matching a "[Ticket #{ticket_number}]" tag that
 * Email_Notifications::dispatch() appends to every outbound ticket email -
 * this is what survives a mail client prefixing "Re: " and an admin
 * rewriting a template's subject text.
 *
 * A scoped-down version of the `wp_thickgrass_email_pipes` idea sketched in
 * PLAN.md section 3.1 (Faza 2: multiple monitored mailboxes that can also
 * create brand new Calls from unmatched mail) - this only handles matching a
 * reply to an EXISTING ticket, one mailbox, configured through a single
 * option rather than a table. Expanding to multiple mailboxes / creating new
 * tickets from unmatched mail remains future work.
 */
class Imap_Mailbox {

	private const OPTION_KEY = 'thickgrass_email_pipe_settings';

	/**
	 * @return array{enabled: bool, host: string, port: int, encryption: string, username: string, password: string, folder: string}
	 */
	public static function get_settings(): array {
		return array_merge( [
			'enabled'    => false,
			'host'       => '',
			'port'       => 993,
			'encryption' => 'ssl',
			'username'   => '',
			'password'   => '',
			'folder'     => 'INBOX',
		], get_option( self::OPTION_KEY, [] ) );
	}

	/**
	 * @param array<string, string> $posted
	 */
	public static function save_settings( array $posted ): void {
		update_option( self::OPTION_KEY, [
			'enabled'    => ! empty( $posted['enabled'] ),
			'host'       => sanitize_text_field( $posted['host'] ?? '' ),
			'port'       => (int) ( $posted['port'] ?? 993 ),
			'encryption' => in_array( $posted['encryption'] ?? '', [ 'ssl', 'tls', 'none' ], true ) ? $posted['encryption'] : 'ssl',
			'username'   => sanitize_text_field( $posted['username'] ?? '' ),
			// Only overwritten when a new value is actually typed, so the
			// settings form doesn't need to round-trip the real password in
			// its value attribute just to avoid clearing it on save.
			'password'   => '' !== ( $posted['password'] ?? '' ) ? $posted['password'] : self::get_settings()['password'],
			'folder'     => sanitize_text_field( $posted['folder'] ?? '' ) ?: 'INBOX',
		] );
	}

	public static function maybe_seed_defaults(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			update_option( self::OPTION_KEY, self::get_settings() );
		}
	}

	public static function is_configured(): bool {
		$settings = self::get_settings();

		return $settings['enabled'] && $settings['host'] && $settings['username'] && $settings['password'];
	}

	/**
	 * Connects, reads every UNSEEN message, threads matches onto their ticket,
	 * marks every message it looked at as \Seen (matched or not, so a message
	 * that can't be matched is not retried forever). Safe to call directly
	 * from the cron hook or from the settings screen's "Check now" button.
	 *
	 * @return array{processed: int, error: string|null}
	 */
	public static function poll(): array {
		if ( ! self::is_configured() ) {
			return [ 'processed' => 0, 'error' => __( 'Mailbox is not configured.', 'thickgrass' ) ];
		}

		if ( ! extension_loaded( 'imap' ) ) {
			return [ 'processed' => 0, 'error' => __( 'The PHP IMAP extension is not available on this server.', 'thickgrass' ) ];
		}

		$settings = self::get_settings();
		$mailbox  = self::mailbox_string( $settings );

		$conn = @imap_open( $mailbox, $settings['username'], $settings['password'] ); // phpcs:ignore -- imap_open() itself warns on failure, surfaced below via imap_last_error().

		if ( ! $conn ) {
			return [ 'processed' => 0, 'error' => imap_last_error() ?: __( 'Could not connect to the mailbox.', 'thickgrass' ) ];
		}

		$message_numbers = imap_search( $conn, 'UNSEEN' );
		$processed       = 0;

		foreach ( (array) $message_numbers as $msg_number ) {
			if ( self::process_message( $conn, (int) $msg_number ) ) {
				$processed++;
			}

			imap_setflag_full( $conn, (string) $msg_number, '\\Seen' );
		}

		imap_close( $conn );

		return [ 'processed' => $processed, 'error' => null ];
	}

	/**
	 * @param resource|\IMAP\Connection $conn
	 */
	private static function process_message( $conn, int $msg_number ): bool {
		$header = imap_headerinfo( $conn, $msg_number );

		if ( ! $header || empty( $header->from[0] ) ) {
			return false;
		}

		$ticket = self::find_ticket_from_subject( imap_utf8( $header->subject ?? '' ) );

		if ( ! $ticket ) {
			return false;
		}

		$from = $header->from[0];
		$user = get_user_by( 'email', $from->mailbox . '@' . $from->host );

		if ( ! $user || ! self::user_is_related_to_ticket( (int) $user->ID, $ticket ) ) {
			return false;
		}

		$body = self::strip_quoted_reply( self::plain_body( $conn, $msg_number ) );

		if ( '' === $body ) {
			return false;
		}

		Comment::create( (int) $ticket->id, (int) $user->ID, $body, false );

		return true;
	}

	private static function find_ticket_from_subject( string $subject ): ?object {
		if ( ! preg_match( '/\[Ticket #([^\]]+)\]/', $subject, $match ) ) {
			return null;
		}

		return Ticket::get_by_number( $match[1] );
	}

	/**
	 * A reply is only ever attributed to the ticket's own requester or one of
	 * the (active) agents - never to an arbitrary WP user whose address
	 * happens to be registered, since From: headers are trivially spoofable.
	 */
	private static function user_is_related_to_ticket( int $wp_user_id, object $ticket ): bool {
		if ( $wp_user_id === (int) $ticket->requester_wp_user_id ) {
			return true;
		}

		global $wpdb;

		$agents_table = $wpdb->prefix . 'thickgrass_agents';

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$agents_table} WHERE wp_user_id = %d AND is_active = 1",
			$wp_user_id
		) );
	}

	/**
	 * @param resource|\IMAP\Connection $conn
	 */
	private static function plain_body( $conn, int $msg_number ): string {
		$structure = imap_fetchstructure( $conn, $msg_number );

		if ( ! empty( $structure->parts ) ) {
			foreach ( $structure->parts as $index => $part ) {
				if ( 'PLAIN' === strtoupper( $part->subtype ?? '' ) ) {
					return self::decode_part( imap_fetchbody( $conn, $msg_number, (string) ( $index + 1 ) ), (int) ( $part->encoding ?? 0 ) );
				}
			}

			return self::decode_part( imap_fetchbody( $conn, $msg_number, '1' ), (int) ( $structure->parts[0]->encoding ?? 0 ) );
		}

		return self::decode_part( imap_body( $conn, $msg_number ), (int) ( $structure->encoding ?? 0 ) );
	}

	private static function decode_part( string $body, int $encoding ): string {
		if ( 3 === $encoding ) { // ENCBASE64
			return base64_decode( $body );
		}

		if ( 4 === $encoding ) { // ENCQUOTEDPRINTABLE
			return quoted_printable_decode( $body );
		}

		return $body;
	}

	/**
	 * Cuts off the quoted history a reply usually carries along ("On ... X
	 * wrote:", Outlook's "-----Original Message-----") - a best-effort
	 * heuristic, not a full parser.
	 */
	private static function strip_quoted_reply( string $body ): string {
		$patterns = [
			'/^On .+wrote:\s*$/mi',
			'/^-{2,}\s*Original Message\s*-{2,}/mi',
			'/^From:\s.+$/mi',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $body, $match, PREG_OFFSET_CAPTURE ) ) {
				$body = substr( $body, 0, $match[0][1] );
			}
		}

		return trim( wp_strip_all_tags( $body ) );
	}

	/**
	 * @param array{host: string, port: int, encryption: string, folder: string} $settings
	 */
	private static function mailbox_string( array $settings ): string {
		$flag = 'none' === $settings['encryption'] ? '/notls' : '/' . $settings['encryption'];

		return '{' . $settings['host'] . ':' . $settings['port'] . '/imap' . $flag . '}' . $settings['folder'];
	}
}
