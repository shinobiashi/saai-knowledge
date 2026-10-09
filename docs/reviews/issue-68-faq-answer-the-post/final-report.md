# dev-cycle 最終報告: issue-68-faq-answer-the-post

## 開発内容
- タスク: Issue #68 FAQ 回答の描画後に `the_post` を再発火しないため、商品説明に FAQ を置くと WooCommerce の `$product` が消えて商品ページが壊れる不具合の修正（無料版 1.0.2）
- PR: #69 https://github.com/shinobiashi/saai-knowledge/pull/69
- 承認された計画の要約:
  - `Faq_List::render_answer_uncached()` の `finally` で、直前のグローバル投稿が `WP_Post` なら `setup_postdata()` をかけ直して `the_post` を再発火し、そのあとで postdata スナップショットを書き戻す（`wp_reset_postdata()` と同じ規約。ただし戻り先はメインクエリではなく直前の投稿）
  - 無料版を 1.0.2 に更新し、DESIGN.md / CLAUDE.md / backlog を更新
- 修正前の再現（wp-env dev、Cookie 付きリクエスト）: Twenty Twenty-Five は HTTP 500（Reviews タブで `get_review_count()` on null）、Storefront は Reviews タブと関連商品が黙って消える。修正後は両テーマとも解消
- コミット:
  - ef50c17 fix: re-run the_post for the previous post after rendering an FAQ answer
  - a8a771f chore: bump saai-knowledge to 1.0.2
  - fffb3f8 docs: record the FAQ answer the_post re-run fix
  - 7e6a2d1 test: pin the restore target and order after an FAQ answer render
  - 1bbe0fd docs: correct the issue #68 wording in the changelog and design notes
  - 0088a3e docs: record review-loop round 1 for issue #68
  - 501c25d docs: record review-loop round 2 for issue #68
  - 760a46f fix: write the postdata snapshot back even if a the_post callback throws
  - 0e6efc8 docs: record dev-cycle gate round 1
- 設計ドキュメントからの逸脱: なし（DESIGN.md §6.2 に無料版 1.0.2 の挙動を追記）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Medium 1・Low 5・対象外 3 | Medium 1・Low 3（この PR が書いた文面の事実誤りの訂正） | Low 2・対象外 2（対象外 1 件は post-merge で対応） |
| R2 | 新規なし（R1-1 の解消をミューテーションで実測） | — | — |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 0 | 0 | 0 | 収束（Approval recommended） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Medium | 再発火した `the_post` のコールバックが例外を投げると、スナップショットの書き戻しが飛ばされる | 760a46f | https://github.com/shinobiashi/saai-knowledge/pull/69#discussion_r4230739244 |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。

## 品質ゲート
- CI: 0e6efc8 で 12 チェックすべて green（PHPCS / PHPStan / PHPUnit 6 マトリクス / Lint JS・CSS / Build / E2E）https://github.com/shinobiashi/saai-knowledge/actions/runs/37938854986
- 品質チェック（ローカル）: PHPCS / PHPStan / PHPUnit（552 件）/ build すべて green

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
  - post-merge で `docs/DEVELOPMENT-PLAN.md` の open Issue 一覧から #68 を外す（R1-X3）
- 1.0.2 のリリース: `v1.0.2` タグを push（`release.yml` が GitHub Release に ZIP を添付）→ `deploy-wporg.yml` を `version=1.0.2` で手動実行
- backlog に送った項目（R1-L2 / R1-X1 / R1-X2（issue-68））。R1-X2（有料版コメントの更新）は #23 で `faq_list_html()` を触る際に合わせて直すのが自然
