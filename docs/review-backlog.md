# レビューバックログ（今回対応しない指摘）

| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-08-27 | R1-L1 | Low | plugins/saai-knowledge/includes/class-search.php | `saai_search_post_types` フィルターが複数の type key を同じ post_type にマッピングした場合、後勝ちで片方が無言で結果から欠落しうる | 未起票 |
| 2026-09-03 | R1-L2 | Low | plugins/saai-knowledge/includes/class-settings.php | `render_field()` が呼び出しごとに `defaults()`/`stored_settings()` を再計算（設定ページ1回の描画で7回）。管理画面専用・低頻度のため実害小 | 未起票 |
| 2026-09-03 | R1-L3 | Low | plugins/saai-knowledge/includes/class-settings.php | `slug_kb`/`slug_faq`/`slug_glossary` の重複チェックが3スラッグ間のみで、`knowledge-category` や既存ページスラッグとの衝突は未検証（WP一般的な制約として許容） | 未起票 |
