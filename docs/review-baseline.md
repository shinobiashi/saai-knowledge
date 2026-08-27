# レビューベースライン（許容済み指摘リスト）

レビュー時、ここに記載された項目は指摘しないこと。

## 形式
- [カテゴリ] 対象範囲 — 許容理由

## 許容項目

<!--
- [WPCS] （例）Yoda condition 非適用 — チーム規約で不採用
- [DB] （例）$wpdb 直接クエリは Repository クラス内に限り許容 — 抽象化済みのため
-->

- [PHPStan] `apply_filters()` の戻り値を `is_array()` 等でガードした直後の
  `ternary.elseUnreachable` / `nullCoalesce.offset` / `booleanAnd.alwaysTrue` 誤検知 —
  `szepeviktor/phpstan-wordpress` が docblock の `@param` 型を戻り値型として信頼するため。
  該当行には `// @phpstan-ignore <identifier> (理由)` を付与済み。プロジェクトの既知パターン
  （`class-search.php` 含む複数ファイルで確立）。
