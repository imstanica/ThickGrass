<?php

namespace ThickGrass\Admin;

use ThickGrass\Email_Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurable email templates (PLAN.md Faza 1: "Șabloane de email
 * configurabile din admin... nu texte hardcodate în cod") - one card per
 * event (ticket created, status changed, new reply, SLA breached), each with
 * an Enabled toggle, Subject, and Body. Sending itself lives in
 * ThickGrass\Email_Notifications, called from Ticket/Comment/Sla.
 */
class Email_Templates_Page {

	private const SAVE_ACTION = 'thickgrass_email_templates_save';

	/**
	 * Embedded as a tab inside "Settings" (see class-settings-page.php) rather
	 * than owning its own top-level admin page.
	 */
	public function render_body(): void {
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Templates saved.', 'thickgrass' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Available placeholders:', 'thickgrass' ) . ' <code>{ticket_number}</code> <code>{title}</code> <code>{status}</code> <code>{priority}</code> <code>{requester_name}</code> <code>{url}</code></p>';
		echo '<p>' . esc_html__( 'Approval events only:', 'thickgrass' ) . ' <code>{requested_by_name}</code> <code>{approver_name}</code> <code>{decision}</code></p>';

		$this->render_form();
	}

	public function handle_actions(): void {
		if ( ! current_user_can( 'thickgrass_manage' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::SAVE_ACTION . '_nonce' ] )
			|| ! wp_verify_nonce( $_POST[ self::SAVE_ACTION . '_nonce' ], self::SAVE_ACTION )
		) {
			return;
		}

		Email_Notifications::save_templates( wp_unslash( $_POST['templates'] ?? [] ) );

		wp_safe_redirect( admin_url( 'admin.php?page=thickgrass-settings&tab=email-templates&updated=1' ) );
		exit;
	}

	private function render_form(): void {
		$templates = Email_Notifications::get_templates();

		echo '<form method="post">';
		wp_nonce_field( self::SAVE_ACTION, self::SAVE_ACTION . '_nonce' );

		foreach ( Email_Notifications::EVENTS as $key => $default ) {
			$template = $templates[ $key ];

			echo '<h2>' . esc_html( $default['label'] ) . '</h2>';
			echo '<div class="thickgrass-card">';

			printf(
				'<p><label><input type="checkbox" name="templates[%1$s][enabled]" value="1" %2$s /> %3$s</label></p>',
				esc_attr( $key ),
				checked( $template['enabled'], true, false ),
				esc_html__( 'Enabled', 'thickgrass' )
			);

			echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Subject', 'thickgrass' ) . '</label>';
			printf(
				'<input type="text" name="templates[%1$s][subject]" class="large-text" value="%2$s" /></div>',
				esc_attr( $key ),
				esc_attr( $template['subject'] )
			);

			echo '<div class="thickgrass-form-row"><label>' . esc_html__( 'Body', 'thickgrass' ) . '</label>';
			printf(
				'<textarea name="templates[%1$s][body]" class="large-text" rows="5">%2$s</textarea></div>',
				esc_attr( $key ),
				esc_textarea( $template['body'] )
			);

			echo '</div>';
		}

		submit_button( __( 'Save templates', 'thickgrass' ) );
		echo '</form>';
	}
}
