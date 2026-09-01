<?php
/**
 * Custom post types: `site` (Heritage Site) and `partner` (Partner/Voucher).
 *
 * Field contract: GR_Content_Model_Field_List_v1.md.
 * - `id`   → post slug (feed merges by it)
 * - `name` → post title
 * - `wpId` (partner) → native post ID, no meta needed
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Post_Types {

	/**
	 * Register both CPTs. Hooked to `init`.
	 */
	public static function register() {
		self::register_site();
		self::register_partner();
	}

	/**
	 * CPT `site` — every trail stop, Blue Plaques included (bp flag, not a
	 * separate type). `page-attributes` enables menu_order alongside the
	 * explicit `trailNum` meta.
	 */
	private static function register_site() {
		register_post_type(
			'site',
			array(
				'labels'       => array(
					'name'          => __( 'Heritage Sites', 'cha' ),
					'singular_name' => __( 'Heritage Site', 'cha' ),
					'add_new_item'  => __( 'Add New Heritage Site', 'cha' ),
					'edit_item'     => __( 'Edit Heritage Site', 'cha' ),
					'search_items'  => __( 'Search Heritage Sites', 'cha' ),
					'not_found'     => __( 'No heritage sites found.', 'cha' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-location-alt',
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'heritage-site' ),
				'supports'     => array( 'title', 'page-attributes' ),
			)
		);
	}

	/**
	 * CPT `partner` — partner-programme businesses and their voucher offers.
	 */
	private static function register_partner() {
		register_post_type(
			'partner',
			array(
				'labels'       => array(
					'name'          => __( 'Partners', 'cha' ),
					'singular_name' => __( 'Partner', 'cha' ),
					'add_new_item'  => __( 'Add New Partner', 'cha' ),
					'edit_item'     => __( 'Edit Partner', 'cha' ),
					'search_items'  => __( 'Search Partners', 'cha' ),
					'not_found'     => __( 'No partners found.', 'cha' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-store',
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'partner' ),
				'supports'     => array( 'title' ),
			)
		);
	}
}
