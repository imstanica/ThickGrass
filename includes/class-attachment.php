<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Files attached directly to a ticket (PLAN.md Faza 1: "Atașamente fișiere pe
 * tichet" - deliberately ticket-level only for now, not per-comment, per the
 * explicit scope given when this was built). `wp_thickgrass_attachments`
 * already supports a `comment_id` for a future per-comment version, left
 * NULL here on purpose.
 */
class Attachment {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_attachments';
	}

	public static function create( int $ticket_id, int $uploaded_by_wp_user_id, string $file_path, string $file_name ): int {
		global $wpdb;

		$wpdb->insert( self::table(), [
			'ticket_id'              => $ticket_id,
			'file_path'              => $file_path,
			'file_name'              => $file_name,
			'uploaded_by_wp_user_id' => $uploaded_by_wp_user_id,
			'created_at'             => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * @return array<int, object>
	 */
	public static function for_ticket( int $ticket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d ORDER BY created_at DESC', $ticket_id )
		);
	}

	/**
	 * Removes the DB row and, best-effort, the file itself - a missing file
	 * (already deleted by some other means) does not block removing the row.
	 */
	public static function delete( int $id ): void {
		global $wpdb;

		$attachment = self::get( $id );

		if ( $attachment && file_exists( $attachment->file_path ) ) {
			@unlink( $attachment->file_path );
		}

		$wpdb->delete( self::table(), [ 'id' => $id ] );
	}

	/**
	 * Whether to render this attachment inline as an image in the Activity
	 * feed (see Dashboard_Page::render_feed_item()) instead of a plain
	 * download link. Deliberately excludes 'svg': WordPress core doesn't
	 * allow SVG uploads by default, but if another plugin/site config ever
	 * adds it to `upload_mimes`, an SVG can carry a <script> payload - safer
	 * to never treat it as a raster image (see also handle_download()
	 * below, which forces "attachment" disposition for it either way).
	 */
	public static function is_image( string $file_name ): bool {
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

		return in_array( $extension, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true );
	}

	/**
	 * A capability + ticket-scope checked download link, routed through
	 * admin-post.php (handle_download() below) instead of the raw public
	 * uploads URL that used to be returned here. Fixed as part of the
	 * pre-release security audit: the uploads directory is public, so the
	 * plain URL let anyone who obtained/guessed it read a ticket attachment
	 * without going through any of the plugin's own visibility checks
	 * (agent group/location scope, requester-only access) - see
	 * Ticket::agent_can_view().
	 */
	public static function url( object $attachment ): string {
		return add_query_arg(
			[
				'action'   => 'thickgrass_download_attachment',
				'id'       => $attachment->id,
				'_wpnonce' => wp_create_nonce( 'thickgrass_download_attachment_' . $attachment->id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Streams the file behind the checked link url() builds above.
	 * Registered on `admin_post_thickgrass_download_attachment`
	 * (see Plugin::init()) - only ever reachable by a logged-in agent whose
	 * own assignment group/location scope covers the attachment's ticket,
	 * the exact same rule already enforced for viewing/editing that ticket
	 * (Dashboard_Page::agent_can_access_ticket()).
	 */
	public static function handle_download(): void {
		$attachment_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( ! $attachment_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'thickgrass_download_attachment_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid or expired link.', 'thickgrass' ), 403 );
		}

		$attachment = self::get( $attachment_id );
		$ticket     = $attachment ? Ticket::get( (int) $attachment->ticket_id ) : null;

		if ( ! $ticket || ! current_user_can( 'thickgrass_agent' ) || ! self::current_agent_can_view( $ticket ) ) {
			wp_die( esc_html__( 'You do not have permission to access this file.', 'thickgrass' ), 403 );
		}

		if ( ! file_exists( $attachment->file_path ) ) {
			wp_die( esc_html__( 'This file no longer exists.', 'thickgrass' ), 404 );
		}

		$mime = wp_check_filetype( $attachment->file_name );

		// Never render SVGs (or anything else not a recognized raster image)
		// inline: a browser opening the link directly (not via <img>) would
		// otherwise execute a <script> payload embedded in an SVG file.
		$disposition = self::is_image( $attachment->file_name ) ? 'inline' : 'attachment';

		nocache_headers();
		header( 'Content-Type: ' . ( $mime['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . sanitize_file_name( $attachment->file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $attachment->file_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $attachment->file_path );
		exit;
	}

	private static function current_agent_can_view( object $ticket ): bool {
		global $wpdb;

		$agents_table = $wpdb->prefix . 'thickgrass_agents';
		$agent_id     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$agents_table} WHERE wp_user_id = %d", get_current_user_id() ) );

		return $agent_id && Ticket::agent_can_view( (int) $agent_id, $ticket );
	}
}
