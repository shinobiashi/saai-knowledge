<?php
/**
 * Resolves single-view templates for the content post types.
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
	 * Post types that get a single-view template.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'saai_kb', 'saai_faq', 'saai_glossary' );

	/**
	 * Hooks template resolution into WordPress.
	 */
	public function register(): void {
		if ( wp_is_block_theme() ) {
			add_action( 'init', array( $this, 'register_block_templates' ) );
		}

		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
	}

	/**
	 * Registers a placeholder block template for each content post type.
	 *
	 * Only hooked on block themes (see register()); a classic theme never
	 * resolves these, so registering them there would just be wasted work
	 * on every request.
	 *
	 * Site owners on a block theme can override these from the Site Editor;
	 * a theme-provided template of the same name always takes priority.
	 */
	public function register_block_templates(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			$slug = "single-{$post_type}";

			register_block_template(
				"saai-knowledge//{$slug}",
				array(
					'title'       => $this->template_title( $post_type ),
					'description' => __( 'Placeholder template provided by SAAI Knowledge. Customize it from the Site Editor.', 'saai-knowledge' ),
					'content'     => $this->read_bundled_asset( "block-templates/{$slug}.html" ),
				)
			);
		}
	}

	/**
	 * Resolves the classic-theme template for the content post types.
	 *
	 * Block themes render single views via the templates registered in
	 * register_block_templates() instead, so this filter is a no-op there.
	 *
	 * @param string $template Template path resolved by WordPress so far.
	 * @return string
	 */
	public function filter_template_include( string $template ): string {
		if ( wp_is_block_theme() ) {
			return $template;
		}

		$post_type = $this->queried_post_type();

		if ( null === $post_type ) {
			return $template;
		}

		$slug = "single-{$post_type}";

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
	 * The content post type of the current main query, if it is a singular view of one.
	 *
	 * @return string|null
	 */
	private function queried_post_type(): ?string {
		foreach ( self::POST_TYPES as $post_type ) {
			if ( is_singular( $post_type ) ) {
				return $post_type;
			}
		}

		return null;
	}

	/**
	 * The Site Editor title for a post type's placeholder block template.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function template_title( string $post_type ): string {
		$titles = array(
			'saai_kb'       => __( 'Single: Knowledge Base Article', 'saai-knowledge' ),
			'saai_faq'      => __( 'Single: FAQ', 'saai-knowledge' ),
			'saai_glossary' => __( 'Single: Glossary Term', 'saai-knowledge' ),
		);

		return $titles[ $post_type ] ?? $post_type;
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
