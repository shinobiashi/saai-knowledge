# dev-cycle 状態: issue-94-markdown-code-in-quote-inline

- CI: あり
- 記録: docs/reviews/issue-94-markdown-code-in-quote-inline/
- タスク: Issue #94（無料版）— Markdown 出力で、引用の中（R2-X1）とインライン構文の中（R2-X2: `a` / `strong` / `em` / `summary`）のコードブロックが Python-Markdown（`<a>` の先頭のコードは CommonMark も）で生の HTML になる不具合を直す。計画中に見つけた見出しの先頭のコード（5 描画系すべてで生の `<script>`）も同じ方法で直す。無料版を 1.0.4 に bump
- 開始: 2026-11-22
- PR: 未作成
- 現在のステップ: 3（review-loop）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-11-22 16:05 | 0 | main 3ce977f から開始（`next` → #94 / backlog R1-X1（issue-100）/ #24 を提示し、ユーザーが #94 を選択）。作業ツリー clean、wp-env は停止中だったので起動 |
| 2026-11-22 16:29 | 1 | 計画承認。引用の中はユーザー判断でコードスパン（1 行。インデントのコードはリストの後・畳まれる場所・入れ子の引用で生の HTML になるため不採用）。インライン構文の要素（`a`・`strong`/`b`・`em`/`i`・`summary`・`h1`〜`h6`）は `<pre>` を含むとき空白を畳む（`<li>` と同じ）。見出しは Issue の列挙に無い範囲の追加（計画で明示）。試作で 19 ケースが 5 描画系すべて ok |
| 2026-11-22 16:55 | 2 | 実装コミット 2 件（8ed9445 引用の中の `<pre>` をコードスパン・インライン構文と見出しの中身を畳む・リンク / 強調の前に空行を補わない + テスト 3 件追加・2 件更新 + DESIGN §7.3、0101c74 1.0.4 に bump + Changelog）。品質チェック green（PHPCS / PHPStan / PHPUnit 652 件。JS の変更なしで build は省略）。ミューテーション: 引用の分岐を外すと 3 件、畳みを外すと 2 件、`starts_new_block()` の除外を外すと 1 件が失敗。`markdown-render-check.sh --from-html` で 20 ケース × 5 描画系 = 100 件 ok（トップレベルのフェンスは pymd 以外 ok で従来どおり）。backlog: R2-X1・R2-X2（issue-81）を解消、Plan-X1（issue-94、Low: 引用の中のリスト・見出し・入れ子の引用の印がエスケープされる）を追記 |
| 2026-11-22 17:40 | 3 | review-loop R1: CHANGES REQUESTED（High 2・Medium 2。自己レビュー + 独立レビュー〔Fable、37 ケースを実測〕）。R1-1 引用の中の空の `<pre>` が `` `` `` になり、畳まれた行で後ろのコードと対になる（退行。`render_inline_code()` が空に `''` を返すよう修正。同じ根の R1-X1〔空のインライン `<code>`、main でも 5 系で生の HTML〕も同時に解消）、R1-2 `href` の無い `<a>` が裸のコードスパンになり隣のインラインコードと続く / fenced_code の偽の開きフェンス（退行。`<span>` と同じく中身を通す）、R1-3 テスト追加、R1-4 DESIGN・readme の文言。範囲外 R1-X2（行頭のインラインコードの偽フェンス、Medium）・R1-X3（`<li>` の中でインラインコードの直後の `<pre>`、Medium）・R1-X4（Low）を backlog へ |
