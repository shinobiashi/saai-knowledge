# dev-cycle 最終報告: issue-22-product-page-display

## 開発内容

- タスク: Issue #22 / M5-3 — 商品ページ表示（FAQ タブ・関連 KB・用語ツールチップ）+ 設定 on/off + E2E
- PR: [#67](https://github.com/shinobiashi/saai-knowledge/pull/67)（OPEN / MERGEABLE / head `b6d9feb`）
- 差分: 26 files, +3604 / −44

### 承認された計画の要約

無料版の公開物だけで（`renderer()` / `settings()` は追加せず）、WooCommerce の商品ページに紐づけ済みコンテンツを 3 種類自動挿入する。

- **FAQ タブ** — `woocommerce_product_tabs` のみ（DESIGN.md §6.2 の (A) で確定）。本文は公開ブロック `saai-knowledge/faq-list` を `render_block()` + `saai_faq_query_args`（`post__in`）で描画。クラシック / ブロックテーマ未カスタマイズ（legacy タブ）/ Product Details ブロックを入れ直して保存したテンプレート（互換レイヤーが accordion item へ変換）の 3 ケースを 1 実装で賄う
- **関連 KB** — `woocommerce_after_single_product_summary`（priority 12）
- **用語ツールチップ** — `saai_autolink_dictionary` で紐づけ用語に絞り、長い説明は有料版自身の `the_content` フック、短い説明は `woocommerce_short_description` / `render_block_*` から `$plugin->autolinker()->process()` を呼ぶ（G1-3 で `saai_autolink_post_types` 方式から変更）
- **設定** — 無料版設定画面の「WooCommerce」セクション 3 項目（`saai_settings_sections` / `saai_default_settings`）
- E2E: Storefront / TT5 legacy タブ / TT5 Site Editor でブロック入れ直し保存（accordion）
- 付随: `Link_Resolver::category_ids_for_product()` を `get_the_terms()` へ（backlog R1-B3 解消）、Storefront を wp-env の keep-list に追加、Playwright の `testDir` を両プラグインに拡張

### コミット一覧

| sha | メッセージ |
| --- | --- |
| `862c210` | feat: display linked FAQs, KB articles, and glossary tooltips on product pages |
| `d150246` | test: cover the product page insertions end to end on Storefront and block themes |
| `700325e` | docs: record the M5-3 product page design decisions and progress |
| `77ca9ec` | fix: prime KB post caches, resolve linked FAQs once, and skip wc_format_content() text |
| `bec0990` | docs: record the review-loop R1 result for issue-22 |
| `98f603c` | test: pin the KB cache priming by query count and reproduce the REST renderer state |
| `8df56f2` | docs: record the review-loop R2 result (APPROVE) for issue-22 |
| `f764a4f` | docs: update the dev-cycle state for issue-22 after the review loop |
| `b020dfc` | docs: record PR #67 in the dev-cycle state for issue-22 |
| `b78a52a` | fix: drop KB links whose permalink escapes to nothing and split the context gate wording |
| `1ac59ba` | fix: carry the linked-term fingerprint into the autolink engine's context |
| `4187b4d` | docs: record dev-cycle gate round 1 |
| `cb21901` | docs: align the autolink notes with the fingerprint-context route |
| `50f6735` | docs: record dev-cycle gate round 2 |
| `adbffe6` | fix: give the product FAQ tab its own JSON-LD slot signature and apply the query restriction once |
| `b6d9feb` | docs: record dev-cycle gate round 3 |

### 設計ドキュメントからの逸脱（いずれもユーザー合意のうえ採用し、ドキュメントを更新済み）

1. DESIGN-HOOKS-API.md §5 の `$plugin->renderer()` / `$plugin->settings()` は無料版に未実装だったため**追加せず**、公開ブロック + `saai_faq_query_args` + オプション名で実装（§5 / §6 / §7 を訂正。無料版 1.0.1 のままアドオンが動く）
2. DESIGN.md §6.2 の未決を (A) `woocommerce_product_tabs` のみで確定。「保存すると Product Details が展開される」という前提は誤りで、WC 11.2 では `hasInnerBlocks || wasBlockJustInserted` のときだけ展開される（実機確認）
3. G1-3（Codex）を受け、`saai_autolink_post_types` に `product` を加える設計をやめ、有料版自身の `the_content` フック + 紐づけ用語 ID の指紋 context に変更（DESIGN-AUTOLINK.md §6 / DESIGN-HOOKS-API.md §3.1・§7 / DESIGN.md §6.2）

### 実機で見つけた WooCommerce 固有の罠（コードと DESIGN.md §6.2 に記録）

- FAQ 回答描画の `setup_postdata()` が `the_post` を発火し、WC の `wc_setup_product_data()` が `$GLOBALS['product']` を unset → 直後の Reviews タブが fatal。タブ描画前後でスナップショット・復元
- `woocommerce_short_description` は `wc_format_content()`（variation 説明・Featured Product 等）からも掛かる → `debug_backtrace()` で除外

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Medium 4 / Low 9（+ 対象外 2） | Medium 4 + Low 3 | Low 3 + 対象外 2 |
| R2 | 新規 Medium 2（テストのみ）/ Low 2 | 4 | 0 → **APPROVE** |

R1-1 / R1-3 / R1-4 の修正は `mutate-check.sh` でガードを壊してテストが落ちることを実測（R1-1 の当初テストはトートロジーだったため R2 で書き換え）。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G1 | Codex | 1（手動 `@codex review` への応答。CI 後 2 回の待ちは TIMEOUT） | 1 | 0 | 未収束 |
| G2 | Copilot | 3（Low。本文は「Approval recommended」） | 3 | 0 | 未収束 |
| G2 | Codex | 1（P3） | 1 | 0 | 未収束 |
| G3 | Copilot | 2（本文「Previously missed」） | 2 | 0 | **上限** |
| G3 | Codex | 1（P2） | 1 | 0 | **上限** |

両 bot とも 3 回目でも新規指摘があったため「上限」。G3 の修正（`adbffe6`）は push して CI green を確認したが、規約どおり再依頼していない。

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Medium | KB リンクの URL をエスケープ後の値で判定し無効な項目を除外 + テスト | `b78a52a` | [r4228289632](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228289632) |
| G1-2 | Copilot | Low | DESIGN.md §6.2 の文脈ゲートの記述を経路ごとに訂正 | `b78a52a` | [r4228289686](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228289686) |
| G1-3 | Codex | Medium | 無料版エンジンのキャッシュキーに紐づけ用語集合が含まれず、商品側メタボックス経由の変更が最大 1 時間反映されない → 自前 `the_content` + 指紋 context（設計変更） | `1ac59ba` | [r4228542517](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228542517) |
| G2-1 | Codex | Low | DESIGN-AUTOLINK.md §6 末尾の旧方針の記述 | `cb21901` | [r4228878583](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228878583) |
| G2-2 | Copilot | Low | 同上 | `cb21901` | [r4228889609](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228889609) |
| G2-3 | Copilot | Low | PR 本文の用語ツールチップの記述を現行実装に | PR 本文編集 | [r4228889676](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228889676) |
| G2-4 | Copilot | Low | テスト docblock を実際の処理経路に | `cb21901` | [r4228889720](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4228889720) |
| G3-1 | Codex | Medium | 商品説明内の faq-list と商品タブの FAQPage JSON-LD スロット署名の衝突 → `category` マーカーで署名を分離（backlog R1-L4 も解消） | `adbffe6` | [r4229015125](https://github.com/shinobiashi/saai-knowledge/pull/67#discussion_r4229015125) |
| G3-2 | Copilot | Low | `saai_faq_query_args` 制限コールバックを初回適用時に自己解除（実害は元々なし） | `adbffe6` | 本文指摘（スレッドなし） |
| G3-3 | Copilot | Low | 存在しないクラス名 `Saai_Autolinker` を `$plugin->autolinker()` に | `adbffe6` | 本文指摘（スレッドなし） |

### 修正しなかった指摘（PR 上で未解決のまま残してあるもの）

なし（全スレッド Resolve 済み、未解決 0）。review-loop の backlog 送り: R1-L5（`faq_list_html()` が自動挿入トグルに縛られる — Issue #23 で分離）、R1-L6（`get_the_terms()` の `WP_Error` / フィルター経由の挙動差 — 多言語対応時に再評価）、R1-X1（無料版 `Faq_List::render_answer_uncached()` が `the_post` を再発火しない — 無料版 `[saai_faq]` を商品説明に置くと同じ Reviews タブ fatal が起きうる）、R1-X2（`saai_faq` が `page-attributes` 非対応で REST から `menu_order` を書けない）。詳細は `docs/review-backlog.md`。

## 品質ゲート

- CI（head `b6d9feb`）: 12 チェック green — [PHP Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/37918242062)（PHPCS / PHPStan / PHPUnit PHP 8.2–8.4 × WP 6.9–latest）、[JS Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/37918242030)（Lint JS / Lint CSS / Build / E2E）
- ローカル品質チェック: PHPCS / PHPStan / PHPUnit 有料版 114 件（全体 545 件相当）/ E2E 17 件（既存 11 + 新規 6）green

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 状態「上限」の bot について、数時間後に `/fix-copilot-review 67` を実行して遅れて届いた指摘が無いか確認するのを推奨（G3 の修正 `adbffe6` には再依頼していない）
- backlog R1-X1（無料版側の `the_post` 復元）は無料版の次のリリースで対応する価値あり。Issue 起票するか判断を
- Issue #23（手動配置ブロック）着手時に R1-L5 を解消する
