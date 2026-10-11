# dev-cycle 最終報告: issue-79-markdown-escape-lt-amp

## 開発内容

- タスク: Issue #79（bug、無料版）。Markdown 出力で、本文に文字として書かれた `<` と `&` が生の HTML・文字参照として出る問題を直す。無料版 1.0.3（未リリース）に含める
- PR: #80 https://github.com/shinobiashi/saai-knowledge/pull/80
- 承認された計画の要約: `Markdown_Converter::escape_text()` に `<`（常に）と、文字参照の形の `&` のエスケープを追加する。`Markdown_Output::meta_lines()` のターム名はデコードしてから渡す。`saai_llms_index_items` の `title` はプレーンテキストと明記する。readme 1.0.3・DESIGN §7.3・backlog・CLAUDE.md を更新する
- 計画からの変更（ユーザー判断）: 計画はバックスラッシュエスケープ（`\<` / `\&`）だった。review-loop R1-1（CommonMark でしか効かず、Python-Markdown 等では生の要素になる）を受けて、文字参照方式に変えた（`<` → `&lt;`、`>` → `&gt;`、文字参照の形の `&` → `&amp;`）
- コミット:

| sha | メッセージ |
| --- | --- |
| 1d683d4 | fix: escape literal < and character-reference & in Markdown output |
| 81bede0 | docs(readme): add Markdown escaping fix to the 1.0.3 changelog |
| 168fc66 | docs: document Markdown escaping rules for < and & (issue 79) |
| f1ca261 | fix: escape < > & in Markdown as character references, not backslashes |
| 2b0f147 | docs: record review-loop round 1 for issue 79 |
| d5258d9 | docs: record review-loop round 2 for issue 79 |
| 0ec908e | docs(readme): limit the 1.0.3 Markdown escaping note to text |
| 259bf58 | docs: record dev-cycle gate round 1 |

- 設計ドキュメントからの逸脱: なし（DESIGN §7.3 と DESIGN-HOOKS-API に追記した）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Critical/High 0 / Medium 1 / Low 4（+ 対象外 3） | 1（R1-1） | Low 3 + 対象外 3（R1-L4 は R1-1 の修正で解消） |
| R2 | R1-1 解消 / 新規 Low 1（+ 対象外 Medium 1） | 0 | Low 1 + 対象外 1 → **APPROVE** |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 2 | 1 | 1 | 未収束 |
| G2 | Copilot | 0 | 0 | 0 | 収束（Approval recommended） |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-2 | Copilot | Medium | readme 1.0.3 の「any Markdown renderer」がコードブロックまで保証して読める。主張を投稿のテキストとタイトルに限定し、CommonMark 以外で描画する場合のサニタイズを追記 | 0ec908e | https://github.com/shinobiashi/saai-knowledge/pull/80#discussion_r4240643089 |

### 修正しなかった指摘

| ID | bot | 重大度 | 理由 | スレッド |
| --- | --- | --- | --- | --- |
| G1-1 | Copilot | Low | `&` と参照名がテキストノードをまたぐと素通りする。既知の制約で、出るのは文字だけ。直すとタイトル末の `Q&A` まで `Q&amp;A` になる。backlog G1-1（issue-79）。スレッドは G2 で Copilot 自身が Resolve | https://github.com/shinobiashi/saai-knowledge/pull/80#discussion_r4240643060 |

## 品質ゲート

- CI: 259bf58 で PHP Quality（https://github.com/shinobiashi/saai-knowledge/actions/runs/38120845325 ）・JS Quality（https://github.com/shinobiashi/saai-knowledge/actions/runs/38120845289 ）とも green
- 品質チェック: PHPUnit 602 件・PHPCS・PHPStan・build が green
- 実機: dev サイトで投稿者ロールの FAQ を確認した（`?format=markdown` / エクスポート / llms.txt）。出力を commonmark.js 0.31.2 と Python-Markdown 3.11 で描画し、どちらも同じ安全な HTML になった

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- **backlog R2-X1（Medium、未起票）の扱いを決める**: `<pre>` のコードは CommonMark のフェンスで出すため、Python-Markdown では拡張なしだとフェンスを解釈せず、コード例の `<script>` が生の要素になる。`fenced_code` 拡張があっても、ブロック間の空白で2つ目以降のブロックの行頭に空白が付き、フェンスにならない。#79 と同じ脅威モデルなので、Issue として起票し、1.0.3 に入れるか（リリース前に直すか）を判断することを推奨する
- 1.0.3 のリリース（readme の `= 1.0.3 =` は #76・#49・#79 の3件）
- そのほかの backlog（issue-79）: R1-L1〜L3、R1-X1〜X3、R2-1、G1-1（いずれも Low）
