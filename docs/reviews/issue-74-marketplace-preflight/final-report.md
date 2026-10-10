# dev-cycle 最終報告: issue-74-marketplace-preflight

## 開発内容

- タスク: Issue #74（M5-5a）— 有料版の WooCommerce.com Marketplace 申請前準備
- PR: #75 https://github.com/shinobiashi/saai-knowledge/pull/75
- 承認された計画の要約:
  - 1.0.0 への bump（ユーザー判断）
  - readme.txt / changelog.txt の新設
  - WC の範囲を L-2 に（11.0〜11.2）
  - `.pot` + 日本語訳の同梱（ユーザー判断）と生成スクリプト
  - 有料版の配布 ZIP の CI 検証
  - ローカルの QIT 相当チェック（Plugin Check・PHPCompatibility・`qit env:up` の WP 7.0/7.1 × WC 11.0〜11.2 の互換マトリクス）と、その記録
  - PHP 要件 8.2 の維持（ユーザー判断）
- 実行モード: 確認ゲートあり。Codex は `--request-codex`。実行モデルは Opus 5.5
- コミット一覧:

| sha | メッセージ |
| --- | --- |
| `487c768` | chore(woo): prepare 1.0.0 for WooCommerce.com |
| `a608db2` | feat(woo): bundle the add-on's translation template and Japanese translation |
| `8e5a938` | ci: verify the add-on's release ZIP |
| `9033af7` | chore: add a QIT local-environment matrix for the add-on |
| `8e3b37f` | docs: record the add-on's Marketplace preflight |
| `2594d5e` | chore: move verify-woo-zip.sh's exit trap into a function |
| `2ad305b` | fix(woo): use WooCommerce.com's changelog.txt format |
| `d8945f4` | fix: keep bin/qit-matrix.sh going when one step fails |
| `279c608` / `d8e1a13` / `f70a71b` | docs: review-loop R1 / R2 の記録 |
| `6ea6647` | docs: record dev-cycle gate round 1 for issue 74 |
| （本コミット） | docs: record dev-cycle final report for issue 74 |

- 設計ドキュメントからの逸脱: なし（DESIGN.md §6.3 / §8.3 を実態に合わせて更新）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | High 1 / Medium 3 / Low 5（+ 対象外 1） | High 1 + Medium 3 | Low 5 + 対象外 1 |
| R2 | 新規 Critical/High/Medium 0 / Low 2 | Low 1（文書の数値） | Low 2 → **APPROVE** |

- R1 の指摘
  - **High**: `changelog.txt` が WooCommerce.com 公式の `YYYY-MM-DD - version x.y.z` でなく、WordPress.org 風の書式だった（共有スキルの例が原因）
  - **Medium**: readme の Editor 権限の記述が #73 前提だった
  - **Medium**: `qit-matrix.sh` のエラー処理（SIGPIPE・ガードの無いコマンド・環境の取り残し）
  - **Medium**: PHPCompatibility の結果が記録されていなかった
- 独立サブエージェント（Opus 5.5）による敵対的レビュー・検証を併用した

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 0 | 0 | 0 | 収束（見出しは Needs a closer look。理由は既出の #73 依存とマネージド検証） |
| G1 | Codex | 0 | 0 | 0 | 収束 |

### 修正した指摘

なし。

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- **CI**: PR #75 の 12 チェックが green（`f70a71b`。有料版の ZIP 検証ステップを含む）
- **ローカル**: PHPUnit 588 件、PHPCS、PHPStan、`lint:js` / `lint:css`、E2E 22 件、shellcheck。すべて green
- **配布 ZIP**: Plugin Check はエラー 0（警告は `load_plugin_textdomain()` のみ）、PHPCompatibility は 0 件
- **QIT のローカル互換マトリクス**: 6 通りすべて pass（修正後に 1 組で再確認）

## 次にできること（人間の判断）

- **マージ順は #73 → #75**
  - #75 の readme の Editor 権限の記述は #73 が前提
  - 両 PR とも `docs/review-backlog.md` の末尾に追記しているので、後からマージする側でコンフリクトした場合は両方の行を残す
- マージ後は `/post-merge`
  - CLAUDE.md の「`languages/` 自体は未作成で、同梱は M5 以降」（backlog R1-X1（issue-74））の更新も、そこで行う
- 共有スキル `woo-marketplace-submission` / `woo-marketplace-extension` の changelog.txt の例を、公式書式（`YYYY-MM-DD - version x.y.z`）に直す。元本は `~/Dev/claude-skills`
- 申請（#24）では、`docs/MARKETPLACE-PREFLIGHT.md` の「申請時に確認すること」に沿って進める
  - changelog の日付
  - WC の範囲
  - QIT の PHPCompatibility の下限
  - オフラインの Activation テストで出る `plugins_api()` の Warning
