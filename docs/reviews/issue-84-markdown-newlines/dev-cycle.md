# dev-cycle 状態: issue-84-markdown-newlines

- タスク: Issue #84（bug、無料版）— Markdown 出力で改行を含む値（インラインコード・リンク先・画像の alt・エクスポートの KB セクション見出し）が1行の構文を壊し、本文の `<img onerror>` やコード例の `<script>` が生の HTML になる問題を直す。無料版 1.0.3（未リリース）に含める
- 開始: 2026-10-12
- PR: 未作成
- 現在のステップ: 3（review-loop）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-12 | 0 | main 968e98c から開始（`next` = memory の次の作業 #84）。作業ツリー clean、wp-env 起動済み |
| 2026-10-12 | 1 | 計画承認（X1 インラインコードの改行→空白、X2 `markdown_link_destination()` で `\t` `\n` `\r` を除去、X3 alt の空白の並びを畳む、X4 エクスポートの `## ` 見出し行だけ改行→空白〔`heading` フィールドはそのまま。`matching_anchor()` の照合を保つ〕。テスト・DESIGN §7.3 / §7.4・readme 1.0.3） |
| 2026-10-12 | 2 | 実装（X4 は 2 経路の重複を `Export::section_markdown()` にまとめた）。テスト 4 件追加（convert 3 件・エクスポート 1 件〔DOM 経路と blocks フォールバック〕）。品質チェック green（PHPCS / PHPStan / PHPUnit 619 件）。ミューテーション（includes の変更を外す）で新テスト 4 件とも失敗。描画比較（`bin/markdown-render-check.sh`）: 修正前は X1 が 5 描画系すべて・X2 が commonmark / markdown-it・X3 が commonmark / markdown-it / pymd・X4（dev の REST エクスポート）が 5 描画系すべてで生の HTML、修正後は X1・X2 が 5 描画系すべて、X3・X4 がフェンスを読む 4 描画系で合格（拡張なしの pymd はコードブロック単体でも失敗する既知の制約）。テスト投稿は削除済み |
