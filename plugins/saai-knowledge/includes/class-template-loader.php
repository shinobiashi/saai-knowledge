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
	 * Hooks template resolution into WordPress.
	 */
	public function register(): void {
		if ( wp_is_block_theme() ) {
			add_action( 'init', array( $this, 'register_block_templates' ) );
		}

		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_layout_style' ) );
		add_action( 'pre_get_posts', array( $this, 'restrict_category_archive_to_kb' ) );
		add_filter( 'render_block_core/post-content', array( $this, 'wrap_kb_article_content' ), 10, 3 );
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
				"saai-knowledge//{$slug}",
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

		$slug = $this->queried_template_slug();

		if ( null === $slug ) {
			return $template;
		}

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

		return is_string( $resolved ) && '' !== $resolved && file_exists( $resolved ) ? $resolved : $template;
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

		// A site's own taxonomy-saai_category override — already given
		// priority over the bundled template (register_block_templates()'s
		// docblock; filter_template_include() for classic themes) — may
		// deliberately want a broader post-type scope for this shared
		// taxonomy; don't force our restriction on it.
		if ( wp_is_block_theme() ) {
			foreach ( get_block_templates( array( 'slug__in' => array( 'taxonomy-saai_category' ) ) ) as $template ) {
				if ( 'plugin' !== $template->source ) {
					return;
				}
			}
		} elseif ( '' !== locate_template( array( 'saai-knowledge/taxonomy-saai_category.php' ) ) ) {
			return;
		}

		$query->set( 'post_type', 'saai_kb' );
	}

	/**
	 * Fires the documented saai_kb_before_article/saai_kb_after_article
	 * insertion points (docs/DESIGN-HOOKS-API.md section 4) around the block
	 * theme's rendered KB article body.
	 *
	 * The single-saai_kb.html block template has no PHP execution point of its
	 * own to call do_action() from directly, so this wraps the one block that
	 * renders the article body instead — scoped to core/post-content
	 * specifically so it doesn't fire for unrelated uses of that block
	 * elsewhere on the site. The classic-theme template calls the same
	 * actions directly around the_content().
	 *
	 * @param string               $block_content The rendered post-content block.
	 * @param array<string, mixed> $parsed_block Parsed block data (unused).
	 * @param \WP_Block            $block The block instance, used to confirm this is the
	 *                                    currently-viewed post's own content, not some
	 *                                    other post's rendered via a nested query loop.
	 * @return string
	 */
	public function wrap_kb_article_content( string $block_content, array $parsed_block, \WP_Block $block ): string {
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

		/**
		 * Fires before the KB article body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The KB article being viewed.
		 */
		ob_start();
		do_action( 'saai_kb_before_article', $post );
		$before = ob_get_clean();

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
