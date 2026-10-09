# dev-cycle 状態: issue-23-product-blocks

- タスク: Issue #23 / M5-4 — 手動配置ブロック（product-faq / product-docs / product-glossary）+ 同等ショートコード + 有料版のビルド基盤
- 開始: 2026-10-09
- PR: #70 https://github.com/shinobiashi/saai-knowledge/pull/70
- 現在のステップ: **完了**（マージ待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 1 回 / 収束（G1 で新規指摘なし）
- Codex: 依頼 1 回 / 収束（G1 で新規指摘なし。`--request-codex` の手動依頼に応答）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-09 | 0 | 起動チェック。無料版 1.0.2 は未リリース（v1.0.2 タグ・WordPress.org デプロイなし）だが、ユーザー判断で #23 を先に進める。Opus 5.5 で続行（ユーザー確認済み）。wp-env（スロット 03）起動 |
| 2026-10-10 | 1 | 計画承認。既存の手書き JS（`assets/js`）は lint 対象に入れない（R1-B2 は縮めて backlog に残す）、見出し文言は固定（表示/非表示のみ）、ZIP の CI 検証は #24 |
| 2026-10-10 | 2 | 実装: `Product_Sections`（`Product_Page` から描画部分を抽出、出力は不変）/ `Product_Context::for_block()` / `Blocks` / 3 ブロック（`src/product-*`、共通 Edit・商品ピッカー）/ `Shortcodes` + 有料版のビルド基盤（`package.json`・`.eslintrc.js`。lockfile 差分はワークスペース追加の 11 行のみ、Linux node:24 の `npm ci` + lint で確認） |
| 2026-10-10 | 2 | 実機確認（wp-env dev、WP 7.1.3 / WC 11.2.0）: TT5 の商品説明内ブロック（FAQ タブと併存で FAQPage 1 つ）・紐づけなし商品（何も出ない）・固定ページ（`productId` / ショートコード / 属性なしは空）、REST block-renderer の 5 パターン、Storefront でのショートコード（Reviews タブ正常）。`show_title="no"` が効かない問題を発見し修正（`8115cb3`） |
| 2026-10-10 | 2 | E2E `product-blocks.spec.js` 4 件を追加（固定ページ・単一商品テンプレート〔REST でブロック挿入〕・エディタープレビュー）。E2E 全 21 件 green。PHPUnit 有料版の新規 3 ファイル、PHPCS・PHPStan green |
| 2026-10-10 | 3 | review-loop R1: High 1 / Medium 4 / Low 5（+対象外 2）→ High・Medium 全件と同じ箇所の Low 2 件を修正（`cb8c8da`〜`0ccc00a`）、Low 1 件と対象外 2 件を backlog へ。独立サブエージェント（Opus。Fable 5.1 は利用枠切れで失敗）と実測で裏取り。主な発見: core-data の既定 `context=edit` で Editor が商品ピッカーを使えない（Issue #21 のパネルも同根 → backlog R1-X1） |
| 2026-10-10 | 3 | review-loop R2: **APPROVE**（R1 の 5 件すべて解消をミューテーションで実測、新規 Critical/High/Medium なし）。Low 3 件（E2E で選択済み商品名を固定・E2E の後片付け・DESIGN の対象範囲）を修正、1 件を backlog へ |
| 2026-10-10 00:54 | 4 | 初回 push（`9bce3af`、T=2026-10-09T15:54:47Z）+ PR #70 作成 |
| 2026-10-10 01:04 | 6-7 | G1: CI 12 チェック green 後に両 bot へ依頼。Copilot「Approval recommended / 0 open findings」、Codex「Didn't find any major issues」→ 両 bot 収束、修正なし |
| 2026-10-10 | 8 | 最終報告を作成し完了 |
| 2026-10-10 01:15 | 5 | docs のみの push（`f621d01`・`0498300`）の CI で Editor ロールの E2E が全試行タイムアウト。トレースでログイン POST が送信されていないことを確認し、`RequestUtils` でのログインに変更（`af075ec`）。CI 12 チェック green（E2E 22 件、リトライなし）。bot には再依頼しない（収束済み・テストのみの修正） |
