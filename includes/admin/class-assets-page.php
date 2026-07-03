<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Assets CRUD - just enough for a ticket to reference one (see
 * PLAN.md: ticket form "Asset" field). A full CMDB with asset-to-asset
 * relationships stays Faza 3; this is the simple "name/type/owner" version
 * already described in the Faza 1 checklist.
 */
class Assets_Page extends Abstract_CRUD_Page {

	protected function table_suffix(): string {
		return 'thickgrass_assets';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-assets';
	}

	protected function page_title(): string {
		return __( 'Assets', 'thickgrass' );
	}

	/**
	 * Embedded as a tab inside "Configurable lists" (PLAN.md) rather than
	 * owning its own top-level admin page - see class-choices-page.php.
	 */
	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'assets' ];
	}

	protected function fields(): array {
		return [
			[ 'key' => 'name', 'label' => __( 'Name', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'asset_type_id', 'label' => __( 'Type', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'asset_type_options' ] ],
			[ 'key' => 'owner_wp_user_id', 'label' => __( 'Owner', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'wp_users_options' ] ],
			[ 'key' => 'organization_id', 'label' => __( 'Organization', 'thickgrass' ), 'type' => 'select', 'options' => [ Admin_Helpers::class, 'organization_options' ] ],
			[ 'key' => 'description', 'label' => __( 'Description', 'thickgrass' ), 'type' => 'textarea' ],
			[ 'key' => 'is_active', 'label' => __( 'Active', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'name'         => __( 'Name', 'thickgrass' ),
			'type'         => __( 'Type', 'thickgrass' ),
			'owner'        => __( 'Owner', 'thickgrass' ),
			'organization' => __( 'Organization', 'thickgrass' ),
			'is_active'    => __( 'Active', 'thickgrass' ),
		];
	}

	/**
	 * @return array<int, string>
	 */
	public function asset_type_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'asset_type' ) as $choice ) {
			$options[ $choice->id ] = $choice->label;
		}

		return $options;
	}

	protected function get_display_rows(): array {
		$rows          = parent::get_display_rows();
		$types         = $this->asset_type_options();
		$organizations = Admin_Helpers::organization_options();

		foreach ( $rows as $row ) {
			$owner = $row->owner_wp_user_id ? get_userdata( (int) $row->owner_wp_user_id ) : false;

			$row->type         = $row->asset_type_id ? ( $types[ (int) $row->asset_type_id ] ?? '—' ) : '—';
			$row->owner        = $owner ? $owner->display_name : '—';
			$row->organization = $row->organization_id ? ( $organizations[ (int) $row->organization_id ] ?? '—' ) : '—';
			$row->is_active    = $row->is_active ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}
}
