# dev-cycle 状態: issue-90-json-ld-hex-tag

- タスク: Issue #90（無料版）— JSON-LD を `JSON_HEX_TAG` 付きでエンコードし、値の `<!--<script>` で script 要素が閉じずページの残りが消える不具合を直す。範囲外で見つけた BreadcrumbList の name の文字参照未デコードも取り込む（ユーザー判断）。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-12
- PR: 未作成
- 現在のステップ: 3（review-loop）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-12 21:20 | 0 | main 9c03100 から開始（`next` → memory の次の作業 #90）。作業ツリー clean、wp-env 起動済み |
| 2026-10-12 21:20 | 1 | 計画承認。JSON-LD の 4 か所（QAPage / DefinedTerm / FAQPage / BreadcrumbList）に `JSON_HEX_TAG`。有料版は無料版の faq-list を描画するので同時に直る。計画中に見つけた `Breadcrumbs::json_ld()` の name の文字参照未デコード（`Don&#8217;t` / `Q&amp;A`、1.0.0 から）はユーザー判断で取り込み（別コミット） |
| 2026-10-12 21:55 | 2 | 実装コミット 2 件（8452dd5 BreadcrumbList の name を strip → decode、39081f3 JSON-LD 4 か所に `JSON_HEX_TAG`）。テスト 6 件追加（breadcrumbs 3 件〔デコード・タグだけのラベル・`<!--<script>`〕、faq-question / glossary-term / faq-list 各 1 件）。品質チェック green（PHPCS / PHPStan / PHPUnit 639 件 / build）。ミューテーション: `JSON_HEX_TAG` を外すと 4 件、デコードを外すと 2 件が失敗。wptexturize がタイトル・地の文の `--` を `&#8211;` にするので、`<!--` が実際に届くのは定義文・回答の `<code>`・ターム名・`saai_structured_data`（テストはこの経路で組んだ）。dev サイトで用語・FAQ・KB 記事（カテゴリー名）を作り parse5 で確認: 修正なしでは JSON-LD がページの残り（15,000〜40,000 文字）を飲み込んで不正な JSON になり、修正後は 3 ページとも正常。テストデータは削除 |
