<?php

namespace ThickGrass\Admin;

use ThickGrass\Ticket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dedicated reporting screen for SLA compliance (PLAN.md: "pagina dedicata
 * pentru generarea de rapoarte in baza SLA-ului" - deliberately NOT a tab
 * under "Configurable lists", that page is for configuration, this one is
 * for reading aggregated results). Read-only: no save/delete actions.
 */
class Sla_Reports_Page {

	private const CAPABILITY = 'thickgrass_manage';

	/**
	 * Only the CSV export needs to run here (must happen before any HTML is
	 * sent - see class-menu.php's `load-{$hook}` wiring) - everything else on
	 * this screen is read-only GET filtering handled directly in render().
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_GET['export'] ) || 'csv' !== $_GET['export'] ) {
			return;
		}

		$this->export_csv( $this->query_tickets( $this->current_filters() ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thickgrass' ) );
		}

		$filters = $this->current_filters();
		$tickets = $this->query_tickets( $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SLA reports', 'thickgrass' ) . '</h1>';

		$this->render_filter_form( $filters );
		$this->render_export_link( $filters );
		$this->render_summary( $tickets );

		$this->render_breakdown( $tickets, 'organization_id', Admin_Helpers::organization_options(), __( 'By organization', 'thickgrass' ) );
		$this->render_breakdown( $tickets, 'priority_id', Admin_Helpers::choice_options( 'priority' ), __( 'By priority', 'thickgrass' ) );
		$this->render_breakdown( $tickets, 'category_id', Admin_Helpers::choice_options( 'category' ), __( 'By category', 'thickgrass' ) );
		$this->render_breakdown( $tickets, 'ticket_type_id', Admin_Helpers::choice_options( 'ticket_type' ), __( 'By ticket type', 'thickgrass' ) );

		echo '</div>';
	}

	/**
	 * @return array{date_from: string, date_to: string, organization_id: ?int, priority_id: ?int, category_id: ?int, ticket_type_id: ?int}
	 */
	private function current_filters(): array {
		$filters = [
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
		];

		foreach ( [ 'organization_id', 'priority_id', 'category_id', 'ticket_type_id' ] as $field ) {
			$filters[ $field ] = isset( $_GET[ $field ] ) && '' !== $_GET[ $field ] ? (int) $_GET[ $field ] : null;
		}

		return $filters;
	}

	/**
	 * Tickets don't store their organization directly - it comes from the
	 * requester's `wp_thickgrass_users` row, same relationship Sla::calculate_due_dates()
	 * uses, so it's joined in here rather than duplicated as a ticket column.
	 *
	 * @return array<int, object>
	 */
	private function query_tickets( array $filters ): array {
		global $wpdb;

		$tickets_table = Ticket::table();
		$users_table   = $wpdb->prefix . 'thickgrass_users';

		$where = [ 't.sla_id IS NOT NULL' ];
		$args  = [];

		if ( $filters['date_from'] ) {
			$where[] = 't.created_at >= %s';
			$args[]  = $filters['date_from'] . ' 00:00:00';
		}

		if ( $filters['date_to'] ) {
			$where[] = 't.created_at <= %s';
			$args[]  = $filters['date_to'] . ' 23:59:59';
		}

		if ( $filters['organization_id'] ) {
			$where[] = 'u.organization_id = %d';
			$args[]  = $filters['organization_id'];
		}

		foreach ( [ 'priority_id', 'category_id', 'ticket_type_id' ] as $field ) {
			if ( $filters[ $field ] ) {
				$where[] = "t.{$field} = %d";
				$args[]  = $filters[ $field ];
			}
		}

		$sql = "SELECT t.*, u.organization_id AS organization_id
			FROM {$tickets_table} t
			LEFT JOIN {$users_table} u ON u.wp_user_id = t.requester_wp_user_id
			WHERE " . implode( ' AND ', $where );

		return $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
	}

	/**
	 * A ticket is "in SLA" if it was resolved by its resolution deadline, or
	 * (still open) has not yet passed that deadline. Returns null when there
	 * is nothing to judge yet (no resolution deadline was ever stamped).
	 */
	private function is_compliant( object $ticket ): ?bool {
		if ( empty( $ticket->sla_resolution_due ) ) {
			return null;
		}

		$due = new \DateTimeImmutable( $ticket->sla_resolution_due, wp_timezone() );

		if ( ! empty( $ticket->resolved_at ) ) {
			return new \DateTimeImmutable( $ticket->resolved_at, wp_timezone() ) <= $due;
		}

		return new \DateTimeImmutable( 'now', wp_timezone() ) <= $due;
	}

	/**
	 * @param array<int, object> $tickets
	 * @return array{compliant: int, breached: int, total: int, rate: ?float}
	 */
	private function compliance_stats( array $tickets ): array {
		$compliant = 0;
		$breached  = 0;

		foreach ( $tickets as $ticket ) {
			$status = $this->is_compliant( $ticket );

			if ( null === $status ) {
				continue;
			}

			$status ? $compliant++ : $breached++;
		}

		$total = $compliant + $breached;

		return [
			'compliant' => $compliant,
			'breached'  => $breached,
			'total'     => $total,
			'rate'      => $total ? round( $compliant / $total * 100, 1 ) : null,
		];
	}

	/**
	 * @param array<int, object> $tickets
	 */
	private function render_summary( array $tickets ): void {
		$stats = $this->compliance_stats( $tickets );

		echo '<h2>' . esc_html__( 'Overall SLA compliance', 'thickgrass' ) . '</h2>';

		if ( ! $stats['total'] ) {
			echo '<p>' . esc_html__( 'No tickets with an SLA match these filters.', 'thickgrass' ) . '</p>';
			return;
		}

		printf(
			'<p><span class="thickgrass-badge thickgrass-badge-green">%1$s%%</span> %2$s (%3$d %4$s, %5$d %6$s, %7$d %8$s)</p>',
			esc_html( (string) $stats['rate'] ),
			esc_html__( 'in SLA', 'thickgrass' ),
			$stats['total'],
			esc_html__( 'total', 'thickgrass' ),
			$stats['compliant'],
			esc_html__( 'compliant', 'thickgrass' ),
			$stats['breached'],
			esc_html__( 'breached', 'thickgrass' )
		);
	}

	/**
	 * @param array<int, object> $tickets
	 * @param array<int, string> $options
	 */
	private function render_breakdown( array $tickets, string $field, array $options, string $title ): void {
		$groups = [];

		foreach ( $tickets as $ticket ) {
			$key              = $ticket->$field ? (int) $ticket->$field : 0;
			$groups[ $key ][] = $ticket;
		}

		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'No data.', 'thickgrass' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Value', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'In SLA', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Breached', 'thickgrass' ) . '</th>';
		echo '<th>' . esc_html__( 'Rate', 'thickgrass' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $groups as $key => $group_tickets ) {
			$label = $key ? ( $options[ $key ] ?? '—' ) : __( 'Unassigned', 'thickgrass' );
			$stats = $this->compliance_stats( $group_tickets );

			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$d</td><td>%4$s</td></tr>',
				esc_html( $label ),
				$stats['compliant'],
				$stats['breached'],
				null === $stats['rate'] ? '—' : esc_html( $stats['rate'] . '%' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array{date_from: string, date_to: string, organization_id: ?int, priority_id: ?int, category_id: ?int, ticket_type_id: ?int} $filters
	 */
	private function render_filter_form( array $filters ): void {
		echo '<form method="get" style="margin:12px 0;display:flex;gap:12px;align-items:end;flex-wrap:wrap;">';
		echo '<input type="hidden" name="page" value="thickgrass-sla-reports" />';

		printf(
			'<label>%1$s<br /><input type="date" name="date_from" value="%2$s" /></label>',
			esc_html__( 'From', 'thickgrass' ),
			esc_attr( $filters['date_from'] )
		);

		printf(
			'<label>%1$s<br /><input type="date" name="date_to" value="%2$s" /></label>',
			esc_html__( 'To', 'thickgrass' ),
			esc_attr( $filters['date_to'] )
		);

		$this->render_filter_select( 'organization_id', __( 'Organization', 'thickgrass' ), Admin_Helpers::organization_options(), $filters['organization_id'] );
		$this->render_filter_select( 'priority_id', __( 'Priority', 'thickgrass' ), Admin_Helpers::choice_options( 'priority' ), $filters['priority_id'] );
		$this->render_filter_select( 'category_id', __( 'Category', 'thickgrass' ), Admin_Helpers::choice_options( 'category' ), $filters['category_id'] );
		$this->render_filter_select( 'ticket_type_id', __( 'Ticket type', 'thickgrass' ), Admin_Helpers::choice_options( 'ticket_type' ), $filters['ticket_type_id'] );

		submit_button( __( 'Filter', 'thickgrass' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * @param array{date_from: string, date_to: string, organization_id: ?int, priority_id: ?int, category_id: ?int, ticket_type_id: ?int} $filters
	 */
	private function render_export_link( array $filters ): void {
		$args = array_merge(
			array_filter( $filters, static fn( $value ) => null !== $value && '' !== $value ),
			[ 'page' => 'thickgrass-sla-reports', 'export' => 'csv' ]
		);

		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Export to CSV (Excel)', 'thickgrass' )
		);
	}

	/**
	 * One row per matching ticket, respecting the current filters - a CSV
	 * opens directly in Excel, no extra library needed for a WordPress plugin.
	 *
	 * @param array<int, object> $tickets
	 */
	private function export_csv( array $tickets ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sla-report-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$organizations = Admin_Helpers::organization_options();
		$priorities    = Admin_Helpers::choice_options( 'priority' );
		$categories    = Admin_Helpers::choice_options( 'category' );
		$ticket_types  = Admin_Helpers::choice_options( 'ticket_type' );

		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, [
			__( 'Ticket number', 'thickgrass' ),
			__( 'Organization', 'thickgrass' ),
			__( 'Priority', 'thickgrass' ),
			__( 'Category', 'thickgrass' ),
			__( 'Ticket type', 'thickgrass' ),
			__( 'Created', 'thickgrass' ),
			__( 'Resolved', 'thickgrass' ),
			__( 'Resolution due', 'thickgrass' ),
			__( 'Compliance', 'thickgrass' ),
		] );

		foreach ( $tickets as $ticket ) {
			$status = $this->is_compliant( $ticket );

			fputcsv( $out, [
				$ticket->ticket_number,
				$ticket->organization_id ? ( $organizations[ (int) $ticket->organization_id ] ?? '' ) : '',
				$ticket->priority_id ? ( $priorities[ (int) $ticket->priority_id ] ?? '' ) : '',
				$ticket->category_id ? ( $categories[ (int) $ticket->category_id ] ?? '' ) : '',
				$ticket->ticket_type_id ? ( $ticket_types[ (int) $ticket->ticket_type_id ] ?? '' ) : '',
				$ticket->created_at,
				$ticket->resolved_at ?: '',
				$ticket->sla_resolution_due,
				null === $status ? '' : ( $status ? __( 'In SLA', 'thickgrass' ) : __( 'Breached', 'thickgrass' ) ),
			] );
		}

		fclose( $out );
	}

	/**
	 * @param array<int, string> $options
	 */
	private function render_filter_select( string $name, string $label, array $options, ?int $selected ): void {
		printf( '<label>%1$s<br /><select name="%2$s"><option value="">%3$s</option>', esc_html( $label ), esc_attr( $name ), esc_html__( 'Any', 'thickgrass' ) );

		foreach ( $options as $id => $option_label ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', $id, selected( $selected, $id, false ), esc_html( $option_label ) );
		}

		echo '</select></label>';
	}
}
