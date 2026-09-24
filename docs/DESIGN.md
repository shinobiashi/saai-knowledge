# SAAI Knowledge — 基礎設計書

作成日: 2026-07-05 / ステータス: v1.0（2026-07-05 最新API ファクトチェック反映済み）

詳細設計: [自動リンクエンジン](DESIGN-AUTOLINK.md) / [公開フックAPI契約](DESIGN-HOOKS-API.md) / 開発計画: [DEVELOPMENT-PLAN.md](DEVELOPMENT-PLAN.md)

## 1. プロダクト概要

| 項目 | 内容 |
| --- | --- |
| 無料版 | **SAAI Knowledge**（slug: `saai-knowledge`）— WordPress.org で配布 |
| 有料版 | **SAAI Knowledge for WooCommerce**（slug: `saai-knowledge-for-woocommerce`）— WooCommerce.com Marketplace で販売 |
| 提供機能（無料） | FAQ / Knowledge Base / 用語集（Glossary）の3コンテンツタイプ、KB 2カラムレイアウト、用語自動リンク＋ツールチップ、ライブ検索 |
| 提供機能（有料） | FAQ・KB記事・用語を WooCommerce の商品／商品カテゴリーに紐づけ、商品ページへ表示 |
| エディター | Gutenberg（ブロックエディター）専用。`show_in_rest => true` |
| 動作要件 | WordPress 6.9+ / PHP 8.2+ / （有料版）WooCommerce L-2 ポリシー準拠（最新版と直近2メジャー） |
| テーマ対応 | ブロックテーマ・クラシックテーマ両対応 |
| 販売・更新 | 有料版のライセンス認証・アップデート配信は WooCommerce.com Marketplace に委譲（自前ライセンスサーバーなし） |

### 設計原則

1. **無料版は単体で完結** — WordPress.org ガイドライン準拠（機能制限による釣り禁止）。有料版は「WooCommerce 連携」という独立した価値を追加するアドオン。
2. **アドオンは基盤のフックのみに依存** — 無料版が公開する actions/filters/サービス経由で拡張し、内部実装に触れない。
3. **セキュリティファースト** — sanitize on input / escape on output、nonce + capability の二重チェック、`$wpdb->prepare()`。
4. **フロントは Interactivity API** — アコーディオン・ツリー開閉・ライブ検索・スクロールスパイを `@wordpress/interactivity` で統一（WP 6.9+ 前提なので全面採用）。

---

## 2. プラグイン構成（2プラグイン体制）

```text
saai-knowledge/                     ← 無料版（WordPress.org）
saai-knowledge-for-woocommerce/     ← 有料版アドオン（WooCommerce.com）
```

- 有料版は起動時に無料版の存在・バージョンをチェックし、未インストールなら管理画面に通知を出して機能を停止（fatal にしない）。
- 有料版は WooCommerce 未有効時も同様に安全に停止。HPOS・Cart/Checkout Blocks 対応を `FeaturesUtil::declare_compatibility()` で宣言。

### リポジトリ構成（モノレポ）

```text
saai-knowledge/                  (リポジトリルート = 現ディレクトリ)
├── docs/                        設計・仕様ドキュメント
├── plugins/
│   ├── saai-knowledge/
│   └── saai-knowledge-for-woocommerce/
├── .wp-env.json                 ローカル開発環境（両プラグイン + WooCommerce をマウント)
├── composer.json                PHPCS / PHPStan / PHPUnit（ルートで共有）
├── package.json                 @wordpress/scripts（workspaces で各プラグインをビルド）
└── .github/workflows/           CI（lint / test / build / deploy）
```

配布時は各プラグインディレクトリを個別に ZIP 化。WordPress.org へは無料版のみ SVN デプロイ。

---

## 3. データモデル

プレフィックスは `saai_`（フック・オプション・メタすべて統一）。

### 3.1 カスタム投稿タイプ

| Post Type | 用途 | 主な設定 |
| --- | --- | --- |
| `saai_faq` | FAQ（1投稿 = 1つのQ&A。タイトル=質問、本文=回答） | `public: true`, `has_archive: true`, `hierarchical: false`, `supports: title, editor, excerpt, revisions, custom-fields`, `show_in_rest: true`, `menu_icon: dashicons-editor-help` |
| `saai_kb` | Knowledge Base 記事 | 同上 + `page-attributes`（`menu_order` でサイドバー内の並び順を制御） |
| `saai_glossary` | 用語（タイトル=用語、本文=定義） | `public: true`, `has_archive: true`, `hierarchical: false`, `supports: title, editor, excerpt, revisions, custom-fields`, `show_in_rest: true` |

### 3.2 タクソノミー

| Taxonomy | 対象 | 設定 |
| --- | --- | --- |
| `saai_category` | `saai_faq` + `saai_kb` **共通** | `hierarchical: true`, `show_in_rest: true`, `show_admin_column: true` |
| `saai_tag` | `saai_faq` + `saai_kb`（任意付加） | `hierarchical: false`。v1では登録のみ・UI最小限 |

- **KB サイドバーのツリー = `saai_category` の階層そのもの**。ターム＝セクション、記事は所属タームの下に `menu_order` 順で並ぶ。
- タームメタ `saai_order`（ターム自体の表示順）、`saai_icon`（任意）を持つ。
- 用語集はタクソノミーを使わず、**読みがな（`saai_reading`）による五十音／A–Z 索引**でグルーピング。

### 3.3 ポストメタ

| メタキー | 対象 | 用途 |
| --- | --- | --- |
| `saai_reading` | glossary | 読みがな（日本語の五十音索引・ソート用）。空なら `remove_accents(title)` でフォールバック |
| `saai_synonyms` | glossary | 別表記・略語（自動リンクのマッチ対象。改行区切り） |
| `saai_no_autolink` | 全CPT + page/post | この投稿内では自動リンクを無効化するフラグ |
| `saai_linked_products` | faq / kb / glossary（**有料版**） | 紐づけ商品ID（1商品 = 1メタ行で複数保存 → `meta_query` を高速に） |
| `saai_linked_product_cats` | 同上（**有料版**） | 紐づけ商品カテゴリー term_id（同上） |

有料版の紐づけをコンテンツ側メタに持たせる理由: 「この商品に紐づく FAQ」は `WP_Query` の meta_query 一発で取得でき、商品側のデータ（HPOS等）に依存しない。商品編集画面には逆引きUI（後述）を提供する。

### 3.4 オプション

`saai_knowledge_settings`（単一オプション、配列、autoload: yes / 小さく保つ）:
スラッグ設定（kb/faq/glossary ベース）、自動リンク対象 post type・1記事あたり最大リンク数・同一用語は初出のみ、KBハブページID、アーカイブ表示件数、構造化データ on/off、アンインストール時のデータ削除 on/off。

### 3.5 URL 設計（スラッグは設定で変更可）

```text
/kb/                        KBハブ（アーカイブ: 検索バー + カテゴリータイル）
/kb/{article-slug}/         KB記事（2カラム）
/knowledge-category/{term}/ カテゴリーアーカイブ（2カラム・記事一覧）
/faq/                       FAQアーカイブ（カテゴリー別アコーディオン）
/glossary/                  用語集索引（五十音 / A–Z タブ）
/glossary/{term-slug}/      用語個別ページ
```

CPT 登録変更時のみ `flush_rewrite_rules()`（有効化時 + スラッグ設定変更時に deferred flush）。

---

## 4. フロントエンド設計

### 4.1 KB 2カラムレイアウト（developer.woocommerce.com/docs 型）

```text
┌────────────┬──────────────────────────┬────────────┐
│ 左サイドバー   │  記事本文                    │ 右: ページ内目次 │
│ (カテゴリツリー) │  パンくず / タイトル / 本文      │ (見出しから生成) │
│ 検索ボックス   │                           │ スクロールスパイ │
└────────────┴──────────────────────────┴────────────┘
       ← モバイルでは左右ともドロワー / アコーディオンに折りたたみ
```

- **左サイドバー**: `saai_category` ツリー + 各タームの記事リンク。現在記事の祖先タームを自動展開。Interactivity API で開閉。
- **右目次**: サーバーサイドで `parse_blocks()` して `core/heading`（h2/h3）を抽出、無IDの見出しにはアンカーを付与してレンダリング。スクロールスパイで現在位置をハイライト。※TOCブロックはWPコアに存在しない（Gutenberg experimentalのまま）ため自前実装で確定。
- **ブロックテーマ**: `register_block_template( 'saai-knowledge//single-saai_kb', [...] )`（WP 6.7+ API・現行仕様確認済み）でプラグインからブロックテンプレートを登録。ユーザーはサイトエディターで上書き可能（DB保存で保持）。テーマが同名テンプレートを持つ場合はテーマ優先になる点に注意。
- **クラシックテーマ**: `template_include` でプラグイン同梱の PHP テンプレート（`do_blocks()` でブロックを描画）にフォールバック。テーマに `saai-knowledge/single-saai_kb.php` があればそちらを優先。

### 4.2 提供ブロック（無料版）

| ブロック | 内容 |
| --- | --- |
| `saai-knowledge/kb-sidebar` | カテゴリー×記事ツリー（dynamic / Interactivity API） |
| `saai-knowledge/kb-toc` | ページ内目次 + スクロールスパイ |
| `saai-knowledge/faq-list` | FAQアコーディオン。アコーディオンUIは**コア Accordion ブロック（WP 6.9で追加）のマークアップ/スタイルを内部利用**し自作しない。属性: カテゴリー・件数・並び順・カテゴリー別グルーピング（FAQアーカイブテンプレートが使用。各FAQは表示順で最初のカテゴリーに属し、未分類は末尾の「Other」グループへ）。`FAQPage` JSON-LD を自動出力（1リクエストにつき最初のブロックのみ。Google の「FAQPage は1ページ1つ」ガイドラインに従う） |
| `saai-knowledge/glossary-index` | 五十音 / A–Z 索引 |
| `saai-knowledge/search` | 横断ライブ検索ボックス（対象タイプを属性で選択） |
| `saai-knowledge/breadcrumbs` | KB用パンくず（`BreadcrumbList` JSON-LD） |

全ブロック dynamic（`render.php`）。クラシックテーマ利用者向けに同等のショートコード（`[saai_faq]`, `[saai_kb_sidebar]` 等）を薄いラッパーとして提供。

### 4.3 用語自動リンク／ツールチップ

- `the_content`（priority 遅め）で本文HTMLを走査し、用語（タイトル + `saai_synonyms`）の初出をツールチップ付きリンクに置換。
- **辞書はキャッシュ**: 公開中の用語一覧をオブジェクトキャッシュ／transient に保持し、用語の保存時に無効化。
- **除外**: 見出し・`a`・`code`/`pre`・ショートコード出力・自身の用語ページ。HTML 操作は `preg_split` ベースの軽量トークナイザー（タグ/テキスト分割 + 除外要素の深度スタック）で行い、タグを壊さない。`WP_HTML_Tag_Processor` はテキストノード置換 API を持たないため不採用（詳細: DESIGN-AUTOLINK.md §4）。
- ツールチップの中身は用語の excerpt（なければ本文冒頭）。ホバー／タップで Interactivity API 表示、リンク先は用語ページ。
- 設定で対象 post type（既定: post, page, saai_kb, saai_faq）・最大リンク数を制御。投稿単位で `saai_no_autolink` により無効化可。
- 辞書取得に `saai_autolink_dictionary` フィルターを用意 → **有料版がここに商品コンテキストを注入**する。

### 4.4 ライブ検索

- REST: `GET /saai-knowledge/v1/search?query=...&types=faq,kb,glossary&per_page=10`
- `permission_callback: __return_true`（公開コンテンツのみ、`post_status: publish` 固定）。入力は `sanitize_text_field`、出力はタイトル・タイプ・URL・抜粋のみの最小スキーマ。
- フロントは Interactivity API + debounce。結果はタイプ別にグルーピング表示。

### 4.5 構造化データ

- FAQ表示ブロック → `FAQPage` / 用語ページ → `DefinedTerm` / パンくず → `BreadcrumbList`。設定でオフ可能（SEOプラグインとの重複対策）。

---

## 5. 管理画面設計（無料版）

- **設定ページ**: 「設定 > SAAI Knowledge」ではなくトップレベルメニュー「SAAI Knowledge」配下に3CPT + 設定を集約。設定画面は Settings API（`register_setting` + sanitize_callback）。capability: `manage_options`。
- **記事の並び替え**: v1 は `page-attributes`（order 属性）+ 管理一覧の order カラム。v1.x でカテゴリー内ドラッグ&ドロップUIを検討。
- **エディター拡張**: 用語CPTに「読みがな」「別表記」パネル（`PluginDocumentSettingPanel` + `register_post_meta`）。
- capability は標準 `post` 相当をマッピング（`capability_type: post`）。カスタム capability は v1 では導入しない。

---

## 6. 有料版（SAAI Knowledge for WooCommerce）

### 6.1 紐づけ UI（双方向）

1. **コンテンツ側**（FAQ / KB / 用語の編集画面）: サイドバーパネル「Linked Products」で商品・商品カテゴリーを検索して複数選択。→ `saai_linked_products` / `saai_linked_product_cats` に保存。
2. **商品側**（商品編集画面）: メタボックス「SAAI Knowledge」で、この商品（＋所属カテゴリー）に紐づく FAQ/KB/用語を一覧表示・その場で追加/解除（実体はコンテンツ側メタを更新する逆引きUI）。カテゴリー経由の紐づけは「そのカテゴリーの全商品に効く」ため商品側では読み取り専用で表示し、経由カテゴリー名を添える。

「商品に対する表示対象」の解決ルール: `商品IDに直接紐づくもの ∪ 商品の所属カテゴリー（祖先含む）に紐づくもの`。重複排除・`menu_order` 順（同値は title 順）。祖先方向にのみ辿るので、親カテゴリーへの紐づけは子孫カテゴリーの商品まで自動的に覆う。実装は `Link_Resolver` 単一クラスで、WooCommerce の関数（`wc_get_product()` 等）を使わず core の `wp_get_object_terms()` / `get_ancestors()` / `WP_Query` だけで組む（PHPUnit が WooCommerce 不在のまま `product` / `product_cat` のスタンドインで本番と同じ経路を検証できる）。

#### 検索 API（2026-09-24 に wp-env 実機で確認して決定）

商品・商品カテゴリーの検索は **core REST の `/wp/v2/product` と `/wp/v2/product_cat`**（`@wordpress/core-data` の `getEntityRecords` 経由）を使う。WooCommerce が両者を `show_in_rest: true` で登録しているため追加エンドポイントは不要。**WooCommerce 自身の `/wc/v3/products` は使わない**: 読み取り権限が `read_private_products`（既定で administrator / shop_manager のみ）なので、`edit_posts` は持つが商品権限を持たない Editor では検索が 403 になり UI が壊れる。

#### 有料版の REST 名前空間

`saai-knowledge-woo/v1`（無料版の `saai-knowledge/v1` とは別）。商品側メタボックス専用で、コンテンツ側パネルはこれを使わない。

| ルート | 用途 | 認可 |
| --- | --- | --- |
| `GET /products/{id}/linked-content` | 逆引き一覧（`direct` / `inherited`。`inherited` は経由カテゴリーも返す） | 商品への `edit_post` |
| `POST /products/{id}/linked-content` | 直接紐づけを追加（body: `content_id`）。冪等（既存なら 200、新規なら 201） | 商品への `edit_post` かつコンテンツへの `edit_post` |
| `DELETE /products/{id}/linked-content/{content_id}` | 直接紐づけを解除 | 同上 |
| `GET /content-search` | FAQ/KB/用語のタイトル検索（下書き含む） | `edit_posts` |

書き込み系の本命の認可は**コンテンツ投稿への `edit_post`**（更新するメタ行はコンテンツ側に属するため）。商品側の一覧は `post_status: any` で下書き・非公開も拾い、`read_post` で閲覧可否を1件ずつ再確認する。

メタ値の規約: どちらのキーも1値1メタ行（`single: false`）で、値は正の整数。`absint` サニタイズを通すため 0 や負値は保存されないが、商品・タームが後から削除されて残った ID は「どの商品にも解決しない無害な値」として扱い、読み取り時に落とす（勝手に行を消さない）。UI 側も未解決の ID を黙って捨てず「見つかりません（#ID）」と表示する。

### 6.2 商品ページ表示（自動挿入 + ブロック提供の両輪）

前提（2026-09-24 に WooCommerce 11.1.2 のソースと wp-env 実機で再確認し、2026-07 時点の記述を訂正）:

- Woo 同梱の blockified テンプレート `templates/templates/blockified/single-product.html` の `wp:woocommerce/product-details` は**自己終了形＝innerBlocks 無し**。`ProductDetails::render()` は innerBlocks が空なら `render_legacy_block()` へ落ち、`woocommerce_output_product_data_tabs()` 経由で**クラシックのタブを描画する**。つまり**未カスタマイズのブロックテーマでもアコーディオンは出ない**。
- アコーディオンになるのは、マーチャントがサイトエディターで単一商品テンプレートを開いて保存し、`product-details` が innerBlocks へ展開された後だけ。
- 展開後のアコーディオンのブロック名は **WP 6.9 以上では `core/accordion`**。`woocommerce/accordion-group` は WP 6.8 以下向けのフォールバックで、6.9+ ではインサーターから外れ、エディターに非推奨バナーが出る（WC 10.5〜10.6 の変更）。本プラグインは WP 6.9+ 必須なので anchor は `core/accordion` 側になる。
- Product Details への項目追加には Woo 公式の専用フィルター **`woocommerce_product_details_hooked_blocks`**（@since WC 10.0）がある。`[ [ 'title' => ..., 'content' => ブロックマークアップ ], ... ]` を返すと、Woo 側が両 anchor 名の `last_child` へ Block Hooks を張り、item マークアップの差異も吸収する。**自前で `hooked_block_types` を書かない。**

| 機能 | クラシックテーマ | ブロックテーマ（blockified） |
| --- | --- | --- |
| FAQセクション | `woocommerce_product_tabs` フィルターで「FAQ」タブ追加 | **主経路は同じ `woocommerce_product_tabs`**（未カスタマイズなら legacy タブとして、保存済みテンプレートなら互換レイヤー `inject_compatible_tabs()` がアコーディオン item に変換して描画）。サイトエディターでの並べ替え・削除を可能にするなら `woocommerce_product_details_hooked_blocks` を併用 |
| 関連KBセクション | `woocommerce_after_single_product_summary` に「関連ドキュメント」リンク一覧 | 同左フック（`SingleProductTemplateCompatibility` が `product-details` の直後へマップ）+ 専用ブロック |
| 用語ツールチップ | 商品説明・詳細説明にも自動リンク適用（`saai_autolink_dictionary` に商品紐づけ用語を注入 + Woo コンテンツフィルター対応） | 同左 |

**未決（M5-3 / Issue #22 の着手時に実機検証して決める）**: 保存済みテンプレートでは互換レイヤーと hooked block の両方が生きるため、FAQ を両方へ登録すると二重表示になりうる（コード読解による推測。実機未検証）。(A) `woocommerce_product_tabs` のみで「クラシック / 未カスタマイズ blockified / 保存済み blockified」の3ケースを1実装で賄う、(B) 両方を排他制御付きで併用、のいずれかを選ぶ。

※ Product Details の hooked block は `content` が `init` 時に固定される静的マークアップなので、商品ごとに内容が変わるものは dynamic block を指定する。また空判定（`hide_empty_accordion_items()`）のため panel が1回余分にレンダーされるので、その dynamic block は副作用なしで複数回描画できる必要がある。

※ `@woocommerce/product-editor`（管理画面のブロック製品エディター）は WC 11.0 で削除済みのため**一切依存しない**（管理UIは従来のメタボックス/エディターサイドバーで実装）。

自動挿入は**設定でそれぞれ on/off 可能**。加えて手動配置用ブロックを提供:
`saai-knowledge/product-faq`, `saai-knowledge/product-docs`, `saai-knowledge/product-glossary`（コンテキストの商品IDを自動解決、属性で商品指定も可）+ 同等ショートコード。

### 6.3 Marketplace 要件

- ライセンス・更新: WooCommerce.com サブスクリプション機構に委譲（`woo:` ヘッダー等 Marketplace 指定の作法に従う）。
- HPOS 互換宣言、WooCommerce L-2 バージョンポリシー、QIT（Quality Insights Toolkit）テストのパス。
- 無料版の `saai_` フック群のみに依存し、無料版更新で壊れない互換ポリシー（無料版はフックの後方互換を semver で保証）。

---

## 7. AI可読性とデータエクスポート

FAQ を生成AIクローラーに発見されやすくし、サイト独自のカスタマーサポートAI（RAG）のデータ源として使いやすくするための設計。2026-07 追加。

### 7.1 基本原則

- **1 Q&A = 1 URL**: FAQ 個別ページは質問を `<h1>`、回答本文を直下に置き、1ページで回答が完結する構造にする。個別ページに `QAPage`、一覧に `FAQPage` の JSON-LD。
- **SSR 必須**: アコーディオン等の折りたたみ UI でも、中身は常に初期 HTML に完全に含める。JS（Interactivity API）の役割は開閉のみ。多くの AI クローラーは JS を実行しないため、これを M2/M3 の受け入れ条件とする。

### 7.2 llms.txt 戦略（3層 — v1 は第1層+第2層のみ）

ルート `/llms.txt` はサイトに1つの単一リソースであり、主要SEOプラグイン（Yoast / AIOSEO / Rank Math 等）が既に生成機能を持つため、**自前でルートを取り合わない**。

| 層 | 内容 | スコープ |
| --- | --- | --- |
| 第1層（主軸） | SEOプラグイン連携: 実装時に Yoast SEO・Rank Math のソースコードを直接確認したところ、いずれも「サードパーティが新しいセクションを注入できるフィルター」は持たない。掲載は各プラグイン自身の仕組みで決まる——Yoast は「indexable な post type」判定（`public: true` の CPT は既定で対象）、Rank Math/AIOSEO は各プラグインの llms.txt 設定画面で post type をチェックする方式——ため、無料版は3CPTを `public: true` で登録している以上、追加コード無しでどのプラグインでも「対象に選べる」状態になる。無料版が実装するのは、選ばれた際の**説明文の穴埋め**のみ: Yoast の `wpseo_llmstxt_link_description`、Rank Math の `rank_math/llms_txt/post_description`、AIOSEO の `aioseo_llms_post_description` を（対象プラグインが実際に有効な場合のみ）フックし、プラグイン側が空文字を渡してきた時だけ自プラグインの抜粋で埋める（`Llms_Txt_Adapter`） | v1 |
| 第2層 | 自名前空間の Markdown インデックス: 衝突しない自プラグイン配下の URL（例: `/{kb-base}/llms.txt`）に FAQ/KB/用語集の全項目一覧を Markdown で常時提供。サイトオーナーや他プラグインの llms.txt からリンクしてもらう受け皿（`Llms_Index`） | v1 |
| 第3層 | ルート `/llms.txt` の自前生成: デフォルトOFF + 物理ファイル/既知プラグインの競合検知 + Site Health チェック | **バックログ**（v1 では実装しない） |

設定画面の「AI Readability」セクションにある単一トグル（既定 ON）が、Markdown 出力・第1層・第2層の3つ全てをまとめて制御する。

### 7.3 Markdown 出力

- FAQ / KB / 用語の個別ページを `?format=markdown` でクリーンな Markdown として取得可能にする（テーマのマークアップを含まない、タイトル + 本文 + メタ情報のみ。キャッシュあり）。
- 第2層インデックスの各項目からこの Markdown 表現へリンクする。
- 加工用フィルター: `saai_markdown_output`（DESIGN-HOOKS-API.md §3.5）。

### 7.4 RAG エクスポート

- REST: `GET /saai-knowledge/v1/export?types=faq,kb,glossary&format=jsonl|json&modified_after={ISO8601}&page=N`
  - 1レコード: `{ id, type, title, content_markdown, content_plain, categories, tags, url, updated_at }`（公開コンテンツのみ）
  - `modified_after` で増分同期に対応（サポートAI側の再取り込みを差分だけにできる）
  - KB 記事は h2 単位のセクション配列 `sections: [ { heading, anchor, content_markdown } ]` を併せて出力（チャンク化しやすく）
  - JSONL は1行1レコード（埋め込みパイプラインの標準形式）
- 管理画面からも同内容を JSONL / CSV でダウンロード可能にする（非エンジニア向け）。
- レコード加工用フィルター: `saai_export_record` — **有料版がここで商品ID / SKU / 商品カテゴリーを付与**し、「商品を認識するサポートAI」（この商品に紐づくFAQだけを検索対象にする等）の構築を可能にする。

### 7.5 バックログ（v1 スコープ外）

- 第3層: ルート `/llms.txt` 自前生成（競合検知 + Site Health チェック付き）
- WordPress Abilities API + MCP アダプター対応（FAQ検索・取得を ability 登録し、AIエージェントがサイトへ直接照会できる形）

---

## 8. コード構造

### 8.1 無料版

```text
plugins/saai-knowledge/
├── saai-knowledge.php            # ブートストラップ（ヘッダー + 定数 + オートローダ + Plugin::boot()）
├── uninstall.php                 # 設定で有効時のみデータ削除
├── includes/                     # namespace SAAI\Knowledge\（PSR-4, WPCS準拠のファイル名）
│   ├── class-plugin.php          # サービス初期化・フック登録の起点
│   ├── PostTypes/                # CPT・タクソノミー・メタ登録
│   ├── Blocks/                   # ブロック登録（block.json ベース）
│   ├── Frontend/                 # テンプレートローダー / 自動リンク / 構造化データ
│   ├── Rest/                     # 検索エンドポイント
│   ├── Admin/                    # 設定画面・管理カラム
│   └── Compat/                   # クラシックテーマ用テンプレート等
├── src/                          # JSソース（blocks/*, view.js は Interactivity API store）
├── build/                        # wp-scripts ビルド成果物
├── templates/                    # ブロックテンプレート(.html) + クラシック用(.php)
└── languages/                    # 翻訳の作業用（.po が正）。無料版の配布ZIPには同梱しない
```

- ファイルロード時の副作用禁止。すべて `plugins_loaded` / `init` 以降のフックで登録。
- 管理専用コードは `is_admin()` 配下でのみロード。
- 有料版向け公開API: `saai_register_content_location`（表示位置追加）、`saai_autolink_dictionary`、`saai_kb_sidebar_items`、`saai_search_results` 等のフィルターを最初から設計に含める。

### 8.2 品質・CI

- **PHPCS**: WordPress-Extra + WordPress-Docs（`.phpcs.xml.dist`、prefix/text-domain チェック有効）
- **PHPStan**: level 6〜 + szepeviktor/phpstan-wordpress（有料版は WooCommerce stubs）
- **PHPUnit**: WP_UnitTestCase（CPT登録・自動リンク・検索REST・紐づけ解決ロジック）
- **Playwright E2E**: wp-env 上で KB レイアウト表示・アコーディオン・ライブ検索・（有料版）商品タブ
- **GitHub Actions**: lint / test マトリクス（PHP 8.2–8.4 × WP 6.9–latest）、タグ push で ZIP 生成、無料版は WP.org SVN デプロイ

### 8.3 i18n

- Text Domain = 各プラグインスラッグ。`wp i18n make-pot` / `make-json`（ブロックJS用）の手順は `bin/i18n-build.sh` にまとめる。
- **無料版（WordPress.org 配布）**: `load_plugin_textdomain()` は呼ばない（WP 4.6 以降不要で、審査の指摘対象）。翻訳は translate.wordpress.org が生成する言語パック（`WP_LANG_DIR/plugins/`）から自動ロードされる。`languages/` は配布ZIPに同梱しない（`package.json` の `files` から除外し、`ci-js.yml` で混入を検知）。リポジトリの `languages/saai-knowledge-ja.po` は GlotPress へインポートする元データとして維持する。
- **有料版（WooCommerce.com 配布）**: WordPress.org の言語パック配信対象外なので、`load_plugin_textdomain()` による自前ロードを維持する（呼び出しは実装済み。`languages/` 自体は未作成で、翻訳を用意する M5 以降に同梱する）。

---

## 9. マイルストーン

| フェーズ | 内容 |
| --- | --- |
| **M1: 基盤** | モノレポ雛形・wp-env・CI、CPT/タクソノミー/メタ登録、テンプレートローダー（両テーマ対応の骨格） |
| **M2: KB コア** | 2カラムレイアウト（sidebar / toc ブロック + Interactivity API）、パンくず、KBハブ |
| **M3: FAQ + 用語集** | FAQアコーディオン + FAQPage schema、用語索引・自動リンク・ツールチップ |
| **M4: 検索 + 仕上げ** | ライブ検索（REST + UI）、設定画面、uninstall、i18n、readme.txt → **WordPress.org 申請** |
| **M5: 有料版** | 紐づけメタ + 双方向UI、商品ページ表示3種 + ブロック、HPOS/QIT 対応 → **WooCommerce.com 申請** |

## 10. 主なリスクと対策

- **自動リンクのパフォーマンス**: 用語数が多いサイトで `the_content` 処理が重くなる → 辞書キャッシュ + 対象 post type 限定 + プロファイリングを M3 の完了条件に含める。
- **テーマ互換（2カラム）**: テーマのコンテンツ幅制約と衝突しやすい → テンプレート上書き手段（サイトエディター / テーマ内 PHP）を必ず残し、CSS はコンテナクエリーベースで自己完結させる。
- **WP.org スラッグ審査**: `saai-knowledge` の商標・既存プラグイン重複を申請前に確認。
- **Woo ブロックテンプレートの過渡期**: 商品ページの blockified 化状況に応じてフック挿入とブロック配置の両対応を維持（本設計は両輪前提なので吸収可能）。
