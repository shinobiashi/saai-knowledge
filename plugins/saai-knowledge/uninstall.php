<?php
/**
 * Fires on plugin deletion via the WordPress admin.
 *
 * Removes plugin data only when explicitly enabled via the
 * `delete_data_on_uninstall` key of the `saai_knowledge_settings` option
 * (docs/DESIGN.md section 3.4); otherwise every FAQ, KB article, glossary
 * term, category, and setting is left untouched.
 *
 * @package SAAI\Knowledge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$saai_uninstall_settings = get_option( 'saai_knowledge_settings' );

if ( ! is_array( $saai_uninstall_settings ) || empty( $saai_uninstall_settings['delete_data_on_uninstall'] ) ) {
	return;
}

// WordPress only includes this file directly (WP_UNINSTALL_PLUGIN), never the
// plugin's main file, so `saai_faq`/`saai_kb`/`saai_glossary`/`saai_category`/
// `saai_tag` were never registered for this request. get_posts()/get_terms()
// need them registered first — same reasoning as Plugin::activate() directly
// calling register_post_types()/register_taxonomies() instead of waiting for
// `init`, which has already fired by the time this file runs.
if ( ! class_exists( '\SAAI\Knowledge\Post_Types' ) ) {
	require __DIR__ . '/includes/class-post-types.php';
}

if ( ! class_exists( '\SAAI\Knowledge\Taxonomies' ) ) {
	require __DIR__ . '/includes/class-taxonomies.php';
}

( new \SAAI\Knowledge\Post_Types() )->register_post_types();
( new \SAAI\Knowledge\Taxonomies() )->register_taxonomies();

foreach ( array( 'saai_faq', 'saai_kb', 'saai_glossary' ) as $saai_uninstall_post_type ) {
	$saai_uninstall_post_ids = get_posts(
		array(
			'post_type'      => $saai_uninstall_post_type,
			// 'any' expands to "every status not registered exclude_from_search",
			// which excludes 'trash' (WordPress core registers it that way) —
			// pass every registered status explicitly so a trashed FAQ/KB/term
			// isn't left behind.
			'post_status'    => array_keys( get_post_stati() ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $saai_uninstall_post_ids as $saai_uninstall_post_id ) {
		wp_delete_post( $saai_uninstall_post_id, true );
	}
}

foreach ( array( 'saai_category', 'saai_tag' ) as $saai_uninstall_taxonomy ) {
	$saai_uninstall_term_ids = get_terms(
		array(
			'taxonomy'   => $saai_uninstall_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! is_array( $saai_uninstall_term_ids ) ) {
		continue;
	}

	foreach ( $saai_uninstall_term_ids as $saai_uninstall_term_id ) {
		wp_delete_term( $saai_uninstall_term_id, $saai_uninstall_taxonomy );
	}
}

foreach (
	array(
		'saai_knowledge_settings',
		'saai_autolink_dict',
		'saai_dict_generation',
		'saai_autolink_dict_truncated',
		'saai_flush_rewrite_rules',
	) as $saai_uninstall_option
) {
	delete_option( $saai_uninstall_option );
}

// On a persistent object cache (Redis/Memcached), Autolinker's saai_autolink
// cache group (dictionary + processed-HTML entries, both wp_cache_set()) would
// otherwise survive this deletion. A later reinstall starts saai_dict_generation
// back at 1 — the same value a low-traffic site would still have been on at
// deletion time — so a stale entry could be served again, re-inserting links to
// now-deleted glossary terms. wp_cache_flush_group() is a WP 6.1+ core function;
// this plugin requires WP 6.9+.
wp_cache_flush_group( 'saai_autolink' );
