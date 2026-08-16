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
	 * The title the visible page actually rendered for the queried FAQ —
	 * captured from the_title (classic themes) or
	 * render_block_core/post-title (block themes), the same reasoning as
	 * $captured_answer_html but for the question name: a the_title filter
	 * can behave differently depending on in_the_loop() (e.g. a callback
	 * that only translates during the main Loop), so re-deriving it via a
	 * fresh get_the_title() call from wp_footer — outside the Loop — could
	 * diverge from what the <h1> actually showed. Null while unset; unlike
	 * $captured_answer_html, title_text() falls back to a fresh
	 * get_the_title() call in that case rather than suppressing the QAPage
	 * — a the_title filter is not expected to have a shortcode-like side
	 * effect a repeat call would double-apply, so there's no
	 * "never re-derive" requirement here the way there is for the answer.
	 *
	 * @var string|null
	 */
	private static $captured_title = null;

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
	 * Tracks whether a core/post-template (Query Loop) block whose query is
	 * NOT inherited from the main query is currently mid-render — same
	 * purpose as Glossary_Term::$post_template_render_depth: rejects a
	 * core/post-content or core/post-title encountered while iterating a
	 * Query Loop that lists unrelated posts (e.g. a "related FAQs" section
	 * that happens to embed the very FAQ being viewed), which
	 * post_content_render_depth's / post_title_render_depth's own
	 * outermost-render checks alone can't distinguish from the primary
	 * answer/question render.
	 *
	 * An *inherited* Query Loop's "posts" are the main query itself — on a
	 * singular saai_faq view that main query is exactly the one viewed FAQ,
	 * so a core/post-content or core/post-title nested inside such a loop
	 * (a template customized to wrap the primary content in an inherited
	 * Query Loop) is still the primary render, not an unrelated nested one,
	 * and must not be rejected. See track_render_block_context()'s
	 * docblock for how "inherited" is determined.
	 *
	 * @var int
	 */
	private $post_template_render_depth = 0;

	/**
	 * Tracks nested core/post-title block renders — the title counterpart
	 * to $post_content_render_depth, same purpose and reasoning: lets
	 * capture_title_for_block_theme() (on render_block_core/post-title)
	 * tell whether the render it's looking at is the outermost
	 * core/post-title currently unwinding.
	 *
	 * @var int
	 */
	private $post_title_render_depth = 0;

	/**
	 * Tracks the_content filter re-entrancy depth. A shortcode or dynamic
	 * block inside the answer can itself call apply_filters( 'the_content',
	 * ... ) on unrelated content (e.g. a "related post" teaser shortcode) —
	 * that nested call's own PHP_INT_MAX pass through capture_answer_content()
	 * would otherwise claim the capture slot with just the inner fragment
	 * before the outer, complete answer finishes rendering. Same
	 * $was_outermost pattern as $post_content_render_depth, applied to
	 * the_content instead of render_block_core/post-content.
	 *
	 * @var int
	 */
	private $the_content_render_depth = 0;

	/**
	 * Hooks the QAPage JSON-LD capture and output into WordPress.
	 */
	public function register(): void {
		// PHP_INT_MIN: must run before any other the_content callback (core's
		// do_blocks at 9, WP_Embed's handlers at 8, do_shortcode at 11, or a
		// plugin's own callback) could itself trigger a nested
		// apply_filters( 'the_content', ... ) call — see
		// $the_content_render_depth's docblock.
		add_filter( 'the_content', array( $this, 'track_the_content_render_start' ), PHP_INT_MIN );
		add_filter( 'the_content', array( $this, 'capture_answer_content' ), PHP_INT_MAX );
		// PHP_INT_MAX: a the_title filter registered at a later priority
		// (translation, a callback that only runs during the main Loop) is
		// what the visible <h1> actually shows — see capture_title()'s
		// docblock.
		add_filter( 'the_title', array( $this, 'capture_title' ), PHP_INT_MAX, 2 );
		// Block themes render the answer via core/post-content, whose own
		// render callback applies the_content internally but without ever
		// calling WP_Query::the_post() — in_the_loop() (capture_answer_content()'s
		// guard) stays false throughout, so that guard alone never fires
		// there. These mirror Glossary_Term::fire_after_definition_hook_for_block_theme()'s
		// pre_render_block / render_block_core/post-content pairing to detect
		// that render directly instead. Not gated on wp_is_block_theme(): a
		// classic theme can still render this core block via do_blocks()
		// (e.g. inside a widget or a block-built page).
		add_filter( 'pre_render_block', array( $this, 'track_post_content_render_start' ), PHP_INT_MAX, 2 );
		// Same short-circuited-render capture path as
		// track_post_content_render_start(), for core/post-title.
		add_filter( 'pre_render_block', array( $this, 'track_post_title_render_start' ), PHP_INT_MAX, 2 );
		// render_block_context, not pre_render_block, for the depth tracking
		// itself — see track_render_block_context()'s docblock for why it
		// must key off the block's post-render_block_data name/context
		// rather than pre_render_block's pre-rename, context-less view of
		// it. PHP_INT_MAX for the same "see the truly final value" reasoning
		// as render_block_core/post-content below.
		add_filter( 'render_block_context', array( $this, 'track_render_block_context' ), PHP_INT_MAX, 2 );
		// PHP_INT_MAX: a later-priority render_block_core/post-template
		// callback on this same apply_filters() call is still mid-render of
		// that post-template as far as this class's own bookkeeping is
		// concerned — see track_post_template_render_end()'s docblock for
		// why decrementing any earlier would let such a callback's own
		// reentrant core/post-content render (e.g. a read-time estimator
		// re-rendering the block tree for analysis) be mistaken for the
		// primary answer.
		add_filter( 'render_block_core/post-template', array( $this, 'track_post_template_render_end' ), PHP_INT_MAX, 3 );
		// PHP_INT_MAX: WP_Block::render() applies this filter via a plain
		// apply_filters(), so a theme or plugin registered at a later
		// priority (translation, access control, hiding part of the answer)
		// still runs after this and changes what the visitor actually sees.
		// Capturing at the default priority 10 would grab a value that goes
		// stale the moment such a later callback modifies it — the same
		// "capture the truly final value" reasoning as capture_answer_content()'s
		// PHP_INT_MAX priority on the_content.
		add_filter( 'render_block_core/post-content', array( $this, 'capture_answer_content_for_block_theme' ), PHP_INT_MAX, 3 );
		// Same "capture the truly final value" reasoning, for core/post-title.
		add_filter( 'render_block_core/post-title', array( $this, 'capture_title_for_block_theme' ), PHP_INT_MAX, 3 );
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
		$this->post_title_render_depth    = 0;
		$this->the_content_render_depth   = 0;
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
		self::$captured_title                = null;
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
	 * Marks the start of a the_content filter application — see
	 * $the_content_render_depth's docblock. Registered at PHP_INT_MIN so it
	 * runs before any other the_content callback (including a shortcode or
	 * dynamic block inside the answer that reentrantly triggers its own
	 * nested apply_filters( 'the_content', ... ) call) could have already
	 * run.
	 *
	 * @param string $content Pass-through; never modified.
	 * @return string
	 */
	public function track_the_content_render_start( string $content ): string {
		++$this->the_content_render_depth;

		return $content;
	}

	/**
	 * Captures the queried FAQ's rendered answer, right as the classic-theme
	 * Loop produces it — the classic-theme counterpart to
	 * Glossary_Term::append_after_definition_hook(); see that method's
	 * docblock for the reasoning behind each guard.
	 *
	 * $was_outermost_the_content_call (captured from $the_content_render_depth
	 * before decrementing, same pattern as capture_answer_content_for_block_theme()'s
	 * $was_outermost) rejects a the_content call that is itself nested inside
	 * another one still unwinding — a shortcode or dynamic block in the
	 * answer can call apply_filters( 'the_content', ... ) on unrelated
	 * content (e.g. a "related post" teaser shortcode), and without this
	 * guard that inner call's own pass through this method would claim the
	 * capture slot with just the inner fragment, before the outer, complete
	 * answer finishes rendering and reaches this same PHP_INT_MAX priority.
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
		$was_outermost_the_content_call = 1 === $this->the_content_render_depth;
		--$this->the_content_render_depth;

		if ( ! $was_outermost_the_content_call ) {
			return $content;
		}

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
	 * Tracks the start of a core/post-template, core/post-content, or
	 * core/post-title render — see $post_template_render_depth's /
	 * $post_content_render_depth's / $post_title_render_depth's docblocks
	 * — keyed off the block's fully-resolved name and context rather than
	 * pre_render_block's pre-rename, context-less view of it.
	 *
	 * Core's render_block() (wp-includes/blocks.php) applies render_block_data
	 * (which can rename the block) BEFORE constructing the WP_Block that
	 * the dynamic render_block_core/post-template / render_block_core/post-content
	 * / render_block_core/post-title hooks below key off — those dynamic
	 * hook names come from the POST-rename value. render_block_context
	 * fires after that rename has already happened, with $parsed_block
	 * reflecting the same final name, so tracking the start here keeps it
	 * symmetric with those dynamic end hooks: a block a render_block_data
	 * callback renames away from one of these three simply never increments
	 * here (and so never needs, or misses, a matching decrement), rather
	 * than incrementing under the old pre_render_block name and permanently
	 * desyncing the counter when the expected dynamic hook — tied to the
	 * new name — never arrives.
	 *
	 * $context is likewise the block's fully-resolved context, unlike
	 * $parsed_block's own attrs: a core/post-template's own parsed block
	 * carries no "inherit" attribute (that lives on its core/query
	 * ancestor), but core/query's providesContext makes its "query"
	 * attribute — inherit included — available as $context['query'] to
	 * every descendant that declares it in usesContext, which
	 * core/post-template does. An inherited Query Loop's iteration is the
	 * main query itself — on a singular saai_faq view that's exactly the
	 * one viewed FAQ, so its core/post-content or core/post-title is the
	 * primary render, not an unrelated nested one (e.g. a "related FAQs"
	 * section, which never inherits) — see $post_template_render_depth's
	 * docblock.
	 *
	 * Only fires for a block that reaches this point un-short-circuited;
	 * track_post_content_render_start()'s / track_post_title_render_start()'s
	 * pre_render_block hooks separately handle each block's
	 * short-circuited-render capture path, which never reaches
	 * render_block_data/render_block_context at all.
	 *
	 * @param array<string, mixed> $context      The block's resolved context.
	 * @param array<string, mixed> $parsed_block The block about to render, already past render_block_data.
	 * @return array<string, mixed>
	 */
	public function track_render_block_context( array $context, array $parsed_block ): array {
		$block_name = $parsed_block['blockName'] ?? null;

		if ( 'core/post-content' === $block_name ) {
			++$this->post_content_render_depth;
		} elseif ( 'core/post-title' === $block_name ) {
			++$this->post_title_render_depth;
		} elseif ( 'core/post-template' === $block_name && empty( $context['query']['inherit'] ) ) {
			++$this->post_template_render_depth;
		}

		return $context;
	}

	/**
	 * Marks the end of a core/post-template render — the
	 * render_block_core/post-template counterpart to
	 * track_render_block_context(). This filter only ever fires for
	 * core/post-template (its dynamic hook name, resolved from the same
	 * post-rename value track_render_block_context() keys off), so every
	 * call here is one such block finishing its render. Decrements only
	 * when track_render_block_context() would have incremented for it (a
	 * non-inherited query) — an inherited Query Loop's render never
	 * incremented the counter in the first place, so it must not decrement
	 * it either.
	 *
	 * Registered at PHP_INT_MAX (see register()): WP_Block::render() applies
	 * this filter via a plain apply_filters(), so a later-priority callback
	 * on the same hook is still, as far as any of its own side effects go,
	 * mid-render of this same post-template. Decrementing any earlier would
	 * let such a callback's own reentrant render of core/post-content (e.g.
	 * a read-time estimator or analytics plugin re-rendering the block tree
	 * to inspect it) be mistaken for a primary answer render happening
	 * outside any Query Loop, wrongly overwriting the real captured answer
	 * with that discarded, analysis-only render's content.
	 *
	 * @param string               $block_content The rendered post-template block.
	 * @param array<string, mixed> $parsed_block  Parsed block data (unused).
	 * @param \WP_Block            $block         The post-template block instance, for its resolved query context.
	 * @return string
	 */
	public function track_post_template_render_end( string $block_content, array $parsed_block, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the render_block_core/post-template filter signature.
		if ( empty( $block->context['query']['inherit'] ) ) {
			--$this->post_template_render_depth;
		}

		return $block_content;
	}

	/**
	 * Handles core/post-content's short-circuited-render capture path — see
	 * $post_content_render_depth's docblock for the normal (non-short-circuited)
	 * depth tracking, which happens on render_block_context via
	 * track_render_block_context() instead.
	 *
	 * A block-caching plugin (or similar) can register its own, earlier-priority
	 * pre_render_block callback that returns non-null HTML for core/post-content
	 * — render_block() (wp-includes/blocks.php) returns that value immediately
	 * without ever constructing WP_Block or applying render_block_core/post-content,
	 * so capture_answer_content_for_block_theme() (which relies on that filter)
	 * never runs even though the cached answer is genuinely what the visitor
	 * sees. This is the only chance to capture that value: capture directly
	 * from the short-circuited $pre_render here instead, via the same guards
	 * capture_answer_content_for_block_theme() uses (0 === $post_content_render_depth
	 * at this point is this method's equivalent of that method's $was_outermost
	 * — nothing else can be short-circuiting mid-unwind of an already-open,
	 * non-short-circuited post-content render at the moment this fires).
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_content_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render || 'core/post-content' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $pre_render;
		}

		if ( 0 === $this->post_content_render_depth ) {
			$this->capture_post_content_if_matching( $pre_render );
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
	 * rendering some other, unrelated FAQ through the same filter. The rest
	 * of the guards (get_queried_object_id() scoping, password check, slot
	 * claiming) are shared with track_post_content_render_start()'s
	 * short-circuited-render path via capture_post_content_if_matching() —
	 * see that method's docblock.
	 *
	 * @param string               $block_content The rendered post-content block.
	 * @param array<string, mixed> $parsed_block  Parsed block data (unused).
	 * @param \WP_Block            $block         Unused.
	 * @return string
	 */
	public function capture_answer_content_for_block_theme( string $block_content, array $parsed_block, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the render_block_core/post-content filter signature.
		$was_outermost = 1 === $this->post_content_render_depth;
		--$this->post_content_render_depth;

		if ( $was_outermost ) {
			$this->capture_post_content_if_matching( $block_content );
		}

		return $block_content;
	}

	/**
	 * Captures $content as the queried FAQ's answer if it's genuinely the
	 * viewed FAQ's own core/post-content, shared by both
	 * capture_answer_content_for_block_theme() (the normal render path) and
	 * track_post_content_render_start() (the pre_render_block short-circuit
	 * path) — see each caller's docblock for how they establish "this is the
	 * outermost, not-nested-in-another-post-content render" before calling
	 * this.
	 *
	 * $post_template_render_depth rejects a core/post-content encountered
	 * while iterating a Query Loop that includes the viewed FAQ (e.g. a
	 * "related FAQs" section) — a case the callers' own outermost checks
	 * can't catch on their own, since each iteration's core/post-content
	 * sits at the same, non-nested depth. claim_block_capture_slot() rejects
	 * a discarded speculative render from permanently consuming the slot —
	 * see its own docblock. get_queried_object_id() === get_the_ID() scopes
	 * this to the viewed FAQ itself, relying on core/post-template's
	 * the_post() call (or, for the primary render, the block template
	 * canvas's own) having set the global $post to it.
	 *
	 * @param string $content The rendered (or short-circuited) post-content HTML.
	 */
	private function capture_post_content_if_matching( string $content ): void {
		if ( 0 !== $this->post_template_render_depth ) {
			return;
		}

		if ( ! is_singular( 'saai_faq' ) || get_queried_object_id() !== get_the_ID() ) {
			return;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Same reasoning as capture_answer_content()'s equivalent guard: the
		// JSON-LD must not carry a protected FAQ's rendered password form,
		// nor leak the real answer to an unauthenticated visitor.
		if ( post_password_required( $post ) ) {
			return;
		}

		if ( ! self::claim_block_capture_slot( $post->ID ) ) {
			return;
		}

		self::$captured_answer_html = $content;
	}

	/**
	 * Captures the queried FAQ's rendered title, right as the classic-theme
	 * Loop produces it — the title counterpart to capture_answer_content().
	 *
	 * A the_title filter can behave differently depending on in_the_loop()
	 * (e.g. a callback that only translates/replaces during the main Loop)
	 * — capturing here, rather than output_structured_data() re-fetching
	 * get_the_title() from wp_footer (outside the Loop, once
	 * WP_Query::have_posts() has already reset in_the_loop to false), keeps
	 * the JSON-LD question name in sync with what the visible <h1> actually
	 * showed.
	 *
	 * Unlike the_content, 'the_title' hands the target post ID directly
	 * ($id), so there's no need for the_content's re-entrant-call detection
	 * ($the_content_render_depth): a the_title call for a different post's
	 * $id simply won't match get_queried_object_id() below. And unlike
	 * capture_answer_content(), this always overwrites rather than claiming
	 * a one-time slot: a the_title filter is not expected to have a
	 * shortcode-like side effect a repeat capture would double-apply, so
	 * there's no "first call wins" requirement here — see title_text()'s
	 * docblock for the same reasoning applied to why this is allowed to
	 * simply go uncaptured (title_text() falls back to a fresh
	 * get_the_title() call) rather than suppressing the whole QAPage the
	 * way an uncaptured answer does.
	 *
	 * @param string $title The post title, already run through the_title.
	 * @param int    $id    The post ID the_title fired for.
	 * @return string
	 */
	public function capture_title( string $title, $id ): string {
		if ( ! in_the_loop() || ! is_singular( 'saai_faq' ) ) {
			return $title;
		}

		if ( get_queried_object_id() !== (int) $id ) {
			return $title;
		}

		$post = get_post( (int) $id );

		if ( ! $post instanceof \WP_Post ) {
			return $title;
		}

		// Same reasoning as capture_answer_content()'s equivalent guard,
		// applied to the title: get_the_title() already prepends
		// "Protected: " for a password-protected post before this filter
		// runs, and while that prefix itself isn't secret,
		// output_structured_data() already suppresses the whole QAPage for
		// a protected FAQ — capturing here too keeps this guarded the same
		// way every other capture point in this class is, rather than
		// relying solely on that later check.
		if ( post_password_required( $post ) ) {
			return $title;
		}

		self::$captured_title = $title;

		return $title;
	}

	/**
	 * Handles core/post-title's short-circuited-render capture path — the
	 * title counterpart to track_post_content_render_start(); see that
	 * method's docblock for the reasoning (a block-caching plugin returning
	 * non-null HTML for core/post-title via an earlier-priority
	 * pre_render_block callback bypasses render_block_core/post-title
	 * entirely, so this is the only chance to capture that value).
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_title_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render || 'core/post-title' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $pre_render;
		}

		if ( 0 === $this->post_title_render_depth ) {
			$this->capture_post_title_if_matching( $pre_render );
		}

		return $pre_render;
	}

	/**
	 * Captures the queried FAQ's rendered title from a block theme's
	 * core/post-title render — the title counterpart to
	 * capture_answer_content_for_block_theme(). $was_outermost (captured
	 * from $post_title_render_depth before decrementing) rejects a
	 * core/post-title nested inside the one actually being captured, same
	 * reasoning as that method's.
	 *
	 * @param string               $block_content The rendered post-title block.
	 * @param array<string, mixed> $parsed_block  Parsed block data (unused).
	 * @param \WP_Block            $block         Unused.
	 * @return string
	 */
	public function capture_title_for_block_theme( string $block_content, array $parsed_block, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the render_block_core/post-title filter signature.
		$was_outermost = 1 === $this->post_title_render_depth;
		--$this->post_title_render_depth;

		if ( $was_outermost ) {
			$this->capture_post_title_if_matching( $block_content );
		}

		return $block_content;
	}

	/**
	 * Captures $content as the queried FAQ's title if it's genuinely the
	 * viewed FAQ's own core/post-title, shared by both
	 * capture_title_for_block_theme() (the normal render path) and
	 * track_post_title_render_start() (the pre_render_block short-circuit
	 * path) — the title counterpart to capture_post_content_if_matching();
	 * see that method's docblock for the shared $post_template_render_depth
	 * / queried-post / password guard reasoning.
	 *
	 * No claim-slot the way capture_post_content_if_matching() has: unlike
	 * core/post-content, there's no known speculative-pre-render scenario
	 * to guard against here (get_the_excerpt()'s nested the_content call
	 * has no title analog), and a plain title string has no side effect a
	 * repeat capture could double-apply, so this simply overwrites.
	 *
	 * @param string $content The rendered (or short-circuited) post-title HTML.
	 */
	private function capture_post_title_if_matching( string $content ): void {
		if ( 0 !== $this->post_template_render_depth ) {
			return;
		}

		if ( ! is_singular( 'saai_faq' ) || get_queried_object_id() !== get_the_ID() ) {
			return;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			return;
		}

		self::$captured_title = $content;
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
		// same check as Faq_List::json_ld(). is_blank() rather than a plain
		// '' check: a title of pure whitespace passes title_text() unchanged
		// and must be Unicode-blank-checked.
		if ( self::is_blank( $this->title_text( $post ) ) ) {
			return;
		}

		// The same wp_insert_post_empty_content() reasoning allows a
		// title-only saai_faq whose body is empty or markup-only (e.g. a
		// single empty paragraph block) — captured_answer_html would then be
		// '' rather than null, passing the null check above, but the
		// required acceptedAnswer.text must not be emitted empty either.
		// is_blank() rather than trim(): a classic-editor "<p>&nbsp;</p>"
		// answer decodes to a lone U+00A0, which trim() does not strip.
		if ( self::is_blank( $this->answer_text() ) ) {
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
				'name'           => $this->title_text( $post ),
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
	 * or adjacent block elements like "<section>First</section><section>Second</section>"
	 * would otherwise collapse into the single word "FirstSecond". Line-break
	 * points are converted to a literal newline first so the plain-text
	 * answer keeps the same word boundaries the visible page shows. The
	 * block-level tag set is core's own wpautop() $allblocks list
	 * (wp-includes/formatting.php) rather than a hand-picked few tag names:
	 * it's the same list WordPress itself uses to decide where a block
	 * boundary is, so it already covers every block-level element a
	 * classic-editor or block-theme answer could contain (section, article,
	 * ul, figure, etc.) without this needing its own, easily-incomplete
	 * enumeration.
	 *
	 * @return string
	 */
	private function answer_text(): string {
		$blocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

		$html = self::$captured_answer_html ?? '';
		// Opening tags get the break before them (nothing follows an opening
		// tag on the same line to separate it from), closing tags after —
		// same two-pass placement as wpautop()'s own handling of $allblocks.
		$html = (string) preg_replace( '#<' . $blocks . '[\s/>]#i', "\n" . '$0', $html );
		$html = (string) preg_replace( '#</' . $blocks . '>|<br\s*/?>#i', '$0' . "\n", $html );

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The captured title as plain text — $captured_title is what the
	 * visible page's <h1> actually rendered (see capture_title()'s
	 * docblock for why that can differ from a fresh get_the_title() call),
	 * falling back to get_the_title( $post ) when nothing was captured
	 * (e.g. an unusual template override that never renders core/post-title
	 * or calls the_title() at all). Unlike answer_text(), a fallback here
	 * is safe: get_the_title() has no shortcode-like side effect a repeat
	 * call could double-apply, unlike re-rendering the answer.
	 *
	 * Both get_the_title() and the_title filters encode characters as HTML
	 * references (e.g. & -> &#038;); JSON-LD consumers never HTML-decode,
	 * so decode to plain text after stripping tags. Same reasoning as
	 * Faq_List::json_ld() / Glossary_Term::json_ld().
	 *
	 * @param \WP_Post $post The FAQ entry, for the get_the_title() fallback.
	 * @return string
	 */
	private function title_text( \WP_Post $post ): string {
		$title = self::$captured_title ?? get_the_title( $post );

		return html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Whether $text has no visible content once Unicode whitespace is
	 * discounted, not just the ASCII space/tab/newline set trim() strips.
	 * A classic-editor "<p>&nbsp;</p>" answer decodes to a lone U+00A0
	 * (non-breaking space), and a title of pure regular spaces is equally
	 * blank; plain trim() treats both as non-empty.
	 *
	 * @param string $text Text to test, already tag-stripped/entity-decoded.
	 * @return bool
	 */
	private static function is_blank( string $text ): bool {
		return '' === preg_replace( '/[\s\x{00A0}]+/u', '', $text );
	}
}
