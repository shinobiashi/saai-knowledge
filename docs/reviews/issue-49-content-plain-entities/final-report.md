# dev-cycle 最終報告: issue-49-content-plain-entities

## 開発内容

- タスク: Issue #49（enhancement、無料版）— RAG エクスポートの `content_plain`（`Markdown_Converter::to_plain_text()`）が `the_content` 出力の HTML 文字参照を残す問題。wptexturize が `'` `"` を `&#8217;` `&#8220;` に変えるため、ほぼすべての記事で `AT&amp;T&#8217;s` のように出ていた
- PR: #78 https://github.com/shinobiashi/saai-knowledge/pull/78
- 承認された計画の要約:
  - `to_plain_text()` だけが、タグ除去の後・空行の圧縮の前に `html_entity_decode()` する（共有の実装 `strip_with_block_breaks()` に `$decode_entities` を追加）
  - `fallback_plain_text()` はデコードしない。出力が Markdown で、CommonMark では文字参照がそのまま文字を表すから
  - テスト 4 件を追加する
  - readme.txt の 1.0.3（未リリース）の Changelog に 1 行追加する
  - DESIGN §7.4 を更新し、backlog R1-L5 を解消扱いにする
  - 範囲外の既存事象（Plan-X1）は backlog に記録する
- 実行モード: `auto-commit`（確認ゲートなし）、Codex は `--request-codex`。実行モデルは Opus 5.5
- コミット一覧:

| sha | メッセージ |
| --- | --- |
| `ec8ac7f` | fix: decode HTML character references in the export's content_plain |
| `f276d5b` | docs(readme): note the content_plain decoding in the 1.0.3 changelog |
| `f5c938d` | docs: resolve backlog R1-L5 and describe content_plain in DESIGN 7.4 |
| `82e7089` | docs: record review-loop round 1 for issue 49 |
| （次のコミット） | docs: record dev-cycle gate round 1 and final report for issue 49 |

- 設計ドキュメントからの逸脱: なし（DESIGN §7.4 に `content_plain` の仕様を追記）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | 差分内 Critical/High/Medium 0 / Low 3（+ 対象外 1） | 0 | Low 3 + 対象外 1 → **APPROVE** |

独立サブエージェント（Opus）の敵対的レビューを併用した。backlog に送った Low は次の 3 件。

- R1-L1: `&nbsp;` だけの段落が U+00A0 の行として残る
- R1-L2: `content_plain` を HTML に差し込む利用側はエスケープが必要、という注記
- R1-L3: 増分同期では取り込み済みのレコードが更新されない、という注記

対象外の R1-X1 は、テストの `setAccessible()` が PHP 8.5 で非推奨になった件。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 0 | 0 | 0 | 収束 |
| G1 | Codex | 0 | 0 | 0 | 収束 |

### 修正した指摘

なし。

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- CI: PR #78 の 12 チェック green（`82e7089`。[PHP Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/38096751021) / [JS Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/38096751047)。記録用コミットの CI は push 後に再確認）
- ローカル: PHPUnit 592 件、PHPCS、PHPStan が green
- ミューテーション
  - デコードを外すと 3 件が失敗する
  - タグ除去の前でデコードすると、`&lt;br&gt;` のテスト 1 件が失敗する
- dev サイトで実測した。`to_plain_text()` は `AT&T’s “plan” <script>alert(1)</script> a b` を返し、`convert()` の出力は変わらない

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- backlog の Plan-X1（issue-49、Medium、未起票）の Issue 起票
  - DOMDocument 経路の Markdown 出力（`?format=markdown` / `content_markdown`）では、記事本文の `&lt;script&gt;` が生の `<script>` として出る。1.0.0 からある挙動
  - `unfiltered_html` を持たない投稿者でも書ける文字列なので、Markdown を生 HTML 許可で描画する利用側では問題になりうる
- backlog の R1-L2 / R1-L3（issue-49）の注記を readme / DESIGN に入れるかどうか。入れるなら 1.0.3 のリリース前に入れる
- 1.0.3 のリリース: マージ後に `v1.0.3` タグを push し、`deploy-wporg.yml` を `version=1.0.3` で手動実行する
