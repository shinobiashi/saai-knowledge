<?php
/**
 * Serves saai_faq/saai_kb/saai_glossary singular pages as clean Markdown via
 * `?format=markdown` (docs/DESIGN.md section 7.3).
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `format` query var and, on template_redirect, short-circuits
 * a matching singular request with a Markdown response instead of the theme
 * template.
 */
final class Markdown_Output {

	/**
	 * Post types this feature applies to.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'saai_faq', 'saai_kb', 'saai_glossary' );

	/**
	 * Transient key prefix — see cache_key() for the full key shape
	 * (saai_markdown_{post ID}_{dictionary generation}) and flush_cache()
	 * for why it's invalidated explicitly on save/trash rather than by
	 * folding the post's modified time into the key itself (Codex review:
	 * that approach missed a second edit within the same second, since
	 * get_post_modified_time( 'U', ... ) only has second-level resolution).
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'saai_markdown_';

	/**
	 * The globals WP_Query::setup_postdata() mutates besides $post —
	 * snapshotted and restored around each render() so that a caller other
	 * than maybe_serve() (which currently always exit()s right after) can't
	 * leak this post's postdata into whatever runs afterward. Same list,
	 * same reasoning, as Faq_List::POSTDATA_GLOBALS.
	 *
	 * @var string[]
	 */
	private const POSTDATA_GLOBALS = array(
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * Hooks the query var, the template_redirect short-circuit, and cache
	 * invalidation into WordPress.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );

		add_action( 'save_post_saai_faq', array( $this, 'flush_cache' ) );
		add_action( 'save_post_saai_kb', array( $this, 'flush_cache' ) );
		add_action( 'save_post_saai_glossary', array( $this, 'flush_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_cache' ) );
		// wp_delete_post( $id, true ) (REST's force=true, `wp post delete
		// --force`) skips wp_trash_post() entirely, so trashed_post never
		// fires — only deleted_post does (Copilot review; Llms_Index and
		// Autolinker::handle_post_deleted() already need the same second
		// hook for the same reason). Without it, a force-deleted post's
		// Markdown transient would sit unreachable but uncollected for up
		// to DAY_IN_SECONDS.
		add_action( 'deleted_post', array( $this, 'flush_cache' ) );
	}

	/**
	 * Deletes one post's cached Markdown. Hooked to save/trash/delete of the
	 * three content post types (see register()); a stale cache otherwise
	 * only self-heals when some other post edit happens to touch the same
	 * transient (it never will, since the key is now per-post).
	 *
	 * @param int $post_id The post whose cache entry to clear.
	 */
	public function flush_cache( int $post_id ): void {
		delete_transient( self::cache_key( $post_id ) );
	}

	/**
	 * The transient key for one post's cached Markdown — shared by
	 * render_cached() and flush_cache() so they can never drift apart.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function cache_key( int $post_id ): string {
		return self::CACHE_PREFIX . $post_id . '_' . (int) get_option( 'saai_dict_generation', 1 );
	}

	/**
	 * Whitelists `format` as a public query var; without this WordPress
	 * drops it from $wp->query_vars and get_query_var( 'format' ) never
	 * sees it, even though it's present in $_GET.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'format';

		return $vars;
	}

	/**
	 * Serves the Markdown response and exits when the current request is a
	 * `?format=markdown` request for one of self::POST_TYPES; otherwise a
	 * no-op, letting the normal template load.
	 */
	public function maybe_serve(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		if ( 'markdown' !== get_query_var( 'format' ) ) {
			return;
		}

		if ( ! is_singular( self::POST_TYPES ) ) {
			return;
		}

		$post = get_queried_object();

		// Mirrors Search::results()'s fixed post_status: this is reachable
		// by an unauthenticated request, so only ever serve published
		// content, regardless of what is_singular()'s main query resolved
		// (e.g. a logged-in editor's private-post preview).
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		// A password-protected post normally has its content replaced with
		// get_the_password_form() by the theme template; without this check
		// this endpoint would instead hand out the real post_content
		// Markdown to anyone with the URL, bypassing that protection
		// entirely (same check Glossary_Term::append_after_definition_hook()
		// makes for the same reason).
		if ( post_password_required( $post ) ) {
			return;
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_cached()/render() build a plain-text Markdown document (not HTML) for a text/markdown response; see render()'s docblock.
		echo $this->render_cached( $post );
		exit;
	}

	/**
	 * Returns the cached Markdown for a post, building and caching it on a
	 * miss.
	 *
	 * The transient this caches into is shared site-wide across every
	 * visitor that uses it, but render()'s `apply_filters( 'the_content', ... )`
	 * call runs the same core the_content chain (do_shortcode(), do_blocks())
	 * a theme template would — and post_content, being ordinary block-editor
	 * content, can legitimately contain a shortcode/block whose output
	 * varies by viewer: not just by login state, but by anything an
	 * anonymous visitor's own cookies drive (a cart, a language switcher, a
	 * geo/currency preference). Caching and replaying one such visitor's
	 * render to every other visitor for up to a day would leak whatever
	 * that render exposed. A request that carries *any* cookie at all —
	 * logged in or not — therefore bypasses the shared cache entirely, both
	 * reading and writing it, the same heuristic full-page-cache plugins
	 * (WP Super Cache et al.) use to decide a request is safe to serve from
	 * a shared cache (Codex review: an earlier version of this check only
	 * looked at is_user_logged_in()). This plugin's actual target audience
	 * for ?format=markdown — AI/RAG crawlers — overwhelmingly send no
	 * cookies at all, so the cache still serves its purpose for them.
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string
	 */
	public function render_cached( \WP_Post $post ): string {
		if ( ! empty( $_COOKIE ) ) {
			return $this->render( $post );
		}

		// render()'s the_content pass includes whatever Autolinker::process()
		// inserted — tooltip links to saai_glossary terms this post's content
		// happens to mention. cache_key() folds Autolinker's own dictionary
		// generation counter in, so a glossary term rename/delete (which
		// bumps that counter, per Settings::finalize_slugs()/
		// Autolinker::handle_glossary_saved()) naturally busts this cache
		// too, instead of a post_id-only key serving stale term names/URLs
		// for up to a day after the dictionary itself already moved on
		// (Codex review).
		$key    = self::cache_key( $post->ID );
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$markdown = $this->render( $post );

		set_transient( $key, $markdown, DAY_IN_SECONDS );

		return $markdown;
	}

	/**
	 * Builds the Markdown document for a single post: an H1 title, a short
	 * meta block, and the body content converted from its rendered HTML.
	 *
	 * The setup_postdata() call is needed because template_redirect fires before the
	 * main Loop's the_post() has populated the global $post/$id family that
	 * shortcode/block rendering (do_shortcode(), do_blocks()) can implicitly
	 * depend on. maybe_serve() (this method's only current caller) exits
	 * right after calling it, so the leaked globals never actually reach
	 * anything else in practice today — but render()/render_cached() are
	 * public, and DESIGN.md section 7.4's planned RAG export is documented
	 * (see Markdown_Converter's own class docblock) to reuse this same
	 * rendering for many posts in one request, which would neither exit
	 * between posts nor want the last one's postdata bleeding into the
	 * next. The full previous postdata state (POSTDATA_GLOBALS, not just
	 * $post) is snapshotted and restored in a finally block, the same
	 * pattern Faq_List::render_answer() already uses for the identical
	 * reason (Copilot review).
	 *
	 * @param \WP_Post    $post         The post to render.
	 * @param string|null $content_html Already-rendered (`the_content`-filtered)
	 *                                  HTML, when a caller (Export::build_record(),
	 *                                  which needs this same content for its own
	 *                                  content_plain/sections fields too) has
	 *                                  already produced it — reused as-is instead
	 *                                  of applying `the_content` a second time.
	 *                                  A stateful shortcode/dynamic block (a view
	 *                                  counter, a "random related post" pick)
	 *                                  would otherwise run twice per record, and
	 *                                  any handler whose output depends on call
	 *                                  count would make content_markdown disagree
	 *                                  with content_plain/sections for the same
	 *                                  record (Codex review). Null (the default)
	 *                                  preserves the original behavior for every
	 *                                  other existing caller (maybe_serve()).
	 * @return string
	 */
	public function render( \WP_Post $post, ?string $content_html = null ): string {
		$previous_post    = $GLOBALS['post'] ?? null;
		$previous_globals = array();

		foreach ( self::POSTDATA_GLOBALS as $var ) {
			$previous_globals[ $var ] = $GLOBALS[ $var ] ?? null;
		}

		setup_postdata( $post );

		try {
			$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
			$title = Markdown_Converter::escape_text( str_replace( array( "\r", "\n" ), ' ', $title ) );

			$lines = array( '# ' . $title, '' );
			$meta  = $this->meta_lines( $post );

			if ( $meta ) {
				$lines = array_merge( $lines, $meta, array( '' ) );
			}

			if ( null === $content_html ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own the_content filter (as WP_REST_Posts_Controller does), not defining a new hook.
				$content_html = apply_filters( 'the_content', $post->post_content );
				$content_html = is_string( $content_html ) ? $content_html : '';
			}

			$lines[] = Markdown_Converter::convert( $content_html );

			$markdown = trim( implode( "\n", $lines ) ) . "\n";

			/**
			 * Filters the `?format=markdown` output for a single FAQ/KB/glossary
			 * page.
			 *
			 * @since 0.5.0
			 *
			 * @param string   $markdown The rendered Markdown document.
			 * @param \WP_Post $post     The post being rendered.
			 */
			$filtered = apply_filters( 'saai_markdown_output', $markdown, $post );

			// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_markdown_output callback can violate it at runtime.)
			return is_string( $filtered ) ? $filtered : $markdown;
		} finally {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the exact pre-render value saved above.

			foreach ( $previous_globals as $var => $value ) {
				$GLOBALS[ $var ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring the exact pre-render values of WordPress's own postdata globals saved above.
			}
		}
	}

	/**
	 * The short meta block placed between the title and the body: canonical
	 * URL, last-updated date, and — for FAQ/KB, which use saai_category —
	 * its category names.
	 *
	 * @param \WP_Post $post The post being rendered.
	 * @return string[]
	 */
	private function meta_lines( \WP_Post $post ): array {
		$lines = array();
		$url   = get_permalink( $post );

		if ( is_string( $url ) && '' !== $url ) {
			$lines[] = '**URL:** ' . $url;
		}

		$modified = get_the_modified_date( 'Y-m-d', $post );

		if ( is_string( $modified ) && '' !== $modified ) {
			$lines[] = '**Updated:** ' . $modified;
		}

		if ( in_array( $post->post_type, array( 'saai_faq', 'saai_kb' ), true ) ) {
			$terms = get_the_terms( $post, 'saai_category' );

			if ( is_array( $terms ) && $terms ) {
				// A term name is as editable/arbitrary as a post title —
				// escape it the same way (Copilot review).
				$names   = array_map( array( Markdown_Converter::class, 'escape_text' ), wp_list_pluck( $terms, 'name' ) );
				$lines[] = '**Category:** ' . implode( ', ', $names );
			}
		}

		return $lines;
	}

	/**
	 * Whether the AI-readability feature (Markdown output + llms.txt) is
	 * enabled. Same defensive get_option() read pattern as
	 * Breadcrumbs::structured_data_enabled() — see that method's docblock.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( ! is_array( $settings ) || ! array_key_exists( 'ai_readability_enabled', $settings ) ) {
			return true;
		}

		return (bool) $settings['ai_readability_enabled'];
	}
}
