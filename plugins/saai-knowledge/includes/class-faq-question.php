<?php
/**
 * Single FAQ page support: QAPage JSON-LD.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the schema.org QAPage for a saai_faq singular view.
 *
 * A saai_faq post is already "1 post = 1 Q&A" (title = question, content =
 * answer, docs/DESIGN.md section 3.1), and the block/classic single templates
 * render it directly via core/post-title + core/post-content — there is no
 * dedicated block to emit the JSON-LD from inline the way Faq_List does for
 * the FAQPage on the archive.
 *
 * Rather than independently re-rendering the answer (do_blocks()/
 * do_shortcode()) to build the JSON-LD text, this captures the exact HTML the
 * visible page already renders — via the_content (classic themes) or
 * render_block_core/post-content (block themes), mirroring Glossary_Term's
 * saai_glossary_after_definition insertion point — and defers the QAPage
 * <script> tag itself to wp_footer, once that capture has happened. This
 * avoids re-running shortcode/block callbacks a second time for the same
 * request: a shortcode with side effects (a view counter, a one-time token)
 * would otherwise fire once for this hidden render and again for the real
 * one, double-applying the side effect and potentially disagreeing with what
 * the visible page shows.
 */
final class Faq_Question {

	/**
	 * Whether the classic-theme capture (capture_answer_content()) has
	 * already claimed this request's answer for the current main query's
	 * Loop pass — see that method's docblock.
	 *
	 * Static, not per-instance: Plugin::register_services() constructs one
	 * Faq_Question and registers its hooks once for the process's lifetime,
	 * but a test (or any other code building its own instance to call
	 * register() again) would otherwise add a second, independent the_content
	 * filter callback — same double-registration hazard
	 * Faq_List::$rendering avoids by being static rather than per-instance.
	 *
	 * @var bool
	 */
	private static $classic_capture_claimed = false;

	/**
	 * The post ID of the FAQ that has claimed this request's answer capture
	 * from the block-theme render path — see claim_block_capture_slot() —
	 * or null while unclaimed. Separate from $classic_capture_claimed
	 * because the two run on different hooks (the_content vs.
	 * render_block_core/post-content) and, per capture_answer_content()'s
	 * $post_content_render_depth guard, only one of them ever actually
	 * captures for a given request.
	 *
	 * @var int|null
	 */
	private static $block_capture_claimed_post_id = null;

	/**
	 * The exact HTML the visible page rendered for the queried FAQ's answer
	 * — post-do_blocks()/do_shortcode()/wp_filter_content_tags, captured
	 * from the_content or render_block_core/post-content — or null if
	 * nothing has been captured yet this request (including: the theme
	 * never actually rendered the answer, in which case output_structured_data()
	 * has nothing truthful to report and skips the QAPage entirely).
	 *
	 * @var string|null
	 */
	private static $captured_answer_html = null;

	/**
	 * Tracks nested core/post-content block renders — same purpose and
	 * reasoning as Glossary_Term::$post_content_render_depth: it lets
	 * capture_answer_content_for_block_theme() (on
	 * render_block_core/post-content) tell whether the render it's looking
	 * at is the outermost core/post-content currently unwinding, and lets
	 * capture_answer_content() (on the_content) tell whether it's firing as
	 * a side effect of that same block's render callback rather than a
	 * classic theme's Loop.
	 *
	 * @var int
	 */
	private $post_content_render_depth = 0;

	/**
	 * Tracks whether a core/post-template (Query Loop) block is currently
	 * mid-render — same purpose as Glossary_Term::$post_template_render_depth:
	 * rejects a core/post-content encountered while iterating a Query Loop
	 * (e.g. a "related FAQs" section embedding the very FAQ being viewed),
	 * which post_content_render_depth's outermost-render check alone can't
	 * distinguish from the primary answer body.
	 *
	 * @var int
	 */
	private $post_template_render_depth = 0;

	/**
	 * Hooks the QAPage JSON-LD capture and output into WordPress.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'capture_answer_content' ), PHP_INT_MAX );
		// Block themes render the answer via core/post-content, whose own
		// render callback applies the_content internally but without ever
		// calling WP_Query::the_post() — in_the_loop() (capture_answer_content()'s
		// guard) stays false throughout, so that guard alone never fires
		// there. These mirror Glossary_Term::fire_after_definition_hook_for_block_theme()'s
		// pre_render_block / render_block_core/post-content pairing to detect
		// that render directly instead. Not gated on wp_is_block_theme(): a
		// classic theme can still render this core block via do_blocks()
		// (e.g. inside a widget or a block-built page).
		add_filter( 'pre_render_block', array( $this, 'track_post_template_render_start' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block_core/post-template', array( $this, 'track_post_template_render_end' ) );
		add_filter( 'pre_render_block', array( $this, 'track_post_content_render_start' ), PHP_INT_MAX, 2 );
		// PHP_INT_MAX: WP_Block::render() applies this filter via a plain
		// apply_filters(), so a theme or plugin registered at a later
		// priority (translation, access control, hiding part of the answer)
		// still runs after this and changes what the visitor actually sees.
		// Capturing at the default priority 10 would grab a value that goes
		// stale the moment such a later callback modifies it — the same
		// "capture the truly final value" reasoning as capture_answer_content()'s
		// PHP_INT_MAX priority on the_content.
		add_filter( 'render_block_core/post-content', array( $this, 'capture_answer_content_for_block_theme' ), PHP_INT_MAX, 3 );
		// wp_head has already fired by the time either capture hook above
		// can possibly have run (the answer body renders in <body>), so the
		// QAPage <script> tag is emitted from wp_footer instead — Google
		// doesn't require JSON-LD to be in <head>.
		add_action( 'wp_footer', array( $this, 'output_structured_data' ) );
		// A real HTTP request is a fresh PHP process, so the statics above
		// start unset naturally, but a single long-running script rendering
		// more than one FAQ in the same process (a WP-CLI export tool, or
		// this test suite itself) reuses this instance across each one —
		// same reasoning as Faq_List::reset_render_state().
		add_action( 'pre_get_posts', array( $this, 'reset_capture_state' ) );
	}

	/**
	 * Resets the per-request capture state for each new main query.
	 *
	 * @param \WP_Query $query The query WordPress is about to run.
	 */
	public function reset_capture_state( \WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		self::reset_state();
		$this->post_content_render_depth  = 0;
		$this->post_template_render_depth = 0;
	}

	/**
	 * Resets all static capture state directly — the reset_capture_state()
	 * internals, exposed for tests that exercise the request-scoped guards
	 * without running a main query.
	 */
	public static function reset_state(): void {
		self::$classic_capture_claimed       = false;
		self::$block_capture_claimed_post_id = null;
		self::$captured_answer_html          = null;
	}

	/**
	 * Claims this request's answer capture for the block-theme render path
	 * — the capture_answer_content_for_block_theme() counterpart to
	 * Faq_List::claim_structured_data_slot() / Glossary_Term::claim_block_definition_slot().
	 *
	 * A post ID rather than a boolean: a theme or SEO plugin can render a
	 * FAQ's core/post-content speculatively (excerpt generation, metadata
	 * analysis) ahead of the visible template pass, and that discarded
	 * speculative render must not permanently consume the slot with content
	 * that never actually reached a visitor. Reclaiming by post ID lets the
	 * later, visible render of the SAME FAQ re-capture and overwrite it.
	 *
	 * Known accepted trade-off, same as Faq_List's / Glossary_Term's: the
	 * rare case of the same FAQ's body genuinely placed twice, non-nested,
	 * in one visible template re-captures the same content twice, which is
	 * harmless.
	 *
	 * @param int $post_id The queried FAQ's post ID.
	 * @return bool Whether the caller may capture.
	 */
	private static function claim_block_capture_slot( int $post_id ): bool {
		if ( null === self::$block_capture_claimed_post_id ) {
			self::$block_capture_claimed_post_id = $post_id;

			return true;
		}

		return self::$block_capture_claimed_post_id === $post_id;
	}

	/**
	 * Captures the queried FAQ's rendered answer, right as the classic-theme
	 * Loop produces it — the classic-theme counterpart to
	 * Glossary_Term::append_after_definition_hook(); see that method's
	 * docblock for the reasoning behind each guard.
	 *
	 * $post_content_render_depth being nonzero here means this the_content
	 * call is firing as a side effect of a core/post-content render (the
	 * block-theme case, already covered by capture_answer_content_for_block_theme()),
	 * so it defers rather than capturing a second, possibly differently-scoped
	 * value.
	 *
	 * get_the_excerpt() also applies the_content internally (via
	 * wp_trim_excerpt(), core's default get_the_excerpt callback) when a
	 * post has no manual excerpt, to derive one from the content — and that
	 * nested call satisfies in_the_loop() and the queried-post check below
	 * just as validly as the real one. A classic theme calling the_excerpt()
	 * ahead of the_content() (a "related FAQs" teaser list, an archive-style
	 * summary) would otherwise have this claim the slot first with
	 * wp_trim_excerpt()'s intermediate value — shortcodes already stripped
	 * via strip_shortcodes() rather than expanded, dynamic blocks reduced by
	 * excerpt_remove_blocks() — permanently pre-empting the real capture
	 * that follows. doing_filter( 'get_the_excerpt' ) reliably detects that
	 * nested call and defers to it instead.
	 *
	 * @param string $content The post content, already run through the_content.
	 * @return string
	 */
	public function capture_answer_content( string $content ): string {
		if ( $this->post_content_render_depth > 0 || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}

		if ( self::$classic_capture_claimed || ! in_the_loop() || ! is_singular( 'saai_faq' ) ) {
			return $content;
		}

		if ( get_queried_object_id() !== get_the_ID() ) {
			return $content;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		// get_the_content() already swapped $content for
		// get_the_password_form() when the FAQ is protected; the JSON-LD
		// must not carry that password form as if it were the real answer,
		// nor leak the real answer to an unauthenticated visitor via the
		// page source. Same guard as Glossary_Term's equivalent.
		if ( post_password_required( $post ) ) {
			return $content;
		}

		self::$classic_capture_claimed = true;
		self::$captured_answer_html    = $content;

		return $content;
	}

	/**
	 * Marks the start of a core/post-template (Query Loop) render — see
	 * $post_template_render_depth's docblock. Identical to
	 * Glossary_Term::track_post_template_render_start(): only increments if
	 * $pre_render is still null, since a lower-priority pre_render_block
	 * callback may have already short-circuited this same block, in which
	 * case render_block_core_post_template() (and with it,
	 * track_post_template_render_end(), the filter that decrements this)
	 * never runs.
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_template_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render && 'core/post-template' === ( $parsed_block['blockName'] ?? null ) ) {
			++$this->post_template_render_depth;
		}

		return $pre_render;
	}

	/**
	 * Marks the end of a core/post-template render — the
	 * render_block_core/post-template counterpart to
	 * track_post_template_render_start(). This filter only ever fires for
	 * core/post-template (its dynamic hook name), so every call here is one
	 * such block finishing its render.
	 *
	 * @param string $block_content The rendered post-template block.
	 * @return string
	 */
	public function track_post_template_render_end( string $block_content ): string {
		--$this->post_template_render_depth;

		return $block_content;
	}

	/**
	 * Marks the start of a core/post-content block render — see
	 * $post_content_render_depth's docblock. Identical reasoning to
	 * track_post_template_render_start(): only increments if $pre_render is
	 * still null.
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_content_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render && 'core/post-content' === ( $parsed_block['blockName'] ?? null ) ) {
			++$this->post_content_render_depth;
		}

		return $pre_render;
	}

	/**
	 * Captures the queried FAQ's rendered answer from a block theme's
	 * core/post-content render — the block-theme counterpart to
	 * capture_answer_content(); see that method's docblock for why
	 * in_the_loop() alone can't detect this case.
	 *
	 * Mirrors Glossary_Term::fire_after_definition_hook_for_block_theme():
	 * $was_outermost (captured from $post_content_render_depth before
	 * decrementing) rejects a core/post-content nested inside the one
	 * actually being captured — a Query Loop the answer body itself embeds,
	 * rendering some other, unrelated FAQ through the same filter.
	 * $post_template_render_depth separately rejects a core/post-content
	 * encountered while iterating a Query Loop that includes the viewed FAQ
	 * (e.g. a "related FAQs" section) — a case $was_outermost can't catch on
	 * its own, since each iteration's core/post-content sits at the same,
	 * non-nested depth. claim_block_capture_slot() then rejects a discarded
	 * speculative render from permanently consuming the slot — see its own
	 * docblock. get_queried_object_id() === get_the_ID() scopes this to the
	 * viewed FAQ itself, relying on core/post-template's the_post() call (or,
	 * for the primary render, the block template canvas's own) having set
	 * the global $post to it.
	 *
	 * @param string               $block_content The rendered post-content block.
	 * @param array<string, mixed> $parsed_block  Parsed block data (unused).
	 * @param \WP_Block            $block         Unused.
	 * @return string
	 */
	public function capture_answer_content_for_block_theme( string $block_content, array $parsed_block, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the render_block_core/post-content filter signature.
		$was_outermost = 1 === $this->post_content_render_depth;
		--$this->post_content_render_depth;

		if ( ! $was_outermost || 0 !== $this->post_template_render_depth ) {
			return $block_content;
		}

		if ( ! is_singular( 'saai_faq' ) || get_queried_object_id() !== get_the_ID() ) {
			return $block_content;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return $block_content;
		}

		// Same reasoning as capture_answer_content()'s equivalent guard: the
		// JSON-LD must not carry a protected FAQ's rendered password form,
		// nor leak the real answer to an unauthenticated visitor.
		if ( post_password_required( $post ) ) {
			return $block_content;
		}

		if ( ! self::claim_block_capture_slot( $post->ID ) ) {
			return $block_content;
		}

		self::$captured_answer_html = $block_content;

		return $block_content;
	}

	/**
	 * Outputs the QAPage JSON-LD for a saai_faq singular view, once the
	 * visible answer has actually rendered — see the class docblock for why
	 * this fires from wp_footer using captured content rather than
	 * re-rendering the answer itself.
	 */
	public function output_structured_data(): void {
		if ( ! is_singular( 'saai_faq' ) || ! $this->structured_data_enabled() ) {
			return;
		}

		// Nothing was captured: either the theme never actually rendered the
		// answer (an unusual template override), or it was protected/empty
		// and the capture hooks already declined — either way there is
		// nothing truthful to report.
		if ( null === self::$captured_answer_html ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Redundant with the capture hooks already declining to capture a
		// protected FAQ's password form, kept as defense in depth: an
		// unauthenticated visitor must not be able to read the protected
		// answer out of the page source via this JSON-LD. Same guard as
		// Glossary_Term's equivalent.
		if ( post_password_required( $post ) ) {
			return;
		}

		// wp_insert_post_empty_content() only rejects a post whose title,
		// content, AND excerpt are all empty, so a saai_faq with a blank
		// title but non-empty content is a valid, admin-savable post. The
		// data model's title = question means an empty title has no
		// question text, so the required Question.name would be empty too;
		// suppress the whole QAPage rather than emit an invalid one. Not
		// empty(): an FAQ legitimately titled "0" must not be dropped —
		// same check as Faq_List::json_ld().
		if ( '' === get_the_title( $post ) ) {
			return;
		}

		// The same wp_insert_post_empty_content() reasoning allows a
		// title-only saai_faq whose body is empty or markup-only (e.g. a
		// single empty paragraph block) — captured_answer_html would then be
		// '' rather than null, passing the null check above, but the
		// required acceptedAnswer.text must not be emitted empty either.
		if ( '' === trim( $this->answer_text() ) ) {
			return;
		}

		$encoded = wp_json_encode( $this->json_ld( $post ), JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			return;
		}

		printf( '<script type="application/ld+json">%s</script>' . "\n", $encoded ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is safe JSON, not HTML; see Faq_List's identical convention.
	}

	/**
	 * Builds the schema.org QAPage for a single FAQ entry.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return array<string, mixed>
	 */
	public function json_ld( \WP_Post $post ): array {
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				// get_the_title() encodes characters as HTML references (the_title
				// filter, e.g. & -> &#038;); JSON-LD consumers never HTML-decode,
				// so decode to plain text after stripping tags. Same reasoning as
				// Faq_List::json_ld() / Glossary_Term::json_ld().
				'name'           => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				// Required by Google's Q&A structured data guidelines. The data
				// model is always 1 post = 1 answer (docs/DESIGN.md section 3.1),
				// so this is never anything but 1.
				'answerCount'    => 1,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $this->answer_text(),
				),
			),
		);

		/** This filter is documented in includes/class-breadcrumbs.php */
		$filtered_schema = apply_filters( 'saai_structured_data', $schema, 'qa-page', $post );

		// A third-party saai_structured_data callback can return a non-array at runtime.
		return is_array( $filtered_schema ) ? $filtered_schema : $schema;
	}

	/**
	 * Whether structured data output is enabled in settings. Same logic as
	 * Faq_List::structured_data_enabled() / Glossary_Term::structured_data_enabled().
	 *
	 * @return bool
	 */
	public function structured_data_enabled(): bool {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( ! is_array( $settings ) || ! array_key_exists( 'structured_data', $settings ) ) {
			return true;
		}

		return (bool) $settings['structured_data'];
	}

	/**
	 * The captured answer as plain text: the docs/DESIGN.md section 7.1
	 * "1ページで回答が完結する" requirement means this must reflect the whole
	 * answer, not a short summary — unlike Glossary_Term::description()'s
	 * 55-word trim for DefinedTerm, this is not truncated.
	 *
	 * Tag removal below deletes tags without inserting a separator (
	 * wp_strip_all_tags() uses strip_tags() internally), so "First<br>Second"
	 * or adjacent block elements like "<p>First</p><p>Second</p>" would
	 * otherwise collapse into the single word "FirstSecond". Line-break
	 * points are converted to a literal newline first so the plain-text
	 * answer keeps the same word boundaries the visible page shows.
	 *
	 * @return string
	 */
	private function answer_text(): string {
		$html = (string) preg_replace( '#</(?:p|div|li|h[1-6]|blockquote|pre|tr|td|th)>|<br\s*/?>#i', '$0' . "\n", self::$captured_answer_html ?? '' );

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
}
