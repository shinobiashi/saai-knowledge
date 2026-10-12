---
paths:
  - "plugins/**/*.php"
---

# セキュリティ・出力エスケープ（Markdown・CSV 出力は markdown-export.md）

- `esc_url()` / `esc_url_raw()` は許可外プロトコル（`javascript:`、`data:` 等）を空文字に落とすため、URLの有無は**サニタイズ後の値で判定する**。生値で判定して出力時にエスケープすると `href=""` の壊れたリンクや、構造化データへの不正値混入になる。なお `esc_url_raw( '0' )` は `'http://0'` を返す（空にはならない）ので、"0" を空扱いする実装は不要。
- JSON-LD は `wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )` の出力をそのまま `<script type="application/ld+json">` に入れる（`esc_html()` を通すとJSONが壊れる）。`wp_json_encode()` は既定でスラッシュをエスケープするので値の `</script>` ではタグを閉じられないが、`<` / `>` はエスケープしない。値に `<!--<script>` があると script の double-escaped 状態に入り、本物の `</script>` で要素が閉じず、ページの残り（次に `-->` が現れた後の `</script>` まで。実際にはほぼ全体）が script の中身になって消える（実行はされない。1.0.2 まで、#90）。`JSON_HEX_TAG` で `\u003C` / `\u003E` にすれば JSON の値は同じまま防げる。値が届く経路: 定義文（生の抜粋・本文をデコードする）、回答の `<code>` / `<pre>` の中（wptexturize が `--` を `&#8211;` に変えない）、ターム名（wptexturize を通らない）、FAQPage の回答 HTML（タグを残すので `unfiltered_html` を持つユーザーの生の HTML コメント + `<script` がそのまま届く）、`saai_structured_data`。タイトルや地の文の `--` は wptexturize が en dash にするので、テストで `<!--` を届けるにはこれらの経路を使う。日本語を読める形で出すために `JSON_UNESCAPED_UNICODE` も付ける。
- `sanitize_text_field()`（core の `_sanitize_text_fields()`）は `%[a-f0-9]{2}` にマッチするパーセントエンコードのオクテットを丸ごと削除する。`sanitize_title()` は非ASCII文字列（日本語スラッグ等）をこの形式でエンコードして保存するため、`$_SERVER['REQUEST_URI']` のようなルーティング比較専用の値（出力はしない）にPlugin Check等の警告を消す目的だけで `sanitize_text_field()` を追加すると、非ASCIIスラッグとの照合が常に不一致になり無言で壊れる。出力しない値への静的解析警告は、機械的にサニタイズ関数を足す前に用途を確認し、必要なら `phpcs:ignore` で理由を明記する。
- dynamic block を投稿本文（商品説明・固定ページ）に置くと、`the_content` の `do_blocks`（priority 9）の出力にさらに `do_shortcode`（11）が掛かるため、ブロックが出力する編集可能なテキスト（タイトル・抜粋・定義文）中の `[foo]` がショートコードとして実行される。`strip_shortcodes()` もエスケープ記法の `[[foo]]` を `[foo]` に戻すので防げない。`esc_html()` の後に `[` / `]` を `&#91;` / `&#93;` へ置換する（有料版 `Blocks::text()`）。`render_block()` の直呼びでは再現しないので、テストは `apply_filters( 'the_content', … )` を通す。
