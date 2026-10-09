# dev-cycle 状態: issue-68-faq-answer-the-post
- タスク: #68 FAQ 回答描画後に the_post を再発火する（無料版 1.0.2）
- 開始: 2026-10-09
- オプション: auto-commit
- PR: 未作成
- 現在のステップ: 3（review-loop）
- Copilot: 依頼 0 回 / 未収束
- Codex: 依頼 0 回 / 未収束

## ログ
| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-10-09 22:05 | 1 | 計画承認（案A: finally で直前の投稿に setup_postdata() をかけ直す。1.0.2 へのバージョン更新を含む） |
| 2026-10-09 22:16 | 2 | 修正前に wp-env dev で再現（Cookie 付き。TT5: Reviews タブで fatal / Storefront: Reviews タブと関連商品が消える）。テストを先に書いて失敗を確認 |
| 2026-10-09 22:18 | 2 | 実装コミット 2 件（fix・1.0.2 bump）+ docs 更新。修正後は TT5・Storefront とも解消を確認。品質チェック green（PHPUnit 550 件） |
