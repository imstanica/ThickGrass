<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the plugin once WordPress core (and other plugins) are loaded.
 */
class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		load_plugin_textdomain( 'thickgrass', false, dirname( plugin_basename( THICKGRASS_FILE ) ) . '/languages' );

		new Shortcodes();

		// Registered unconditionally (not inside is_admin()) - WP-Cron fires
		// this on any page load, front-end included, once the schedule is due.
		add_action( 'thickgrass_sla_escalation_check', [ Sla::class, 'run_escalations' ] );
		add_action( 'thickgrass_sla_escalation_check', [ Sla::class, 'run_notifications' ] );

		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );
		add_action( 'thickgrass_email_pipe_check', [ Imap_Mailbox::class, 'poll' ] );

		// A new WP user can come from a front-end registration form, not just
		// wp-admin - see Users_Page's "Automatically assign new users to the
		// default organization" checkbox.
		add_action( 'user_register', [ Admin\Admin_Helpers::class, 'maybe_auto_assign_default_organization' ] );

		if ( is_admin() ) {
			new Admin\Menu();
			add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

			// Ticket attachment downloads (Attachment::url()) - routed through
			// admin-post.php instead of a raw public uploads URL, so every
			// download is capability + ticket-scope checked (PLAN.md:
			// pre-release security audit fix).
			add_action( 'admin_post_thickgrass_download_attachment', [ Attachment::class, 'handle_download' ] );
		}
	}

	/**
	 * Self-healing schema/data upgrade: whenever the plugin code ships a new
	 * `THICKGRASS_DB_VERSION` (new column, new seed data...), the very next
	 * admin page load re-runs the full activation routine automatically -
	 * every step in Activator is idempotent, so this is safe to call again.
	 * Removes the need to manually deactivate/reactivate the plugin after an
	 * update, in line with PLAN.md 1.2 ("works from activation alone").
	 */
	public function maybe_upgrade(): void {
		if ( get_option( 'thickgrass_db_version' ) !== THICKGRASS_DB_VERSION ) {
			Activator::activate();
		}
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_cron_schedules( array $schedules ): array {
		$schedules['thickgrass_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (ThickGrass mailbox check)', 'thickgrass' ),
		];

		return $schedules;
	}
}
