<?php
/**
 * Registers the SAAI Knowledge settings page and the `saai_knowledge_settings`
 * option consumed elsewhere in the plugin.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API screen for the `saai_knowledge_settings` option (docs/DESIGN.md
 * section 3.4). Several consumers (Autolinker::max_links(), Breadcrumbs,
 * Faq_List, Faq_Question, Glossary_Term structured_data_enabled()) already
 * read this option defensively with their own fallback; this class is only
 * responsible for letting an admin actually write a value, plus the two
 * effects that don't already have a dedicated reader: the post type slugs
 * and the auto-link target post types.
 */
final class Settings {

	/**
	 * The option name storing all settings.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'saai_knowledge_settings';

	/**
	 * The register_setting() option group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'saai_knowledge_settings_group';

	/**
	 * The settings page slug (also used as the top-level admin menu slug).
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'saai-knowledge-settings';

	/**
	 * Option flagging that a slug changed and rewrite rules need a flush on
	 * the next request. See maybe_flush_rewrite_rules() for why this can't
	 * just happen synchronously in sanitize().
	 *
	 * @var string
	 */
	private const FLUSH_FLAG_OPTION = 'saai_flush_rewrite_rules';

	/**
	 * Post types eligible for auto-linking, offered as checkboxes.
	 *
	 * @var string[]
	 */
	private const AUTOLINK_POST_TYPE_CHOICES = array( 'post', 'page', 'saai_kb', 'saai_faq', 'saai_glossary' );

	/**
	 * Hooks the admin screen, the auto-link post type filter, and the
	 * deferred rewrite flush into WordPress.
	 *
	 * The admin-only hooks are gated the same way Term_Order gates its own
	 * admin hooks; the filter and the flush check must run on every request,
	 * so they're registered unconditionally.
	 */
	public function register(): void {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_setting' ) );
		}

		add_filter( 'saai_autolink_post_types', array( $this, 'filter_autolink_post_types' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
	}

	/**
	 * Adds the top-level "SAAI Knowledge" settings page.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'SAAI Knowledge Settings', 'saai-knowledge' ),
			__( 'SAAI Knowledge', 'saai-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-database',
			26
		);
	}

	/**
	 * Registers the option and the Settings API sections/fields that make up
	 * the settings page.
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);

		foreach ( $this->sections() as $section_id => $section ) {
			if ( ! is_array( $section ) || ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			add_settings_section(
				$section_id,
				isset( $section['title'] ) ? (string) $section['title'] : '',
				'__return_null',
				self::PAGE_SLUG
			);

			foreach ( $section['fields'] as $field_id => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				add_settings_field(
					$field_id,
					isset( $field['label'] ) ? (string) $field['label'] : $field_id,
					array( $this, 'render_field' ),
					self::PAGE_SLUG,
					$section_id,
					array(
						'field_id' => $field_id,
						'field'    => $field,
					)
				);
			}
		}
	}

	/**
	 * Renders the settings page shell.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SAAI Knowledge', 'saai-knowledge' ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders one settings field, dispatched by the `type` key set in
	 * sections().
	 *
	 * @param array{field_id: string, field: array<string, mixed>} $args Field definition, as passed to add_settings_field().
	 */
	public function render_field( array $args ): void {
		$field_id = $args['field_id'];
		$field    = $args['field'];
		$settings = array_merge( $this->defaults(), $this->stored_settings() );
		$name     = self::OPTION_KEY . '[' . $field_id . ']';
		$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $name ),
					checked( ! empty( $settings[ $field_id ] ), true, false ),
					esc_html( isset( $field['checkbox_label'] ) ? (string) $field['checkbox_label'] : '' )
				);
				break;

			case 'checkboxes':
				$selected = isset( $settings[ $field_id ] ) && is_array( $settings[ $field_id ] ) ? $settings[ $field_id ] : array();
				$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

				foreach ( $options as $value => $label ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
						esc_attr( $name ),
						esc_attr( (string) $value ),
						checked( in_array( (string) $value, $selected, true ), true, false ),
						esc_html( (string) $label )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" min="%1$s" step="1" name="%2$s" value="%3$s" class="small-text" />',
					esc_attr( isset( $field['min'] ) ? (string) $field['min'] : '0' ),
					esc_attr( $name ),
					esc_attr( (string) ( $settings[ $field_id ] ?? '' ) )
				);
				break;

			default:
				printf(
					'<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( (string) ( $settings[ $field_id ] ?? '' ) )
				);
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $field['description'] ) );
		}
	}

	/**
	 * The settings page's sections and fields.
	 *
	 * Filterable so a future consumer (e.g. the AI-readability toggles
	 * planned for Issue #25, or the paid add-on) can add its own section to
	 * this same page instead of building a separate one.
	 *
	 * The value is intentionally typed loosely: a `saai_settings_sections`
	 * callback can return anything, so callers must not assume 'title'/
	 * 'fields' exist (see the is_array()/isset() guards in register_setting()).
	 *
	 * @return array<string, mixed>
	 */
	private function sections(): array {
		$sections = array(
			'general'   => array(
				'title'  => __( 'URL Slugs', 'saai-knowledge' ),
				'fields' => array(
					'slug_kb'       => array(
						'type'        => 'text',
						'label'       => __( 'Knowledge Base slug', 'saai-knowledge' ),
						'description' => __( 'Articles are served at /{slug}/article-name/.', 'saai-knowledge' ),
					),
					'slug_faq'      => array(
						'type'        => 'text',
						'label'       => __( 'FAQ slug', 'saai-knowledge' ),
						'description' => __( 'The FAQ archive is served at /{slug}/.', 'saai-knowledge' ),
					),
					'slug_glossary' => array(
						'type'        => 'text',
						'label'       => __( 'Glossary slug', 'saai-knowledge' ),
						'description' => __( 'The glossary index is served at /{slug}/.', 'saai-knowledge' ),
					),
				),
			),
			'autolink'  => array(
				'title'  => __( 'Term Auto-Linking', 'saai-knowledge' ),
				'fields' => array(
					'autolink_post_types' => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Auto-link terms in', 'saai-knowledge' ),
						'options' => array(
							'post'          => __( 'Posts', 'saai-knowledge' ),
							'page'          => __( 'Pages', 'saai-knowledge' ),
							'saai_kb'       => __( 'Knowledge Base articles', 'saai-knowledge' ),
							'saai_faq'      => __( 'FAQs', 'saai-knowledge' ),
							'saai_glossary' => __( 'Glossary terms', 'saai-knowledge' ),
						),
					),
					'autolink_max_links'  => array(
						'type'        => 'number',
						'min'         => 1,
						'label'       => __( 'Maximum links per post', 'saai-knowledge' ),
						'description' => __( 'A term is only ever linked once per post, regardless of this limit.', 'saai-knowledge' ),
					),
				),
			),
			'output'    => array(
				'title'  => __( 'Output', 'saai-knowledge' ),
				'fields' => array(
					'structured_data' => array(
						'type'           => 'checkbox',
						'label'          => __( 'Structured data', 'saai-knowledge' ),
						'checkbox_label' => __( 'Output FAQPage / QAPage / DefinedTerm / BreadcrumbList JSON-LD', 'saai-knowledge' ),
						'description'    => __( 'Turn this off if an SEO plugin already outputs this structured data.', 'saai-knowledge' ),
					),
				),
			),
			'uninstall' => array(
				'title'  => __( 'Uninstall', 'saai-knowledge' ),
				'fields' => array(
					'delete_data_on_uninstall' => array(
						'type'           => 'checkbox',
						'label'          => __( 'Delete data on uninstall', 'saai-knowledge' ),
						'checkbox_label' => __( 'Permanently delete all FAQs, KB articles, glossary terms, categories, and settings when this plugin is deleted.', 'saai-knowledge' ),
					),
				),
			),
		);

		/**
		 * Filters the SAAI Knowledge settings page's sections and fields.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, mixed>> $sections Section definitions keyed by section ID.
		 */
		$filtered = apply_filters( 'saai_settings_sections', $sections );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_settings_sections callback can violate it at runtime.)
		return is_array( $filtered ) ? $filtered : $sections;
	}

	/**
	 * The built-in default settings, run through the `saai_default_settings`
	 * filter documented in docs/DESIGN.md section 3.4.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		$defaults = array(
			'slug_kb'                  => 'kb',
			'slug_faq'                 => 'faq',
			'slug_glossary'            => 'glossary',
			'autolink_post_types'      => array( 'post', 'page', 'saai_kb', 'saai_faq' ),
			'autolink_max_links'       => 20,
			'structured_data'          => true,
			'delete_data_on_uninstall' => false,
		);

		/**
		 * Filters the default SAAI Knowledge settings.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $defaults Default option values.
		 */
		$filtered = apply_filters( 'saai_default_settings', $defaults );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_default_settings callback can violate it at runtime.)
		return is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
	}

	/**
	 * The currently stored option value, or an empty array when unset.
	 *
	 * @return array<string, mixed>
	 */
	private function stored_settings(): array {
		$stored = get_option( self::OPTION_KEY );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sanitizes a submitted settings array; the register_setting()
	 * sanitize_callback.
	 *
	 * Also detects a post type slug change and, if found, flags a deferred
	 * rewrite flush (see maybe_flush_rewrite_rules()).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$defaults = $this->defaults();
		$old      = array_merge( $defaults, $this->stored_settings() );

		$sanitized = array(
			'slug_kb'                  => $this->sanitize_slug( $value['slug_kb'] ?? '', $defaults['slug_kb'] ),
			'slug_faq'                 => $this->sanitize_slug( $value['slug_faq'] ?? '', $defaults['slug_faq'] ),
			'slug_glossary'            => $this->sanitize_slug( $value['slug_glossary'] ?? '', $defaults['slug_glossary'] ),
			'autolink_post_types'      => $this->sanitize_post_types( $value['autolink_post_types'] ?? array() ),
			'autolink_max_links'       => $this->sanitize_max_links( $value['autolink_max_links'] ?? null, $defaults['autolink_max_links'] ),
			'structured_data'          => ! empty( $value['structured_data'] ),
			'delete_data_on_uninstall' => ! empty( $value['delete_data_on_uninstall'] ),
		);

		$slugs = array( $sanitized['slug_kb'], $sanitized['slug_faq'], $sanitized['slug_glossary'] );

		if ( count( $slugs ) !== count( array_unique( $slugs ) ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'saai_duplicate_slug',
				__( 'The Knowledge Base, FAQ, and Glossary slugs must be different from each other; the previous slugs were kept.', 'saai-knowledge' )
			);

			$sanitized['slug_kb']       = $old['slug_kb'];
			$sanitized['slug_faq']      = $old['slug_faq'];
			$sanitized['slug_glossary'] = $old['slug_glossary'];
		}

		if ( $sanitized['slug_kb'] !== $old['slug_kb'] || $sanitized['slug_faq'] !== $old['slug_faq'] || $sanitized['slug_glossary'] !== $old['slug_glossary'] ) {
			update_option( self::FLUSH_FLAG_OPTION, 1, false );
		}

		return $sanitized;
	}

	/**
	 * Sanitizes a slug field: `sanitize_title()`'d, falling back to the
	 * default when that leaves nothing (blank input, or input that was only
	 * punctuation/whitespace).
	 *
	 * @param mixed  $raw     Raw submitted value.
	 * @param string $fallback Fallback slug.
	 * @return string
	 */
	private function sanitize_slug( $raw, string $fallback ): string {
		$slug = sanitize_title( is_string( $raw ) ? $raw : '' );

		return '' !== $slug ? $slug : $fallback;
	}

	/**
	 * Sanitizes the auto-link target post types to a subset of the known
	 * choices. An empty result is valid — it means auto-linking is off.
	 *
	 * @param mixed $raw Raw submitted value.
	 * @return string[]
	 */
	private function sanitize_post_types( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array_map(
			static function ( $post_type ) {
				return is_string( $post_type ) ? sanitize_key( $post_type ) : '';
			},
			$raw
		);

		return array_values( array_intersect( self::AUTOLINK_POST_TYPE_CHOICES, $clean ) );
	}

	/**
	 * Sanitizes the max-links-per-post field to a positive integer.
	 *
	 * @param mixed $raw     Raw submitted value.
	 * @param int   $fallback Fallback value.
	 * @return int
	 */
	private function sanitize_max_links( $raw, int $fallback ): int {
		$value = absint( $raw );

		return $value > 0 ? $value : $fallback;
	}

	/**
	 * Filters `saai_autolink_post_types` (see Autolinker::target_post_types())
	 * to the configured value, when the admin has ever saved one. Leaves the
	 * incoming default (or any other filter's value) untouched otherwise, so
	 * a fresh install with no settings saved yet keeps Autolinker's own
	 * built-in default.
	 *
	 * @param mixed $post_types Post type slugs passed down the filter chain.
	 * @return mixed
	 */
	public function filter_autolink_post_types( $post_types ) {
		$settings = get_option( self::OPTION_KEY );

		if ( ! is_array( $settings ) || ! array_key_exists( 'autolink_post_types', $settings ) ) {
			return $post_types;
		}

		return is_array( $settings['autolink_post_types'] ) ? $settings['autolink_post_types'] : $post_types;
	}

	/**
	 * Flushes rewrite rules once, if a slug change flagged it.
	 *
	 * A slug change can't be flushed synchronously from sanitize(): that runs
	 * during this same request's admin_init/options.php handling, after
	 * `init` (and thus Post_Types::register_post_types()) already ran with
	 * the *old* slug. The new slug only takes effect once `init` re-runs on
	 * a later request with the option already saved — this is registered at
	 * priority 20, after Post_Types/Taxonomies register at the default
	 * priority 10, so the new rewrite rules exist by the time this flushes
	 * them. Same reasoning as Plugin::activate()'s direct registration call.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( self::FLUSH_FLAG_OPTION ) ) {
			return;
		}

		flush_rewrite_rules();
		delete_option( self::FLUSH_FLAG_OPTION );
	}
}
