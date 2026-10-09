# dev-cycle 状態: issue-22-product-page-display

- タスク: Issue #22 / M5-3 — 商品ページ表示（FAQ タブ・関連 KB・用語ツールチップ）+ 設定 on/off + E2E
- 開始: 2026-10-09
- PR: #67 https://github.com/shinobiashi/saai-knowledge/pull/67
- 現在のステップ: **完了**（マージ待ち）
- Copilot: 依頼 3 回 / 上限（3 回目でも新規 2 件）
- Codex: 依頼 3 回 / 上限（3 回目でも新規 1 件。G1 では push 時の自動レビューが走らず手動 `@codex review` で応答、G2 からは `--request-codex`）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-09 | 0 | 起動チェック。wp-env 起動（WP 7.1.3 / WC 11.2.0）、WooCommerce 内部（ProductDetails 互換レイヤー・短い説明の経路）を実機ソースで確認 |
| 2026-10-09 | 1 | 計画承認。公開 API は既存物のみで実装（renderer()/settings() は追加せずドキュメントを訂正）、Fable 5.1 で続行、FAQ は `woocommerce_product_tabs` のみ（DESIGN.md §6.2 の (A)） |
| 2026-10-09 | 2 | 実装: `Settings` / `Product_Context` / `Product_Page` / `Product_Autolink` + `Plugin` 配線 + `Link_Resolver` を `get_the_terms()` へ（R1-B3 解消）。実機で Reviews タブが `$product` null で fatal になる問題を発見し、タブ描画前後で `$GLOBALS['product']` を復元する対応を追加 |
| 2026-10-09 | 2 | 実機確認（wp-env dev）: Storefront / TT5 未カスタマイズ / TT5 で Product Details を入れ直して保存したテンプレート、の 3 ケースで FAQ（1 回だけ）・関連 KB・短い説明と説明文のツールチップが出て、紐づけ無し商品では何も出ない。設定画面に WooCommerce セクション 3 項目を確認 |
| 2026-10-09 | 2 | 設計の前提を訂正: WC 11.2 では単一商品テンプレートを「開いて保存」してもアコーディオンにならない（`hasInnerBlocks \|\| wasBlockJustInserted` のときだけ展開）。DESIGN.md §6.2 / DESIGN-HOOKS-API.md §5・§6・§7 / DESIGN-AUTOLINK.md §6 / DEVELOPMENT-PLAN.md / review-backlog.md を更新 |
| 2026-10-09 | 2 | PHPUnit（有料版スイート 105 件）・PHPCS・PHPStan green。E2E（Storefront + TT5 ×2 テンプレート）を追加し wp-env tests で実行 |
| 2026-10-09 | 3 | review-loop R1: Medium 4 / Low 9（+対象外 2）→ Medium 4 + Low 3 を修正（`77ca9ec`）、Low 3 + 対象外 2 を backlog へ。独立サブエージェント併用、WC ソースで裏取り |
| 2026-10-09 | 3 | review-loop R2: **APPROVE**。R1 の全指摘解消をミューテーション実測で確認（R1-1 の当初テストはトートロジーだったため書き換え）、新規はテスト・文言のみ 4 件を修正（`98f603c`）。PHPUnit 全体 543 件 / E2E 17 件 green |
| 2026-10-09 17:35 | 4 | 初回 push（`f764a4f`）+ PR #67 作成 |
| 2026-10-09 18:45 | 7 | G1: Copilot 2 件 / Codex 1 件（手動依頼への応答）→ 3 件すべて修正（`b78a52a`, `1ac59ba`）。G1-3 は設計変更（指紋 context）をユーザー確認のうえ採用。push `1ac59ba` |
| 2026-10-09 19:05 | 7 | G2: Copilot 3 件 / Codex 1 件（いずれも Low の文言・コメント整合）→ 4 件修正（`cb21901` + PR 本文編集）。push `cb21901` |
| 2026-10-09 19:32 | 7 | G3: Codex 1 件（JSON-LD スロット署名）/ Copilot 本文 2 件 → 3 件修正（`adbffe6`）。両 bot とも 3 回目で新規指摘あり＝上限。再依頼せず CI 確認後に最終報告へ |
| 2026-10-09 19:45 | 8 | 最終 HEAD `b6d9feb` の CI 12 チェック green。最終報告を作成し完了 |
