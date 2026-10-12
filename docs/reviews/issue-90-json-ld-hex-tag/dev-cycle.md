# dev-cycle 状態: issue-90-json-ld-hex-tag

- タスク: Issue #90（無料版）— JSON-LD を `JSON_HEX_TAG` 付きでエンコードし、値の `<!--<script>` で script 要素が閉じずページの残りが消える不具合を直す。範囲外で見つけた BreadcrumbList の name の文字参照未デコードも取り込む（ユーザー判断）。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-12
- PR: #92 https://github.com/shinobiashi/saai-knowledge/pull/92
- 現在のステップ: **完了**（マージ待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 2 回 / 収束（G2 で新規指摘なし）
- Codex: 依頼 1 回 / 収束（G1 で新規指摘なし）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-12 21:20 | 0 | main 9c03100 から開始（`next` → memory の次の作業 #90）。作業ツリー clean、wp-env 起動済み |
| 2026-10-12 21:20 | 1 | 計画承認。JSON-LD の 4 か所（QAPage / DefinedTerm / FAQPage / BreadcrumbList）に `JSON_HEX_TAG`。有料版は無料版の faq-list を描画するので同時に直る。計画中に見つけた `Breadcrumbs::json_ld()` の name の文字参照未デコード（`Don&#8217;t` / `Q&amp;A`、1.0.0 から）はユーザー判断で取り込み（別コミット） |
| 2026-10-12 21:27 | 2 | 実装コミット 2 件（8452dd5 BreadcrumbList の name を strip → decode、39081f3 JSON-LD 4 か所に `JSON_HEX_TAG`）。テスト 6 件追加（breadcrumbs 3 件〔デコード・タグだけのラベル・`<!--<script>`〕、faq-question / glossary-term / faq-list 各 1 件）。品質チェック green（PHPCS / PHPStan / PHPUnit 639 件 / build）。ミューテーション: `JSON_HEX_TAG` を外すと 4 件、デコードを外すと 2 件が失敗。wptexturize がタイトル・地の文の `--` を `&#8211;` にするので、`<!--` が実際に届くのは定義文・回答の `<code>`・ターム名・`saai_structured_data`（テストはこの経路で組んだ）。dev サイトで用語・FAQ・KB 記事（カテゴリー名）を作り parse5 で確認: 修正なしでは JSON-LD がページの残り（15,000〜40,000 文字）を飲み込んで不正な JSON になり、修正後は 3 ページとも正常。テストデータは削除 |
| 2026-10-12 21:39 | 3 | review-loop R1: APPROVE（Critical / High / Medium なし。自己レビュー + 独立レビュー〔Fable〕）。R1-1・R1-2（readme の 1.0.3 Changelog と security.md で JSON のエスケープが `<` / `>` のまま書かれていた。ツール入力の `\u003C` が文字に変換されたため。python で書き直し）を 6ced8bf で修正。R1-L1（パンくずの HTML 側と JSON-LD 側で空判定の規則がずれた）・R1-X1（表示側がタイトルのマークアップを文字のまま出す、1.0.0 から）を backlog へ。ループ終了 |
| 2026-10-12 21:40 | 4 | 初回 push（HEAD 7bf8b6a、T=2026-10-12T12:40:18Z）、PR #92 作成 |
| 2026-10-12 21:52 | 6-7 | G1: CI green（2 workflow run）の後に両 bot へ依頼（Copilot は gh pr edit で登録確認、Codex は @codex review）。Codex「Didn't find any major issues」で収束。Copilot 1 件（G1-1、Low。backlog の R1-X1（issue-56）が未解消のまま）を 7a3d1c0 で修正。ログの時刻（ステップ 2・3）を実際のコミット時刻に訂正 |
| 2026-10-12 21:52 | 4 | G1 の記録を push（HEAD d457ff1、T=2026-10-12T12:52:21Z） |
| 2026-10-12 22:02 | 6-7 | G2: CI 12 チェック green の後に Copilot だけ再依頼。「Approval recommended」（0 open findings）で収束。修正なし |
| 2026-10-12 22:03 | 8 | 最終報告（final-report.md）。マージ待ち |
