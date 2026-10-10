# dev-cycle 状態: issue-76-faq-list-category-view-context

- タスク: Issue #76（bug、無料版）— FAQ List ブロックのカテゴリー選択に `context: 'view'` を明示し、`manage_categories` を持たない Author / Contributor で 403 になり選択肢が出ない不具合を直す。無料版を 1.0.3 に上げる
- 開始: 2026-10-11
- PR: 未作成
- 現在のステップ: 4（push・PR 作成）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-11 | 0 | 起動チェック。作業ツリー clean、main 最新。wp-env（スロット 03）起動済み。Opus 5.5 で続行（計画承認で確認） |
| 2026-10-11 | 1 | 計画承認。`context: 'view'` を明示、administrator / editor / author / contributor でパラメータ化した E2E を新設、無料版を 1.0.3 に上げる（#69 と同じく修正と同じ PR）、backlog R1-X1（issue-71）を解消に更新 |
| 2026-10-11 | 2 | 実装: `src/faq-list/index.js` に `context: 'view'` とコメント。E2E `tests/e2e/faq-list-category.spec.js`（4 ロール。選択肢にタームが出ること + 選ぶと `category` 属性がその slug になること）— `context: 'view'` を外すミューテーションでは author / contributor だけが失敗、administrator / editor は通過。版数 3 箇所を 1.0.3 に、readme.txt に Changelog / Upgrade Notice。E2E 全体 28 件・PHPUnit 588 件・PHPCS・PHPStan・lint:js green |
| 2026-10-11 | 3 | review-loop R1: **APPROVE**（差分内に Critical/High/Medium なし）。独立サブエージェント（Opus）併用。Low 3・対象外 2（R1-X1: カテゴリー選択肢のラベルを `decodeEntities()` していない。REST は `Q&amp;A` を返すことを実測）をすべて backlog へ。修正なしのため R2 不要 |
