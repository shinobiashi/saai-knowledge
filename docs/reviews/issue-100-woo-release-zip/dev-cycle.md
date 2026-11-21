# dev-cycle 状態: issue-100-woo-release-zip

- CI: あり
- 記録: docs/reviews/issue-100-woo-release-zip/
- タスク: Issue #100（M5）— 有料版の配布 ZIP を `woo-v<x.y.z>` タグの push で CI がビルド・検証し、draft Release に sha256 と一緒に保管する（`release-woo.yml` 新設、`bin/verify-woo-zip.sh` に `--tag` / `--notes`、ドキュメント）
- 開始: 2026-11-22
- PR: 未作成
- 現在のステップ: 2（実装）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-11-22 | 0 | main 60958e4 から開始（Opus 5.5、auto-commit はユーザー選択）。作業ツリー clean |
| 2026-11-22 | 1 | 計画承認。計画中にユーザー判断で方針を変更: 同じ版の ZIP は差し替えずパッチ版を上げる（Issue の `-rc.N` 案は、最終タグでビルドし直した ZIP がアップロードした ZIP と一致しないため取りやめ）。実地確認は PR ブランチに試しタグ `woo-v1.0.0`（成功）・`woo-v9.9.9`（失敗）を push して行い、push・削除はそのつど承認を取る |
