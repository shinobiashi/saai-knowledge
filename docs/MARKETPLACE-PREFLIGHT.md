# Marketplace 申請前チェック（有料版）

有料版（`saai-knowledge-for-woocommerce`）を WooCommerce.com に申請する前に、ローカルで済ませられる確認の手順と結果をまとめる。申請そのものと QIT マネージドテストは Issue #24。

QIT のマネージドテスト（Activation / Security / Validation / Woo E2E / Woo API など）を動かすには、「掲載済みの拡張を 1 つ以上持つ WooCommerce.com Partner アカウント」が要る（[QIT FAQ](https://qit.woo.com/docs/support/faqs/)）。それまでは次の 3 つで代える。

- 配布 ZIP の検証（`bin/verify-woo-zip.sh`。CI の build ジョブでも流れる）
- 配布 ZIP への Plugin Check
- QIT のローカル環境（`qit env:up`）での互換マトリクス（`bin/qit-matrix.sh`）

## 手順

```sh
npm run build
(cd plugins/saai-knowledge-for-woocommerce && npx wp-scripts plugin-zip)

# 1. 配布 ZIP: 中身（readme.txt / changelog.txt / languages/ があり、src/ 等が無い）と
#    バージョン・WC 要件ヘッダーの一致
bash bin/verify-woo-zip.sh

# 2. Plugin Check（展開した ZIP に対して。dist/ は .gitignore 済み。前回の展開物は消してから入れ直す）
rm -rf dist/woo-check && mkdir -p dist/woo-check
unzip -oq plugins/saai-knowledge-for-woocommerce/saai-knowledge-for-woocommerce.zip -d dist/woo-check
npx wp-env run cli bash -c "wp plugin check /var/www/html/saai-monorepo/dist/woo-check/saai-knowledge-for-woocommerce \
  --slug=saai-knowledge-for-woocommerce \
  --categories=general,plugin_repo,security,performance,accessibility --include-experimental"

# 3. PHPCompatibility（composer lint にも含まれる。有料版だけを明示的に見る場合）
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --extensions=php \
  plugins/saai-knowledge-for-woocommerce/includes plugins/saai-knowledge-for-woocommerce/src \
  plugins/saai-knowledge-for-woocommerce/saai-knowledge-for-woocommerce.php

# 4. 互換マトリクス（qit CLI と Docker が必要。既定は WP 7.0 / 7.1 × WC 11.0 / 11.1 / 11.2 の 6 通り）
bash bin/qit-matrix.sh
bash bin/qit-matrix.sh 7.1.3:11.3.0   # 組み合わせを指定する場合（WP:WC）
```

`bin/qit-matrix.sh` は組み合わせごとに次を行う。

1. 素の WordPress + WooCommerce の環境を作る（`--online`）。有料版は配布 ZIP から入れ、無料版は QIT が `Requires Plugins` を解決して WordPress.org から入れる
2. 有料版の Playwright E2E を、その環境に向けて流す
3. 管理者で巡回する。ダッシュボード・プラグイン・WooCommerce 設定・商品の一覧・新規・編集・FAQ の一覧・新規・SAAI 設定・ショップ・FAQ を紐づけた商品・カート・チェックアウトを開く
4. ログアウトした訪問者として、商品ページに FAQ タブがあることを確認する
5. 有料版の無効化と再有効化を行う。さらに無料版を外し、有料版が fatal にならず通知を出すことを確認する
6. `wp-content/debug.log`（QIT の環境は WP_DEBUG 有効）が空であることを確認する

## 結果（2026-10-10、有料版 1.0.0）

環境は `qit env:up`（PHP 8.2）。無料版は 1.0.2（WordPress.org）。

- **`bin/verify-woo-zip.sh`**: OK（version 1.0.0、WooCommerce 11.0 to 11.2）
- **Plugin Check**（全カテゴリー + `--include-experimental`）: エラー 0。警告は `load_plugin_textdomain()` の 1 件だけ（下記の許容事項）
  - 対応前の ZIP では次の 2 件も出ていたが、今回の対応で解消した
  - `no_plugin_readme`（ERROR）
  - `plugin_header_nonexistent_domain_path`（WARNING）
- **PHPCompatibility**（`PHPCompatibilityWP`、testVersion 8.2-）: エラー・警告とも 0（有料版の PHP 20 ファイル）
- **`bin/qit-matrix.sh`**: 6 通りすべて pass

| WordPress | WooCommerce | 有効化 | E2E（有料版） | 巡回 | 訪問者に FAQ タブ | 無料版を外した時 | debug.log |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 7.1.3 | 11.2.1 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |
| 7.1.3 | 11.1.2 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |
| 7.1.3 | 11.0.1 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |
| 7.0.7 | 11.2.1 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |
| 7.0.7 | 11.1.2 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |
| 7.0.7 | 11.0.1 | 失敗なし | 11 passed | すべて 200（checkout は 302） | あり | 通知のみ | 空 |

E2E の 11 件は、`product-blocks` 5 件・`product-page-block-theme` 4 件・`product-page-classic-theme` 2 件。

- `linked-products-panel.spec.js`（Issue #71、PR #73）は、このブランチの時点では main に入っていないので含まない
- checkout の 302 は、空のカートをカートへ戻す WooCommerce の通常の動作

## 既知の許容事項

- **Plugin Check の `load_plugin_textdomain()` 警告**（`PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound`）
  - WordPress.org の言語パックを前提にした指摘
  - WooCommerce.com の製品は言語パックの対象外なので、呼び出しが要る（DESIGN.md §8.3）
  - Plugin Check はマーケットプレイス提出では自動実行されず、通過も必須ではない

## 分かったこと（申請時に関係するもの）

- **QIT は `Requires Plugins` を解決する**
  - 有料版の ZIP だけで環境を作っても、無料版 SAAI Knowledge を WordPress.org から入れて有効化する（`dependencies_mode: activate`、有効化の失敗なし）
  - マネージドテストでも、無料版を手で足す必要はない見込み
- **オフライン（QIT の既定）だと、プラグイン画面を開くたびに core が PHP Warning を出す**
  - `plugins_api()` が依存プラグイン（`woocommerce` / `saai-knowledge`）の情報を WordPress.org から取れないため。`--online` では出ない
  - 有料版のコードではなく `Requires Plugins` ヘッダーが引き金なので、`Requires Plugins: woocommerce` を宣言する拡張なら同じことが起きる
  - マネージドの Activation テストがこれを失敗扱いするかは未確認
- **新しいストアは WooCommerce の「coming soon」モードになっていることがある**。ログアウトした訪問者にはプレースホルダーのページが出る（E2E はログインした状態で巡回するので気づかない）
- **QIT の環境のテーマは Storefront だけ**
  - `--theme=storefront` を付けると、入れ直しに失敗して環境が作れない
  - ブロックテーマの spec 用に Twenty Twenty-Five を `wp theme install` で足す

## 申請時（#24）に確認すること

- **PR #73（Issue #71）がマージ済みであること**
  - readme.txt の「an Editor does not need WooCommerce's product permissions」は、Linked Products パネルが `context: 'view'` で商品を引く #73 の修正が前提
  - マージ後は `bin/qit-matrix.sh` の E2E に `linked-products-panel.spec.js`（Editor ロールでパネルを操作する）が自動で加わる。その件数が 11 件より増えていることを確かめる
- QIT の PHPCompatibility テストの `--min_php_version=auto` が `Requires PHP: 8.2` を下限に使うか。7.4 から検査されると、PHP 8 の構文で失敗しうる
- PHP 要件 8.2 が受け入れられるか（提出要件の読み方。DESIGN.md §6.3）
- オフラインの Activation テストで、上記の `plugins_api()` の Warning が問題にならないか
- バージョンまわり
  - `changelog.txt` の日付を提出日にする
  - `WC tested up to` をその時点の最新にする
  - `WC requires at least` / `SAAI_WOO_MIN_WC_VERSION` を L-2 で計算し直す
  - `bin/verify-woo-zip.sh` と `bin/qit-matrix.sh` を流し直す
