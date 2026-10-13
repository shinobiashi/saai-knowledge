# dev-cycle 最終報告: issue-89-product-glossary-excerpt

## 開発内容

- タスク: Issue #89（有料版、M5）— 商品ページの用語の定義文を、サイトの言語ではなく文章の文字種で切る
- PR: #98 <https://github.com/shinobiashi/saai-knowledge/pull/98>
- 承認された計画の要約: 無料版 `Plain_Excerpt::trim_words()` を有料版の `SAAI\KnowledgeWoo\Plain_Excerpt` に複製し（有料版は無料版の内部クラスを呼べないため）、`Product_Sections::definition()` の本文側をそれで切る。手動抜粋側と `strip_shortcodes()` は据え置き。複製のずれはテストで検出する。有料版は未公開の 1.0.0 なので版数は据え置き

### コミット

| sha | メッセージ |
| --- | --- |
| 50fc421 | fix: trim product glossary definitions by script, not site language |
| a60a16d | docs: describe the add-on's copied excerpt trimming |
| 35607d9 | test: compare the excerpt copy's code with the free plugin's（R1-1） |
| 9ece7d7 | docs: record review-loop R1 for #89 |
| a968f0c | docs: record the dev-cycle PR for #89 |
| 0a64de4 | docs: note that definitions match the tooltip from free 1.0.3（G1-1 の注記） |
| ada997e | test: check the definition length matches the tooltip's（G1-2） |
| 2bbf4ed | docs: record dev-cycle gate round 1 |

### 設計ドキュメントからの逸脱

なし。DESIGN.md §6.2 を更新した（「本文先頭 55 語」→「55 単位」、数え方・複製とその照合テスト、ツールチップと揃うのは無料版 1.0.3 以降である旨）。`SAAI_WOO_MIN_BASE_VERSION` は 1.0.0 のまま（G1-1 をユーザー判断で保留）。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1（APPROVE） | Medium 1 / Low 2 / 対象外 1 | 1（R1-1） | 2（R1-L1・R1-X1。R1-L2 は R1-1 の修正に含めた） |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 1 | 0 | 1 | 未収束 |
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Codex | 0 | 0 | 0 | 収束 |
| G2 | Copilot | 0 | 0 | 0 | 収束 |

G1 の Copilot の依頼は、スクリプトの 2 方法とも登録されず、ユーザーが PR 画面から手動で依頼した。G2 は `gh pr edit` で登録された。

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| R1-1 | 独立レビュー | Medium | 照合テストが結果の比較だけで、複製のずれの多く（`\p{Hangul}` の追加、`\x{30FC}` の削除など）を見逃す → 定数とメソッドのトークン列を比べるテストを追加 | 35607d9 | — |
| G1-2 | Copilot | Medium | 照合テストが抜粋の長さを比べていない → 無料版 `WORDS` と `DEFINITION_WORDS` の一致を確かめる | ada997e | [r4254429905](https://github.com/shinobiashi/saai-knowledge/pull/98#discussion_r4254429905) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
| --- | --- | --- | --- | --- |
| G1-1 | Codex | Low（P2） | 最低版数を 1.0.3 に上げる案。ユーザー判断で保留: 食い違いは旧無料版のツールチップ側の不具合で、有料版の出力が正しい側。注記を足した（0a64de4）。backlog G1-1（issue-89） | [r4254133636](https://github.com/shinobiashi/saai-knowledge/pull/98#discussion_r4254133636) |

backlog に送った PR 前の指摘: R1-L1（無料版 `Plain_Excerpt` の docblock に複製があることを書く）、R1-X1（`Blocks::text()` の `esc_html()` が既存の文字参照を二重エンコードしない、1.0.0 から）。

## 品質ゲート

- CI: 2bbf4ed で 12 チェックすべて green（PHP Quality <https://github.com/shinobiashi/saai-knowledge/actions/runs/38342521544>、JS Quality <https://github.com/shinobiashi/saai-knowledge/actions/runs/38342521529>）
- 品質チェック: `composer verify` green（PHPCS / PHPStan / PHPUnit 649 件）、`npm run build` green
- 実機: wp-env の dev サイト（`ja`）で product-glossary のフロントと REST block-renderer を確認（英語の定義が単語の途中で切れない、和文は 55 文字 + `…`、無料版ツールチップの抜粋と同じ文）

## 次にできること（人間の判断）

- 保留分の修正: `/dev-cycle fix G1-1`（最低版数を 1.0.3 に上げる場合。直す箇所は backlog G1-1（issue-89）に記載）
- 両 bot とも「収束」なので、後日の再確認は不要
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
