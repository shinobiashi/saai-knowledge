# SAAI Knowledge

[日本語版 README はこちら](README-JA.md)

A WordPress plugin monorepo that adds FAQ, Knowledge Base, and Glossary content types to any site — with the structure, navigation, and structured data that both readers and AI crawlers expect.

- **[SAAI Knowledge](plugins/saai-knowledge/)** — the free plugin, distributed on [WordPress.org](https://wordpress.org/plugins/saai-knowledge/).
- **SAAI Knowledge for WooCommerce** — a paid add-on that links FAQ/KB/Glossary content to WooCommerce products and product categories. Planned as a separately sold WooCommerce.com Marketplace purchase; it isn't available yet and is not required to use the free plugin.

## Features (free plugin)

- **Knowledge Base** — two-column layout (category sidebar + article), automatic ancestor-term navigation, an auto-generated table of contents with scroll-spy, and `BreadcrumbList` structured data.
- **FAQ** — accordion display built on the core Accordion block, per-question pages with `QAPage` structured data, and `FAQPage` structured data on the archive. Content is always present in the initial server-rendered HTML.
- **Glossary** — an A–Z / 五十音 (Japanese syllabary) index block, automatic term linking across your site with a hover/tap tooltip preview, and `DefinedTerm` structured data.
- **Live search** — a single block that queries FAQ, Knowledge Base, and Glossary content and groups results by type as you type.
- **Built for AI / RAG pipelines** — clean Markdown output (`?format=markdown`) for every page, an `llms.txt` index, automatic SEO-plugin description fill-in (Yoast SEO, Rank Math, All in One SEO), and a REST export endpoint (JSONL/JSON, with incremental sync) plus an admin-side full-dump download.
- **Extensible** — every automatic behavior is controlled from Settings, and the plugin exposes `saai_*` actions/filters for other plugins and themes.
- Works with both block themes and classic themes. No data is sent to any external service.

## Requirements

| | |
| --- | --- |
| WordPress | 6.9+ |
| PHP | 8.2+ |
| WooCommerce (paid add-on only) | Latest + previous 2 major versions (L-2 policy) |

## Repository layout

```text
saai-knowledge/
├── docs/                                 Design and planning documents
├── plugins/
│   ├── saai-knowledge/                   Free plugin (WordPress.org)
│   └── saai-knowledge-for-woocommerce/   Paid add-on (WooCommerce.com)
├── .wp-env.json                          Local dev environment (wp-env)
├── composer.json                         PHPCS / PHPStan / PHPUnit (shared)
├── package.json                          @wordpress/scripts (npm workspaces)
└── .github/workflows/                    CI (lint / test / build / deploy)
```

Each plugin directory is packaged into its own ZIP for distribution. Only the free plugin is deployed to the WordPress.org SVN repository.

## Documentation

- [`docs/DESIGN.md`](docs/DESIGN.md) — base design: data model, URLs, blocks, and paid-plugin integration.
- [`docs/DESIGN-AUTOLINK.md`](docs/DESIGN-AUTOLINK.md) — the term auto-linking engine.
- [`docs/DESIGN-HOOKS-API.md`](docs/DESIGN-HOOKS-API.md) — the public hooks (`saai_*` actions/filters) contract.
- [`docs/DEVELOPMENT-PLAN.md`](docs/DEVELOPMENT-PLAN.md) — phased development plan and progress.

## Development

```sh
npx wp-env start                # Start the local WordPress environment (free plugin + WooCommerce)
npm run start / npm run build   # Build assets with @wordpress/scripts (per workspace)

composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit
composer verify                 # lint + analyze + test in one pass
```

See [`CLAUDE.md`](CLAUDE.md) for detailed coding conventions and project-specific notes.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
