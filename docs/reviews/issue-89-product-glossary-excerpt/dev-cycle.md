# dev-cycle 状態: issue-89-product-glossary-excerpt

- タスク: Issue #89（有料版、M5）— 商品ページの用語の定義文（`Product_Sections::definition()`）を、無料版 `Plain_Excerpt` と同じ文字種による数え方で切る。有料版は無料版の内部クラスを呼べないので `SAAI\KnowledgeWoo\Plain_Excerpt` に複製し、照合テストで同一性を保つ。有料版は未公開の 1.0.0 なので版数は据え置き
- 開始: 2026-10-13
- PR: #98 https://github.com/shinobiashi/saai-knowledge/pull/98
- 現在のステップ: 6（ゲート G1・bot の応答待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-13 11:04 | 0 | main 5420f85 から開始（`next` → #89 / #94 からユーザーが #89 を選択、Opus 5.5 で続行を確認）。作業ツリー clean、wp-env 起動済み |
| 2026-10-13 11:04 | 1 | 計画承認。無料版 `Plain_Excerpt::trim_words()` を有料版に複製、`definition()` の本文側をそれに切り替え（抜粋側は据え置き）、移植テスト + 照合テスト + `glossary_entries()` 経由のテスト。DESIGN.md §6.2・`.claude/rules/i18n.md`・backlog（Plan-X1（issue-56）・R1-X2（issue-23））を更新 |
| 2026-10-13 11:09 | 2 | 実装コミット 2 件（コード + テスト、ドキュメント）。`SAAI\KnowledgeWoo\Plain_Excerpt` を新設（無料版の `trim_words()` の複製）、`definition()` の本文側をそれに切り替え。テスト: 移植 7 件 + 照合テスト 1 件（`test-woo-plain-excerpt.php`）、`glossary_entries()` 経由 1 件。品質チェック green（PHPCS / PHPStan / PHPUnit 648 件 / build）。ミューテーション: 旧 `wp_trim_words()` に戻す・結果をもう一度 strip / decode すると `glossary_entries()` のテストが失敗、複製から `\p{Thai}` を外す・省略記号を変える・フォールバックのプレーン化を外すと照合テストが失敗（`\x{30FC}` を外す改変はこの環境の PCRE では `\p{Katakana}` が拾うので挙動が変わらない）。dev サイト（`ja`、`characters_including_spaces`）で verify-block: 固定ページの product-glossary と REST block-renderer の両方で英語の定義が全文（54 語）、和文が 55 文字 + `…`、紐づけ無しの商品は空。修正前の切り方では `…your passwor…`。無料版ツールチップの抜粋（`Plain_Excerpt::for_post()`）と一致。テストデータは削除 |
| 2026-10-13 11:22 | 3 | review-loop R1: APPROVE（Critical / High なし。自己レビュー + 独立レビュー〔Opus。Fable は利用枠切れで出し直し〕）。R1-1（Medium、照合テストが結果の比較だけで複製のずれの多くを見逃す）を 35607d9 で修正: 両クラスの定数と `trim_words()` / `plain_text()` のトークン列を比べるテストを追加し、入力も拡充。`mutate-check.sh` で4つの改変（`\p{Hangul}` 追加・`\f`・`ENT_HTML401`・`\x{30FC}` 削除）がすべて CAUGHT。品質チェック green（PHPUnit 649 件）。R1-L1・R1-X1 を backlog へ、R1-L2 は R1-1 の書き直しに含めた。ループ終了 |
| 2026-10-13 11:23 | 4 | 初回 push（HEAD 9ece7d7、T=2026-10-13T02:22:57Z）、PR #98 作成。状態ファイルの PR 欄を記録して push |
