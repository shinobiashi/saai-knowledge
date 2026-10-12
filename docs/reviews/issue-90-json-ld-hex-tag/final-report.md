# dev-cycle 最終報告: issue-90-json-ld-hex-tag

## 開発内容

- タスク: Issue #90（無料版）— JSON-LD の値に `<!--<script>` があると script 要素が閉じず、ページの残りが消える不具合を直す。無料版 1.0.3（未リリース）に含める
- PR: #92 https://github.com/shinobiashi/saai-knowledge/pull/92
- 承認された計画の要約
  - JSON-LD の 4 か所（QAPage / DefinedTerm / FAQPage / BreadcrumbList）を `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG` でエンコードする（`<` / `>` が `\u003C` / `\u003E` になる。JSON の値は同じ）
  - 有料版は無料版の faq-list を描画するので同時に直る
  - 計画中に見つけた範囲外の既存不具合（`Breadcrumbs::json_ld()` だけ name の文字参照をデコードしていない。`Don&#8217;t` / `Q&amp;A`、1.0.0 から）をユーザー判断で取り込む
- コミット

| sha | メッセージ |
| --- | --- |
| 8452dd5 | fix: decode character references in BreadcrumbList names |
| 39081f3 | fix: hex-escape < and > in JSON-LD |
| 46ad8c9 | docs: record issue-90 dev-cycle state and backlog entry |
| 6ced8bf | docs: write the JSON escapes literally in the 1.0.3 changelog |
| 7bf8b6a | docs: record review-loop R1 for issue-90 |
| acc2352 | docs: record PR #92 in issue-90 dev-cycle state |
| 7a3d1c0 | docs: mark the issue-56 JSON-LD backlog entry as resolved |
| d457ff1 | docs: record dev-cycle gate round 1 |

- 設計ドキュメントからの逸脱: なし
- Issue の想定からの補足: wptexturize がタイトル・地の文の `--` を `&#8211;` にするので、ブロックエディターで普通に書いた `<!--` がすべての経路で届くわけではない。届くのは、用語の定義文、FAQ の回答の `<code>` / `<pre>` の中、ターム名（パンくずのデコード以降）、FAQPage の回答の生の HTML、`saai_structured_data`。テストはこの経路で組み、`.claude/rules/security.md` に記録した

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Low 3・範囲外 Low 1 | 2（R1-1・R1-2） | 2（R1-L1・R1-X1） |

- R1 で APPROVE（Critical / High / Medium なし。自己レビュー + 独立レビュー〔Fable〕）
- R1-1・R1-2: readme の 1.0.3 Changelog と security.md で、JSON のエスケープが `<` / `>` のまま書かれていた（ツールに渡した `\u003C` が文字に変換された）。python で書き直した

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G2 | Copilot | 0 | 0 | 0 | 収束 |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Low | backlog の R1-X1（issue-56）が Medium・未解消のまま | 7a3d1c0 | https://github.com/shinobiashi/saai-knowledge/pull/92#discussion_r4248188582 |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし

## 品質ゲート

- CI: green（d457ff1。12 チェック pass）
  - PHP Quality https://github.com/shinobiashi/saai-knowledge/actions/runs/38251936287
  - JS Quality https://github.com/shinobiashi/saai-knowledge/actions/runs/38251936294
- 品質チェック: PHPCS clean / PHPStan No errors / PHPUnit 639 件 green / `npm run build`
- ミューテーション: `JSON_HEX_TAG` を外すと 4 件、デコードを外すと 2 件が失敗
- 実機（wp-env の dev サイト）: 修正を外すと JSON-LD がページの残り（15,000〜40,000 文字）を飲み込み、修正後は 3 ページとも正常（parse5 で確認、テストデータは削除済み）

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 1.0.3 のリリース（#76・#49・#79・#81・#82・#84・#56・#90）。Upgrade Notice の更新はリリース時に判断（backlog R1-L2（issue-82））
- backlog の R1-L1・R1-X1（issue-90、いずれも Low）の起票要否
