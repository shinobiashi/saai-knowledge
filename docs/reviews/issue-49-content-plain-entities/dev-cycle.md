# dev-cycle 状態: issue-49-content-plain-entities

- タスク: Issue #49（enhancement、無料版）— RAG エクスポートの `content_plain`（`Markdown_Converter::to_plain_text()`）が `the_content` 出力の HTML 文字参照（`&amp;`、wptexturize の `&#8217;` 等）を残す問題を直す。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-11
- PR: 未作成
- 現在のステップ: 3（review-loop）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-11 | 0 | 起動チェック。作業ツリー clean、main 最新。wp-env（スロット 03）起動済み。`.nvmrc` なし |
| 2026-10-11 | 1 | 計画承認。`to_plain_text()` だけがタグ除去の後にデコード（`fallback_plain_text()` は Markdown 出力なので据え置き）、テスト 4 件、readme.txt の 1.0.3 Changelog、DESIGN §7.4、backlog R1-L5 解消。範囲外の既存事象（Markdown 出力で `&lt;script&gt;` が生の `<script>` になる）は backlog に記録のみ |
| 2026-10-11 | 2 | 実装: `strip_with_block_breaks()` に `$decode_entities` を追加。テスト（`to_plain_text()` のデコード / `&lt;br&gt;` と二重エンコード / フォールバックは据え置き / REST 経由の `content_plain`）。ミューテーション: デコードを外すと 3 件、strip の前でデコードすると `&lt;br&gt;` の 1 件が失敗。PHPUnit 592 件・PHPCS・PHPStan green。dev で実測（`AT&T’s “plan” <script>alert(1)</script> a b`、`convert()` 不変） |
