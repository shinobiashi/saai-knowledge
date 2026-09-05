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
		$this->assertStringContainsString( '\\> literal', Markdown_Converter::convert( '<p>&gt; literal</p>' ) );
		$this->assertStringContainsString( '\\- literal', Markdown_Converter::convert( '<p>- literal</p>' ) );
		$this->assertStringContainsString( '\\1. literal', Markdown_Converter::convert( '<p>1. literal</p>' ) );
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
