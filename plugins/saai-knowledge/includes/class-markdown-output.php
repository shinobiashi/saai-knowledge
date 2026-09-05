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
	 * Transient key prefix. The post's own modified timestamp is folded into
	 * the key (see render_cached()) so an edit invalidates the cache by
	 * simply changing the key, without needing a save_post hook.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'saai_markdown_';

	/**
	 * Hooks the query var and the template_redirect short-circuit into
	 * WordPress.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
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
	 * anonymous visitor, but render()'s `apply_filters( 'the_content', ... )`
	 * call runs the same core the_content chain (do_shortcode(), do_blocks())
	 * a theme template would — and post_content, being ordinary block-editor
	 * content, can legitimately contain a shortcode/block whose output
	 * varies by viewer (e.g. a login-state-dependent block, or one that
	 * reveals more to a user with elevated capabilities). Caching and
	 * replaying a logged-in user's render for every subsequent anonymous
	 * visitor would leak whatever that render exposed. Logged-in requests
	 * therefore bypass the shared cache entirely — both reading and writing
	 * it — so the cache is only ever populated by, and served to, genuinely
	 * anonymous renders.
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string
	 */
	public function render_cached( \WP_Post $post ): string {
		if ( is_user_logged_in() ) {
			return $this->render( $post );
		}

		$key    = self::CACHE_PREFIX . $post->ID . '_' . get_post_modified_time( 'U', true, $post );
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
	 * The setup_postdata() call is deliberately never restored — this
	 * method is only ever reached from maybe_serve(), which exits right
	 * after — because template_redirect fires before the main Loop's
	 * the_post() has populated the global $post/$id family that
	 * shortcode/block rendering (do_shortcode(), do_blocks()) can implicitly
	 * depend on.
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string
	 */
	public function render( \WP_Post $post ): string {
		setup_postdata( $post );

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );

		$lines = array( '# ' . $title, '' );
		$meta  = $this->meta_lines( $post );

		if ( $meta ) {
			$lines = array_merge( $lines, $meta, array( '' ) );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own the_content filter (as WP_REST_Posts_Controller does), not defining a new hook.
		$content_html = apply_filters( 'the_content', $post->post_content );
		$lines[]      = Markdown_Converter::convert( is_string( $content_html ) ? $content_html : '' );

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
				$lines[] = '**Category:** ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
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
