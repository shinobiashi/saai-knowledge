---
paths:
  - "plugins/**/*.php"
---

# セキュリティ・出力エスケープ（Markdown・CSV 出力は markdown-export.md）

- `esc_url()` / `esc_url_raw()` は許可外プロトコル（`javascript:`、`data:` 等）を空文字に落とすため、URLの有無は**サニタイズ後の値で判定する**。生値で判定して出力時にエスケープすると `href=""` の壊れたリンクや、構造化データへの不正値混入になる。なお `esc_url_raw( '0' )` は `'http://0'` を返す（空にはならない）ので、"0" を空扱いする実装は不要。
- JSON-LD は `wp_json_encode()` の出力をそのまま `<script type="application/ld+json">` に入れる（`esc_html()` を通すとJSONが壊れる）。`wp_json_encode()` は既定でスラッシュをエスケープするため、値に `</script>` が含まれてもタグを閉じられない。ただし `<` / `>` はエスケープしないので、値に `<!--<script>` があると script の double-escaped 状態に入り、本物の `</script>` で要素が閉じず以降のページ全体が消える（実行はされない。未修正、#90。`JSON_HEX_TAG` を付けて直す）。日本語を読める形で出すなら `JSON_UNESCAPED_UNICODE` を付ける。
- `sanitize_text_field()`（core の `_sanitize_text_fields()`）は `%[a-f0-9]{2}` にマッチするパーセントエンコードのオクテットを丸ごと削除する。`sanitize_title()` は非ASCII文字列（日本語スラッグ等）をこの形式でエンコードして保存するため、`$_SERVER['REQUEST_URI']` のようなルーティング比較専用の値（出力はしない）にPlugin Check等の警告を消す目的だけで `sanitize_text_field()` を追加すると、非ASCIIスラッグとの照合が常に不一致になり無言で壊れる。出力しない値への静的解析警告は、機械的にサニタイズ関数を足す前に用途を確認し、必要なら `phpcs:ignore` で理由を明記する。
- dynamic block を投稿本文（商品説明・固定ページ）に置くと、`the_content` の `do_blocks`（priority 9）の出力にさらに `do_shortcode`（11）が掛かるため、ブロックが出力する編集可能なテキスト（タイトル・抜粋・定義文）中の `[foo]` がショートコードとして実行される。`strip_shortcodes()` もエスケープ記法の `[[foo]]` を `[foo]` に戻すので防げない。`esc_html()` の後に `[` / `]` を `&#91;` / `&#93;` へ置換する（有料版 `Blocks::text()`）。`render_block()` の直呼びでは再現しないので、テストは `apply_filters( 'the_content', … )` を通す。
