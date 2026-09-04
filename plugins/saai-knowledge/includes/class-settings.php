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
	 * The settings page slug — also the top-level admin menu slug that
	 * Post_Types nests the saai_faq/saai_kb/saai_glossary admin screens
	 * under (docs/DESIGN.md section 5: "SAAI Knowledge" top-level menu
	 * with the 3 CPTs + settings consolidated under it), so this is public.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'saai-knowledge-settings';

	/**
	 * Option flagging that a slug changed and rewrite rules need a flush on
	 * the next request. See maybe_flush_rewrite_rules() for why this can't
	 * just happen synchronously in sanitize().
	 *
	 * @var string
	 */
	private const FLUSH_FLAG_OPTION = 'saai_flush_rewrite_rules';

	/**
	 * Hooks the admin screen, the auto-link post type filter, and the
	 * deferred rewrite flush into WordPress.
	 *
	 * Filter_default_option() is registered unconditionally (not just
	 * admin-side) — see its docblock for why: without it, a
	 * `saai_default_settings` customization only ever takes effect in the
	 * admin form's pre-fill, never in actual front-end behavior, until an
	 * admin saves the page once. It's a deliberately narrow, read-only
	 * filter rather than giving register_setting() (below) itself global
	 * reach — see the same docblock for why that would be unsafe. The rest
	 * of the admin-only hooks are gated the same way Term_Order gates its
	 * own; the filter and the flush check must run on every request, so
	 * they're registered unconditionally too.
	 */
	public function register(): void {
		add_filter( 'default_option_' . self::OPTION_KEY, array( $this, 'filter_default_option' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_option' ) );
			add_action( 'admin_init', array( $this, 'register_fields' ) );
		}

		add_filter( 'saai_autolink_post_types', array( $this, 'filter_autolink_post_types' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
	}

	/**
	 * Adds the top-level "SAAI Knowledge" settings page.
	 *
	 * Wp-admin/menu-header.php always points the top-level link's href (and
	 * "current" state) at `$submenu[ $parent_slug ][0]` — the *first*
	 * registered child — regardless of what add_menu_page() itself pointed
	 * to. Post_Types nests saai_faq/saai_kb/saai_glossary under this same
	 * slug (Post_Types::shared_args()), and wp-admin/menu.php inserts post
	 * type admin menu items *before* the `admin_menu` action fires (its own
	 * comment: "Post types are registered before the admin menu, so we can
	 * add them 1st-priority") — before this method even runs. So a plain
	 * add_submenu_page() call here only appends "Settings" to the end,
	 * leaving the top-level link pointing at the first CPT ("All FAQs")
	 * instead of the settings page (confirmed against a live wp-env
	 * instance). The only way to win is to add it, then move it to index 0
	 * in the global $submenu array directly — there's no earlier hook to
	 * register it into that position through the normal API.
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

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'SAAI Knowledge Settings', 'saai-knowledge' ),
			__( 'Settings', 'saai-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		global $submenu;

		if ( ! isset( $submenu[ self::PAGE_SLUG ] ) ) {
			return;
		}

		foreach ( $submenu[ self::PAGE_SLUG ] as $index => $item ) {
			if ( ! isset( $item[2] ) || self::PAGE_SLUG !== $item[2] ) {
				continue;
			}

			unset( $submenu[ self::PAGE_SLUG ][ $index ] );
			array_unshift( $submenu[ self::PAGE_SLUG ], $item );
			break;
		}
	}

	/**
	 * Registers the `saai_knowledge_settings` option with WordPress core:
	 * the sanitize_callback that validates an actual settings-page
	 * submission, plus the 'default' WordPress core itself uses to back its
	 * own `default_option_{$name}` filter (redundant with, but harmless
	 * alongside, filter_default_option() below).
	 *
	 * Deliberately admin-only. Giving this global reach (instead of the
	 * narrower filter_default_option()) would also activate its
	 * sanitize_callback for every plain
	 * `update_option( 'saai_knowledge_settings', $partial_array )` call
	 * anywhere — including existing tests, e.g.
	 * Test_Autolinker::test_max_links_per_post_is_enforced(), which sets
	 * only `autolink_max_links` and expects every other key to be left
	 * alone. sanitize()'s full-form semantics (an omitted checkbox/
	 * checkboxes field means "off") would silently zero out
	 * `autolink_post_types` on a call like that instead of leaving it
	 * untouched (confirmed — this exact scenario was reproduced when
	 * register_setting() briefly ran unconditionally during development).
	 */
	public function register_option(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Registers the Settings API sections/fields that make up the settings
	 * page. Admin-only: add_settings_section()/add_settings_field() have no
	 * effect outside wp-admin.
	 */
	public function register_fields(): void {
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

			foreach ( $section['fields'] as $field_key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_id = $this->field_id( $field_key, $field );

				if ( null === $field_id ) {
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
		$value    = $this->field_value( $field_id, $field );
		$name     = self::OPTION_KEY . '[' . $field_id . ']';
		$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( isset( $field['checkbox_label'] ) ? (string) $field['checkbox_label'] : '' )
				);
				break;

			case 'checkboxes':
				$selected = is_array( $value ) ? $value : array();
				$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

				foreach ( $options as $option_value => $label ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
						esc_attr( $name ),
						esc_attr( (string) $option_value ),
						checked( in_array( (string) $option_value, $selected, true ), true, false ),
						esc_html( (string) $label )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" min="%1$s" step="1" name="%2$s" value="%3$s" class="small-text" />',
					esc_attr( isset( $field['min'] ) ? (string) $field['min'] : '0' ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;

			default:
				printf(
					'<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $field['description'] ) );
		}
	}

	/**
	 * The current value to display for a field: the stored option value,
	 * else defaults() (the `saai_default_settings` filter), else the
	 * field's own declared `default` — the docs/DESIGN-HOOKS-API.md field
	 * shape allows a consumer to set this without also duplicating the same
	 * value via `saai_default_settings`. Same source priority as sanitize()'s
	 * per-field `$fallback` for the latter two, but stored_settings() is
	 * checked first here since this renders what's actually saved.
	 *
	 * @param string               $field_id Field identifier.
	 * @param array<string, mixed> $field    Field definition.
	 * @return mixed
	 */
	private function field_value( string $field_id, array $field ) {
		$stored = $this->stored_settings();

		if ( array_key_exists( $field_id, $stored ) ) {
			return $stored[ $field_id ];
		}

		$defaults = $this->defaults();

		if ( array_key_exists( $field_id, $defaults ) ) {
			return $defaults[ $field_id ];
		}

		return $field['default'] ?? null;
	}

	/**
	 * Makes a bare `get_option( 'saai_knowledge_settings' )` return
	 * defaults() (the `saai_default_settings`-filtered defaults) when the
	 * option row doesn't exist, on every request — see register().
	 *
	 * Mirrors the `$passed_default` handling WordPress's own
	 * register_setting() uses for its own `default_option_{$name}` filter:
	 * a caller that explicitly passed its own fallback to get_option() gets
	 * that back unchanged; only the bare `get_option( self::OPTION_KEY )`
	 * (no second argument — the common case, used throughout this plugin)
	 * is substituted.
	 *
	 * @param mixed  $fallback       The default value passed to get_option().
	 * @param string $option         Option name. Unused — this filter is only ever attached to one option's hook name.
	 * @param bool   $passed_default Whether get_option() was called with an explicit $default argument.
	 * @return mixed
	 */
	public function filter_default_option( $fallback, string $option = '', bool $passed_default = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $option kept to match the default_option_{$name} filter signature.
		if ( $passed_default ) {
			return $fallback;
		}

		return $this->defaults();
	}

	/**
	 * The settings page's sections and fields.
	 *
	 * Filterable so a future consumer (e.g. the AI-readability toggles
	 * planned for Issue #25, or the paid add-on) can add its own section to
	 * this same page instead of building a separate one.
	 *
	 * Field shape (per docs/DESIGN-HOOKS-API.md section 3.5): `type`, `label`,
	 * and optionally `default` and a `sanitize` callable. Per the "Settings
	 * API compliant" wording in that doc, a plain 1-argument sanitize
	 * callback (`function( mixed $raw ): mixed` — including a built-in like
	 * `'boolval'`) is valid; this class's own built-in fields use a 2nd
	 * `$fallback` parameter, which call_field_sanitizer() only passes to a
	 * callable that actually declares it. sanitize() honors both — see
	 * sanitize()/sanitize_by_type()/call_field_sanitizer() — so a
	 * `saai_settings_sections` consumer's fields are actually persisted, not
	 * just rendered.
	 *
	 * The value is intentionally typed loosely: a `saai_settings_sections`
	 * callback can return anything, so callers must not assume 'title'/
	 * 'fields' exist (see the is_array()/isset() guards in register_fields()
	 * and sanitize()).
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
						'default'     => 'kb',
						'sanitize'    => array( $this, 'sanitize_slug' ),
					),
					'slug_faq'      => array(
						'type'        => 'text',
						'label'       => __( 'FAQ slug', 'saai-knowledge' ),
						'description' => __( 'The FAQ archive is served at /{slug}/.', 'saai-knowledge' ),
						'default'     => 'faq',
						'sanitize'    => array( $this, 'sanitize_slug' ),
					),
					'slug_glossary' => array(
						'type'        => 'text',
						'label'       => __( 'Glossary slug', 'saai-knowledge' ),
						'description' => __( 'The glossary index is served at /{slug}/.', 'saai-knowledge' ),
						'default'     => 'glossary',
						'sanitize'    => array( $this, 'sanitize_slug' ),
					),
				),
			),
			'autolink'  => array(
				'title'  => __( 'Term Auto-Linking', 'saai-knowledge' ),
				'fields' => array(
					'autolink_post_types' => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Auto-link terms in', 'saai-knowledge' ),
						// saai_glossary is intentionally absent: Autolinker::process()
						// always bails out for a saai_glossary post itself (keeping
						// term definitions free of outgoing auto-links takes
						// priority — see the comment there), so offering it here
						// would be a checkbox with no effect.
						'options' => array(
							'post'     => __( 'Posts', 'saai-knowledge' ),
							'page'     => __( 'Pages', 'saai-knowledge' ),
							'saai_kb'  => __( 'Knowledge Base articles', 'saai-knowledge' ),
							'saai_faq' => __( 'FAQs', 'saai-knowledge' ),
						),
						'default' => array( 'post', 'page', 'saai_kb', 'saai_faq' ),
					),
					'autolink_max_links'  => array(
						'type'        => 'number',
						'min'         => 1,
						'label'       => __( 'Maximum links per post', 'saai-knowledge' ),
						'description' => __( 'A term is only ever linked once per post, regardless of this limit.', 'saai-knowledge' ),
						'default'     => 20,
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
						'default'        => true,
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
						'default'        => false,
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
	 * Resolves a field's identifier.
	 *
	 * Docs/DESIGN-HOOKS-API.md section 3.5 documents the
	 * `saai_settings_sections` field shape as a list (`'fields' => field[]`)
	 * where each field carries its own `id` — e.g.
	 * `array( array( 'id' => 'wc_insert', ... ) )`, a plain numerically
	 * indexed list. This class's own built-in fields instead use the
	 * simpler associative `'field_id' => array( ... )` form. Support both:
	 * an explicit `id` wins; otherwise the array key is used, but only when
	 * it's a non-empty string — a numeric list index (0, 1, 2, ...) is never
	 * a usable option key.
	 *
	 * @param int|string           $field_key The field's key in `$section['fields']`.
	 * @param array<string, mixed> $field     Field definition.
	 * @return string|null Null when neither yields a usable identifier.
	 */
	private function field_id( $field_key, array $field ): ?string {
		if ( isset( $field['id'] ) && is_string( $field['id'] ) && '' !== $field['id'] ) {
			return $field['id'];
		}

		return is_string( $field_key ) && '' !== $field_key ? $field_key : null;
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
	 * The resolved option value: what was actually saved, or — since
	 * filter_default_option() is registered unconditionally in register()
	 * — defaults() when nothing has been saved yet. Only ever an empty
	 * array if get_option() itself returns something other than an array
	 * (e.g. a third-party callback on this same filter hook misbehaving).
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
	 * Starts from the previously stored settings (merged with defaults) and
	 * updates only the fields declared by sections() that are actually
	 * present in $value — a text/number field absent from the submission
	 * entirely (as opposed to present-but-empty) keeps its stored value
	 * rather than resetting to a fallback/default; only checkbox/checkboxes
	 * fields treat absence itself as meaningful ("unchecked"). This is what
	 * lets a `saai_settings_sections` consumer's field (e.g. the paid
	 * add-on's own tab) actually persist through options.php instead of
	 * being silently dropped by a fixed, built-in-only key list. Each
	 * field's own `sanitize` callable (per the docs/DESIGN-HOOKS-API.md
	 * field shape) is honored when present; sanitize_by_type() covers the
	 * common `type`s otherwise, so a consumer that only sets `type` still
	 * gets reasonable sanitization for free.
	 *
	 * Also detects a slug change and, if found, flags a deferred rewrite
	 * flush (and, for slug_glossary, an auto-link dictionary rebuild) — see
	 * finalize_slugs().
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $value ): array {
		$value     = is_array( $value ) ? $value : array();
		$defaults  = $this->defaults();
		$old       = array_merge( $defaults, $this->stored_settings() );
		$sanitized = $old;

		foreach ( $this->sections() as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			foreach ( $section['fields'] as $field_key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_id = $this->field_id( $field_key, $field );

				if ( null === $field_id ) {
					continue;
				}

				$present = array_key_exists( $field_id, $value );
				$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';

				// A checkbox/checkboxes field's key is legitimately absent from
				// $_POST when unchecked — that absence must still be processed
				// (as "off"/empty). Any other field type is always submitted by
				// its <input> when the containing form is, so a genuine absence
				// means this field isn't part of *this* submission at all (e.g.
				// it's declared for a different page/form that also targets this
				// option) — leave its previously stored value alone rather than
				// resetting it to a fallback/default.
				if ( ! $present && ! in_array( $type, array( 'checkbox', 'checkboxes' ), true ) ) {
					continue;
				}

				$raw      = $present ? $value[ $field_id ] : null;
				$fallback = array_key_exists( $field_id, $defaults ) ? $defaults[ $field_id ] : ( $field['default'] ?? null );

				if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
					$sanitized[ $field_id ] = $this->call_field_sanitizer( $field['sanitize'], $raw, $fallback );
				} else {
					$sanitized[ $field_id ] = $this->sanitize_by_type( $field, $raw, $fallback );
				}
			}
		}

		return $this->finalize_slugs( $sanitized, $old );
	}

	/**
	 * Calls a field's `sanitize` callable, passing `$fallback` as a second
	 * argument only when the callable actually declares one.
	 *
	 * Docs/DESIGN-HOOKS-API.md describes a field's `sanitize` as "Settings
	 * API compliant" — a plain 1-argument sanitize_callback (e.g.
	 * `'sanitize' => 'boolval'`) is a valid, unremarkable case under that
	 * wording. This class's own built-in slug fields use a 2nd `$fallback`
	 * parameter (sanitize_slug()), which is not part of the standard
	 * Settings API convention. Unconditionally calling every callable with
	 * 2 arguments would work fine for a user-defined function (PHP silently
	 * ignores extra arguments there) but PHP 8 throws ArgumentCountError for
	 * an *internal* function like `boolval()` called with more arguments
	 * than it declares — which would fatal the entire settings save, not
	 * just this one field.
	 *
	 * @param callable $callback The field's `sanitize` callable.
	 * @param mixed    $raw      Raw submitted value.
	 * @param mixed    $fallback Fallback value.
	 * @return mixed
	 */
	private function call_field_sanitizer( callable $callback, $raw, $fallback ) {
		$accepts_fallback = true;

		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) || $callback instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $callback );
			} else {
				$reflection = null;
			}

			if ( null !== $reflection ) {
				$accepts_fallback = $reflection->getNumberOfParameters() >= 2;
			}
		} catch ( \ReflectionException $e ) {
			// An unresolvable callable (e.g. a private method on another
			// object) is a contract violation on the consumer's part; erring
			// toward the plain Settings API 1-argument convention is the
			// safer default.
			$accepts_fallback = false;
		}

		return $accepts_fallback ? call_user_func( $callback, $raw, $fallback ) : call_user_func( $callback, $raw );
	}

	/**
	 * Generic sanitizer used when a field declares a `type` but no explicit
	 * `sanitize` callable (see sanitize()).
	 *
	 * @param array<string, mixed> $field    Field definition.
	 * @param mixed                $raw      Raw submitted value.
	 * @param mixed                $fallback Fallback value for an empty/invalid `number`.
	 * @return mixed
	 */
	private function sanitize_by_type( array $field, $raw, $fallback ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		switch ( $type ) {
			case 'checkbox':
				return ! empty( $raw );

			case 'checkboxes':
				if ( ! is_array( $raw ) ) {
					return array();
				}

				$clean = array_map(
					static function ( $item ) {
						return is_string( $item ) ? sanitize_key( $item ) : '';
					},
					$raw
				);

				// Restrict to the field's own offered choices when it declares
				// any (e.g. autolink_post_types) — an empty result stays valid,
				// it just means the feature is off for every eligible type.
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$allowed = array_map( 'strval', array_keys( $field['options'] ) );

					return array_values( array_intersect( $allowed, $clean ) );
				}

				return array_values( array_filter( $clean ) );

			case 'number':
				$value = absint( $raw );

				return $value > 0 ? $value : $fallback;

			default:
				return is_string( $raw ) ? sanitize_text_field( $raw ) : $fallback;
		}
	}

	/**
	 * Sanitizes a slug field: `sanitize_title()`'d, falling back to the
	 * default when that leaves nothing (blank input, or input that was only
	 * punctuation/whitespace). The built-in slug_kb/slug_faq/slug_glossary
	 * fields wire this in as their `sanitize` callable (see sections()).
	 *
	 * @param mixed $raw      Raw submitted value.
	 * @param mixed $fallback Fallback slug (a non-string here — a corrupted
	 *                        `saai_default_settings` filter, say — falls
	 *                        through to '' rather than throwing).
	 * @return string
	 */
	private function sanitize_slug( $raw, $fallback ): string {
		$slug = sanitize_title( is_string( $raw ) ? $raw : '' );

		if ( '' !== $slug ) {
			return $slug;
		}

		return is_string( $fallback ) ? $fallback : '';
	}

	/**
	 * Cross-field validation and side effects for the three built-in slug
	 * fields, run after the per-field loop in sanitize() has populated them.
	 *
	 * Rejects a duplicate slug (reverting all three to the previous values)
	 * and, on a real change, flags a deferred rewrite flush (see
	 * maybe_flush_rewrite_rules()). A slug_glossary change additionally
	 * bumps the auto-link dictionary generation: Autolinker's cached
	 * dictionary (`saai_autolink_dict`) stores each glossary term's
	 * get_permalink(), which embeds this slug, so leaving it alone would
	 * keep inserting links to the old (now 404ing) URLs until some unrelated
	 * glossary term save happened to invalidate it. By the time any
	 * front-end request actually rebuilds the dictionary, `init` has already
	 * re-registered saai_glossary with the new slug (same deferred ordering
	 * as the rewrite flush), so the rebuilt permalinks are correct.
	 *
	 * @param array<string, mixed> $sanitized Sanitized settings so far.
	 * @param array<string, mixed> $old       Previously stored settings (merged with defaults).
	 * @return array<string, mixed>
	 */
	private function finalize_slugs( array $sanitized, array $old ): array {
		$slugs = array(
			$sanitized['slug_kb'] ?? $old['slug_kb'],
			$sanitized['slug_faq'] ?? $old['slug_faq'],
			$sanitized['slug_glossary'] ?? $old['slug_glossary'],
		);

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

		if ( $sanitized['slug_glossary'] !== $old['slug_glossary'] ) {
			$generation = (int) get_option( 'saai_dict_generation', 1 );
			update_option( 'saai_dict_generation', $generation + 1, false );
		}

		return $sanitized;
	}

	/**
	 * Filters `saai_autolink_post_types` (see Autolinker::target_post_types())
	 * to Settings' resolved 'autolink_post_types' value — get_option() itself
	 * always resolves to one via filter_default_option() (registered
	 * unconditionally in register()), whether an admin has explicitly saved
	 * one or not. Absent a `saai_default_settings` customization, that
	 * default is identical to Autolinker's own hardcoded
	 * DEFAULT_POST_TYPES, so this is not observably a no-op pre-save; it's
	 * just redundant with it. The incoming $post_types (or any other
	 * filter's value) is only left untouched in the unlikely case
	 * get_option() itself returns something other than an array.
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
