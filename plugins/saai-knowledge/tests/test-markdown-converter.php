<?php
/**
 * Tests for Markdown_Converter's HTML-to-Markdown conversion.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Markdown_Converter;

/**
 * Class Test_Markdown_Converter.
 */
class Test_Markdown_Converter extends WP_UnitTestCase {

	/**
	 * Empty input converts to an empty string.
	 */
	public function test_empty_input_returns_empty_string() {
		$this->assertSame( '', Markdown_Converter::convert( '' ) );
		$this->assertSame( '', Markdown_Converter::convert( '   ' ) );
	}

	/**
	 * Paragraphs become plain text blocks separated by a blank line.
	 */
	public function test_paragraphs_are_separated_by_blank_line() {
		$markdown = Markdown_Converter::convert( '<p>First.</p><p>Second.</p>' );

		$this->assertSame( "First.\n\nSecond.\n", $markdown );
	}

	/**
	 * Headings map to the matching number of '#' characters.
	 */
	public function test_headings_map_to_hash_prefixes() {
		$markdown = Markdown_Converter::convert( '<h2>Section</h2><h3>Subsection</h3>' );

		$this->assertStringContainsString( "## Section\n", $markdown );
		$this->assertStringContainsString( "### Subsection\n", $markdown );
	}

	/**
	 * Bold/italic and links use standard Markdown emphasis/link syntax.
	 */
	public function test_emphasis_and_links() {
		$markdown = Markdown_Converter::convert( '<p><strong>bold</strong> <em>italic</em> <a href="https://example.com">a link</a></p>' );

		$this->assertStringContainsString( '**bold**', $markdown );
		$this->assertStringContainsString( '_italic_', $markdown );
		$this->assertStringContainsString( '[a link](<https://example.com>)', $markdown );
	}

	/**
	 * An unordered list becomes '- ' bullet lines.
	 */
	public function test_unordered_list() {
		$markdown = Markdown_Converter::convert( '<ul><li>One</li><li>Two</li></ul>' );

		$this->assertStringContainsString( "- One\n", $markdown );
		$this->assertStringContainsString( "- Two\n", $markdown );
	}

	/**
	 * An ordered list is numbered, and a nested list under one item is
	 * indented beneath it.
	 */
	public function test_ordered_list_with_nested_sublist_is_indented() {
		$markdown = Markdown_Converter::convert( '<ol><li>Parent<ul><li>Child</li></ul></li><li>Second</li></ol>' );

		$this->assertStringContainsString( "1. Parent\n", $markdown );
		// 3 spaces: the width of the parent's own "1. " marker, not a flat
		// 2-space-per-level indent (see test_nested_list_indent_matches_two_digit_marker_width()).
		$this->assertStringContainsString( "   - Child\n", $markdown );
		$this->assertStringContainsString( "2. Second\n", $markdown );
	}

	/**
	 * A blockquote's lines are each prefixed with '> '.
	 */
	public function test_blockquote_is_prefixed() {
		$markdown = Markdown_Converter::convert( '<blockquote><p>Quoted text.</p></blockquote>' );

		$this->assertStringContainsString( '> Quoted text.', $markdown );
	}

	/**
	 * A <pre><code> block becomes a fenced code block, and its contents are
	 * not otherwise re-processed as Markdown (e.g. asterisks stay literal).
	 */
	public function test_pre_code_becomes_fenced_block() {
		$markdown = Markdown_Converter::convert( '<pre><code>$x = 1 * 2;</code></pre>' );

		$this->assertStringContainsString( "```\n\$x = 1 * 2;\n```", $markdown );
	}

	/**
	 * An inline <code> (not inside <pre>) is wrapped in backticks.
	 */
	public function test_inline_code_uses_backticks() {
		$markdown = Markdown_Converter::convert( '<p>Run <code>wp cli</code> now.</p>' );

		$this->assertStringContainsString( '`wp cli`', $markdown );
	}

	/**
	 * Inline code containing its own backtick uses a longer delimiter (with
	 * padding spaces, per CommonMark, since the code starts/ends with a
	 * backtick) instead of a fixed single backtick that would close the
	 * span early (Codex review).
	 */
	public function test_inline_code_with_backtick_uses_longer_padded_delimiter() {
		$markdown = Markdown_Converter::convert( '<p><code>a`b</code></p>' );

		$this->assertStringContainsString( '``a`b``', $markdown );
	}

	/**
	 * A table becomes a pipe table with a header separator row, and a pipe
	 * character inside a cell is escaped so it can't be mistaken for a
	 * column boundary.
	 */
	public function test_table_becomes_pipe_table_with_escaped_pipes() {
		$markdown = Markdown_Converter::convert( '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>x | y</td></tr></table>' );

		$this->assertStringContainsString( "| A | B |\n", $markdown );
		$this->assertStringContainsString( "| --- | --- |\n", $markdown );
		$this->assertStringContainsString( '| 1 | x \\| y |', $markdown );
	}

	/**
	 * An image becomes a Markdown image reference using its alt text.
	 */
	public function test_image_uses_alt_text() {
		$markdown = Markdown_Converter::convert( '<img src="https://example.com/a.png" alt="A description">' );

		$this->assertStringContainsString( '![A description](<https://example.com/a.png>)', $markdown );
	}

	/**
	 * An unescaped ']'/'[' in alt text would close the image's label early
	 * and start a second, unintended image/link (Codex review).
	 */
	public function test_image_alt_text_is_escaped() {
		$markdown = Markdown_Converter::convert( '<img src="https://example.com/a.png" alt="x](/other) [y">' );

		$this->assertStringContainsString( '![x\\](/other) \\[y](<https://example.com/a.png>)', $markdown );
	}

	/**
	 * A link/image destination containing an unbalanced ')' would otherwise
	 * close the Markdown link/image early — CommonMark's `<...>`
	 * angle-bracket destination form tolerates parentheses freely (Copilot
	 * review).
	 */
	public function test_link_and_image_destinations_with_parentheses_are_wrapped_in_angle_brackets() {
		$link_markdown = Markdown_Converter::convert( '<a href="https://example.com/wiki/Foo_(bar)">link</a>' );
		$this->assertStringContainsString( '[link](<https://example.com/wiki/Foo_(bar)>)', $link_markdown );

		$image_markdown = Markdown_Converter::convert( '<img src="https://example.com/a_(1).png" alt="alt">' );
		$this->assertStringContainsString( '![alt](<https://example.com/a_(1).png>)', $image_markdown );
	}

	/**
	 * <script>/<style> contents are dropped entirely, not leaked as text.
	 */
	public function test_script_and_style_are_stripped() {
		$markdown = Markdown_Converter::convert( '<p>Visible</p><script>alert(1)</script><style>.a{color:red}</style>' );

		$this->assertStringContainsString( 'Visible', $markdown );
		$this->assertStringNotContainsString( 'alert', $markdown );
		$this->assertStringNotContainsString( 'color:red', $markdown );
	}

	/**
	 * A <pre><code> block's own leading indentation (e.g. an indented
	 * Python/YAML snippet) survives conversion — only the wrapping
	 * newline(s) around the block are trimmed, not whitespace that's part
	 * of the code itself (Codex review).
	 */
	public function test_pre_code_preserves_leading_indentation_on_first_line() {
		$markdown = Markdown_Converter::convert( "<pre><code>    def foo():\n        return 1</code></pre>" );

		$this->assertStringContainsString( "```\n    def foo():\n        return 1\n```", $markdown );
	}

	/**
	 * The newlines the_content puts between blocks don't indent the next
	 * block: Python-Markdown (even with fenced_code) only recognizes a
	 * fence or an ATX heading at column 0, so " ```" left the code's
	 * <script> to render as a raw element there (Issue #81).
	 */
	public function test_block_separating_whitespace_does_not_indent_the_next_block() {
		$html = "<p>Intro</p>\n\n<pre class=\"wp-block-code\"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>\n\n<h2>Next</h2>\n\n<p>After</p>";

		$this->assertSame( "Intro\n\n```\n<script>alert(1)</script>\n```\n\n## Next\n\nAfter\n", Markdown_Converter::convert( $html ) );
	}

	/**
	 * Whitespace that is the first child of a container (core/group's
	 * <div>, core/details) doesn't indent the container's first block
	 * either, even though nothing precedes it in the container's own output
	 * (Issue #81).
	 */
	public function test_whitespace_at_the_start_of_a_container_does_not_indent_its_first_block() {
		$code = '<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>';

		$this->assertSame(
			"Intro\n\n```\n<b>x</b>\n```\n\nAfter\n",
			Markdown_Converter::convert( "<p>Intro</p>\n\n<div class=\"wp-block-group\">\n" . $code . "\n</div>\n\n<p>After</p>" )
		);
		$this->assertSame(
			"**Q**\n\n```\n<b>x</b>\n```\n",
			Markdown_Converter::convert( "<details><summary>Q</summary>\n" . $code . "\n</details>" )
		);
	}

	/**
	 * A code block after a list, or inside a blockquote, starts at the
	 * start of its line (after the "> " prefix in a blockquote) (Issue #81).
	 */
	public function test_code_after_a_list_or_inside_a_quote_starts_at_the_line_start() {
		$code = '<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>';

		$this->assertSame(
			"- a\n- b\n\n```\n<b>x</b>\n```\n",
			Markdown_Converter::convert( "<ul><li>a</li><li>b</li></ul>\n\n" . $code )
		);
		$this->assertSame(
			"> q\n> \n> ```\n> <b>x</b>\n> ```\n",
			Markdown_Converter::convert( "<blockquote class=\"wp-block-quote\">\n<p>q</p>\n" . $code . "\n</blockquote>" )
		);
	}

	/**
	 * Inline content right before a block element (a stray <span> in a
	 * Custom HTML block, a <dt> before a <dd>'s code, a linked image before
	 * its caption, text before an <hr>) ends with a blank line, so the block
	 * still starts its own line: "stray ```" isn't a fence in any renderer,
	 * and CommonMark too then rendered the code's <script> as a raw element
	 * (Issue #81, review R1-2).
	 */
	public function test_block_after_inline_content_starts_a_new_block() {
		$this->assertSame(
			"Intro\n\nstray\n\n```\n<script>alert(1)</script>\n```\n",
			Markdown_Converter::convert( "<p>Intro</p>\n<span>stray</span>\n<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>" )
		);
		$this->assertSame( "T\n\n```\nx\n```\n", Markdown_Converter::convert( '<dl><dt>T</dt><dd><pre><code>x</code></pre></dd></dl>' ) );
		$this->assertSame(
			"[![C](<https://example.com/a.png>)](<https://example.com/>)\n\ncap\n",
			Markdown_Converter::convert( '<figure><a href="https://example.com/"><img src="https://example.com/a.png" alt="C"></a><figcaption>cap</figcaption></figure>' )
		);
		$this->assertSame( "stray\n\n---\n\nafter\n", Markdown_Converter::convert( '<div>stray<hr>after</div>' ) );
	}

	/**
	 * Inside an inline element, only a block that is or holds a code block
	 * starts a new Markdown block: a Custom HTML card's <div>s inside an <a>
	 * stay one link instead of splitting it with a blank line (review R2-1),
	 * while code still gets its fence on a line of its own.
	 */
	public function test_inside_inline_element_only_code_starts_a_new_block() {
		$this->assertSame(
			"[TitleDesc](<https://example.com/>)\n",
			Markdown_Converter::convert( '<a href="https://example.com/"><div>Title</div><div>Desc</div></a>' )
		);
		$this->assertSame( "s\n\n```\nx\n```\n", Markdown_Converter::convert( '<span>s<pre><code>x</code></pre></span>' ) );
		$this->assertSame( "s\n\n```\nx\n```\n", Markdown_Converter::convert( '<span>s<div><pre><code>x</code></pre></div></span>' ) );
	}

	/**
	 * An inline element between text and a code block (an <ins> or custom
	 * element in a Custom HTML block) still gets the blank line before it:
	 * inside it there's no output of its own to end, so "prefix```" came
	 * out and the code's <script> rendered as a raw element (Codex review
	 * G1-2).
	 */
	public function test_inline_wrapper_holding_code_starts_a_new_block() {
		$this->assertSame(
			"prefix\n\n```\n<script>x</script>\n```\n",
			Markdown_Converter::convert( '<div>prefix<ins><div><pre><code>&lt;script&gt;x&lt;/script&gt;</code></pre></div></ins></div>' )
		);
	}

	/**
	 * Text that ends up at the start of a line gets its block markers
	 * escaped like a paragraph's first line, wherever it sits: directly in
	 * a <div> that end_block() now separates from the inline content before
	 * it (Codex review G2-1), or after a block, possibly indented.
	 */
	public function test_text_at_the_start_of_a_line_escapes_block_markers() {
		$this->assertSame( "prefix\n\n\\# literal\n", Markdown_Converter::convert( '<div>prefix<div># literal</div></div>' ) );
		$this->assertSame( "prefix\n\n\\- item\n", Markdown_Converter::convert( '<div>prefix<div>- item</div></div>' ) );
		$this->assertSame( "prefix\n\n\\---\n", Markdown_Converter::convert( '<div>prefix<div>---</div></div>' ) );
		$this->assertSame( "a\n\n\\# x\n", Markdown_Converter::convert( "<div><p>a</p>\n  # x</div>" ) );
		$this->assertSame( "Steps:  \n1\\. Install  \n2\\. Run\n", Markdown_Converter::convert( '<p>Steps:<br>1. Install<br>2. Run</p>' ) );
	}

	/**
	 * Only whitespace at the start of a line is dropped: whitespace between
	 * inline elements stays a space, including directly inside a block
	 * container and inside an inline element with nothing before it.
	 */
	public function test_whitespace_in_the_middle_of_a_line_is_kept() {
		$this->assertSame( "a _b_ **c** _d_ e\n", Markdown_Converter::convert( "<p>a <em>b</em> <strong>c</strong>\n<em>d</em><span> </span>e</p>" ) );
		$this->assertSame( "_a_ _b_\n", Markdown_Converter::convert( '<div><em>a</em> <em>b</em></div>' ) );
	}

	/**
	 * Literal Markdown syntax characters in ordinary text (not text this
	 * converter itself wraps in syntax) are escaped so they can't be
	 * misread as real Markdown once emitted (Codex review).
	 */
	public function test_plain_text_special_characters_are_escaped() {
		$markdown = Markdown_Converter::convert( '<p>See [literal](not-a-link) and *not bold* here.</p>' );

		$this->assertStringContainsString( '\\[literal\\](not-a-link)', $markdown );
		$this->assertStringContainsString( '\\*not bold\\*', $markdown );
	}

	/**
	 * Markup an article shows as text (`&lt;script&gt;` in the HTML) comes
	 * out as character references, so a Markdown renderer that allows raw
	 * HTML shows it as text instead of running it as markup — in every
	 * dialect, unlike a `\<` backslash escape, which only CommonMark honors
	 * (Issue #79).
	 */
	public function test_literal_angle_brackets_are_escaped() {
		$markdown = Markdown_Converter::convert( '<p>Use &lt;script&gt;alert(1)&lt;/script&gt; and &lt;div&gt; and a &lt; b here</p>' );

		$this->assertSame( "Use &lt;script&gt;alert(1)&lt;/script&gt; and &lt;div&gt; and a &lt; b here\n", $markdown );
	}

	/**
	 * `<` is escaped even with nothing after it in its own text node: an
	 * empty inline element splits `&lt;script&gt;` into the runs "<" and
	 * "script>", which only form a tag once concatenated (Issue #79).
	 */
	public function test_literal_angle_bracket_split_across_text_nodes_is_escaped() {
		$markdown = Markdown_Converter::convert( '<p>&lt;<em></em>script&gt;alert(1)</p>' );

		$this->assertSame( "&lt;script&gt;alert(1)\n", $markdown );
	}

	/**
	 * A literal `<https://...>` would otherwise become an autolink, with its
	 * angle brackets disappearing from the rendered text (Issue #79).
	 */
	public function test_literal_autolink_is_escaped() {
		$markdown = Markdown_Converter::convert( '<p>See &lt;https://example.com&gt;</p>' );

		$this->assertSame( "See &lt;https://example.com&gt;\n", $markdown );
	}

	/**
	 * An `&` that would start a character reference is escaped, so a literal
	 * "&copy;" isn't rendered as "©"; any other `&` (by far the common case:
	 * "Q&A", "AT&T", "a & b") is left readable (Issue #79).
	 */
	public function test_ampersand_is_escaped_only_where_it_starts_a_character_reference() {
		$markdown = Markdown_Converter::convert( '<p>Write &amp;copy; &amp;#169; &amp;#x00A9; for Q&amp;A, AT&amp;T &amp; R&amp;D;</p>' );

		$this->assertSame( "Write &amp;copy; &amp;#169; &amp;#x00A9; for Q&A, AT&T & R&amp;D;\n", $markdown );
	}

	/**
	 * Image alt text and the titles/names other classes pass straight to
	 * escape_text() get the same `<`/`>`/`&` escaping as body text, and the
	 * `&` of a `&lt;` escape_text() adds isn't itself escaped again (Issue
	 * #79).
	 */
	public function test_escape_text_escapes_angle_brackets_and_character_references() {
		$this->assertSame( 'a &lt;b&gt; &amp;amp; Q&A \\\\', Markdown_Converter::escape_text( 'a <b> &amp; Q&A \\' ) );

		$markdown = Markdown_Converter::convert( '<img src="https://example.com/a.png" alt="&lt;b&gt;bold&lt;/b&gt; &amp;amp;">' );
		$this->assertStringContainsString( '![&lt;b&gt;bold&lt;/b&gt; &amp;amp;](<https://example.com/a.png>)', $markdown );
	}

	/**
	 * Code is emitted as-is: CommonMark reads neither raw HTML nor character
	 * references inside a code span or fenced block, so escaping there
	 * would show up as literal `&lt;`/`&amp;` text (Issue #79).
	 */
	public function test_code_keeps_angle_brackets_and_ampersands_unescaped() {
		$markdown = Markdown_Converter::convert( '<p><code>&lt;div&gt; &amp;amp;</code></p><pre><code>&lt;?php echo "&amp;copy;";</code></pre>' );

		$this->assertStringContainsString( '`<div> &amp;`', $markdown );
		$this->assertStringContainsString( "```\n<?php echo \"&copy;\";\n```", $markdown );
	}

	/**
	 * An <ol start="N"> begins numbering at N instead of always at 1
	 * (Codex review).
	 */
	public function test_ordered_list_honors_start_attribute() {
		$markdown = Markdown_Converter::convert( '<ol start="5"><li>Fifth</li><li>Sixth</li></ol>' );

		$this->assertStringContainsString( "5. Fifth\n", $markdown );
		$this->assertStringContainsString( "6. Sixth\n", $markdown );
	}

	/**
	 * The no-DOMDocument/unparsable-HTML fallback path (fallback_plain_text(),
	 * exercised directly via reflection since DOMDocument is present in this
	 * test environment) inserts block-boundary separation instead of
	 * concatenating adjacent block elements with nothing between them
	 * (Codex review).
	 */
	public function test_fallback_plain_text_preserves_block_separation() {
		$method = new \ReflectionMethod( Markdown_Converter::class, 'fallback_plain_text' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '<p>First</p><p>Second</p>' );

		$this->assertSame( "First\n\nSecond", $result );
	}

	/**
	 * Adjacent table cells must not collapse into one run-on word:
	 * `<td>`/`<th>` are not block-level breaks on their own (only their
	 * containing `<tr>` is), so without inserting *some* separator at each
	 * cell boundary, "Name"/"$9.99" in separate cells would strip down to
	 * the single word "Name$9.99" — corrupting two distinct values into one
	 * for the RAG export's content_plain field (Codex review).
	 */
	public function test_to_plain_text_separates_adjacent_table_cells() {
		$result = Markdown_Converter::to_plain_text( '<table><tr><th>Name</th><th>Price</th></tr><tr><td>Widget</td><td>$9.99</td></tr></table>' );

		$this->assertStringNotContainsString( 'NamePrice', $result );
		$this->assertStringNotContainsString( 'Widget$9.99', $result );
		$this->assertStringContainsString( 'Name', $result );
		$this->assertStringContainsString( 'Price', $result );
		$this->assertStringContainsString( 'Widget', $result );
		$this->assertStringContainsString( '$9.99', $result );
	}

	/**
	 * The RAG export's content_plain is plain text, not Markdown, so the
	 * character references `the_content` is full of (wptexturize's curly
	 * quotes, an escaped "&", a non-breaking space) must come out as the
	 * characters themselves (Issue #49).
	 */
	public function test_to_plain_text_decodes_html_character_references() {
		$result = Markdown_Converter::to_plain_text( '<p>AT&amp;T&#8217;s &#8220;plan&#8221; a&nbsp;b</p>' );

		$this->assertSame( "AT&T\u{2019}s \u{201C}plan\u{201D} a\u{00A0}b", $result );
	}

	/**
	 * Decoding happens after the tags are stripped: an article's literal
	 * `&lt;br&gt;` text stays the text "<br>" rather than becoming a real
	 * tag that is stripped or turned into a line break, and a double-encoded
	 * `&amp;lt;` is decoded exactly once.
	 */
	public function test_to_plain_text_keeps_escaped_markup_as_literal_text() {
		$result = Markdown_Converter::to_plain_text( '<p>Use &lt;br&gt; here</p><p>Write &amp;lt; for &lt;</p>' );

		$this->assertSame( "Use <br> here\n\nWrite &lt; for <", $result );
	}

	/**
	 * The DOMDocument-less fallback of convert() returns Markdown, where a
	 * character reference already stands for its character (CommonMark), so
	 * it keeps them: decoding would turn an escaped `&lt;tag&gt;` into raw
	 * HTML inside the Markdown.
	 */
	public function test_fallback_plain_text_keeps_html_character_references() {
		$method = new \ReflectionMethod( Markdown_Converter::class, 'fallback_plain_text' );
		$method->setAccessible( true );

		$this->assertSame( 'AT&amp;T &lt;b&gt;', $method->invoke( null, '<p>AT&amp;T &lt;b&gt;</p>' ) );
	}

	/**
	 * A code sample that itself contains a triple-backtick line (e.g. an
	 * article demonstrating Markdown syntax) gets a fence longer than any
	 * backtick run inside it, so that run can't close the block early
	 * (Codex review).
	 */
	public function test_pre_code_with_triple_backtick_content_uses_a_longer_fence() {
		$markdown = Markdown_Converter::convert( "<pre><code>Example:\n```\ncode\n```</code></pre>" );

		$this->assertStringContainsString( "````\nExample:\n```\ncode\n```\n````", $markdown );
	}

	/**
	 * A paragraph whose literal text happens to start with a heading '#',
	 * blockquote '>', or list '-'/'+'/'1.' marker is escaped so it isn't
	 * misread as that block construct (Codex review).
	 */
	public function test_paragraph_starting_with_block_marker_is_escaped() {
		$this->assertStringContainsString( '\\# literal', Markdown_Converter::convert( '<p># literal</p>' ) );
		$this->assertStringContainsString( '&gt; literal', Markdown_Converter::convert( '<p>&gt; literal</p>' ) );
		$this->assertStringContainsString( '\\- literal', Markdown_Converter::convert( '<p>- literal</p>' ) );
		$this->assertStringContainsString( '1\\. literal', Markdown_Converter::convert( '<p>1. literal</p>' ) );
		$this->assertStringContainsString( '2026\\) literal', Markdown_Converter::convert( '<p>2026) literal</p>' ) );
	}

	/**
	 * A `<details><summary>` pair (core/details) separates the summary from
	 * the content that follows it, instead of concatenating them with no
	 * boundary (Codex review).
	 */
	public function test_details_summary_is_separated_from_content() {
		$markdown = Markdown_Converter::convert( '<details><summary>What?</summary><p>Answer.</p></details>' );

		$this->assertStringContainsString( "**What?**\n\nAnswer.", $markdown );
	}

	/**
	 * A nested list under a two-digit ordered marker (e.g. item 10) is
	 * indented to that marker's own width ("10. " = 4 columns), not a flat
	 * 2 spaces — otherwise CommonMark reads the nested list as a new
	 * top-level list rather than a child of item 10 (Codex review).
	 */
	public function test_nested_list_indent_matches_two_digit_marker_width() {
		$markdown = Markdown_Converter::convert( '<ol start="10"><li>Tenth<ul><li>Child</li></ul></li></ol>' );

		$this->assertStringContainsString( "10. Tenth\n", $markdown );
		$this->assertStringContainsString( "    - Child\n", $markdown );
	}
}
