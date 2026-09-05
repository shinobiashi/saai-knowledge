# レビューバックログ（今回対応しない指摘）

| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-08-27 | R1-L1 | Low | plugins/saai-knowledge/includes/class-search.php | `saai_search_post_types` フィルターが複数の type key を同じ post_type にマッピングした場合、後勝ちで片方が無言で結果から欠落しうる | 未起票 |
| 2026-09-03 | R1-L2 | Low | plugins/saai-knowledge/includes/class-settings.php | `render_field()` が呼び出しごとに `defaults()`/`stored_settings()` を再計算（設定ページ1回の描画で7回）。管理画面専用・低頻度のため実害小 | 未起票 |
| 2026-09-03 | R1-L3 | Low | plugins/saai-knowledge/includes/class-settings.php | `slug_kb`/`slug_faq`/`slug_glossary` の重複チェックが3スラッグ間のみで、`knowledge-category` や既存ページスラッグとの衝突は未検証（WP一般的な制約として許容） | 未起票 |
| 2026-09-04 | R1-L4 | Low | plugins/saai-knowledge/includes/class-markdown-output.php | `render_cached()` のキャッシュキーは投稿自身の `post_modified` のみに依存するため、`saai_category` タームのリネームや `ai_readability_enabled` のOFF→ON切り替えでは、対象記事が再保存されるまでキャッシュ済みMarkdownの表記が古いまま残りうる（既存の自動リンク辞書キャッシュと同種のtradeoffとして許容） | 未起票 |
| 2026-09-05 | R1-L5 | Low | plugins/saai-knowledge/includes/class-markdown-converter.php | `to_plain_text()`（RAG export の `content_plain` 用）は既存の `fallback_plain_text()` と同じくHTMLエンティティをデコードしない。全文検索/embedding用途では「AT&amp;T」のように一部記号がエンティティ表記のまま残りテキスト品質がわずかに劣化しうる | [#49](https://github.com/shinobiashi/saai-knowledge/issues/49) |
| 2026-09-05 | R1-L6 | Low | plugins/saai-knowledge/includes/class-export.php | 管理画面の一括ダウンロード（JSONL/CSV）は `MAX_DOWNLOAD_ITEMS`(5000)件を一括でメモリに構築してからストリーミングする。R1-3でタイムアウト対策（`set_time_limit(0)`）は入れたが、根本的な「WP_Queryのページングに合わせて逐次出力する」設計への変更は将来の改善課題として残す | [#50](https://github.com/shinobiashi/saai-knowledge/issues/50) |
