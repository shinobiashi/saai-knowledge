# dev-cycle 状態: issue-56-trim-excerpt-by-script

- タスク: Issue #56（無料版）— 手動の抜粋が無い用語の抜粋（自動リンクのツールチップ・用語ページの JSON-LD の description）を `wp_trim_words()` のサイトの言語依存の数え方ではなく、文章の文字種で切り詰める。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-12
- PR: #91 https://github.com/shinobiashi/saai-knowledge/pull/91
- 現在のステップ: **完了**（マージ待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 2 回 / 収束（G2 で新規指摘なし）
- Codex: 依頼 2 回 / 収束（G2 で新規指摘なし）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-12 | 0 | main a312e22 から開始（`next` → ユーザー選択で #56）。作業ツリー clean、wp-env 起動済み |
| 2026-10-12 | 1 | 計画承認。Issue の方針案（gettext フィルターで `words` に切り替える）は `WP_Locale::init()` が値を保存するため効かないので、和文などは1文字、それ以外は空白区切りの1語を1単位として55単位で切る `Plain_Excerpt` を作る。対象は無料版の `Autolinker::entry_excerpt()` と `Glossary_Term::description()`（ユーザー判断）。有料版 `Product_Sections::definition()` は backlog + Issue 起票。既存サイトのツールチップを作り直すため、辞書のキーにプラグインの版数を畳み込む |
| 2026-10-12 | 2 | 実装コミット 2 件（4fc41e9 `Plain_Excerpt` と呼び出し側 2 か所、6912ca7 辞書のリビジョン）。テスト 11 件追加（`test-plain-excerpt.php` 8 件、autolinker 2 件、glossary-term 1 件）。品質チェック green（PHPCS / PHPStan / PHPUnit 632 件）。ミューテーション（includes の変更を外す）で統合テスト 3 件とも失敗。dev サイト（`ja`）で英語の定義文のツールチップと JSON-LD が 55 語・語の途中で切れないこと、日本語の定義文が 55 単位（64 文字）で切れることを確認し、テスト投稿は削除。有料版 `Product_Sections::definition()` は Issue #89 を起票し backlog Plan-X1（issue-56）に記録 |
| 2026-10-12 | 3 | review-loop R1: APPROVE（差分内は Low のみ。独立レビュー〔Opus〕と突き合わせ）。範囲外の R1-1（High。パスワード保護された用語の本文・手動の抜粋がツールチップで全訪問者に出る。dev で再現）をユーザー判断でこの PR に取り込み、`entry_excerpt()` が `post_password` のある用語に空を返すよう修正（8d3bf09。ミューテーション CAUGHT）。R1-L3〜L5（この差分で書いた文言の誤り）も修正（8b8606c）。R1-X1（Medium。JSON-LD の `<!--<script>` でページの残りが消える）はユーザー判断で Issue #90 を起票。R1-L1・R1-L2・R1-X1〜X3 を backlog へ。PHPUnit 633 件 green |
| 2026-10-12 | 3 | review-loop R2: APPROVE（R1-1 解消。独立レビューの別観点の変異3通りも CAUGHT）。新規 Low の R2-1（保護された用語はタイトルに「保護中: 」が付き同義語でしかリンクされない）・R2-2（ページキャッシュの案内）を readme・docblock・DESIGN で修正（ff3910d）。範囲外の R2-X1 を backlog へ。ループ終了 |
| 2026-10-12 | 4 | 初回 push（HEAD 8a70fdc、T=2026-10-12T07:24:52Z）、PR #91 作成 |
| 2026-10-12 | 4 | 状態ファイルの PR 欄を更新して push（HEAD 5f94f26、T=2026-10-12T07:25:35Z） |
| 2026-10-12 | 6-7 | G1: CI green の後に両 bot へ依頼（Copilot は gh pr edit で登録確認、Codex は @codex review）。Copilot 1 件（G1-1、会員制プラグインの `the_content` 保護の迂回）は差分範囲外・共有キャッシュでは直せないため保留（backlog）。Codex 1 件（G1-2、文字参照をデコードする前に数えていた）を a791f04 で修正。返信・Resolve・サマリコメント済み |
| 2026-10-12 | 4 | G1 の記録を push（HEAD f68be26、T=2026-10-12T07:41:28Z） |
| 2026-10-12 | 6-7 | G2: CI 12 チェック green の後に両 bot へ再依頼。Copilot「Approval recommended」（open finding は保留済みの G1-1 のみ）、Codex「Didn't find any major issues」で両 bot 収束。修正なし |
| 2026-10-12 | 8 | 最終報告（final-report.md）。マージ待ち |
