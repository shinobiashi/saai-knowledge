# dev-cycle 状態: issue-79-markdown-escape-lt-amp

- タスク: Issue #79（bug、無料版）— Markdown 出力（`?format=markdown` / llms.txt / RAG エクスポートの `content_markdown`）で、本文に文字として書かれた `<` と `&` がエスケープされず、生の HTML・文字参照として出る問題を直す。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-11
- PR: #80 https://github.com/shinobiashi/saai-knowledge/pull/80
- 現在のステップ: 6（G2 依頼・待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 1 回 / 未収束（G1 で新規 2 件）
- Codex: 依頼 1 回 / 収束（G1 で新規指摘なし。`--request-codex` の手動依頼に応答）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-11 | 0 | 起動チェック。作業ツリー clean、main 最新（`9d1434b`）。wp-env（スロット 03）を起動。`.nvmrc` なし |
| 2026-10-11 | 1 | 計画承認。`escape_text()` で `<` は常に `\<`、`&` は文字参照の形（`name;` / `#123;` / `#x1F;`）のときだけ `\&`。`Markdown_Output::meta_lines()` のターム名はデコードしてから渡す。`saai_llms_index_items` の `title` はプレーンテキストと DESIGN-HOOKS-API に明記。readme 1.0.3・DESIGN §7.3・backlog Plan-X1・CLAUDE.md を更新。キャッシュの即時無効化・フォールバック経路・リンク先 URL 内の文字参照は範囲外 |
| 2026-10-11 | 2 | 実装: `escape_text()` に `<`（常に）と文字参照の形の `&` を追加、`Markdown_Output::meta_lines()` のターム名をデコード。テスト 10 件（converter 6 / output 2 / llms 1 / export 1）。ミューテーション: `<` を外すと 8 件・`&` を外すと 5 件・ターム名のデコードを外すと 1 件が失敗。PHPUnit 602 件・PHPCS・PHPStan・build green。dev で投稿者ロールの FAQ を実測（`?format=markdown` / エクスポート / llms.txt）し、commonmark.js 0.31.2 で描画して `<script>` 要素が生成されないことを確認 |
| 2026-10-11 | 3 | review-loop R1: APPROVE（Critical/High なし）。独立サブエージェント（Fable）併用。Medium 1（R1-1: `\<` / `\&` は CommonMark でしか効かず Python-Markdown 等では生の要素になる）→ ユーザー判断で文字参照方式（`<`→`&lt;`、`>`→`&gt;`、文字参照の形の `&`→`&amp;`）に変更（f1ca261）。Low 3・対象外 3 を backlog へ。commonmark.js 0.31.2 と Python-Markdown 3.11 で同じ HTML になることを確認。PHPUnit 602 件・PHPCS・PHPStan・build green |
| 2026-10-11 | 3 | review-loop R2: **APPROVE**（R1-1 解消、新規 Critical/High なし）。独立サブエージェント（Fable）併用、ミューテーション 3 件 CAUGHT。新規 Low 1（R2-1: `escape_line_start()` の `>` 分岐）と対象外 Medium 1（R2-X1: Python-Markdown ではコードのフェンスが効かず、コード例の `<script>` が生の要素になる。既存挙動）を backlog へ。R2 での修正なし |
| 2026-10-11 | 4 | 初回 push（`d5258d9`、T=2026-10-11T07:05:11Z）+ PR #80 作成 |
| 2026-10-11 | 6-7 | G1: CI 12 チェック green 後に両 bot へ依頼。Codex「Didn't find any major issues」→ 収束。Copilot 新規 2 件: G1-2（Medium、readme の「any Markdown renderer」がコードブロックまで保証して読める）を修正（`0ec908e`）、G1-1（Low、`&` と参照名がテキストノードをまたぐと素通り。既知の制約）を保留して backlog へ |
