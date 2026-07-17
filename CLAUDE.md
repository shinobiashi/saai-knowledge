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
- PHPUnitテストクラス（`WP_UnitTestCase` 継承）は非名前空間の `Test_*` 慣習に従う。WPCSの `PrefixAllGlobals` sniff は既知のユニットテスト基底クラスを継承したクラスを prefix 規約の対象外にするため、`saai_`/`SAAI\Knowledge` prefix は不要。
- ファイルdocblock直後の文が裸の `require`/`include` だと、PHPCSの `Squiz.Commenting.FileComment.Missing` が誤検知することがある（bootstrap系ファイルで発生実績あり）。docblock直後には代入文などを挟み、requireは後に置く。
- `composer.json` の `config.platform.php` は必ずプロジェクトの最小PHP要件（8.2.0）に固定する。外すと `composer.lock` が開発機のPHPバージョンに引きずられ、8.2/8.3環境で `composer install` が壊れうる（doctrine/instantiatorで実際に発生）。

## Git 運用（重要）

- ブランチ名は `issue-<番号>-<内容>` 形式（例: `issue-3-bootstrap`）。Issue 単位の作業に対応させる。
- **`git commit` / `git push` は実行しない。コミットは必ずユーザーが手動で行う。**
  - コミットメッセージの作成・提案、`git status` / `git diff` / `git log` 等の参照系、変更内容の整理は行ってよい。
  - コミット準備が整ったら「このメッセージでコミットしてください」とメッセージ案を提示して止まる。
- **PR の作成（`gh pr create` 等）も明示的な指示がない限り実行しない。** PR タイトル・本文の下書き作成は行ってよい。
- コミットメッセージは英語（グローバルルールどおり）。

## コマンド

```sh
npm run start / npm run build   # wp-scripts（workspaces で各プラグイン。M2でブロック追加まではno-op）
npx wp-env start                # ローカル環境（無料版 + WooCommerce。有料版は M5 でマウント追加）
composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit（ローカルは wp-env の tests-cli コンテナ、CI は bin/install-wp-tests.sh + WP_TESTS_DIR でホスト直実行）
```

ローカルでの `composer test` 実行例（`.wp-env.json` の `mappings.saai-monorepo` によりリポジトリルートはコンテナの `wp-content/` 配下ではなく `saai-monorepo/` 直下にマウントされる）:

```sh
npx wp-env run tests-cli --env-cwd=saai-monorepo bash -c "composer test"
```

`wp-env run` はスペース区切りの複数語コマンドを直接渡すと失敗するため `bash -c "..."` で包む。`composer analyze` がメモリ不足で落ちる場合は `composer exec phpstan analyse -- --memory-limit=512M` を使う。

Playwright E2E（`npm run test:e2e`）は M2 でセットアップ予定、現時点では未整備。

## CI（GitHub Actions）

- `ci-php.yml`（PHPCS / PHPStan / PHPUnit、PHP 8.2–8.4 × WP 6.9–latest）、`ci-js.yml`、`release.yml`（`v*` タグ push で無料版 ZIP を GitHub Release に添付）。
- `bin/install-wp-tests.sh` は wp-cli scaffold の移植。改変時は必ず canonical と突き合わせる（ABSPATH sed の末尾スラッシュ欠落で全マトリクスが落ちた実績）。ubuntu-latest ランナーに svn は無い（ワークフロー側で apt install 済み）。

## Markdown 規約（docs/）

- テーブル区切り行は `| --- |` 形式（スペースあり）。
- フェンスコードブロックは言語指定必須（図やツリーは `text`）。

## リリース

- 無料版: readme.txt の Stable tag 更新 → WordPress.org SVN（GitHub Actions からデプロイ）。
- 有料版: WooCommerce.com Marketplace（ライセンス・更新配信は Marketplace 任せ。自前ライセンス実装は行わない）。
