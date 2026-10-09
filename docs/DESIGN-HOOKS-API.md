# 詳細設計: 公開フック API 契約（無料版 ⇔ 有料版）

親ドキュメント: `DESIGN.md` 8.1 / 対象フェーズ: M1〜M4 で実装、M5 で消費 / ステータス: Draft v1

無料版（saai-knowledge）が公開する拡張ポイントの**契約書**。有料版（saai-knowledge-for-woocommerce）はここに列挙されたフック・定数・サービスのみに依存する。ここにないものはすべて内部実装であり、予告なく変更できる。

## 1. 互換性ポリシー

- 公開フックのシグネチャ・データ形状は **semver** で管理する。破壊的変更はメジャーバージョンのみ。
- 廃止時は最低2マイナーバージョンの猶予を置き、`apply_filters_deprecated()` / `do_action_deprecated()` を経由させる。
- すべての公開フックに `@since` docblock を付ける。**このファイルに載っていないフックは公開APIではない**（実装中に増やしたくなったら、先にこのファイルへ追記してレビューする）。
- 表面積は最小に保つ。「あると便利そう」では追加せず、有料版・実ユースケースが必要とするものだけを公開する。

## 2. ブートストラップ契約

| 項目 | 内容 |
| --- | --- |
| バージョン定数 | `SAAI_KNOWLEDGE_VERSION`（メインファイルで define） |
| 起動アクション | `saai_loaded` — 無料版の全サービス登録完了後（`plugins_loaded` priority 20）に発火。引数: `SAAI\Knowledge\Plugin $plugin` |
| アドオンの作法 | アドオンは `saai_loaded` を待って初期化する。`class_exists()` / `function_exists()` による存在チェックは行わない。無料版が無い・バージョン不足の場合は admin notice を出して静かに停止 |
| 最低バージョン確認 | `version_compare( SAAI_KNOWLEDGE_VERSION, '1.0.0', '>=' )` をアドオン側で実施 |

```php
// アドオン側のエントリーポイント（参考実装）
add_action( 'saai_loaded', function ( $plugin ) {
    if ( version_compare( SAAI_KNOWLEDGE_VERSION, SAAI_WOO_MIN_BASE_VERSION, '<' ) ) {
        add_action( 'admin_notices', 'saai_woo_render_version_notice' );
        return;
    }
    SAAI\KnowledgeWoo\Plugin::boot( $plugin );
} );
```

## 3. 公開フィルター

### 3.1 自動リンク

| フック | シグネチャ | 用途 |
| --- | --- | --- |
| `saai_autolink_post_types` | `( string[] $post_types ): string[]` | 自動リンク対象 post type の追加/削除。有料版は `product` を**追加しない**（自前の `the_content` フックから `process()` を呼ぶ。理由は DESIGN-AUTOLINK.md §6）— 逆にツールチップが ON の間は `product` を取り除く |
| `saai_autolink_dictionary` | `( array $entries, array $context ): array` | 辞書の差し替え/絞り込み。entry 形状は `DESIGN-AUTOLINK.md` §2.1。`$context = [ 'post_id' => int, 'post_type' => string ]` |
| `saai_autolink_enabled` | `( bool $enabled, WP_Post $post ): bool` | 投稿単位の最終有効判定 |
| `saai_autolink_match_rejected` | `( bool $rejected, array $match ): bool` | スクリプト境界判定の上書き。`$match = [ 'pattern', 'text', 'offset', 'before_char', 'after_char' ]` |

### 3.2 KB / 表示

| フック | シグネチャ | 用途 |
| --- | --- | --- |
| `saai_kb_sidebar_items` | `( array $tree, array $context ): array` | サイドバーツリーの加工。node 形状: `[ 'type' => 'term'\|'post', 'id', 'title', 'url', 'order', 'children' => node[] ]`。`$context = [ 'current_post_id' => int\|null, 'current_term_id' => int\|null, 'taxonomy' => string ]`（`current_term_id` は saai_category タクソノミーアーカイブ表示時のみ設定。単体記事表示では `current_post_id` が優先され `current_term_id` は常に null） |
| `saai_kb_toc_items` | `( array $headings, array $context ): array` | ページ内目次の見出しリストの加工。heading 形状: `[ 'id' => string, 'text' => string, 'level' => 2\|3 ]`。`$context = [ 'post_id' => int ]` |
| `saai_breadcrumbs_items` | `( array $trail, array $context ): array` | パンくずリストの加工。node 形状: `[ 'label' => string, 'url' => string, 'current' => bool ]`。`$context = [ 'post_id' => int\|null, 'term_id' => int\|null, 'taxonomy' => string ]` |
| `saai_faq_query_args` | `( array $args, array $block_attrs ): array` | FAQ 一覧ブロックの WP_Query 引数調整。有料版が商品タブの描画時に紐づけ FAQ の `post__in`（+ `orderby => post__in`）を注入する |
| `saai_structured_data` | `( array $schema, string $schema_type, ?WP_Post $post ): array` | JSON-LD 出力の加工。`$schema_type` は `faq-page` / `qa-page` / `defined-term` / `breadcrumbs` |
| `saai_template` | `( string $template_path, string $slug ): string` | クラシックテーマ向けテンプレート解決の最終上書き。`$slug` 例: `single-saai_kb` |

`saai_template` は `taxonomy-saai_category` の解決時に限り、`pre_get_posts`（`saai_category`
タクソノミーアーカイブの KB 限定クエリスコープ判定）と `template_include`（最終的なテンプレー
ト決定）の2箇所から呼ばれる。ステートフルまたは自己解除するコールバックは、この2回の呼び出し
で異なる結果を返しうるため、その場合はクエリスコープと実際に描画されるテンプレートが一致しな
くなることがある。クエリスコープの判定にも確実に反映させたい場合は、`init` フック以前
（またはそれより早く）で登録すること。

### 3.3 検索

| フック | シグネチャ | 用途 |
| --- | --- | --- |
| `saai_search_post_types` | `( array $types ): array` | 検索対象の登録。形状: `[ 'faq' => [ 'post_type' => 'saai_faq', 'label' => string ], ... ]`。キーが REST の `types` パラメータ値になる |
| `saai_search_query_args` | `( array $args, string $query, string[] $types ): array` | 検索 WP_Query 引数の調整 |
| `saai_search_results` | `( array $results, string $query, string[] $types ): array` | 結果の加工。result 形状: `[ 'id', 'type', 'title', 'url', 'excerpt' ]`（すべて出力エスケープ前の生値。エスケープは出力層の責務） |

### 3.4 エクスポート / AI可読性

| フック | シグネチャ | 用途 |
| --- | --- | --- |
| `saai_export_record` | `( array $record, WP_Post $post, string $format ): array` | エクスポート1レコードの加工。record 形状は `DESIGN.md` §7.4。**有料版が商品ID / SKU / 商品カテゴリーを付与** |
| `saai_markdown_output` | `( string $markdown, WP_Post $post ): string` | `?format=markdown` 出力の加工 |
| `saai_llms_index_items` | `( array $items ): array` | 自名前空間 Markdown インデックス（llms.txt 第2層）の項目加工。item 形状: `[ 'type', 'title', 'url', 'markdown_url' ]` |

### 3.5 設定

| フック | シグネチャ | 用途 |
| --- | --- | --- |
| `saai_settings_sections` | `( array $sections ): array` | 設定画面へのセクション追加。形状: `[ 'section_id' => [ 'title' => string, 'fields' => field[] ] ]`。field は Settings API 準拠の `[ 'id', 'type', 'label', 'default', 'sanitize' ]` |
| `saai_default_settings` | `( array $defaults ): array` | デフォルト設定値の拡張 |

## 4. 公開アクション（テンプレート挿入ポイント）

すべてのフロントテンプレート（ブロック/クラシック共通のレンダリング層）で発火する。

| フック | 位置 | 引数 |
| --- | --- | --- |
| `saai_kb_before_article` / `saai_kb_after_article` | KB 記事本文の前後 | `WP_Post $post` |
| `saai_kb_sidebar_top` / `saai_kb_sidebar_bottom` | サイドバー内 | `array $context`（3.2 と同形状） |
| `saai_kb_toc_before` / `saai_kb_toc_after` | ページ内目次リストの前後 | `array $context`（3.2 と同形状） |
| `saai_breadcrumbs_before` / `saai_breadcrumbs_after` | パンくずリストの前後 | `array $context`（3.2 と同形状） |
| `saai_faq_before_list` / `saai_faq_after_list` | FAQ 一覧の前後 | `array $block_attrs` |
| `saai_glossary_after_definition` | 用語定義の後 | `WP_Post $post` |

有料版はこれらに「関連商品」等の逆方向リンク（コンテンツ → 商品）を将来挿入できる（v1 スコープ外、フックだけ先行提供）。

## 5. 公開サービス（PHP API）

`saai_loaded` で渡される `Plugin` インスタンス経由でのみ取得する（グローバル関数・シングルトン直接参照は提供しない）。

| サービス | メソッド | 用途 |
| --- | --- | --- |
| `$plugin->autolinker()` | `process( string $html, array $context ): string` | 自動リンクエンジンの単体実行。有料版が Woo の説明文フィルターに接続 |

公開サービスはこの1つだけ。初版の設計に載せていた `$plugin->settings()` / `$plugin->renderer()` は、M5-3（Issue #22、2026-10-09）で**追加しないと決めた**。設定値は §6 のオプション名を `get_option()` で読めば足り、FAQ の描画は §6 の公開ブロック `saai-knowledge/faq-list` を `render_block()` に渡して §3.2 の `saai_faq_query_args` で絞れば、アコーディオンのマークアップ・Interactivity API 連携・FAQPage JSON-LD を二重実装せずに済む（無料版 `Shortcodes` が使うのと同じ経路）。KB リンク一覧は有料版側の素朴なマークアップで十分で、無料版に対応する描画は存在しない。§1 の「実ユースケースが必要とするものだけを公開する」に従い、表面積を増やさない。

## 6. その他の公開識別子

| 種別 | 名前 |
| --- | --- |
| Post types | `saai_faq`, `saai_kb`, `saai_glossary` |
| Taxonomies | `saai_category`, `saai_tag` |
| Post meta | `saai_reading`, `saai_synonyms`, `saai_no_autolink`（有料版定義: `saai_linked_products`, `saai_linked_product_cats`） |
| REST namespace | `saai-knowledge/v1` |
| ブロック namespace | `saai-knowledge/*`（block.json の属性も公開契約。アドオンはサーバー側から `render_block()` で再利用してよい） |
| オプション | `saai_knowledge_settings`（配列）。アドオンからは**読み取り専用**: 自分のフィールドは `saai_settings_sections` / `saai_default_settings` で宣言し、保存は無料版の設定画面に任せる。無料版の `default_option_*` フィルターが既定値を補うのはオプション行そのものが無いときだけなので、行はあるが自分のキーが無い場合（アドオン有効化前に保存済みのサイト）は自分の既定値へフォールバックする |
| Interactivity API store namespace | `saai-knowledge/{feature}`（例: `saai-knowledge/tooltip`） |
| CSS プレフィックス | `.saai-` |

## 7. 有料版が消費するフックの対応表

| 有料版の機能 | 使用する公開API |
| --- | --- |
| 起動・依存チェック | `saai_loaded`, `SAAI_KNOWLEDGE_VERSION` |
| 商品説明への用語ツールチップ | `$plugin->autolinker()->process()`（長い説明は自前の `the_content` フック、短い説明は `woocommerce_short_description` / `render_block_*` から。`$context` に紐づけ用語 ID の指紋 `saai_woo_terms` を含める）, `saai_autolink_dictionary`, `saai_autolink_post_types`（`product` を取り除く側） |
| 商品タブに FAQ | 公開ブロック `saai-knowledge/faq-list` を `render_block()` で描画し、`saai_faq_query_args` で紐づけ FAQ の `post__in` に絞る（紐づけ解決は有料版側のクエリ）。挿入先は WooCommerce の `woocommerce_product_tabs` |
| 関連 KB セクション | 無料版 API 不要（有料版側のマークアップ。`get_permalink()` / `get_the_title()` のみ）。挿入先は WooCommerce の `woocommerce_after_single_product_summary` |
| 手動配置ブロック（`product-faq` / `product-docs` / `product-glossary`）+ ショートコード | `product-faq` は商品タブと同じく公開ブロック `saai-knowledge/faq-list` + `saai_faq_query_args`（`category` に商品ごとのマーカーを渡して FAQPage の署名を分ける）。`product-docs` / `product-glossary` は無料版 API 不要（`get_permalink()` / `get_the_title()` / `get_the_excerpt()` のみ）。ブロック名前空間 `saai-knowledge/*` は §6 のとおり無料版と共通 |
| 設定タブ追加（自動挿入 on/off） | `saai_settings_sections`, `saai_default_settings`。読み取りは `saai_knowledge_settings` オプション（§6） |
| RAG エクスポートへの商品メタ付与 | `saai_export_record` |

この表が「有料版を壊さずに無料版をリファクタリングできる範囲」の定義になる。無料版の変更が上記フック・サービスの契約を守る限り、有料版の追従リリースは不要。
