# dev-cycle 最終報告: issue-76-faq-list-category-view-context

## 開発内容

- タスク: Issue #76（bug、無料版）— FAQ List ブロックのカテゴリー選択が core-data 既定の `context=edit` でタームを引くため、`manage_categories` を持たない Author / Contributor では 403 になり、選択肢が「All categories」だけになる不具合
- PR: #77 https://github.com/shinobiashi/saai-knowledge/pull/77
- 承認された計画の要約:
  - `src/faq-list/index.js` の `getEntityRecords()` に `context: 'view'` を明示する
  - administrator / editor / author / contributor でカテゴリーを選ぶ E2E を新設する
  - 無料版を 1.0.3 に上げる（版数 3 箇所 + readme.txt の Changelog / Upgrade Notice）
  - backlog R1-X1（issue-71）を解消に更新する
- 実行モード: `auto-commit`（確認ゲートなし）、Codex は `--request-codex`。実行モデルは Opus 5.5
- コミット一覧:

| sha | メッセージ |
| --- | --- |
| `6008eef` | fix(faq-list): request categories in the view context |
| `b853cc0` | chore: bump saai-knowledge to 1.0.3 |
| `1ba36ff` | docs: resolve backlog R1-X1 (issue-71) and start dev-cycle state for issue 76 |
| `71bbb7b` | docs: record review-loop round 1 for issue 76 |
| （次のコミット） | docs: record dev-cycle gate round 1 and final report for issue 76 |

- 設計ドキュメントからの逸脱: なし

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | 差分内 Critical/High/Medium 0 / Low 3（+ 対象外 2） | 0 | Low 3 + 対象外 2 → **APPROVE** |

独立サブエージェント（Opus）の敵対的レビューを併用した。対象外の R1-X1（issue-76、Low）は、同じブロックのカテゴリー選択肢のラベルを `decodeEntities()` していない問題。ターム名は保存時に HTML エスケープされるので、`Q&A` は「Q&amp;A」と表示される（REST の戻り値で実測）。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 0 | 0 | 0 | 収束 |
| G1 | Codex | 0 | 0 | 0 | 収束 |

### 修正した指摘

なし。

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- CI: PR #77 の 12 チェック green（`71bbb7b`。記録用コミットの CI は push 後に再確認）
- ローカル:
  - PHPUnit 588 件、PHPCS、PHPStan、`lint:js`、新しい spec の ESLint: green
  - E2E 全体 28 件: green（wp-env tests: WP 7.1.3）
- `context: 'view'` を外すミューテーションでは、author と contributor のケースだけが失敗し、administrator と editor のケースは通った

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 1.0.3 のリリース: マージ後に `v1.0.3` タグを push し、`deploy-wporg.yml` を `version=1.0.3` で手動実行する。#49 / #56 や backlog R1-X1（issue-76、ラベルの `decodeEntities()`）も 1.0.3 に入れる場合は、タグの前にそれぞれの PR を入れ、readme.txt の `= 1.0.3 =` に行を足す
