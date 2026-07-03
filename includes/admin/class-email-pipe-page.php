<?php

namespace ThickGrass\Admin;

use ThickGrass\Imap_Mailbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for the single IMAP mailbox ThickGrass polls for replies (see
 * ThickGrass\Imap_Mailbox) - a scoped-down version of the multi-mailbox
 * "email pipe" idea sketched in PLAN.md section 3.1.
 */
class Email_Pipe_Page {

	private const SAVE_ACTION  = 'thickgrass_email_pipe_save';
	private const CHECK_ACTION = 'thickgrass_email_pipe_check_now';

	/**
	 * Embedded as a tab inside "Settings" (see class-settings-page.php) rather
	 * than owning its own top-level admin page.
	 */
	public function render_body(): void {
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'thickgrass' ) . '</p></div>';
		}

		if ( isset( $_GET['checked'] ) ) {
			$this->render_check_result();
		}

		if ( ! extension_loaded( 'imap' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The PHP IMAP extension is not enabled on this server - ask your host to enable it before turning this on.', 'thickgrass' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'A reply sent to a message from ThickGrass is matched back onto its ticket automatically (via a hidden tag in the subject line) and added as a public reply - from either the requester or an agent.', 'thickgrass' ) . '</p>';

		$this->render_form();
	}

	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			return;
		}

		$this->handle_save();
		$this->handle_check_now();
	}

	private function handle_save(): void {
		if ( ! isset( $_POST[ self::SAVE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::SAVE_ACTION . '_nonce' ], self::SAVE_ACTION )
		) {
			return;
		}

		Imap_Mailbox::save_settings( wp_unslash( $_POST['pipe'] ?? [] ) );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-settings&tab=email-inbox&updated=1' ) );
		exit;
	}

	private function handle_check_now(): void {
		if ( ! isset( $_POST[ self::CHECK_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::CHECK_ACTION . '_nonce' ], self::CHECK_ACTION )
		) {
			return;
		}

		$result = Imap_Mailbox::poll();

		set_transient( 'thickgrass_email_pipe_check_result', $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-settings&tab=email-inbox&checked=1' ) );
		exit;
	}

	private function render_check_result(): void {
		$result = get_transient( 'thickgrass_email_pipe_check_result' );

		if ( ! $result ) {
			return;
		}

		if ( $result['error'] ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result['error'] ) );
		} else {
			printf(
				/* translators: %d: number of replies matched to a ticket */
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf( _n( '%d new reply processed.', '%d new replies processed.', $result['processed'], 'thickgrass' ), $result['processed'] ) )
			);
		}

		delete_transient( 'thickgrass_email_pipe_check_result' );
	}

	private function render_form(): void {
		$settings = Imap_Mailbox::get_settings();

		echo '<div class="thickgrass-card">';
		echo '<form method="post">';
		wp_nonce_field( self::SAVE_ACTION, self::SAVE_ACTION . '_nonce' );

		printf(
			'<p><label><input type="checkbox" name="pipe[enabled]" value="1" %s /> %s</label></p>',
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Enabled', 'thickgrass' )
		);

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Host', 'thickgrass' ) . '</label>';
		printf( '<input type="text" name="pipe[host]" class="regular-text" value="%s" placeholder="imap.example.com" /></div>', esc_attr( $settings['host'] ) );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Port', 'thickgrass' ) . '</label>';
		printf( '<input type="number" name="pipe[port]" class="small-text" value="%d" /></div>', (int) $settings['port'] );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Encryption', 'thickgrass' ) . '</label>';
		echo '<select name="pipe[encryption]">';
		foreach ( [ 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => __( 'None', 'thickgrass' ) ] as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $settings['encryption'], $value, false ), esc_html( $label ) );
		}
		echo '</select></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Username', 'thickgrass' ) . '</label>';
		printf( '<input type="text" name="pipe[username]" class="regular-text" value="%s" /></div>', esc_attr( $settings['username'] ) );

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Password', 'thickgrass' ) . '</label>';
		echo '<input type="password" name="pipe[password]" class="regular-text" value="" autocomplete="new-password" />';
		echo ' <span class="description">' . esc_html__( 'Leave blank to keep the current password.', 'thickgrass' ) . '</span></div>';

		echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Folder', 'thickgrass' ) . '</label>';
		printf( '<input type="text" name="pipe[folder]" class="regular-text" value="%s" /></div>', esc_attr( $settings['folder'] ) );

		submit_button( __( 'Save settings', 'thickgrass' ) );
		echo '</form>';
		echo '</div>';

		echo '<form method="post">';
		wp_nonce_field( self::CHECK_ACTION, self::CHECK_ACTION . '_nonce' );
		submit_button( __( 'Check now', 'thickgrass' ), 'secondary' );
		echo '</form>';
	}
}
