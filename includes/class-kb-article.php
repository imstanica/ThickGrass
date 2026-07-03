<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Base articles (PLAN.md 7.43, Faza 2) - authored from the admin
 * screen (`Admin\Kb_Page`, full CRUD via `Abstract_CRUD_Page`), read here for
 * the public-facing side (`Shortcodes::render_kb()`). Deliberately public -
 * no login required, unlike the rest of the end-user portal.
 */
class Kb_Article {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_kb_articles';
	}

	/**
	 * Only ever returns a PUBLISHED article - a direct link to a draft (e.g.
	 * a guessed/old ?kb_article=<id>) must 404 the same as one that doesn't
	 * exist at all.
	 */
	public static function get_published( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND is_published = 1',
			$id
		) );

		return $row ?: null;
	}

	/**
	 * Simple substring match on title/content/tags - no full-text search
	 * engine, deliberately minimal (PLAN.md: "cat mai putin cod").
	 *
	 * @return array<int, object>
	 */
	public static function search( string $term = '', ?int $category_id = null ): array {
		global $wpdb;

		$where = [ 'is_published = 1' ];
		$args  = [];

		if ( '' !== $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = '(title LIKE %s OR content LIKE %s OR tags LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		if ( $category_id ) {
			$where[] = 'category_id = %d';
			$args[]  = $category_id;
		}

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY title ASC';

		return $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
	}
}
