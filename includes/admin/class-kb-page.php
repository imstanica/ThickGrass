<?php

namespace ThickGrass\Admin;

use ThickGrass\Choices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Base article authoring - full CRUD via `Abstract_CRUD_Page`,
 * embedded as a "Configurable lists" tab (PLAN.md 7.46: "muta si
 * Knowledgebase in Configurable lists") rather than its own top-level menu
 * item, same pattern as Canned_Responses_Page. Public-facing read side lives
 * in `ThickGrass\Kb_Article` / `Shortcodes::render_kb()`.
 */
class Kb_Page extends Abstract_CRUD_Page {

	protected function table_suffix(): string {
		return 'thickgrass_kb_articles';
	}

	protected function capability(): string {
		return 'thickgrass_manage';
	}

	protected function page_slug(): string {
		return 'thickgrass-kb';
	}

	protected function base_url_args(): array {
		return [ 'page' => 'thickgrass-choices', 'list_key' => 'kb_articles' ];
	}

	protected function page_title(): string {
		return __( 'Knowledge Base', 'thickgrass' );
	}

	protected function fields(): array {
		return [
			[ 'key' => 'title', 'label' => __( 'Title', 'thickgrass' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'content', 'label' => __( 'Content', 'thickgrass' ), 'type' => 'wysiwyg' ],
			[ 'key' => 'category_id', 'label' => __( 'Category', 'thickgrass' ), 'type' => 'select', 'options' => [ $this, 'category_options' ] ],
			[ 'key' => 'tags', 'label' => __( 'Tags (comma-separated)', 'thickgrass' ), 'type' => 'text' ],
			[ 'key' => 'is_published', 'label' => __( 'Published', 'thickgrass' ), 'type' => 'checkbox', 'default' => true ],
		];
	}

	protected function list_columns(): array {
		return [
			'title'        => __( 'Title', 'thickgrass' ),
			'category'     => __( 'Category', 'thickgrass' ),
			'tags'         => __( 'Tags', 'thickgrass' ),
			'is_published' => __( 'Published', 'thickgrass' ),
		];
	}

	protected function get_display_rows(): array {
		$rows = parent::get_display_rows();

		foreach ( $rows as $row ) {
			$category      = $row->category_id ? $this->category_options()[ (int) $row->category_id ] ?? null : null;
			$row->category = $category ?: '—';
			$row->is_published_raw = (bool) $row->is_published;
			$row->is_published = $row->is_published ? __( 'Yes', 'thickgrass' ) : __( 'No', 'thickgrass' );
		}

		return $rows;
	}

	/**
	 * Public (not protected) - passed as an `[$this, 'category_options']`
	 * callable into Generic_Form, same reasoning as
	 * Abstract_CRUD_Page::wp_users_options().
	 *
	 * @return array<int, string> kb_category choice id => label
	 */
	public function category_options(): array {
		$options = [];

		foreach ( Choices::get_list( 'kb_category' ) as $category ) {
			$options[ $category->id ] = $category->label;
		}

		return $options;
	}

	/**
	 * The shortcode is global (one Knowledge Base for the whole site, unlike
	 * per-form Custom Forms shortcodes), so it's shown once above the list
	 * rather than per-row (PLAN.md 7.46: "dupa generarea lui lipseste
	 * shortcodurile pentru a fi plasate in front-end" - already auto-placed
	 * on its own portal page at activation, but shown here too for embedding
	 * it anywhere else).
	 */
	protected function render_extra( ?object $editing ): void {
		Admin_Helpers::render_shortcode_box( '[thickgrass_kb]' );
	}

	protected function insert_row( array $data ): int {
		$data['updated_at'] = current_time( 'mysql' );

		return parent::insert_row( $data );
	}

	protected function update_row( int $id, array $data ): void {
		$data['updated_at'] = current_time( 'mysql' );

		parent::update_row( $id, $data );
	}
}
