# dev-cycle 最終報告: issue-23-product-blocks

## 開発内容

- タスク: Issue #23 / M5-4 — 手動配置ブロック `product-faq` / `product-docs` / `product-glossary` + ショートコード `[saai_product_faq]` / `[saai_product_docs]` / `[saai_product_glossary]`、有料版のビルド基盤
- PR: [#70](https://github.com/shinobiashi/saai-knowledge/pull/70)
- 承認された計画の要約: 描画内容を `Product_Sections` に集約して自動挿入（`Product_Page`）と共有し、3 ブロックは `Product_Context::for_block()` で商品を解決（属性 → `postId` 文脈〔アーカイブ等のルートでは `queryId` 必須〕→ 表示中の商品）。紐づけ 0 件なら見出しごと出さない。自動挿入のトグルとは独立。`product-faq` は無料版 faq-list を商品ごとのマーカー付きで描画し FAQPage を 1 つに保つ
- コミット:

| sha | メッセージ |
| --- | --- |
| 728e5f7 | refactor: extract the product section rendering from Product_Page |
| 0e2204c | feat: add the product-faq, product-docs and product-glossary blocks |
| 73d61ed | feat: add shortcode wrappers for the product blocks |
| 8115cb3 | fix: accept no and off for the product shortcodes' show_title |
| a8c30b8 | test: cover the product blocks end to end |
| f2ac556 | docs: specify the manual-placement product blocks |
| cb8c8da | fix: let editors without product capabilities use the product picker |
| f49bfc2 | fix: print shortcode syntax in product block text instead of running it |
| 5ea6b96 | fix: tell merchants to leave the product empty in the Single Product template |
| 0ccc00a | fix: match the product shortcodes' attribute parsing to the spec |
| 7eb45cc | docs: record review-loop round 1 for issue #23 |
| 91ec8d6 | test: pin the product picker's chosen-name lookup for editors |
| 9bce3af | docs: record review-loop round 2 for issue #23 |

- 設計ドキュメントからの逸脱: なし（DESIGN.md §6.2 に「手動配置ブロック」節、§6.1 に `context: 'view'` の明示を追記。計画承認時の判断 — 手書き管理画面 JS は lint 対象外のまま〔backlog R1-B2〕、見出し文言は固定、配布 ZIP の CI 検証は #24 — を反映済み）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | High 1 / Medium 4 / Low 5（+ 対象外 2） | High 1 + Medium 4 + Low 2（+ 後始末 2） | Low 1 + 対象外 2 |
| R2 | 新規 Critical/High/Medium 0 / Low 4 | Low 3 | Low 1 → **APPROVE** |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 0 | 0 | 0 | 収束 |
| G1 | Codex | 0 | 0 | 0 | 収束 |

### 修正した指摘

なし（ゲートでの指摘なし）。

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- CI: 9bce3af で PHP Quality（<https://github.com/shinobiashi/saai-knowledge/actions/runs/37955254613>）・JS Quality（<https://github.com/shinobiashi/saai-knowledge/actions/runs/37955254713>）とも green（12 チェック）。以降は docs のみのコミット
- 品質チェック: PHPCS / PHPStan / ESLint / Stylelint green、PHPUnit 576 件 green（ビルドあり・ビルド無しの CI 状態の両方）、E2E 22 件 green（wp-env tests）
- 実機（wp-env dev、WP 7.1.3 / WC 11.2.0）: TT5・Storefront、REST block-renderer、Editor ロールでの REST 403/200 を確認

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- **範囲外で見つけた既存の不具合の Issue 化**（backlog R1-X1、High）: Issue #21 のコンテンツ側「Linked Products」パネルも core-data の既定 `context=edit` で商品・商品カテゴリーを引いており、商品権限を持たない Editor では検索が 403 になる（DESIGN §6.1 の前提が成り立っていない）。商品ブロックのピッカーと同じく `context: 'view'` を明示し、Editor の E2E を足す修正を推奨
- 無料版 1.0.2 のリリース（`v1.0.2` タグ push → `deploy-wporg.yml` を `version=1.0.2` で手動実行）は未実施のまま
- M5 の残り: #27（RAG エクスポートの商品メタ）→ #24（QIT・Marketplace 申請。有料版 ZIP の CI 検証もここで追加）
