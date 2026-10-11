# dev-cycle 最終報告: issue-81-markdown-code-block-whitespace

## 開発内容

- タスク: Issue #81（bug、無料版）。Markdown 出力でコードブロック・見出しが行頭から始まらず、Python-Markdown（`fenced_code` / `extra` あり）がコード例の `<script>` を生の要素として描画する問題を直す。無料版 1.0.3（未リリース）に含める
- PR: #83 https://github.com/shinobiashi/saai-knowledge/pull/83
- 承認された計画の要約: フェンスのまま、出力が行頭に来る空白だけのテキストノードを出力しない（`is_line_start_whitespace()`）。インデントのコードブロックは、リストの直後で CommonMark でも生の要素になるので採らない。readme 1.0.3・DESIGN §7.3・CLAUDE.md・backlog を更新する。描画比較スクリプト `bin/markdown-render-check.sh`（ユーザー指示でこの PR に含めた）で確かめる
- 計画からの変更
  - review-loop R1 の範囲外指摘（ブロックの直前のインライン要素でフェンスが行の途中から始まり、CommonMark でも生の `<script>`）を、ユーザー判断でこの PR に含めた（R1-2）
  - その後のレビューで、行頭の扱いを次のように広げた
    - インライン要素の中ではコードを含むブロックの前だけ空行を入れる（R2-1、G1-2）
    - 行頭に来るテキストとインライン要素にも行頭の記号のエスケープを掛ける（G2-1、G3-1）
    - 番号は `1\.` でエスケープする（G2-1）
    - `---` と `~~~` もエスケープする（G2-1、G3-2）
- R1 で見つけた attr_list の件（本文の `{: onclick=… }` が Python-Markdown extra で属性になる）は、ユーザー判断で Issue #82 として起票した（別 PR で 1.0.3 の前に直す）
- コミット:

| sha | メッセージ |
| --- | --- |
| 138d0b0 | chore: add a script that renders Markdown with several libraries |
| 71fba6d | fix: keep block-separating whitespace from indenting Markdown blocks |
| 65ba1ac | docs: document why Markdown blocks start at column 0 |
| cbd7760 | fix: start every Markdown block on a new line |
| dd9cc92 | fix: report raw HTML attributes in the Markdown render check |
| 90ff8df | docs: record review-loop round 1 for issue 81 |
| 007d287 | fix: keep links and emphasis whole around blocks inside them |
| 4698296 | docs: record review-loop round 2 for issue 81 |
| fa95282 | fix: start a new block before inline wrappers that hold code |
| 49fc638 | docs(readme): exclude inline elements from the 1.0.3 line-start claim |
| 694f1a2 | docs: record dev-cycle gate round 1 for issue 81 |
| bd90c71 | fix: escape block markers in any text that starts a Markdown line |
| 4f81da7 | docs: record dev-cycle gate round 2 for issue 81 |
| 72af1b4 | fix: escape inline elements and tilde fences that start a line |
| 02a614e | docs: record dev-cycle gate round 3 for issue 81 |

- 設計ドキュメントからの逸脱: なし（DESIGN §7.3 に追記した）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Critical/High 0 / Medium 2 / Low 6（+ 対象外 2） | 2（R1-1・R1-2） | Low 6 + 対象外 2（R1-X1 は Issue #82） |
| R2 | R1-1・R1-2 解消 / 新規 Low 3（+ 対象外 3） | 1（R2-1） | Low 2 + 対象外 3 → **APPROVE** |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 2 | 1 | 1 | 未収束 |
| G1 | Codex | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 0 | 0 | 0 | 収束 |
| G2 | Codex | 1 | 1 | 0 | 未収束 |
| G3 | Codex | 2 | 2 | 0 | 上限（3 回目でも新規指摘あり。修正後の再依頼はしない） |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Medium | readme の「常に行頭から」が言い過ぎ（リンクなどの中は行頭にならない） | 49fc638 | https://github.com/shinobiashi/saai-knowledge/pull/83#discussion_r4241616470 |
| G1-2 | Codex | Medium | `<ins>` などで包まれたコードの前に空行が入らず `prefix```` になる | fa95282 | https://github.com/shinobiashi/saai-knowledge/pull/83#discussion_r4241618939 |
| G2-1 | Codex | Medium | `<div>` の直下で行頭に来たテキストの `#` / `-` / `---` がエスケープされない（番号は `1\.` に） | bd90c71 | https://github.com/shinobiashi/saai-knowledge/pull/83#discussion_r4241664475 |
| G3-1 | Codex | Medium | `<span>` で包まれた行頭のテキストがエスケープされない | 72af1b4 | https://github.com/shinobiashi/saai-knowledge/pull/83#discussion_r4241727051 |
| G3-2 | Codex | Medium | 行頭の `~~~` がフェンスを開き、コード中の `~~~` で閉じると `<script>` が外に出る | 72af1b4 | https://github.com/shinobiashi/saai-knowledge/pull/83#discussion_r4241727056 |

### 修正しなかった指摘

| ID | bot | 重大度 | 理由 | スレッド |
| --- | --- | --- | --- | --- |
| G1-3 | Copilot（レビュー本文） | Low | 描画比較スクリプトが表のセルの `style` 属性を値を問わず許している。ツールの精度の問題なので backlog G1-3（issue-81）へ | なし（レビュー本文のみ） |

## 品質ゲート

- CI: PR #83 の最終 HEAD 02a614e で全チェック green
- 品質チェック: PHPUnit 612 件、PHPCS、PHPStan、build、shellcheck が green
- ミューテーション（`mutate-check.sh`）: 自分で 16 個、R2 のサブエージェントが 2 個かけ、すべて CAUGHT。復元後の作業ツリーは clean
- 描画比較（`bin/markdown-render-check.sh --from-html`）: 21 入力 × 4 描画系（commonmark / markdown-it / pymd-fenced / pymd-extra）がすべて合格。拡張なしの Python-Markdown はフェンスを解釈しないので、既知の制約として不合格
- dev サイト: コードブロックに `&lt;script&gt;` を含む KB・FAQ で、`?format=markdown` とエクスポート（本文・KB セクション）が合格した（実装の段階で確認。テスト投稿は削除済み）

## 残っている範囲外の事象（backlog）

- **R2-X1（issue-81）、Medium**: 引用の中のコードブロックを、Python-Markdown は拡張があっても認識しない。空行を含むコードは生の `<script>` になる。readme と DESIGN にサニタイズの案内として記載済み
- **R2-X2（issue-81）、Medium**: `<a>` / `<strong>` などインライン構文の中のコードブロックは、閉じフェンスの後ろに `](…)` が続くので Python-Markdown では閉じない（生の `<script>`）。CommonMark 系はこの PR で安全になった
- **Issue #82**: 本文の `{: onclick=… }` を Python-Markdown の attr_list が属性として読む（R1-X1（issue-81）、Medium）
- Low: R1-L1〜L5・R2-2・R2-3・G1-3（いずれも描画比較スクリプトとテストの細部）、R1-X2・R2-X3（変換の忠実さ）

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- Issue #82（attr_list）を 1.0.3 の前に `/dev-cycle issue #82` で直す
- backlog の R2-X1・R2-X2（Medium。どちらも Python-Markdown に限った、コードが生の HTML になる経路）を 1.0.3 に入れるか、起票するかの判断
- 保留した G1-3 を直す場合は `/dev-cycle fix G1-3`
