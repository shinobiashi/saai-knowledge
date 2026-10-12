=== SAAI Knowledge ===
Contributors:      shinobiashi
Tags:               faq, knowledge-base, glossary, search, structured-data
Requires at least:  6.9
Tested up to:       7.1
Stable tag:         1.0.3
Requires PHP:       8.2
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

FAQ, Knowledge Base, and Glossary content types with a two-column layout, term auto-linking, live search, and AI-ready Markdown/RAG export.

== Description ==

SAAI Knowledge adds three purpose-built content types to WordPress — FAQ, Knowledge Base, and Glossary — with the structure, navigation, and structured data that both readers and AI crawlers expect.

= Knowledge Base =

* Two-column layout: category sidebar + article, with automatic ancestor-term navigation
* Table of contents generated from your article's headings, with scroll-spy highlighting
* Breadcrumbs with `BreadcrumbList` structured data
* Works with block themes and classic themes alike

= FAQ =

* Accordion display built on the core Accordion block — no extra JavaScript framework
* Each question gets its own page (question as heading, answer directly below) with `QAPage` structured data; the archive gets `FAQPage` structured data
* Server-rendered content: the answer is always present in the initial HTML, so JavaScript-averse crawlers see it too

= Glossary =

* A–Z / Japanese (五十音) index block, sorted by reading
* Automatic term linking across your site's content, with a tooltip preview on hover/tap
* `DefinedTerm` structured data on each term's page

= Live search =

* A single search block that queries FAQ, Knowledge Base, and Glossary content and groups results by type as you type

= Built for AI / RAG pipelines =

* Every FAQ/KB/Glossary page is available as clean Markdown via `?format=markdown`
* A Markdown index of your whole knowledge base, published for AI crawlers
* If you run Yoast SEO, Rank Math, or All in One SEO, SAAI Knowledge fills in their llms.txt descriptions for these pages automatically
* A REST export endpoint with incremental sync (`modified_after`), plus a full-dump JSONL/CSV download from the admin screen — ideal for feeding a support chatbot or RAG index
* The Markdown output, llms.txt index, and SEO-plugin integration are controlled by a single setting; the REST export endpoint and admin download aren't affected by it and are always available (the REST endpoint only ever returns already-public content; the admin download requires the same capability as the rest of Settings)

= Extensible =

Every automatic behavior (auto-linking, structured data, and the Markdown/llms.txt output) is controlled from Settings, and the plugin exposes `saai_*` actions/filters so other plugins and themes can customize or extend it.

= Source code and build process =

SAAI Knowledge is developed in the open. The complete, human-readable source — including the un-minified JavaScript and SCSS under `src/` that the compiled files under `build/` are generated from — is maintained at:

https://github.com/shinobiashi/saai-knowledge

The free plugin is the `plugins/saai-knowledge/` directory of that repository.

The build uses [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts) (webpack + Babel) and needs Node.js and npm; the project's CI builds with Node.js 24. To reproduce the compiled files from source:

1. `git clone https://github.com/shinobiashi/saai-knowledge.git`
2. `cd saai-knowledge`
3. `npm install`
4. `npm run build`

`npm run build` compiles `plugins/saai-knowledge/src/**` into `plugins/saai-knowledge/build/**`. No third-party JavaScript library is bundled: the only module the front-end scripts import is `@wordpress/interactivity`, which WordPress itself provides at runtime.

= Looking for WooCommerce integration? =

SAAI Knowledge for WooCommerce, a companion add-on that links FAQ/KB/Glossary content to products and product categories and displays it on product pages, is planned as a separately sold, optional purchase — it isn't available yet and is not required to use this plugin.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/saai-knowledge` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > SAAI Knowledge to configure URL slugs, auto-linking, structured data, and AI readability options.
4. Create your first FAQ, Knowledge Base article, or Glossary term from the admin menu, and add the matching blocks (KB Sidebar, KB Table of Contents, FAQ List, Glossary Index, Knowledge Search) to your templates or pages.

== Frequently Asked Questions ==

= Does this work with my theme? =

Yes. Both block themes and classic themes are supported. Block-theme users get dedicated templates for the Knowledge Base hub, category archives, and individual articles; classic-theme users get the same layout through the classic template hierarchy.

= Do the accordions and folded content work without JavaScript? =

Yes. FAQ answers and other collapsible content are always present in the page's initial HTML; JavaScript only controls the open/close interaction. Search engines and AI crawlers that don't execute JavaScript still see the full content.

= Can I turn off the Markdown/llms.txt output? =

Yes. A single "Markdown & llms.txt" setting controls the `?format=markdown` output, the llms.txt index, and the SEO-plugin description integration together. The REST export endpoint and the admin-side JSONL/CSV download aren't affected by this setting — the REST endpoint only ever returns already-public content, and the admin download requires the same capability as the rest of Settings.

= Does this include a WooCommerce integration? =

SAAI Knowledge for WooCommerce, a companion add-on that links FAQ/KB/Glossary content to products and product categories and displays it on product pages, is planned as a separately sold, optional purchase — it isn't available yet and is not required to use this plugin.

= Is my content sent to any external service? =

No. SAAI Knowledge does not call any external API or service; all processing happens on your own site.

== Screenshots ==

1. Knowledge Base article with category sidebar and table of contents.
2. FAQ accordion list, expanded.
3. Glossary A–Z index.
4. Live search results grouped by content type.
5. Settings screen — auto-linking, structured data, and AI readability options.
6. Term auto-linking tooltip shown on hover.

== Changelog ==

= 1.0.3 =
* Fixed: Authors and Contributors can now choose a category in the FAQ List block's settings. The list of categories was requested in a way that requires permission to manage categories, so for these roles it silently showed only "All categories".
* Fixed: the `content_plain` field of the RAG export (the REST endpoint and the admin download) now contains the characters themselves instead of HTML character references such as `&amp;` or `&#8217;`. Records you have already imported through incremental sync (`modified_after`) keep the old text until their post is updated, so run one full export to refresh them. Text such as `<script>` written in a post now appears as those characters, so escape `content_plain` (as you already would `title`) before inserting it into HTML.
* Fixed: in the Markdown versions of your content (`?format=markdown`, the llms.txt index, and the RAG export's `content_markdown`), text such as `<script>` or `&copy;` written in a post's text or its title is now written as character references (`&lt;script&gt;`, `&amp;copy;`), so a Markdown renderer shows those characters instead of reading them as HTML or as a character reference. As with `content_plain` above, run one full export to refresh records you have already imported. Cached Markdown pages and the llms.txt index update on their own within a day.
* Fixed: in the same Markdown, a code block or heading that isn't inside a link or other inline element now always starts at the beginning of its own line. One that followed another block started with a stray space, which Python-Markdown doesn't accept even with its fenced_code or extra extension, and one that directly followed inline content, such as text in a Custom HTML block, started in the middle of a line, which no Markdown library accepts. Either way, HTML in a code sample, such as `<script>`, was rendered as HTML, and a heading was shown as plain text. Text at the start of a line is now escaped wherever it appears, so a line such as ~~~ or # in a Custom HTML block can't open a code block or become a heading. Code blocks are written as fenced code blocks, so if you render this Markdown to HTML with a library that doesn't fully support them, sanitize the resulting HTML: Python-Markdown, for example, doesn't read them without one of those extensions, or inside a quote even with one. The full export mentioned above refreshes records you have already imported.
* Fixed: in the same Markdown, curly braces in a post's text or its title are now escaped (`\{`, `\}`), so Python-Markdown's attr_list extension (part of its extra extension) no longer reads text such as `{: onclick=... }` at the end of a heading or right after a link or emphasized text as HTML attributes of that element. The full export mentioned above refreshes records you have already imported.
* Fixed: in the same Markdown, a line break inside inline code, in the address of a link or image, in an image's alt text, in a category name, or in a heading of the RAG export's `sections` no longer splits the Markdown it's in, and neither does a carriage return in a code block inside a quote or a table. The next line could start Markdown of its own or fall outside the quote or table, or a blank line could leave inline code unclosed, so text such as `<img onerror=...>` in inline code or in such a code block, or a `<script>` in a code sample after such an image, category or heading, was rendered as HTML. Inline code now has a space where the line break was, which is how Markdown already displays it, and a link or image address drops line breaks and tabs, as browsers already do. The full export mentioned above refreshes records you have already imported.
* Fixed: when a glossary term has no excerpt, its auto-link tooltip and the description in its structured data are now cut after the first 55 words of its definition whatever the site language is, counting each character of Japanese or Chinese text as a word. On a site in Japanese, an English definition was cut after 55 characters, in the middle of a word; on a site in English, a Japanese definition wasn't shortened at all. Tooltips pick up the change on their own after you update.
* Fixed: auto-link tooltips no longer show the definition or excerpt of a password-protected glossary term, which any visitor could read in the page source without the password. Links to such a term still appear, without a tooltip, and tooltips already saved by an earlier version are rebuilt when you update.

= 1.0.2 =
* Fixed: an FAQ list (the `[saai_faq]` shortcode or the FAQ List block) in a WooCommerce product description no longer breaks the rest of the product page. Rendering the FAQ answers cleared the product that WooCommerce had set up for the page, so, depending on the theme, the Reviews tab could fail with a fatal error, or the Reviews tab and related products could silently disappear.

= 1.0.1 =
* Translations are now delivered as language packs from translate.wordpress.org instead of being bundled with the plugin; the `languages/` directory and the `Domain Path` header have been removed accordingly.
* Documented the public source repository and the steps to rebuild the compiled files under `build/` from the un-minified sources under `src/`.
* Clarified that the SAAI Knowledge for WooCommerce add-on is not available yet.
* No functional or behavioral changes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
Lets Authors and Contributors choose a category in the FAQ List block, which previously offered them only "All categories".

= 1.0.2 =
Fixes WooCommerce product pages whose description contains an FAQ list: the Reviews tab could fail with a fatal error or disappear. Recommended for all WooCommerce sites.

= 1.0.1 =
Housekeeping release. Translations now come from translate.wordpress.org language packs rather than files bundled with the plugin, and the readme documents the public source repository and build process. No functional changes.

= 1.0.0 =
Initial release.
