# SAAI Knowledge

[English README is here](README.md)

FAQ・Knowledge Base・用語集（Glossary）を WordPress サイトに追加する WordPress プラグインのモノレポです。読者と AI クローラーの両方が期待する構造・ナビゲーション・構造化データを備えています。

- **[SAAI Knowledge](plugins/saai-knowledge/)** — 無料版。[WordPress.org](https://wordpress.org/plugins/saai-knowledge/) で配布。
- **SAAI Knowledge for WooCommerce** — 有料版アドオン。FAQ/KB/用語集コンテンツを WooCommerce の商品・商品カテゴリーに紐づけます。WooCommerce.com Marketplace での販売を予定していますが、まだ公開されていません。無料版単体でも利用可能で、有料版は必須ではありません。

## 主な機能（無料版）

- **Knowledge Base** — 2カラムレイアウト（カテゴリーサイドバー + 記事）、祖先タームの自動展開ナビゲーション、見出しから自動生成されるスクロールスパイ付き目次、`BreadcrumbList` 構造化データ。
- **FAQ** — コアの Accordion ブロックを利用したアコーディオン表示。各質問は個別ページを持ち `QAPage` 構造化データを、アーカイブは `FAQPage` 構造化データを出力。回答は常に初期HTMLに含まれるサーバーサイドレンダリング。
- **用語集（Glossary）** — 五十音 / A–Z 索引ブロック、サイト全体を横断する用語自動リンク（ホバー／タップでツールチッププレビュー）、`DefinedTerm` 構造化データ。
- **ライブ検索** — FAQ・Knowledge Base・用語集を横断検索し、入力に応じてタイプ別にグループ化して表示する単一ブロック。
- **AI / RAG パイプライン対応** — 全ページの Markdown 出力（`?format=markdown`）、`llms.txt` インデックス、SEOプラグイン（Yoast SEO / Rank Math / All in One SEO）の説明文自動補完、REST エクスポートエンドポイント（JSONL/JSON、増分同期対応）と管理画面からの全件ダウンロード。
- **拡張性** — 自動的な挙動はすべて設定画面から制御可能。他プラグイン・テーマ向けに `saai_*` actions/filters を公開。
- ブロックテーマ・クラシックテーマの両方に対応。外部サービスへのデータ送信は一切ありません。

## 動作要件

| 項目 | 要件 |
| --- | --- |
| WordPress | 6.9+ |
| PHP | 8.2+ |
| WooCommerce（有料版のみ） | 最新版 + 直近2メジャーバージョン（L-2 ポリシー） |

## リポジトリ構成

```text
saai-knowledge/
├── docs/                                 設計・計画ドキュメント
├── plugins/
│   ├── saai-knowledge/                   無料版（WordPress.org）
│   └── saai-knowledge-for-woocommerce/   有料版アドオン（WooCommerce.com）
├── .wp-env.json                          ローカル開発環境（wp-env）
├── composer.json                         PHPCS / PHPStan / PHPUnit（共有）
├── package.json                          @wordpress/scripts（npm workspaces）
└── .github/workflows/                    CI（lint / test / build / deploy）
```

配布時は各プラグインディレクトリを個別に ZIP 化します。WordPress.org の SVN リポジトリへデプロイされるのは無料版のみです。

## ドキュメント

- [`docs/DESIGN.md`](docs/DESIGN.md) — 基礎設計書（データモデル・URL・ブロック・有料版連携）。
- [`docs/DESIGN-AUTOLINK.md`](docs/DESIGN-AUTOLINK.md) — 用語自動リンクエンジンの詳細設計。
- [`docs/DESIGN-HOOKS-API.md`](docs/DESIGN-HOOKS-API.md) — 公開フック（`saai_*` actions/filters）の契約。
- [`docs/DEVELOPMENT-PLAN.md`](docs/DEVELOPMENT-PLAN.md) — フェーズ別開発計画と進捗。

## 開発

```sh
npx wp-env start                # ローカル環境を起動（無料版 + WooCommerce）
npm run start / npm run build   # @wordpress/scripts でビルド（各ワークスペース）

composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit
composer verify                 # lint + analyze + test を一括実行
```

コーディング規約・プロジェクト固有の詳細は [`CLAUDE.md`](CLAUDE.md) を参照してください。

## ライセンス

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
