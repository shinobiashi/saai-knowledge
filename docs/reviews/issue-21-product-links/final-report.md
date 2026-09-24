# dev-cycle 最終報告: issue-21-product-links

## 開発内容

- タスク: Issue #21 / M5-2 — 紐づけメタ + 解決ロジック + 双方向 UI
- PR: [#64](https://github.com/shinobiashi/saai-knowledge/pull/64)（OPEN / MERGEABLE / head `2ce23f5`）
- 差分: 24 files, +4850 / -9

### 承認された計画の要約

FAQ / KB / 用語 ⇔ WooCommerce 商品・商品カテゴリーの紐づけを、**コンテンツ側ポストメタを唯一の真実**として実装し、双方向どちらの画面から編集しても同じメタ行に収束させる。

- `Post_Meta` — `saai_linked_products` / `saai_linked_product_cats` を3CPTに `single: false`（1値1行）で登録
- `Link_Resolver` — 直接紐づけ ∪ 所属カテゴリー（祖先含む）、重複排除、`menu_order` → title 順。**WooCommerce の関数を一切使わず core API のみ**で組み、PHPUnit がスタンドイン登録で本番と同じ経路を検証できるようにした
- `Links_Controller` — `saai-knowledge-woo/v1` の4ルート（一覧 / 追加 / 解除 / コンテンツ検索）
- `Content_Editor` + `Product_Metabox` — サイドバーパネルと商品メタボックス（ビルドなしの手書き JS）

### コミット一覧

| sha | メッセージ |
| --- | --- |
| `209e6f1` | feat: store and resolve product links on SAAI content |
| `5c5f348` | feat: add REST routes for editing a product's links |
| `8a437a5` | feat: add the bidirectional product-linking admin UI |
| `73c3c81` | docs: record the M5-2 product-linking design and progress |
| `d0631ee` | fix: narrow the link search to titles and cover the review-loop findings |
| `99e0397` | docs: record the review-loop R1 result for issue-21 |
| `72e3517` | fix: strip protected title prefixes and land the R2 review findings |
| `f90abf1` | docs: record the review-loop R2 result (APPROVE) for issue-21 |
| `1250038` | docs: update the dev-cycle state for issue-21 |
| `ec93757` | fix: report password protection explicitly in the product meta box |
| `756de48` | docs: record dev-cycle gate round 1 |
| `a933fc6` | fix: stop offering link actions that can only answer 403 |
| `daaa9f4` | docs: record dev-cycle gate round 2 |
| `3fb132f` | fix: keep editable search matches from being crowded out |
| `2ce23f5` | docs: record dev-cycle gate round 3 |

### 設計ドキュメントからの逸脱

1. **検索 API**: DESIGN.md §6.1 の「Woo の商品検索 REST を利用」→ **core REST**（`/wp/v2/product`・`/wp/v2/product_cat`）に変更（着手前にユーザー承認済み）。`/wc/v3/products` は読み取りに `read_private_products`（既定で administrator / shop_manager のみ）を要求し、Editor 権限で 403 になり UI が壊れるため。DESIGN.md を訂正済み。
2. **権限境界の明文化**（実装変更なし）: 商品側ルートの `edit_post` は**リンクの権限境界ではない**。同じメタは core の `/wp/v2/saai_faq/{id}` にコンテンツへの `edit_post` だけで書ける（実機確認済み）。1の設計判断の裏返しの意図した挙動なので、M5-3 以降が誤った前提を置かないよう DESIGN.md §6.1 に明記した。
3. **管理画面 JS はビルドなし**（承認済み）: 有料版に `package.json` を作らず、無料版 `glossary-panel.js` と同じ手書き `window.wp` 方式。`package-lock.json` / `ci-js.yml` に一切触れていない。副作用として有料版の JS は CI で lint されない（R1-B2。M5-3 のビルド導入時に解消予定）。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog | 判定 |
| --- | --- | --- | --- | --- |
| R1 | High 1 / Medium 5 / Low 10 | 16 | 8 | CHANGES REQUESTED |
| R2 | 新規 Critical/High 0 / Low 7 | 8 | 0 | **APPROVE** |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 1 | 1 | 0 | 応答あり |
| G1 | Copilot | 3 | 2 | 1 | 応答あり |
| G2 | Codex | 2 | 2 | 0 | 応答あり |
| G2 | Copilot | 1 | 0 | 1 | 応答あり |
| G3 | Codex | 1 | 1 | 0 | 応答あり |
| G3 | Copilot | 0スレッド + 本文1件 | 0 | 1（誤検知） | 応答あり |

両 bot とも3ラウンド実施し、毎回現 HEAD に応答があった（TIMEOUT による打ち切りは無し）。
ただし G3 でも Codex から新規指摘が出ているため「収束」ではなく**3回到達による終了**。

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット |
| --- | --- | --- | --- | --- |
| G1-1 | Codex | Medium | R2-4 の副作用でパスワード保護状態の表示情報が消えていた（保護された公開投稿は `post_status` が `publish` のまま） | `ec93757` |
| G1-3 | Copilot | Medium | `wp.editPost` へのフォールバックがあるのに `wp-edit-post` を依存宣言していなかった | `ec93757` |
| G1-4 | Copilot | Low | DEVELOPMENT-PLAN の1行がユニットテストと E2E を束ねていた | `ec93757` |
| G2-1 | Codex | Medium | 編集できないコンテンツに、必ず 403 になる `Unlink` ボタンを出していた | `a933fc6` |
| G2-2 | Codex | Medium | 同じ穴が検索側にも（選んでも必ず失敗する候補） | `a933fc6` |
| G3-1 | Codex | Medium | G2-2 の副作用。`posts_per_page` が権限フィルタより先に効き、編集可能な候補が結果から消える | `3fb132f` |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
| --- | --- | --- | --- | --- |
| G1-2 | Copilot | — | **誤検知**。`WP_REST_Server` は `has_valid_params()` を `sanitize_params()` より先に実行するため、`minimum: 1` が先に 400 を返し `absint` に到達しない。実行順序への依存を固定するテストは追加した | [r4092544845](https://github.com/shinobiashi/saai-knowledge/pull/64#discussion_r4092544845) |
| G2-3 | Copilot | Low | 指摘は正しいが、`wp_postmeta` に一意制約が無く `add_post_meta()` の `$unique` は meta_key 単位のため原子的一意化の手段が無い。重複行が無害かつ自己修復することを検証し、DESIGN.md の「1値1行」が保存形式の規約であって一意性保証ではないことを明記 | [r4093020428](https://github.com/shinobiashi/saai-knowledge/pull/64#discussion_r4093020428) |
| G3-2 | Copilot | — | **誤検知**（レビュー本文・スレッドなし）。`hide_empty` の REST 既定値は `true` ではなく `false`（`class-wp-rest-terms-controller.php:1190`）。実機でも商品ゼロの term が返ることを確認 | サマリコメントで回答 |

## 品質ゲート

- CI: [14 チェック green](https://github.com/shinobiashi/saai-knowledge/pull/64/checks)（PHPCS / PHPStan / PHPUnit PHP 8.2–8.4 × WP 6.9・latest / Build / E2E Playwright / Lint JS / Lint CSS）
- ローカル品質チェック: PHPCS green / PHPStan green / **PHPUnit 515件 green**（本ブランチ新規 76件）
- 実機検証（wp-env / WP 7.1.2 / WC 11.2.0-beta.1）: 受け入れ条件どおり、サイドバーから紐づけ →
  商品メタボックスに direct / inherited(via カテゴリー) として出る → メタボックスから追加すると
  サイドバー側にも同じ商品が現れる、という**双方向の収束**を確認。ブラウザで両 UI の描画・検索・
  追加・解除も確認（プラグイン由来のコンソールエラーなし）

## 次にできること（人間の判断）

- **マージ**（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 保留分に手を入れる場合: `/dev-cycle fix G1-2 G2-3` または `/fix-copilot-review 64`
- 両 bot とも毎ラウンド現 HEAD に応答しており「未確認」で打ち切った bot は無いため、
  後日の再確認は必須ではない。ただし G3 でも新規指摘が出た（収束はしていない）ので、
  時間をおいて `/fix-copilot-review 64` を回す価値はある
- backlog に R1-B1〜B8（R1-B4 は G3 で解消）。特に **R1-B2**（有料版が `npm run lint:js` の対象外）は
  M5-3 のビルド導入時に合わせて解消する
