<?php

namespace ThickGrass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Reusable list table for any ThickGrass entity.
 * Configured through a column array + a callback that fetches the rows,
 * instead of a brand new WP_List_Table class per entity (see PLAN.md 4.1).
 */
class Generic_List_Table extends \WP_List_Table {

	/** @var array<string, string> column key => label */
	private array $columns_config;

	/** @var callable(): array */
	private $data_provider;

	private string $primary_key;

	/** @var null|callable(object|array $item): array<string, string> label => url */
	private $row_actions_provider;

	public function __construct( array $args ) {
		parent::__construct( [
			'singular' => $args['singular'],
			'plural'   => $args['plural'],
			'ajax'     => false,
		] );

		$this->columns_config       = $args['columns'];
		$this->data_provider        = $args['data_provider'];
		$this->primary_key          = $args['primary_key'] ?? 'id';
		$this->row_actions_provider = $args['row_actions'] ?? null;
	}

	public function get_columns(): array {
		return array_merge( [ 'cb' => '<input type="checkbox" />' ], $this->columns_config );
	}

	public function column_cb( $item ): string {
		$id = $this->get_field( $item, $this->primary_key );

		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', $id );
	}

	public function column_default( $item, $column_name ): string {
		return esc_html( (string) $this->get_field( $item, $column_name ) );
	}

	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $column_name !== $primary || ! $this->row_actions_provider ) {
			return '';
		}

		$actions = call_user_func( $this->row_actions_provider, $item );

		return $this->row_actions( $actions );
	}

	public function get_bulk_actions(): array {
		return [ 'delete' => __( 'Delete', 'thickgrass' ) ];
	}

	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = [];

		$this->_column_headers = [ $columns, $hidden, $sortable ];
		$this->items           = call_user_func( $this->data_provider );
	}

	/**
	 * @param object|array $item
	 * @return mixed
	 */
	private function get_field( $item, string $key ) {
		return is_object( $item ) ? ( $item->$key ?? '' ) : ( $item[ $key ] ?? '' );
	}
}
