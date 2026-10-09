# dev-cycle 最終報告: issue-27-export-product-metadata

## 開発内容

- タスク: Issue #27 / M5-6 — RAG エクスポートへの商品メタデータ付与
- PR: [#72](https://github.com/shinobiashi/saai-knowledge/pull/72)
- 承認された計画の要約: 有料版の新規サービス `Export_Metadata` が無料版の公開フィルター `saai_export_record` に掛かり、`products: [ { id, sku, name } ]` / `product_categories: [ { id, slug, name } ]` を追加する。中身はコンテンツに保存された紐づけそのもの（カテゴリー → 商品の展開はしない）。認証なしのエンドポイントなので公開中・パスワードなしの商品だけを出す。キーは要素があるときだけ追加し、紐づけの無いレコードは無料版単体と同じ形を保つ。CSV のときだけ JSON 文字列（日本語をエスケープしない）。無料版のコードは変更しない
- コミット:

| sha | メッセージ |
| --- | --- |
| 31a84d8 | feat: add linked product metadata to RAG export records |
| d1c8fd8 | docs: document the product metadata in RAG export records |
| 586f9ad | perf: fetch linked products in one query regardless of dangling IDs |
| 51dbb17 | docs: record review-loop round 1 for issue 27 |
| 194969f | test: pin stored order and the uncapped count of exported products |
| 42b66d5 | docs: record review-loop round 2 for issue 27 |
| f830dab | docs: update dev-cycle state for issue 27 |

- 設計ドキュメントからの逸脱: なし（DESIGN.md §7.4 に付与するキーの形状・規則を追記、DESIGN-HOOKS-API.md §7 に `Export_Metadata` を追記）。計画承認時のユーザー判断 — CSV は JSON 文字列、増分同期で商品側の変更を検知できない点は文書化のみ（backlog P1（issue-27）） — を反映済み

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Medium 1 / Low 4（+ 対象外 1） | Medium 1 + Low 2（ドキュメント） | Low 2 + 対象外 1 |
| R2 | 新規 Critical/High/Medium 0 / Low 2 | Low 2（テスト追加） | 0 → **APPROVE** |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Copilot | 0 | 0 | 0 | 収束 |
| G1 | Codex | 0 | 0 | 0 | 収束 |

### 修正した指摘

なし（ゲートでの指摘なし）。

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし。

## 品質ゲート

- CI: f830dab で PHP Quality（<https://github.com/shinobiashi/saai-knowledge/actions/runs/38002632850>）・JS Quality（<https://github.com/shinobiashi/saai-knowledge/actions/runs/38002632962>）とも green（12 チェック）
- 品質チェック: PHPCS / PHPStan green、PHPUnit 588 件 green（wp-env tests）、`npm run build` 成功
- 実機（wp-env dev、WP 7.1.3 / WC 11.2.0）: 実際の `WC_Product` で REST（JSON / JSONL）と CSV 書き出しを確認。SKU の読み取り、非公開・下書き・完全削除の除外、保存順、日本語のまま、紐づけなし FAQ にキーが無いこと

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- backlog に送った Low（R1-L1 削除済みタームの個別クエリ、R1-L4 CSV の書き出しまで通すテスト、R1-X1 無料版の REST スキーマに有料版のキーが載らない、P1 増分同期の制約）は必要になった時点で Issue 化
- 無料版 1.0.2 のリリース（`v1.0.2` タグ push → `deploy-wporg.yml` を `version=1.0.2` で手動実行）は未実施のまま
- M5 の残り: #71（Linked Products パネルの `context=edit` 問題）、#24（QIT・Marketplace 申請）
