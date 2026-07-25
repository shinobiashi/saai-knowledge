<?php
/**
 * Resolves single, archive, and taxonomy templates for the content post types.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers block-theme templates and falls back to bundled PHP templates on classic themes.
 */
final class Template_Loader {

	/**
	 * Template slugs mapped to how they're matched against the current request.
	 *
	 * `match` is one of 'singular', 'post_type_archive', or 'taxonomy'; `target`
	 * is the post type or taxonomy that `match` is checked against.
	 *
	 * @var array<string, array{match: string, target: string}>
	 */
	private const TEMPLATES = array(
		'single-saai_kb'         => array(
			'match'  => 'singular',
			'target' => 'saai_kb',
		),
		'single-saai_faq'        => array(
			'match'  => 'singular',
			'target' => 'saai_faq',
		),
		'single-saai_glossary'   => array(
			'match'  => 'singular',
			'target' => 'saai_glossary',
		),
		'archive-saai_kb'        => array(
			'match'  => 'post_type_archive',
			'target' => 'saai_kb',
		),
		'taxonomy-saai_category' => array(
			'match'  => 'taxonomy',
			'target' => 'saai_category',
		),
	);

	/**
	 * Post types the KB two-column layout stylesheet applies to.
	 *
	 * @var string[]
	 */
	private const LAYOUT_STYLE_POST_TYPES = array( 'saai_kb' );

	/**
	 * Plugin identifier passed as the register_block_template() namespace,
	 * and matched back against WP_Block_Template::$plugin to recognize the
	 * plugin's own block templates specifically (see
	 * plugin_taxonomy_template_wins()).
	 *
	 * @var string
	 */
	private const PLUGIN_SLUG = 'saai-knowledge';

	/**
	 * Output buffered from the saai_kb_before_article action, captured in
	 * fire_before_article_hook() and consumed by wrap_kb_article_content()
	 * once it confirms the article's own core/post-content is rendering
	 * (not some other, nested one — see that method for why).
	 *
	 * A hooked callback that echoes markup — the ordinary WordPress
	 * convention for an insertion-point action — would otherwise write
	 * straight to the output stream from inside pre_render_block(), landing
	 * wherever do_blocks() happens to be in assembling the surrounding
	 * template rather than next to the article body its name promises.
	 *
	 * @var string|null
	 */
	private $before_article_output = null;

	/**
	 * How many core/post-content blocks are currently being rendered,
	 * counting from pre_render_block (fire_before_article_hook()) to
	 * render_block_core/post-content (wrap_kb_article_content()).
	 *
	 * The core/post-content render callback applies the_content, the same
	 * filter wrap_kb_article_content_classic() hooks for classic themes; a
	 * nonzero depth here means the_content is firing as part of that block's
	 * render (whether the outer article's own, or a nested one from a Query
	 * Loop the article embeds), so wrap_kb_article_content_classic() must
	 * defer to the block-specific hooks already covering it rather than
	 * firing a second time.
	 *
	 * @var int
	 */
	private $post_content_render_depth = 0;

	/**
	 * Output buffered from the saai_kb_before_article action for a classic
	 * theme's rendering, captured in buffer_before_article_hook_classic()
	 * and consumed by wrap_kb_article_content_classic() — the the_content
	 * equivalent of $before_article_output, kept as its own property since
	 * the two mechanisms run independently (see
	 * wrap_kb_article_content_classic()'s docblock).
	 *
	 * @var string|null
	 */
	private $classic_before_article_output = null;

	/**
	 * Hooks template resolution into WordPress.
	 */
	public function register(): void {
		if ( wp_is_block_theme() ) {
			add_action( 'init', array( $this, 'register_block_templates' ) );
		}

		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_layout_style' ) );
		add_action( 'pre_get_posts', array( $this, 'restrict_category_archive_to_kb' ) );
		add_filter( 'pre_render_block', array( $this, 'fire_before_article_hook' ), 10, 2 );
		add_filter( 'render_block_core/post-content', array( $this, 'wrap_kb_article_content' ), 10, 3 );

		// Fires the same insertion points for classic themes via the_content
		// rather than the bundled classic template's own do_action() calls,
		// so a theme (or the saai_template filter) overriding that template
		// file still gets them, as long as it renders the body the standard
		// way via the_content(). Registered unconditionally, not gated on
		// wp_is_block_theme(): core/post-content's own render applies
		// the_content too (see wrap_kb_article_content_classic()'s docblock),
		// so this can't tell classic and block themes apart by theme type
		// alone — $post_content_render_depth is what actually prevents it
		// from double-firing when that happens. Split into an early buffer
		// (priority 1, before wpautop/do_blocks/etc.) and a late wrap
		// (PHP_INT_MAX, after them): firing saai_kb_before_article early
		// mirrors fire_before_article_hook()'s pre_render_block timing on
		// block themes, so an add-on that registers its own the_content
		// filter from within that action still gets to affect this same
		// content pass, rather than the filter surviving unused into later,
		// unrelated the_content calls.
		add_filter( 'the_content', array( $this, 'buffer_before_article_hook_classic' ), 1 );
		add_filter( 'the_content', array( $this, 'wrap_kb_article_content_classic' ), PHP_INT_MAX );
	}

	/**
	 * Registers a block template for each entry in self::TEMPLATES.
	 *
	 * Only hooked on block themes (see register()); a classic theme never
	 * resolves these, so registering them there would just be wasted work
	 * on every request.
	 *
	 * Site owners on a block theme can override these from the Site Editor;
	 * a theme-provided template of the same name always takes priority.
	 */
	public function register_block_templates(): void {
		foreach ( self::TEMPLATES as $slug => $spec ) {
			register_block_template(
				self::PLUGIN_SLUG . "//{$slug}",
				array(
					'title'       => $this->template_title( $slug ),
					'description' => __( 'Template provided by SAAI Knowledge. Customize it from the Site Editor.', 'saai-knowledge' ),
					'content'     => $this->localize_template_content( $this->read_bundled_asset( "block-templates/{$slug}.html" ) ),
				)
			);
		}
	}

	/**
	 * Resolves the classic-theme template for the content post types, KB archive, and category taxonomy.
	 *
	 * Block themes render these views via the templates registered in
	 * register_block_templates() instead, so this filter is a no-op there.
	 *
	 * @param string $template Template path resolved by WordPress so far.
	 * @return string
	 */
	public function filter_template_include( string $template ): string {
		if ( wp_is_block_theme() ) {
			return $template;
		}

		if ( is_tax( 'saai_category' ) ) {
			$term = get_queried_object();

			if ( ! $term instanceof \WP_Term ) {
				return $template;
			}

			$resolved = $this->resolved_classic_taxonomy_template_path( $term );

			return '' !== $resolved && file_exists( $resolved ) ? $resolved : $template;
		}

		$slug = $this->queried_template_slug();

		if ( null === $slug ) {
			return $template;
		}

		$resolved = $this->resolved_classic_template_path( $slug );

		return '' !== $resolved && file_exists( $resolved ) ? $resolved : $template;
	}

	/**
	 * Resolves the classic-theme template path for a fixed slug (singular
	 * post types, the KB archive), honoring both a theme's own file override
	 * and the public saai_template filter.
	 *
	 * Called fresh (not memoized) each time: the public saai_template filter
	 * is documented as the final override for classic-theme template
	 * resolution, so it must run at whichever phase actually calls this —
	 * caching an early result across hook phases would either duplicate a
	 * later, correctly-timed evaluation or, worse, pre-empt it entirely for
	 * a callback an add-on registers on a later hook (e.g.
	 * wp/template_redirect — a normal pattern for a "final override" hook
	 * like this one).
	 *
	 * The saai_category taxonomy has its own dedicated
	 * resolved_classic_taxonomy_template_path() instead: unlike these fixed
	 * slugs, its winning slug depends on the queried term.
	 *
	 * @param string $slug Template hierarchy slug, e.g. `single-saai_kb`.
	 * @return string Absolute path, or '' if a saai_template filter returned something unusable.
	 */
	private function resolved_classic_template_path( string $slug ): string {
		$resolved = locate_template( array( "saai-knowledge/{$slug}.php" ) );

		if ( '' === $resolved ) {
			$resolved = SAAI_KNOWLEDGE_DIR . "templates/classic/{$slug}.php";
		}

		/**
		 * Filters the final resolved classic-theme template path.
		 *
		 * @since 0.1.0
		 *
		 * @param string $resolved Absolute path to the template file.
		 * @param string $slug     Template hierarchy slug, e.g. `single-saai_kb`.
		 */
		$resolved = apply_filters( 'saai_template', $resolved, $slug );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_template callback can violate it at runtime.)
		return is_string( $resolved ) ? $resolved : '';
	}

	/**
	 * Ordered classic-theme template-file candidates for a saai_category
	 * term, term-specific first — mirrors WordPress's own classic taxonomy
	 * template hierarchy (see get_taxonomy_template()).
	 *
	 * Shared by resolved_classic_taxonomy_template_path() and
	 * classic_taxonomy_template_overridden().
	 *
	 * @param \WP_Term $term The queried term.
	 * @return string[] Slugs, e.g. `taxonomy-saai_category-{term-slug}`.
	 */
	private function classic_taxonomy_template_slug_candidates( \WP_Term $term ): array {
		$candidates = array();

		$decoded_slug = urldecode( $term->slug );

		if ( $decoded_slug !== $term->slug ) {
			$candidates[] = "taxonomy-{$term->taxonomy}-{$decoded_slug}";
		}

		$candidates[] = "taxonomy-{$term->taxonomy}-{$term->slug}";
		$candidates[] = "taxonomy-{$term->taxonomy}";

		return $candidates;
	}

	/**
	 * Resolves the classic-theme template path for a saai_category term,
	 * honoring a theme's term-specific override
	 * (saai-knowledge/taxonomy-saai_category-{term-slug}.php) ahead of its
	 * generic one (saai-knowledge/taxonomy-saai_category.php), and the
	 * public saai_template filter — same reasoning as
	 * resolved_classic_template_path()'s docblock on why this isn't
	 * memoized.
	 *
	 * Only called from filter_template_include(); restrict_category_archive_to_kb()
	 * uses classic_taxonomy_template_overridden() instead, precisely to
	 * avoid invoking the saai_template filter this early.
	 *
	 * @param \WP_Term $term The queried term.
	 * @return string Absolute path, or '' if a saai_template filter returned something unusable.
	 */
	private function resolved_classic_taxonomy_template_path( \WP_Term $term ): string {
		$slug     = "taxonomy-{$term->taxonomy}";
		$resolved = '';

		foreach ( $this->classic_taxonomy_template_slug_candidates( $term ) as $candidate ) {
			$resolved = locate_template( array( "saai-knowledge/{$candidate}.php" ) );

			if ( '' !== $resolved ) {
				$slug = $candidate;
				break;
			}
		}

		if ( '' === $resolved ) {
			$resolved = SAAI_KNOWLEDGE_DIR . "templates/classic/{$slug}.php";
		}

		/** This filter is documented in resolved_classic_template_path() */
		$resolved = apply_filters( 'saai_template', $resolved, $slug );

		return is_string( $resolved ) ? $resolved : '';
	}

	/**
	 * Whether a classic theme file override exists in the
	 * taxonomy-saai_category template hierarchy for this term (term-specific
	 * or generic slug), or an add-on has registered a saai_template
	 * callback at all — used by restrict_category_archive_to_kb() to decide
	 * whether to defer its post_type restriction.
	 *
	 * Deliberately doesn't invoke the saai_template filter itself (only
	 * has_filter()'s presence check): resolved_classic_taxonomy_template_path()
	 * is the one place that does, at its own correct phase
	 * (filter_template_include(), on template_include) — see that method's
	 * docblock. A currently-registered callback is treated as "an override
	 * might be in play" and deferred to that later, authoritative
	 * evaluation, rather than guessed at here.
	 *
	 * @param \WP_Term $term The queried term.
	 * @return bool
	 */
	private function classic_taxonomy_template_overridden( \WP_Term $term ): bool {
		if ( has_filter( 'saai_template' ) ) {
			return true;
		}

		foreach ( $this->classic_taxonomy_template_slug_candidates( $term ) as $candidate ) {
			if ( '' !== locate_template( array( "saai-knowledge/{$candidate}.php" ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueues the KB two-column layout stylesheet and script on the views they apply to.
	 *
	 * Both are hand-authored (no build step), so their own mtime drives
	 * cache-busting instead of the plugin version constant.
	 */
	public function enqueue_layout_style(): void {
		if ( ! $this->is_kb_layout_view() ) {
			return;
		}

		$style_path = SAAI_KNOWLEDGE_DIR . 'assets/css/kb-layout.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'saai-knowledge-kb-layout',
				SAAI_KNOWLEDGE_URL . 'assets/css/kb-layout.css',
				array(),
				(string) filemtime( $style_path )
			);
		}

		$script_path = SAAI_KNOWLEDGE_DIR . 'assets/js/kb-layout.js';

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				'saai-knowledge-kb-layout',
				SAAI_KNOWLEDGE_URL . 'assets/js/kb-layout.js',
				array(),
				(string) filemtime( $script_path ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * Restricts the saai_category taxonomy archive's main query to saai_kb posts.
	 *
	 * `saai_category` is registered on both saai_kb and saai_faq (DESIGN.md
	 * section 3.5 / CLAUDE.md), but the bundled taxonomy-saai_category
	 * template (block and classic) is entirely KB-branded — sidebar,
	 * breadcrumbs, and empty-state copy all read as a Knowledge Base page.
	 * Left unrestricted, a term shared with FAQ content would list saai_faq
	 * posts inside this KB-only page. Both the block theme's inherited Query
	 * block and the classic template's main loop read directly from the main
	 * query, so this single filter covers both.
	 *
	 * @param \WP_Query $query The query WordPress is about to run.
	 */
	public function restrict_category_archive_to_kb( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( 'saai_category' ) ) {
			return;
		}

		// A category feed (/knowledge-category/{term}/feed/) is still a
		// saai_category taxonomy query, but it's served by WordPress's own
		// feed templates, not the bundled KB-branded archive template — don't
		// hide saai_faq entries from it too.
		if ( $query->is_feed() ) {
			return;
		}

		// A compound search scoped to this taxonomy (e.g.
		// /?s=setup&saai_category=guides) is also a saai_category taxonomy
		// query, but WordPress's own template hierarchy (is_search() is
		// checked ahead of is_tax() in template-loader.php) renders its
		// search template for it, not the bundled KB-branded archive
		// template — don't hide saai_faq entries from a plain search
		// results page.
		if ( $query->is_search() ) {
			return;
		}

		// Something already gave this query an explicit post-type scope
		// (e.g. a ?post_type=saai_faq query var) before this runs — a plain
		// taxonomy archive still has post_type unset at this point, it's
		// only resolved to the taxonomy's registered object types later in
		// WP_Query::get_posts(). Respect that deliberate choice rather than
		// silently overwriting it.
		if ( '' !== $query->get( 'post_type' ) ) {
			return;
		}

		// A site's own taxonomy-saai_category override — already given
		// priority over the bundled template (register_block_templates()'s
		// docblock; filter_template_include() for classic themes, including
		// via the public saai_template filter) — may deliberately want a
		// broader post-type scope for this shared taxonomy; don't force our
		// restriction on it. This also has to defer to a more specific
		// taxonomy-saai_category-{term-slug} override, which WordPress's own
		// template hierarchy prefers over the generic slug.
		$term = $query->get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		if ( wp_is_block_theme() ) {
			if ( ! $this->plugin_taxonomy_template_wins( $term ) ) {
				return;
			}
		} elseif ( $this->classic_taxonomy_template_overridden( $term ) ) {
			return;
		}

		$query->set( 'post_type', 'saai_kb' );
	}

	/**
	 * Determines whether the plugin's bundled taxonomy-saai_category block
	 * template is the one WordPress will actually render for the given term,
	 * or whether a site's own override — general or term-specific — wins
	 * instead.
	 *
	 * Mirrors the slug candidates and priority-sort algorithm core's
	 * (`@access private`) resolve_block_template() applies for a taxonomy
	 * archive, but built only from the public get_block_templates(), since
	 * calling an internal core function directly isn't safe to depend on.
	 *
	 * The winner is identified by WP_Block_Template::$plugin rather than
	 * just $source: another add-on can register its own 'plugin'-sourced
	 * template at any of these same hierarchy candidates (e.g. its own
	 * taxonomy-saai_category or a generic taxonomy template), and that must
	 * be treated as a site override too, not mistaken for this plugin's own.
	 *
	 * @param \WP_Term $term The queried term.
	 * @return bool Whether the plugin's own template is the one that wins.
	 */
	private function plugin_taxonomy_template_wins( \WP_Term $term ): bool {
		$slugs = array();

		$decoded_slug = urldecode( $term->slug );

		if ( $decoded_slug !== $term->slug ) {
			$slugs[] = "taxonomy-{$term->taxonomy}-{$decoded_slug}";
		}

		$slugs[] = "taxonomy-{$term->taxonomy}-{$term->slug}";
		$slugs[] = "taxonomy-{$term->taxonomy}";
		$slugs[] = 'taxonomy';

		$templates = get_block_templates( array( 'slug__in' => $slugs ) );

		if ( ! $templates ) {
			// No registered template matches any hierarchy candidate at all —
			// keep the previous default of applying the restriction rather
			// than silently widening the query's scope.
			return true;
		}

		$priorities = array_flip( $slugs );

		usort(
			$templates,
			static function ( \WP_Block_Template $a, \WP_Block_Template $b ) use ( $priorities ) {
				return $priorities[ $a->slug ] - $priorities[ $b->slug ];
			}
		);

		return self::PLUGIN_SLUG === $templates[0]->plugin;
	}

	/**
	 * Fires the documented saai_kb_before_article insertion point
	 * (docs/DESIGN-HOOKS-API.md section 4) before the block theme renders the
	 * KB article body.
	 *
	 * Hooked on pre_render_block rather than wrap_kb_article_content()'s
	 * render_block_core/post-content filter: that filter only sees the block
	 * after core/post-content's render_callback already produced the
	 * content, which is too late for an add-on that uses this hook to set up
	 * something the render itself depends on (e.g. registering a the_content
	 * filter). pre_render_block fires before the callback runs, so this
	 * mirrors the classic template's do_action() call directly ahead of
	 * the_content().
	 *
	 * The action's output is captured rather than left to print immediately:
	 * see $before_article_output.
	 *
	 * Restricted to the queried KB post itself, not just any core/post-content
	 * on a saai_kb singular request: a customized single-saai_kb.html could
	 * nest a Query/Post Template block (e.g. a "related articles" section)
	 * that also renders core/post-content once per listed post, and
	 * is_singular( 'saai_kb' ) alone can't tell those apart from the article
	 * being viewed. Unlike wrap_kb_article_content(), this can't check the
	 * block's own postId context — pre_render_block's $parent_block is one
	 * level up the tree, and at this point core/post-content's own context
	 * (which it does declare wanting via usesContext) hasn't been resolved
	 * yet; that only happens later, via the render_block_context filter,
	 * inside the very same render_block() call this filter is part of. The
	 * reliable signal instead is the global $post: core/post-template's own
	 * render callback calls the_post() for each item before rendering its
	 * inner blocks (the same mechanism a classic Loop uses), so get_the_ID()
	 * reflects whichever post is actually being rendered right now.
	 *
	 * $post_content_render_depth, in contrast, is tracked for every
	 * core/post-content block regardless of which post it's for — see that
	 * property's docblock.
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function fire_before_article_hook( $pre_render, array $parsed_block ) {
		if ( 'core/post-content' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $pre_render;
		}

		// Tracked for every core/post-content block, matched or not: see
		// $post_content_render_depth.
		++$this->post_content_render_depth;

		if ( ! is_singular( 'saai_kb' ) || get_queried_object_id() !== get_the_ID() ) {
			return $pre_render;
		}

		$post = get_post( get_queried_object_id() );

		if ( ! $post instanceof \WP_Post ) {
			return $pre_render;
		}

		/**
		 * Fires before the KB article body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The KB article being viewed.
		 */
		ob_start();
		do_action( 'saai_kb_before_article', $post );
		$this->before_article_output = ob_get_clean();

		return $pre_render;
	}

	/**
	 * Fires the documented saai_kb_after_article insertion point
	 * (docs/DESIGN-HOOKS-API.md section 4) after the block theme's rendered
	 * KB article body.
	 *
	 * The single-saai_kb.html block template has no PHP execution point of its
	 * own to call do_action() from directly, so this wraps the one block that
	 * renders the article body instead — scoped to core/post-content
	 * specifically so it doesn't fire for unrelated uses of that block
	 * elsewhere on the site. wrap_kb_article_content_classic() covers the
	 * equivalent classic-theme case via the_content instead.
	 *
	 * @param string               $block_content The rendered post-content block.
	 * @param array<string, mixed> $parsed_block Parsed block data (unused).
	 * @param \WP_Block            $block The block instance, used to confirm this is the
	 *                                    currently-viewed post's own content, not some
	 *                                    other post's rendered via a nested query loop.
	 * @return string
	 */
	public function wrap_kb_article_content( string $block_content, array $parsed_block, \WP_Block $block ): string {
		// This filter only ever fires for core/post-content (its dynamic hook
		// name), so every call here is one such block finishing its render —
		// see $post_content_render_depth.
		--$this->post_content_render_depth;

		if ( ! is_singular( 'saai_kb' ) ) {
			return $block_content;
		}

		$post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : get_queried_object_id();

		if ( get_queried_object_id() !== $post_id ) {
			return $block_content;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $block_content;
		}

		// Only consumed once this invocation is confirmed to be the queried
		// article's own body: an unrelated nested core/post-content (e.g. a
		// Query Loop the article itself embeds) renders and hits the checks
		// above before the outer, real one does, and must leave the buffer
		// untouched for that real invocation still to come — reading it here
		// unconditionally would let the nested render's early return above
		// discard the outer article's before-hook output.
		$before                      = $this->before_article_output ?? '';
		$this->before_article_output = null;

		/**
		 * Fires after the KB article body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The KB article being viewed.
		 */
		ob_start();
		do_action( 'saai_kb_after_article', $post );
		$after = ob_get_clean();

		return $before . $block_content . $after;
	}

	/**
	 * The queried KB article, if the current the_content call is rendering
	 * its own body — shared by buffer_before_article_hook_classic() and
	 * wrap_kb_article_content_classic().
	 *
	 * A nonzero $post_content_render_depth means the_content is firing as
	 * part of a core/post-content block's render (its own render callback
	 * applies the_content too — see render_block_core_post_content()),
	 * whether the outer article's own or a nested one from a Query Loop the
	 * article embeds; either way, fire_before_article_hook()/
	 * wrap_kb_article_content() already cover that case, so both classic
	 * hooks defer to them rather than firing a second time. Otherwise, scoped
	 * to the queried post itself, not just any the_content call on a saai_kb
	 * singular request: the article body can itself embed a Query Loop
	 * rendering other posts' content through the very same filter, and
	 * is_singular( 'saai_kb' ) alone can't tell those apart from the article
	 * being viewed — same reasoning as fire_before_article_hook()'s docblock.
	 *
	 * @return \WP_Post|null
	 */
	private function queried_kb_article_for_classic_content_hooks(): ?\WP_Post {
		if ( 0 !== $this->post_content_render_depth ) {
			return null;
		}

		if ( ! is_singular( 'saai_kb' ) || get_queried_object_id() !== get_the_ID() ) {
			return null;
		}

		$post = get_post( get_the_ID() );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Fires the documented saai_kb_before_article insertion point
	 * (docs/DESIGN-HOOKS-API.md section 4) before a classic theme's
	 * the_content filter chain processes the KB article body.
	 *
	 * Hooked at priority 1 — before wpautop, do_blocks, and the rest of the
	 * default the_content chain — rather than at wrap_kb_article_content_classic()'s
	 * late priority: an add-on that uses this hook to register its own
	 * the_content filter (a supported pattern on the block-theme path — see
	 * fire_before_article_hook()'s equivalent pre_render_block timing) needs
	 * that filter added before this same content pass reaches it, or it
	 * either misses affecting this article entirely or leaks into later,
	 * unrelated the_content calls instead.
	 *
	 * The action's output is captured rather than left to print immediately:
	 * see $classic_before_article_output.
	 *
	 * @param string $content The post content, not yet run through the_content.
	 * @return string
	 */
	public function buffer_before_article_hook_classic( string $content ): string {
		$post = $this->queried_kb_article_for_classic_content_hooks();

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		/**
		 * Fires before the KB article body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The KB article being viewed.
		 */
		ob_start();
		do_action( 'saai_kb_before_article', $post );
		$this->classic_before_article_output = ob_get_clean();

		return $content;
	}

	/**
	 * Fires the documented saai_kb_after_article insertion point
	 * (docs/DESIGN-HOOKS-API.md section 4) after a classic theme's rendered
	 * KB article body, and prepends buffer_before_article_hook_classic()'s
	 * buffered output ahead of it.
	 *
	 * Hooked on the_content rather than called directly from the bundled
	 * templates/classic/single-saai_kb.php: that file is only one of several
	 * ways the body ends up rendered (a theme's own
	 * saai-knowledge/single-saai_kb.php override, or an add-on's saai_template
	 * filter, both take priority over it — see resolved_classic_template_path()),
	 * and none of those alternatives call our do_action()s. the_content is
	 * the one thing every classic template calls to output the body, so
	 * hooking it here fires these consistently regardless of which template
	 * wins. Registered at PHP_INT_MAX so the wrapped output is the fully
	 * processed content (past wpautop, do_blocks, etc.), matching how
	 * wrap_kb_article_content() concatenates onto already-rendered markup for
	 * block themes.
	 *
	 * @param string $content The fully filtered post content.
	 * @return string
	 */
	public function wrap_kb_article_content_classic( string $content ): string {
		$post = $this->queried_kb_article_for_classic_content_hooks();

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$before                              = $this->classic_before_article_output ?? '';
		$this->classic_before_article_output = null;

		/**
		 * Fires after the KB article body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The KB article being viewed.
		 */
		ob_start();
		do_action( 'saai_kb_after_article', $post );
		$after = ob_get_clean();

		return $before . $content . $after;
	}

	/**
	 * Whether the current request renders the KB two-column layout.
	 *
	 * @return bool
	 */
	private function is_kb_layout_view(): bool {
		foreach ( self::LAYOUT_STYLE_POST_TYPES as $post_type ) {
			if ( is_singular( $post_type ) || is_post_type_archive( $post_type ) ) {
				return true;
			}
		}

		return is_tax( 'saai_category' );
	}

	/**
	 * The template slug matching the current main query, if any.
	 *
	 * @return string|null
	 */
	private function queried_template_slug(): ?string {
		foreach ( self::TEMPLATES as $slug => $spec ) {
			if ( $this->matches_current_request( $spec ) ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Whether a template spec matches the current main query.
	 *
	 * @param array{match: string, target: string} $spec Template spec, see self::TEMPLATES.
	 * @return bool
	 */
	private function matches_current_request( array $spec ): bool {
		switch ( $spec['match'] ) {
			case 'singular':
				return is_singular( $spec['target'] );
			case 'post_type_archive':
				return is_post_type_archive( $spec['target'] );
			case 'taxonomy':
				return is_tax( $spec['target'] );
			default:
				return false;
		}
	}

	/**
	 * The Site Editor title for a template slug.
	 *
	 * @param string $slug Template slug, e.g. `single-saai_kb`.
	 * @return string
	 */
	private function template_title( string $slug ): string {
		$titles = array(
			'single-saai_kb'         => __( 'Single: Knowledge Base Article', 'saai-knowledge' ),
			'single-saai_faq'        => __( 'Single: FAQ', 'saai-knowledge' ),
			'single-saai_glossary'   => __( 'Single: Glossary Term', 'saai-knowledge' ),
			'archive-saai_kb'        => __( 'Knowledge Base Hub', 'saai-knowledge' ),
			'taxonomy-saai_category' => __( 'Knowledge Base Category Archive', 'saai-knowledge' ),
		);

		return $titles[ $slug ] ?? $slug;
	}

	/**
	 * Reads a bundled template asset relative to the plugin's templates/ directory.
	 *
	 * @param string $relative_path Path relative to templates/.
	 * @return string
	 */
	private function read_bundled_asset( string $relative_path ): string {
		$path = SAAI_KNOWLEDGE_DIR . 'templates/' . $relative_path;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin asset, not a remote/user-supplied path.
		return (string) file_get_contents( $path );
	}

	/**
	 * Replaces translatable placeholder tokens in bundled block-template HTML.
	 *
	 * Block-template files are static HTML, so they can't call translation
	 * functions directly; they carry a `{{saai_..._label}}` token instead, and
	 * this substitutes the translated string in at registration time (the
	 * classic-theme PHP templates translate the same labels via esc_html_e()
	 * directly).
	 *
	 * @param string $content Bundled block-template HTML.
	 * @return string
	 */
	private function localize_template_content( string $content ): string {
		return strtr(
			$content,
			array(
				'{{saai_categories_label}}'        => esc_html__( 'Categories', 'saai-knowledge' ),
				'{{saai_toc_label}}'               => esc_html__( 'Table of contents', 'saai-knowledge' ),
				'{{saai_kb_hub_empty_label}}'      => esc_html__( 'No knowledge base articles found.', 'saai-knowledge' ),
				'{{saai_kb_category_empty_label}}' => esc_html__( 'No knowledge base articles found in this category.', 'saai-knowledge' ),
			)
		);
	}
}
