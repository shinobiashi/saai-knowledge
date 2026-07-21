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
					'content'     => $this->read_bundled_asset( "block-templates/{$slug}.html" ),
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
	 * Enqueues the KB two-column layout stylesheet on the views it applies to.
	 *
	 * Hand-authored (no build step), so its own mtime drives cache-busting
	 * instead of the plugin version constant.
	 */
	public function enqueue_layout_style(): void {
		if ( ! $this->is_kb_layout_view() ) {
			return;
		}

		$path = SAAI_KNOWLEDGE_DIR . 'assets/css/kb-layout.css';

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_style(
			'saai-knowledge-kb-layout',
			SAAI_KNOWLEDGE_URL . 'assets/css/kb-layout.css',
			array(),
			(string) filemtime( $path )
		);
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
}
