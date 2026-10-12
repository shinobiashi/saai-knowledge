---
paths:
  - "**/*.php"
  - ".phpcs.xml.dist"
  - "phpstan.neon.dist"
  - "phpstan-bootstrap.php"
---

# 静的解析・lint（PHPCS / PHPStan）

- ファイルdocblock直後の文が裸の `require`/`include` だと、PHPCSの `Squiz.Commenting.FileComment.Missing` が誤検知することがある（bootstrap系ファイルで発生実績あり）。docblock直後には代入文などを挟み、requireは後に置く。
- PHPクラス名は WordPress 標準の `Post_Types` 形式（アンダースコア区切りPascalCase）を使う。カスタムオートローダー（`saai-knowledge.php`）はアンダースコア→ハイフン変換のみでファイルパスを解決し、WPCSの `WordPress.Files.FileName` sniffも同じ変換を期待するため、`PostTypes` のような純CamelCaseクラス名は両方と不整合になる（lint失敗・オートロード不可）。
- `szepeviktor/phpstan-wordpress` は `apply_filters()` 呼び出し直前のdocblockコメントの最初の `@param` 型を、その呼び出しの戻り値型として採用する。サードパーティ由来の戻り値を実行時に `is_array()` 等でガードしていても、PHPStanはdocblockの型を信頼して「常に真」（`ternary.elseUnreachable`等）と誤検知することがある。ガードの必要性自体は変わらないため、該当行に理由を添えた `// @phpstan-ignore <identifier>` を付ける。
- テンプレートファイル名（`single-{$post_type}.php` 等）はCPTスラッグ（アンダースコア含む）と一致させる必要があり、PHPCSの `WordPress.Files.FileName`（ハイフン強制）と衝突する。`templates/` ディレクトリは当該sniffの除外対象に追加済み。
