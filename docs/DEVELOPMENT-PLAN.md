# SAAI Knowledge — 開発計画書

作成日: 2026-07-05 / 進捗はこのファイルのチェックボックスで管理する。

設計の詳細は `docs/DESIGN.md` を参照。本書は「何を・どの順で・どの完了条件で」作るかを定義する。

## フェーズ一覧

| フェーズ | 内容 | 成果物 |
| --- | --- | --- |
| M0 | プロジェクト基盤（本書・設計書・リポジトリ初期化） | docs 一式、git リポジトリ |
| M1 | 開発環境 + プラグイン骨格 + データモデル | 有効化できる無料版プラグイン、CI |
| M2 | Knowledge Base コア（2カラムレイアウト） | KB が動く状態 |
| M3 | FAQ + 用語集 | 無料版の全コンテンツ機能 |
| M4 | 検索・設定・仕上げ → WordPress.org 申請 | 無料版 v1.0.0 公開 |
| M5 | 有料版（WooCommerce 連携）→ WooCommerce.com 申請 | 有料版 v1.0.0 公開 |

各フェーズは「完了条件をすべて満たす → 次へ」。フェーズ内のタスク順は原則上から。

M1〜M5 の全タスクは GitHub Issues #1〜#27（マイルストーン M1〜M5 に割当済み。#25〜#27 は AI可読性・RAGエクスポート関連）として登録済み。実装は Issue 単位で進め、受け入れ条件は各 Issue に記載（本書と二重管理になった場合は Issue 側を正とする）。

---

## M0: プロジェクト基盤

### タスク

- [x] 基礎設計書（docs/DESIGN.md）
- [x] CLAUDE.md（プロジェクト規約）
- [x] 開発計画書（本書）
- [x] git リポジトリ初期化（.gitignore / 初回コミット、<https://github.com/shinobiashi/saai-knowledge>）
- [x] WordPress.org スラッグ `saai-knowledge` の重複・商標の事前確認（2026-07-05 調査済み: スラッグ未使用・競合名称被りなし・著名商標衝突なし。既存 `saai-blocks` は同一アカウント shinobiashi 名義。申請前に USPTO / J-PlatPat で「SAAI」第9類・42類の手動確認を推奨）

### 完了条件

- ドキュメント3点が揃い、git 管理下にある。

---

## M1: 開発環境 + プラグイン骨格 + データモデル

### タスク

- [ ] モノレポ雛形: `plugins/saai-knowledge/`、ルート `package.json`（workspaces）+ `composer.json`
- [ ] `.wp-env.json`（WP 6.9 / PHP 8.2、両プラグイン + WooCommerce マウント）
- [ ] ツールチェーン: @wordpress/scripts、PHPCS（.phpcs.xml.dist、prefix/text-domain チェック）、PHPStan（szepeviktor/phpstan-wordpress）、PHPUnit ブートストラップ
- [ ] GitHub Actions: lint + test（PHP 8.2–8.4 × WP 6.9–latest マトリクス）+ ZIP ビルド
- [ ] 無料版ブートストラップ: メインファイル、オートローダ、`Plugin::boot()`、activation/deactivation フック（rewrite flush）、`uninstall.php`
- [ ] CPT 登録: `saai_faq` / `saai_kb` / `saai_glossary`（DESIGN.md 3.1 準拠）
- [ ] タクソノミー登録: `saai_category`（FAQ+KB 共通・階層）/ `saai_tag`
- [ ] ポストメタ登録: `saai_reading` / `saai_synonyms` / `saai_no_autolink`（`register_post_meta`、REST 公開）
- [ ] 用語エディター拡張: 読みがな・別表記の DocumentSettingPanel
- [ ] テンプレートローダー骨格: ブロックテーマ（`register_block_template()`）/ クラシック（`template_include`）の分岐

### 完了条件

- wp-env 上で有効化してもエラー・notice ゼロ。3CPT + タクソノミーが管理画面・REST に出る。
- CI（lint / analyze / test）がグリーン。CPT/タクソノミー登録のユニットテストあり。

### 使用スキル

`wp-plugin-development`, `wp-phpcs`, `wp-phpstan`, `wp-phpunit`, `wp-github-actions`

---

## M2: Knowledge Base コア

### タスク

- [ ] `saai-knowledge/kb-sidebar` ブロック（saai_category ツリー + 記事、祖先ターム自動展開、Interactivity API 開閉）
- [ ] `saai-knowledge/kb-toc` ブロック（`parse_blocks()` で h2/h3 抽出、アンカー付与、スクロールスパイ）
- [ ] `saai-knowledge/breadcrumbs` ブロック（BreadcrumbList JSON-LD）
- [ ] 見出しアンカー付与フィルター（本文側の h2/h3 に ID を保証）
- [ ] KB 記事テンプレート（2カラム、ブロック / クラシック両対応）+ モバイル折りたたみ
- [ ] カテゴリーアーカイブ・KB ハブテンプレート
- [ ] タームメタ `saai_order`（ターム並び順）+ 管理 UI
- [ ] ショートコード版ラッパー（`[saai_kb_sidebar]` 等）

### 完了条件

- ブロックテーマ（Twenty Twenty-Five）とクラシックテーマ（Twenty Twenty-One 等）の両方で、developer.woocommerce.com/docs 型の2カラム表示が成立する。
- サイドバーの開閉・目次のスクロールスパイが JS エラーなしで動作。E2E テスト（表示 + ナビゲーション）あり。

### 使用スキル

`wp-block-development`, `wp-interactivity-api`, `wp-e2e-playwright`

---

## M3: FAQ + 用語集

### タスク

- [ ] `saai-knowledge/faq-list` ブロック（カテゴリー・件数・並び順属性、コア Accordion ブロック（WP 6.9）を内部利用したアコーディオン、FAQPage JSON-LD）
- [ ] FAQ アーカイブテンプレート（カテゴリー別アコーディオン）
- [ ] FAQ 個別ページテンプレート（質問=h1・回答が直下・`QAPage` JSON-LD。AI可読性要件: DESIGN.md §7.1）
- [ ] `saai-knowledge/glossary-index` ブロック（五十音 / A–Z タブ、`saai_reading` ソート）
- [ ] 用語個別ページテンプレート + DefinedTerm JSON-LD
- [ ] 自動リンクエンジン: 辞書キャッシュ（保存時無効化）、`preg_split` ベース軽量トークナイザーによる本文置換（DESIGN-AUTOLINK.md §4）、除外ルール（見出し/a/code/pre/自身のページ）、初出のみ・最大リンク数制御
- [ ] ツールチップ UI（Interactivity API、excerpt 表示、タップ対応）
- [ ] `saai_autolink_dictionary` フィルター（有料版の注入ポイント）
- [ ] 自動リンクのユニットテスト（置換・除外・キャッシュ無効化）とパフォーマンス計測（用語500件で計測）

### 完了条件

- FAQ アコーディオンが構造化データ付きで表示され、リッチリザルトテストを通る。
- **SSR 要件**: アコーディオン等の折りたたみ UI の中身が JS 無効環境でも初期 HTML に完全に含まれる（JS の役割は開閉のみ）。
- 日本語・英語両方の用語で索引と自動リンクが正しく動く。用語500件時の `the_content` 追加処理が実用範囲（目安 +10ms 以内 / キャッシュヒット時）。

### 使用スキル

`wp-block-development`, `wp-interactivity-api`, `wp-phpunit`, `wp-performance`

---

## M4: 検索・設定・仕上げ → WordPress.org 申請

### タスク

- [ ] REST 検索エンドポイント `/saai-knowledge/v1/search`（公開コンテンツのみ、スキーマ定義、レート配慮）
- [ ] `saai-knowledge/search` ブロック（ライブ検索、debounce、タイプ別グルーピング）
- [ ] Markdown 出力: FAQ/KB/用語の個別ページを `?format=markdown` で提供（`saai_markdown_output` フィルター、キャッシュあり）
- [ ] llms.txt 連携（第1層+第2層）: SEOプラグイン向け注入アダプター + 自名前空間 Markdown インデックス ※ルート `/llms.txt` の自前生成（第3層）はバックログ
- [ ] RAG エクスポート: REST `/saai-knowledge/v1/export`（jsonl/json、`modified_after` 増分、KB は h2 セクション配列付き）+ 管理画面 JSONL/CSV ダウンロード
- [ ] 設定画面（Settings API: スラッグ、自動リンク、構造化データ、AI可読性機能の on/off、アンインストール時削除）
- [ ] スラッグ変更時の deferred rewrite flush
- [ ] `uninstall.php` 実装（設定で有効時のみ CPT・メタ・オプション削除）
- [ ] i18n: POT 生成、`make-json`、日本語翻訳同梱
- [ ] セキュリティ監査（`wp-security-check` を実施）
- [ ] readme.txt（タグ・説明・FAQ・スクリーンショット）、アセット（banner / icon / screenshots）
- [ ] WordPress.org 申請 → レビュー対応 → SVN 初回デプロイ → GitHub Actions からの自動デプロイ設定

### 完了条件

- プラグインチェック（Plugin Check プラグイン）でエラーゼロ。
- WordPress.org で v1.0.0 公開。

### 使用スキル

`wp-rest-api`, `wp-plugin-development`, `wp-i18n`, `wp-security-check`, `wp-org-release`

---

## M5: 有料版（WooCommerce 連携）→ WooCommerce.com 申請

### タスク

- [ ] アドオン骨格: 依存チェック（無料版 + WooCommerce、fatal にしない）、HPOS / Cart-Checkout Blocks 互換宣言
- [ ] 紐づけメタ（`saai_linked_products` / `saai_linked_product_cats`、1値1行保存）+ 解決ロジック（商品 ∪ 所属カテゴリー祖先、重複排除）
- [ ] コンテンツ側 UI: エディターサイドバーで商品・商品カテゴリー検索選択
- [ ] 商品側 UI: 商品編集画面の逆引きメタボックス（一覧・追加・解除）
- [ ] 商品ページ表示: FAQ セクション（クラシック: `woocommerce_product_tabs` / blockified: `hooked_block_types` で `woocommerce/accordion-group` に `last_child` フック）、関連 KB セクション、商品説明への用語ツールチップ注入（`saai_autolink_dictionary`）— 各自動挿入は設定で on/off
- [ ] 手動配置ブロック: `product-faq` / `product-docs` / `product-glossary` + ショートコード
- [ ] RAG エクスポートへの商品メタデータ付与（`saai_export_record` で商品ID / SKU / 商品カテゴリーを注入 — 商品対応サポートAI構築用）
- [ ] 紐づけ解決ロジックのユニットテスト、商品ページの E2E テスト
- [ ] QIT（Quality Insights Toolkit）テストのパス
- [ ] WooCommerce.com Marketplace 申請ドキュメント整備 → 申請 → レビュー対応

### 完了条件

- クラシック（Storefront）+ ブロックテーマの両方で商品ページ表示3種が動作。
- QIT グリーン、WooCommerce.com で v1.0.0 公開。

### 使用スキル

`wc-block-development`, `wp-block-development`, `wp-phpunit`, `wp-e2e-playwright`

Marketplace 申請はプロジェクトスキル（`.claude/skills/`、saai-ten4wc から移植・2026-07-06）を使用する:

- `woo-marketplace-extension` — 拡張の技術要件
- `woo-marketplace-qit` — QIT テスト対応
- `woo-marketplace-submission` — Vendor Dashboard 提出フォーム・審査プロセス・リリース後運用
- `woo-marketplace-content` — 製品ページ・ドキュメント・ビジュアルアセット
- `woo-marketplace-pricing` — 価格設定・競合分析

※スキル内容は saai-ten4wc 側の知見が正。向こうで更新したらこちらへも同期すること。

---

## 運用ルール

- タスク完了時に本書のチェックボックスを更新し、同一コミットに含める。
- 設計と実装が乖離したら DESIGN.md を先に直す（ドキュメント優先）。
- v1.0 スコープ外（バックログ）: 記事のドラッグ&ドロップ並び替え UI、`saai_tag` の本格活用、KB 記事の評価（役に立った？）ボタン、アナリティクス、Freemius 等による自社販売、ルート `/llms.txt` の自前生成（第3層: 競合検知 + Site Health チェック付き。DESIGN.md §7.2）、WordPress Abilities API + MCP アダプター対応。
