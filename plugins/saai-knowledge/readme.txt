=== SAAI Knowledge ===
Contributors:      shinobiashi
Tags:               faq, knowledge-base, glossary, search, structured-data
Requires at least:  6.9
Tested up to:       7.1
Stable tag:         1.0.0
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

= Looking for WooCommerce integration? =

A separate add-on, SAAI Knowledge for WooCommerce, links FAQ/KB/Glossary content to products and product categories and displays it on product pages. It's sold separately and is not required to use this plugin.

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

A separate add-on, SAAI Knowledge for WooCommerce, links FAQ/KB/Glossary content to products and product categories and displays it on product pages. It's sold separately and is not required to use this plugin.

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

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
