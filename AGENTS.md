# SAAI Knowledge — Agent Instructions

WordPress plugin monorepo providing FAQ / Knowledge Base / Glossary features.

- Free plugin: `plugins/saai-knowledge/` (WordPress.org distribution) — PHP 8.2+, WordPress 6.9+.
- Paid add-on: `plugins/saai-knowledge-for-woocommerce/` (WooCommerce.com Marketplace).
- Design source of truth: `docs/DESIGN.md`. Development plan: `docs/DEVELOPMENT-PLAN.md`.
- Coding standards: WordPress Coding Standards (WordPress-Extra + WordPress-Docs), enforced in CI via PHPCS, PHPStan, and PHPUnit (PHP 8.2–8.4 × WP 6.9–latest).

## Review guidelines

- Verify claims against actual WordPress core behavior before flagging, and cite the core function, filter, or default that makes the code incorrect. Past reviews have flagged patterns core explicitly supports — see "Known-correct patterns" below.
- Do not re-flag decisions documented in code comments or docblocks as an "accepted trade-off", "known limitation", or equivalent wording, unless you have new evidence the documented reasoning is wrong. These are deliberate design decisions.
- Focus on high-priority risks:
  - Missing output escaping / input sanitization, missing nonce + capability checks, SQL not going through `$wpdb->prepare()`.
  - Request-scoped or global state that leaks across renders, requests, or tests — this codebase frequently renders content inside other content (blocks in answers, shortcodes in loops).
- The paid add-on must depend only on the free plugin's public `saai_*` hooks; flag any direct use of the free plugin's internal classes.
- Write review comments in Japanese.

## Known-correct patterns — do not flag

- `esc_html( get_the_title() )`: `esc_html()` calls `_wp_specialchars()` with `$double_encode = false`, so existing HTML character references (`&#038;`, `&#8217;`, …) are NOT double-encoded. Plain-text sinks (JSON-LD names, attributes fed to APIs) do decode entities first — that is already handled where needed.
- `getEntityRecords( 'taxonomy', ..., { per_page: -1 } )` in editor JS: core-data routes `per_page: -1` through plain `apiFetch`, whose `fetchAllMiddleware` converts it into paginated `per_page=100` requests and merges all pages. The REST endpoint never receives `-1`, and more than 100 terms are handled.
- The `queryId` block context only proves "descendant of core/query", never "genuine per-item render". WordPress core provides no per-item context key; this limitation is documented and accounted for in `plugins/saai-knowledge/src/breadcrumbs/render.php` and `plugins/saai-knowledge/src/faq-list/render.php`.

## Testing notes

- CI runs PHPUnit without a JS build (`build/` is gitignored), so block types are not registered from `build/` there. Tests that render real blocks register them from `src/<block>/render.php` and restore the registry afterwards (see `tests/test-shortcodes.php`, `tests/test-faq-list.php`).
- PHPUnit test classes follow the non-namespaced `Test_*` convention (exempt from the `saai_` prefix rule).
