---
paths:
  - "plugins/saai-knowledge-for-woocommerce/**"
  - "plugins/saai-knowledge/includes/class-{faq-list,autolinker}.php"
  - "docs/MARKETPLACE-PREFLIGHT.md"
  - "bin/{verify-woo-zip,qit-matrix,i18n-build-woo}.sh"
---

# WooCommerce 連携（有料版と、有料版が依存する無料版の挙動）

- 有料版アドオンがヘッダーで `WC requires at least: X.Y` を宣言しても、WordPress core の `Requires Plugins`（`WP_Plugin_Dependencies`）はWooCommerce本体の導入・有効化のみを検証し、そのバージョン番号自体は一切見ない。`class_exists('WooCommerce')` だけで依存チェックを `ok` にすると、宣言より古いWooCommerceでも起動してしまう。宣言した最低バージョンは自前で `WC_VERSION` と `version_compare()` する（`Bootstrap::requirements_status()` が確立パターン。Codexレビューで指摘）。
- WooCommerce は `the_post` アクションに `wc_setup_product_data()` を掛けており、商品以外の投稿で発火すると `$GLOBALS['product']` を **unset** する。商品テンプレートの描画中に別投稿を `setup_postdata()` し、`the_post` を再発火せずに戻すと、後続の `global $product` 依存の描画が壊れる（Twenty Twenty-Five は Reviews タブの `get_review_count()` on null で fatal、Storefront は Reviews タブと関連商品が黙って消える。無料版 1.0.1 までの FAQ 回答描画で発生、1.0.2 で上記の再発火方式に修正。Issue #68）。有料版 `Product_Sections::faq_html()`（商品タブと product-faq ブロックが共用）の `$GLOBALS['product']` 退避・復元は、対応範囲の無料版 1.0.2 未満のために残す。FAQ 回答は transient キャッシュがヒットすると描画経路を通らないため、実機再現は Cookie 付きの `curl -b 'x=1'` で非キャッシュ経路を通す。
- `woocommerce_short_description` フィルターは短い説明テンプレート（`single-product/short-description.php`）だけでなく、WC の汎用整形関数 `wc_format_content()` からも掛かる（可変商品の variation 説明＝JSON 埋め込みで後から jQuery が DOM へ挿入、Featured Product / Featured Category / Category Description ブロック、カート行の商品データ、REST）。「表示中商品の短い説明」だけに処理したい場合、`doing_filter( 'woocommerce_format_content' )` は内側の `woocommerce_short_description` が先に評価されるため使えず、`debug_backtrace()` で `wc_format_content` を関数名照合して除外する（`Product_Autolink::is_inside_wc_format_content()`）。
- WC 11.2 の Product Details ブロック（`woocommerce/product-details`）は innerBlocks が無いと legacy のクラシックタブを描画し、エディター側でアコーディオンに展開されるのは `hasInnerBlocks || wasBlockJustInserted` のとき＝**新規挿入されたときだけ**（単一商品テンプレートを開いて保存しても展開されない。DESIGN.md §6.2）。アコーディオン化済みテンプレートでは `inject_compatible_tabs()` が `woocommerce_product_tabs` のサードパーティタブを `core/accordion-item` に変換してネイティブ項目の**後ろに追記**する（タブの priority が効くのは legacy タブ / クラシックテーマのみ）。
- 無料版 `Autolinker::cache_key()` は投稿 ID・`post_modified_gmt`・辞書世代・`$context` を畳み込むが、**`saai_autolink_dictionary` フィルターが返した集合は含まない**。辞書を文脈ごとに絞るアドオン（有料版は商品の紐づけ用語に絞る）が無料版の `the_content` 経路に乗ると、紐づけ変更（商品側メタボックスは `add_post_meta()` だけで投稿保存も世代バンプもない）が永続オブジェクトキャッシュ下で最大 1 時間反映されない。絞り込みの根拠となる集合の指紋を `$context` に入れて自前で `process()` を呼ぶ（`Product_Autolink` の `saai_woo_terms`。Codex レビュー PR #67 で指摘）。
- `Faq_List` の FAQPage JSON-LD スロット（`claim_structured_data_slot()`）は「正規化された公開属性 + 文脈投稿」の署名で判定し、**同一署名は再クレーム可・別署名は拒否**。同じ投稿文脈で faq-list を 2 回描画する側（商品説明内の既定属性ブロックと商品タブ）が同じ署名だと内容の異なる FAQPage が 2 つ出る。`normalize()` が公開属性以外を捨てるため、署名を変えるには公開属性を使うしかなく、`category` に存在しないスラッグを渡して `saai_faq_query_args` で `tax_query` を外す（`Product_Page::FAQ_TAB_CATEGORY_MARKER`）。手動配置の product-faq ブロックは商品ごとのマーカー（`Product_Sections::faq_block_marker()`）を使い、タブとも別商品のブロックとも署名を分ける（同じページの FAQPage は先に描画された 1 つだけ）。
