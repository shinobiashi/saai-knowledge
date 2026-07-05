# SAAI Knowledge — プロジェクト設定

FAQ / Knowledge Base / 用語集を提供する WordPress プラグインのモノレポ。

- 無料版 `plugins/saai-knowledge/` → WordPress.org 配布
- 有料版 `plugins/saai-knowledge-for-woocommerce/` → WooCommerce.com Marketplace 販売（WooCommerce 商品・商品カテゴリー連携）

## 必読ドキュメント

- `docs/DESIGN.md` — 基礎設計（データモデル・URL・ブロック・有料版連携）。実装はこれに従う。設計変更時は必ず更新する。
- `docs/DEVELOPMENT-PLAN.md` — フェーズ別開発計画。タスク完了時にチェックを付けて進捗を反映する。

## 動作要件

| 項目 | 要件 |
| --- | --- |
| WordPress | 6.9+ |
| PHP | 8.2+ |
| WooCommerce（有料版のみ） | L-2 ポリシー（最新 + 直近2メジャー） |

## コーディング規約

- WordPress Coding Standards（WordPress-Extra + WordPress-Docs）。`composer lint` / `composer lint:fix` を使う。
- プレフィックスは `saai_`（フック・オプション・メタ・CPT・タクソノミー）、ブロックは `saai-knowledge/*`。
- PHP namespace: 無料版 `SAAI\Knowledge\`、有料版 `SAAI\KnowledgeWoo\`。
- Text domain: 各プラグインのスラッグと同一（`saai-knowledge` / `saai-knowledge-for-woocommerce`）。翻訳関数の text domain はリテラル文字列で書く。
- フロント JS は Interactivity API（`@wordpress/interactivity`）で統一。ブロックは全て dynamic（block.json + render.php）。
- ファイルロード時の副作用禁止。フック登録は `plugins_loaded` / `init` 以降。管理専用コードは `is_admin()` 配下でのみロード。
- セキュリティ: sanitize on input / escape on output、nonce + capability 両方のチェック、SQL は `$wpdb->prepare()`。
- 有料版は無料版の公開フック（`saai_*` filters/actions）のみに依存する。無料版の内部クラスを直接呼ばない。

## コマンド（M1 でセットアップ後に有効）

```sh
npm run start / npm run build   # wp-scripts（workspaces で各プラグイン）
npx wp-env start                # ローカル環境（両プラグイン + WooCommerce）
composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit
npm run test:e2e                # Playwright
```

## Markdown 規約（docs/）

- テーブル区切り行は `| --- |` 形式（スペースあり）。
- フェンスコードブロックは言語指定必須（図やツリーは `text`）。

## リリース

- 無料版: readme.txt の Stable tag 更新 → WordPress.org SVN（GitHub Actions からデプロイ）。
- 有料版: WooCommerce.com Marketplace（ライセンス・更新配信は Marketplace 任せ。自前ライセンス実装は行わない）。
