# dev-cycle 最終報告: issue-84-markdown-newlines

## 開発内容

- タスク: Issue #84（bug、無料版）— Markdown 出力（`?format=markdown`・llms.txt・RAG エクスポートの `content_markdown`）で、改行を含む値が1行の構文を壊し、本文の `<img onerror>` やコード例の `<script>` が生の HTML になる問題を直す。無料版 1.0.3（未リリース）に含める
- PR: #86 https://github.com/shinobiashi/saai-knowledge/pull/86
- 承認された計画の要約: X1 インラインコードの改行→空白（`render_inline_code()`）、X2 リンク・画像の行き先から ASCII のタブ・改行を除去（`markdown_link_destination()`。llms.txt の URL も）、X3 alt の空白の並びを畳む、X4 エクスポートの KB セクションの `## ` 見出し行だけ改行→空白（`Export::section_markdown()`。`heading` フィールドは `matching_anchor()` の照合のためそのまま）。テスト・DESIGN §7.3 / §7.4・readme 1.0.3
- 計画からの追加（review-loop R1 でユーザー判断）: コードブロックの単独の CR を `\n` に揃える（`render_code_fence()`。`escape_table_cell()` も `\r` を空白に）、`**Category:**` 行のターム名の改行→空白（`Markdown_Output::meta_lines()`）

コミット:

| sha | メッセージ |
| --- | --- |
| 29ee472 | fix: keep line breaks out of one-line Markdown syntax |
| 074dd4d | docs: record issue 84 progress |
| a0b1180 | fix: keep carriage returns and category-name newlines out of Markdown lines |
| 524031f | test: pin the export section anchor match with an explicit heading anchor |
| 93823b0 | docs: record review-loop round 1 for issue 84 |
| e3f40a6 | docs: record review-loop round 2 for issue 84 |
| 554e80b | docs: record dev-cycle gate round 1 for issue 84 |
| （本コミット） | docs: record dev-cycle final report for issue 84 |

設計ドキュメントからの逸脱: なし（DESIGN §7.3 の方針の範囲内。§7.3 / §7.4 は更新済み）。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Medium 2（R1-1 範囲外からユーザー判断で追加、R1-3 テストの検証不足）・Low 1（R1-2 範囲外からユーザー判断で追加） | 3 | Low 3（R1-X1・R1-L1・R1-L2） |
| R2 | 新規 Low 1（R2-1 DESIGN の文言） | 1 | 範囲外 Low 1（R2-X1） |

R1・R2 とも APPROVE（実装者のレビュー + 独立サブエージェント〔Opus〕。R2 はミューテーション 3 件とも CAUGHT）。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 1 | 1 | 0 | 未収束 → G2 |
| G2 | Copilot | 0 | 0 | 0 | 収束 |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Low | dev-cycle の状態ファイルの PR 欄が「未作成」のまま | 554e80b | https://github.com/shinobiashi/saai-knowledge/pull/86#discussion_r4244119818 |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- CI: https://github.com/shinobiashi/saai-knowledge/actions/runs/38193492361 （PHPCS・PHPStan・PHPUnit 7 マトリクス）/ https://github.com/shinobiashi/saai-knowledge/actions/runs/38193492364 （Lint JS / CSS・Build・E2E）— 554e80b で 12 チェック green
- 品質チェック: `composer verify` green（PHPCS / PHPStan / PHPUnit 621 件）
- 描画比較（`bin/markdown-render-check.sh`）: 修正前は X1・X4 が5描画系すべて、X2 が commonmark / markdown-it、X3 が commonmark / markdown-it / pymd、CR が引用で commonmark / markdown-it・表のセルで markdown-it / pymd-extra で生の HTML。修正後は X1・X2 が5描画系すべて、X3・X4・CR がフェンスを読む4描画系で合格（拡張なしの pymd はコードブロック単体でも失敗する既知の制約）

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 両 bot とも「収束」なので再確認は不要
- backlog に追加した Low（R1-X1 setext の `===`、R1-L1 改行→空白の処理の共通化、R1-L2 CRLF の扱い、R2-X1 表のセルの中の引用 + コード）は、いずれも生の HTML にはならない。Issue 化するかは判断待ち
- 1.0.3 は #76・#49・#79・#81・#82・#84 を含めて未リリース。Upgrade Notice の更新はリリース時に判断（backlog R1-L2（issue-82））
