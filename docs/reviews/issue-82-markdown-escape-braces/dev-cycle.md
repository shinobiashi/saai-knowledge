# dev-cycle 状態: issue-82-markdown-escape-braces

- タスク: Issue #82（bug、無料版）— Markdown 出力で本文・タイトルの `{: … }` が Python-Markdown の attr_list（`extra`）に属性リストとして読まれ、`onclick` などの属性になる問題を直す（backlog R1-X1（issue-81））。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-12
- PR: #85 https://github.com/shinobiashi/saai-knowledge/pull/85
- 現在のステップ: **完了**（マージ待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 1 回 / 収束（G1 で新規指摘なし）
- Codex: 依頼 1 回 / 収束（G1 で新規指摘なし）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-12 | 0 | main 3b4be36 から開始。作業ツリー clean、wp-env 停止中 → 起動 |
| 2026-10-12 | 1 | 計画承認（`escape_text()` で `{` / `}` もバックスラッシュでエスケープ。テスト・readme 1.0.3・DESIGN §7.3・CLAUDE.md・backlog を合わせて更新） |
| 2026-10-12 | 2 | 実装（`escape_text()` に `{` / `}`、テスト 3 件〔convert・コードは非エスケープ・`?format=markdown` の H1〕、readme 1.0.3・DESIGN §7.3・CLAUDE.md・backlog〔R1-X1（issue-81）を解消、Impl-1（issue-82）Low: markdown-it は alt の中のエスケープ済みの文字を落とす〕）。品質チェック green（PHPCS / PHPStan / PHPUnit 615 件 / build）。ミューテーション（置換を外す）で新テスト 2 件が落ちる。描画比較: 修正前は `pymd-extra` が `<h2 onclick>`・`<em onclick>`・`<a onclick>`・`<code id onclick>`、修正後は 5 描画系すべて合格（`--expect-text` で `{: onclick=… }` が文字として出ることも確認）。dev の `?format=markdown` でも H1・見出し・本文がエスケープされ、テスト投稿は削除済み |
| 2026-10-12 | 3 | review-loop R1: APPROVE（Critical/High/Medium なし。独立レビュー〔Opus〕と突き合わせ）。Low 2 件（R1-L1 markdown-it-attrs も同じく直る旨の記載、R1-L2 Upgrade Notice）を backlog へ。範囲外の X1〜X4（改行を含む値が行の構造を壊し生の HTML になる、Medium。実装者が再測定して再現）はユーザー判断で Issue #84 を起票（1.0.3 前に別 PR）、X5（`fallback_plain_text()`、Low）は backlog |
| 2026-10-12 | 4 | 初回 push（HEAD c4918b5、T=2026-10-11T21:59:38Z）、PR #85 作成 |
| 2026-10-12 | 6-7 | G1: CI 12 チェック green の後に両 bot へ依頼（Copilot は gh pr edit で登録確認、Codex は @codex review）。Copilot「Approval recommended / 0 open findings」、Codex「Didn't find any major issues」で、両 bot とも新規指摘 0 件 = 収束 |
| 2026-10-12 | 8 | 最終報告（final-report.md）を記録 |
