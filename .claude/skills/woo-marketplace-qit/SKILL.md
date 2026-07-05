---
name: woo-marketplace-qit
description: >
  WooCommerce.com Marketplace向けプラグインの品質テストスキル。QIT（Quality Insights Toolkit）の全テストスイート
  （Activation, Security, PHPStan, PHP Compatibility, Malware, Plugin Check, Validation, Woo E2E, Woo API）への
  対応方法、qit.json設定、ローカル環境（qit env:up）、GitHub Actions CI統合、カスタムテストパッケージ作成、
  PHPCS/ESLintによるコーディングスタンダード準拠を網羅する。
  「QIT」「マーケットプレイステスト」「品質テスト」「セキュリティスキャン」「PHPStan」「Woo E2E」「プラグインテスト」
  「プレサブミッション」「提出前チェック」「PHPCS」「コーディングスタンダード」といったキーワードが出た場合に使用する。
  WooCommerceプラグインのCI/CDやテスト戦略の設計時にも積極的に参照すること。
---

# WooCommerce Marketplace QIT & Quality Assurance

QIT（Quality Insights Toolkit）はWooCommerceが開発したテストプラットフォームで、
マーケットプレイスへの提出と継続的なバージョンアップの両方で品質ゲートとして機能する。
全テストに通過しないと提出が先に進まないため、開発初期から組み込むことが重要。

---

## QIT テストスイート一覧（2026年7月時点）

マーケットプレイスで要求されるマネージドテスト:

| テスト | コマンド | 内容 | 必須 |
|--------|---------|------|------|
| **Activation** | `run:activation` | クリーンなWP+WC環境でPHP notice/warning/errorなくactivate→基本操作→deactivateできるか | ✅ |
| **Security** | `run:security` | 5種のスキャンツール（PHPCS WordPressセキュリティルール、Semgrep等）によるセキュリティスキャン | ✅ |
| **PHPStan** | `run:phpstan` | 静的解析（レベルは `--phpstan_level` で0–9指定、デフォルト2） | ✅ |
| **PHP Compatibility** | `run:phpcompatibility` | PHPCompatibilityルールセットでPHPバージョン互換を静的解析（マーケットプレイス基準はPHP 7.4+） | ✅ |
| **Malware** | `run:malware` | 難読化コード（eval, system等）・不可視文字などマルウェアパターンの検出 | ✅ |
| **Plugin Check** | `run:plugin-check` | WordPress.org Plugin Checkツール（plugin_repoカテゴリ）による検証 | ✅ |
| **Validation** | `run:validation` | メタデータ検証（readme.txt、HPOS/Blocks互換宣言などWooCommerce feature declarations） | ✅ |
| **Woo E2E** | `run:woo-e2e` | WooCommerce CoreのE2Eテスト（Playwright）を拡張有効状態で実行 | ✅ |
| **Woo API** | `run:woo-api` | WooCommerce CoreのREST APIテスト（商品/顧客/注文CRUD）を拡張有効状態で実行 | ✅ |
| **カスタムE2E** | `run:e2e` | 開発者が作成するテストパッケージ（Playwright）を実行 | 推奨 |

⚠️ **コマンド名の変更に注意**: 旧 `run:e2e` / `run:api`（Core テスト）は現在
`run:woo-e2e` / `run:woo-api` に改名され、`run:e2e` は**カスタムテストパッケージ実行用**になった。

Activation / Security / Malware テストに通過しないとバージョンアップのデプロイもブロックされる。

---

## QIT CLI セットアップ

### インストール

```bash
# グローバルインストール（公式推奨。qit-cli 自体の実行要件が PHP 7.2.5+。
# マーケットプレイスのプラグイン側要件（PHP 7.4+）とは別物）
composer global require "woocommerce/qit-cli:*"
qit --version

# またはプロジェクトローカル（CI向け）
composer require --dev woocommerce/qit-cli
./vendor/bin/qit --version
```

> **注**: 以降のコマンド例はグローバルインストール前提で `qit ...` と表記する。
> プロジェクトローカルにインストールした場合は `qit` を `./vendor/bin/qit` に
> 読み替えること（GitHub Actions のセクションはローカルインストール前提のため
> `./vendor/bin/qit` で表記している）。

### 認証

WooCommerce.com Partner アカウント（マーケットプレイスに拡張が1つ以上あること）で認証する:

```bash
qit connect
# ブラウザが開き、WooCommerce.comでの認証フローが始まる

# 認証確認: 自分のマーケットプレイス拡張一覧が表示される
qit extensions
```

### 基本的なテスト実行

スラッグのみ指定するとマーケットプレイス上の公開版、`--zip` 指定でローカルビルドをテストする:

```bash
# Activation テスト
qit run:activation my-extension --zip=./my-extension.zip

# Security テスト
qit run:security my-extension --zip=./my-extension.zip

# PHPStan テスト（レベル指定可、デフォルト2）
qit run:phpstan my-extension --zip=./my-extension.zip --phpstan_level=5

# PHP Compatibility テスト
qit run:phpcompatibility my-extension --zip=./my-extension.zip

# Malware テスト
qit run:malware my-extension --zip=./my-extension.zip

# Plugin Check テスト（WordPress.org Plugin Check）
qit run:plugin-check my-extension --zip=./my-extension.zip

# Validation テスト（メタデータ・互換宣言の検証）
qit run:validation my-extension --zip=./my-extension.zip

# Woo E2E テスト（WooCommerce CoreのE2Eをプラグイン有効状態で実行）
qit run:woo-e2e my-extension --zip=./my-extension.zip

# Woo API テスト
qit run:woo-api my-extension --zip=./my-extension.zip
```

### 環境カスタマイズ

```bash
# PHP / WordPress / WooCommerce バージョンを指定
# （Activation / Woo E2E / Woo API がバージョン指定をサポート）
qit run:woo-e2e my-extension \
  --zip=./my-extension.zip \
  --php_version=8.4 \
  --wordpress_version=7.0 \
  --woocommerce_version=10.9

# 他の拡張を同時に有効化してテスト（互換テスト）
qit run:activation my-extension \
  --zip=./my-extension.zip \
  --with-extension=woocommerce-subscriptions \
  --with-extension=woocommerce-payments
```

### qit.json による設定の永続化

CLIフラグの再入力を避けるため、`qit.json` に SUT（テスト対象）・プロファイル・
グループを定義できる:

- **SUT**: テスト対象のソース（local / URL / wccom / wporg）とビルドコマンド
- **profiles**: テストタイプごとの設定（バージョン、テストパッケージ、オプション）を名前付き保存
- **groups**: 複数のテストプロファイルを束ね `qit run:group` で一括実行
- **extension sets**: `woocommerce-extensions` などの定義済みプラグインセットで互換テスト

詳細: https://qit.woo.com/docs/configuration/

---

## ローカルテスト環境

Dockerベースの使い捨てWP+WC環境をローカルに起動できる:

```bash
# 環境の起動（PHP/WP/WCバージョン指定可）
qit env:up

# 環境の破棄
qit env:down
```

テストパッケージ開発時のデバッグや手動確認に有用。Webhook/決済ゲートウェイの
テストにはトンネル機能（Cloudflare Tunnel）、PHPデバッグにはXdebug統合が使える。

---

## AI 連携（Claude Code）

QIT は AI コーディングエージェントでの利用を公式サポートしている:

```
# Claude Code プラグインのインストール
/plugin marketplace add woocommerce/qit-cli
/plugin install qit@woocommerce-qit
```

- ドキュメントは llms.txt 標準に対応: **https://qit.woo.com/docs/llms.txt**
  （QITの詳細情報が必要になったら、まずこのインデックスを取得して該当ページを参照すること）
- テストパッケージ作成のAI向け方法論: https://qit.woo.com/docs/ai/test-packages/writing-with-agents/
- Playwright MCP（ブラウザ観察）・Xdebug MCP（PHPデバッグ）との併用が推奨されている

---

## カスタム E2E テスト（テストパッケージ）

QIT のカスタムテストは「テストパッケージ」という標準化されたフォーマットで作成する。
`qit-test.json` マニフェスト + Playwright テストで構成され、公開すれば
クロスプラグイン互換テストにも参加できる。

### スキャフォールドと実行

```bash
# テストパッケージの雛形を生成（qit-test.json + Playwright設定一式）
qit package:scaffold --package-type=test

# ローカルのテストパッケージを実行
qit run:e2e my-extension --zip=./my-extension.zip ./tests/qit-e2e

# QITレジストリに公開（他ベンダーとの互換テストで利用可能に）
qit package:publish
```

### テストの構成

```
tests/qit-e2e/
├── qit-test.json           # マニフェスト（package名、package_type、requires等）
├── playwright.config.ts
├── example.spec.ts
└── utils/
    └── helpers.ts
```

### playwright.config.ts の基本設定

```typescript
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  timeout: 60000,
  retries: 1,
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8889',
    storageState: process.env.STORAGE_STATE || undefined,
  },
  projects: [
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
    },
  ],
});
```

### テスト例: 設定ページのテスト

```typescript
import { test, expect } from '@playwright/test';

test.describe( 'My Extension Settings', () => {
  test.beforeEach( async ( { page } ) => {
    // WP Admin にログイン
    await page.goto( '/wp-login.php' );
    await page.fill( '#user_login', 'admin' );
    await page.fill( '#user_pass', 'password' );
    await page.click( '#wp-submit' );
    await page.waitForURL( '**/wp-admin/**' );
  });

  test( 'settings page loads without errors', async ( { page } ) => {
    await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=my_extension' );
    await expect( page.locator( '.woocommerce' ) ).toBeVisible();
    // コンソールエラーがないことを確認
    const errors: string[] = [];
    page.on( 'console', msg => {
      if ( msg.type() === 'error' ) errors.push( msg.text() );
    });
    await page.waitForTimeout( 2000 );
    expect( errors ).toHaveLength( 0 );
  });

  test( 'can save settings', async ( { page } ) => {
    await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=my_extension' );
    await page.fill( '#my_extension_api_key', 'test-key-123' );
    await page.click( '.woocommerce-save-button' );
    await expect( page.locator( '.updated' ) ).toBeVisible();
    // 値が保持されているか確認
    const value = await page.inputValue( '#my_extension_api_key' );
    expect( value ).toBe( 'test-key-123' );
  });
});
```

### テスト例: チェックアウトフローのテスト

```typescript
test.describe( 'Checkout Integration', () => {
  test( 'extension feature works on block checkout', async ( { page } ) => {
    // 商品をカートに追加
    await page.goto( '/shop/' );
    await page.click( '.add_to_cart_button' );
    await page.waitForTimeout( 1000 );

    // ブロックチェックアウトに移動
    await page.goto( '/checkout/' );
    await expect( page.locator( '.wc-block-checkout' ) ).toBeVisible();

    // 拡張の要素が表示されているか
    await expect(
      page.locator( '[data-block-name="my-extension/checkout-field"]' )
    ).toBeVisible();
  });
});
```

### 注意点

- テストはデフォルトで**オフライン実行**される。外部APIが必要な場合は
  `qit-test.json` の `requires.network: true` を宣言する
- APIキー等のシークレットは `requires.secrets` で宣言し、CLI/環境変数から注入する
- Playwright の `--shard` オプションは `qit run:e2e` では非サポート
- 結果は CTRF（Common Test Results Format）JSON で出力される

---

## コーディングスタンダード設定

### PHPCS（PHP CodeSniffer）

`composer.json`:

```json
{
  "require-dev": {
    "squizlabs/php_codesniffer": "^3.7",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "scripts": {
    "phpcs": "phpcs",
    "phpcbf": "phpcbf"
  }
}
```

```xml
<!-- phpcs.xml -->
<?xml version="1.0"?>
<ruleset name="My Extension">
  <description>PHPCS ruleset for WooCommerce Marketplace extension</description>

  <file>.</file>

  <exclude-pattern>vendor/*</exclude-pattern>
  <exclude-pattern>node_modules/*</exclude-pattern>
  <exclude-pattern>build/*</exclude-pattern>
  <exclude-pattern>tests/*</exclude-pattern>

  <arg name="extensions" value="php"/>
  <arg name="colors"/>
  <arg value="sp"/>

  <!-- WordPress Coding Standards -->
  <rule ref="WordPress-Extra">
    <exclude name="WordPress.Files.FileName.InvalidClassFileName"/>
    <exclude name="WordPress.Files.FileName.NotHyphenatedLowercase"/>
  </rule>

  <!-- WooCommerce-specific -->
  <rule ref="WordPress.WP.I18n">
    <properties>
      <property name="text_domain" type="array">
        <element value="my-extension"/>
      </property>
    </properties>
  </rule>

  <!-- PHP Compatibility: testVersion は自プラグインの Requires PHP に合わせる。
       この例は Requires PHP: 8.0 のプラグインの場合。
       マーケットプレイスの最低要件（PHP 7.4+）をそのまま採用するなら "7.4-" にする -->
  <config name="testVersion" value="8.0-"/>
  <rule ref="PHPCompatibilityWP"/>

  <!-- Minimum WP version -->
  <config name="minimum_supported_wp_version" value="6.4"/>
</ruleset>
```

### PHPStan

```neon
# phpstan.neon
includes:
  - vendor/phpstan/phpstan/conf/bleedingEdge.neon

parameters:
  level: 5
  paths:
    - my-extension.php
    - includes/
  excludePaths:
    - vendor/
    - node_modules/
    - build/
  scanDirectories:
    - vendor/woocommerce/
  bootstrapFiles:
    - vendor/autoload.php
  ignoreErrors:
    # WooCommerce dynamic methods
    - '#Call to an undefined method WC_Order::#'
```

`composer.json` に追加:

```json
{
  "require-dev": {
    "phpstan/phpstan": "^2.0",
    "phpstan/extension-installer": "^1.4",
    "szepeviktor/phpstan-wordpress": "^2.0"
  },
  "scripts": {
    "phpstan": "phpstan analyse --memory-limit=512M"
  }
}
```

QIT の PHPStan テストはデフォルトレベル2で実行される（`--phpstan_level` で変更可）。
ローカルではレベル5以上を維持し、QIT側のデフォルトより厳しく保つのが安全。

### ESLint（JavaScript / TypeScript）

`.eslintrc.json`:

```json
{
  "extends": [
    "plugin:@woocommerce/eslint-plugin/recommended"
  ],
  "env": {
    "browser": true,
    "es2021": true
  },
  "parserOptions": {
    "ecmaVersion": "latest",
    "sourceType": "module",
    "ecmaFeatures": {
      "jsx": true
    }
  },
  "settings": {
    "react": {
      "version": "detect"
    }
  }
}
```

`package.json`:

```json
{
  "devDependencies": {
    "@woocommerce/eslint-plugin": "^3.0.0",
    "@wordpress/scripts": "^32.0.0"
  },
  "scripts": {
    "lint:js": "wp-scripts lint-js src/",
    "lint:css": "wp-scripts lint-style src/**/*.scss",
    "build": "wp-scripts build",
    "start": "wp-scripts start"
  }
}
```

---

## GitHub Actions CI 統合

### 基本的な CI ワークフロー

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  phpcs:
    name: PHPCS
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer, cs2pr
      - run: composer install --no-progress
      - run: composer phpcs -- --report=checkstyle | cs2pr

  phpstan:
    name: PHPStan
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-progress
      - run: composer phpstan

  eslint:
    name: ESLint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'
      - run: npm ci
      - run: npm run lint:js

  php-compatibility:
    name: PHP Compatibility
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - run: composer install --no-progress
      - run: |
          ./vendor/bin/phpcs \
            --standard=PHPCompatibilityWP \
            --runtime-set testVersion ${{ matrix.php }} \
            --extensions=php \
            --ignore=vendor/,node_modules/,build/ \
            .

  activation-test:
    name: Activation Test
    runs-on: ubuntu-latest
    strategy:
      matrix:
        wc: ['9.0', '10.0', 'latest']
        php: ['8.1', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
      - run: npm ci && npm run build
      - name: Install wp-env
        run: npm -g install @wordpress/env
      - name: Configure wp-env
        run: |
          cat > .wp-env.override.json << 'EOF'
          {
            "plugins": ["."],
            "env": {
              "tests": {
                "phpVersion": "${{ matrix.php }}"
              }
            }
          }
          EOF
      - name: Start wp-env
        run: wp-env start
      - name: Verify activation
        run: |
          wp-env run tests-cli wp plugin activate my-extension
          wp-env run tests-cli wp plugin list --status=active --format=csv | grep my-extension
          # PHPエラーがないことを確認
          wp-env run tests-cli wp eval "error_reporting(E_ALL); do_action('admin_init');" 2>&1 | grep -v "^$" && echo "OK" || exit 1
```

### QIT を GitHub Actions に統合

```yaml
  qit-tests:
    name: QIT Tests
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-progress
      - name: Build ZIP
        run: |
          npm ci && npm run build
          mkdir -p dist
          zip -r dist/my-extension.zip . \
            -x ".git/*" "node_modules/*" ".github/*" "tests/*" ".wp-env*"
      - name: Run QIT Activation
        env:
          QIT_TOKEN: ${{ secrets.QIT_TOKEN }}
        run: ./vendor/bin/qit run:activation my-extension --zip=dist/my-extension.zip
      - name: Run QIT Security
        env:
          QIT_TOKEN: ${{ secrets.QIT_TOKEN }}
        run: ./vendor/bin/qit run:security my-extension --zip=dist/my-extension.zip
      - name: Run QIT Validation
        env:
          QIT_TOKEN: ${{ secrets.QIT_TOKEN }}
        run: ./vendor/bin/qit run:validation my-extension --zip=dist/my-extension.zip
      - name: Run QIT Plugin Check
        env:
          QIT_TOKEN: ${{ secrets.QIT_TOKEN }}
        run: ./vendor/bin/qit run:plugin-check my-extension --zip=dist/my-extension.zip
```

CI では `CI=true` が自動検出され、CI向けの出力モードで動作する。
詳細: https://qit.woo.com/docs/test-packages/ci/

---

## プレサブミッション品質チェックリスト

提出前に以下を全て確認する:

### 自動テスト
- [ ] PHPCS（WordPress-Extra）エラーゼロ
- [ ] PHPStan level 5 以上でエラーゼロ
- [ ] ESLint エラーゼロ
- [ ] PHP 8.1, 8.2, 8.3, 8.4 で互換性テスト通過
- [ ] WP_DEBUG 有効でエラー/警告/noticeゼロ
- [ ] QIT Activation Test 通過（クリーンWP+WC環境）
- [ ] QIT Security Test 通過
- [ ] QIT Malware Test 通過
- [ ] QIT Plugin Check Test 通過
- [ ] QIT Validation Test 通過（readme.txt、HPOS/Blocks互換宣言）
- [ ] QIT Woo E2E Test（run:woo-e2e）通過（Core Critical Flows が壊れない）
- [ ] QIT Woo API Test（run:woo-api）通過

### 互換テスト
- [ ] WooCommerce 最新安定版で動作確認
- [ ] WooCommerce 最新マイナー-1 で動作確認
- [ ] WordPress 最新安定版で動作確認
- [ ] マーケットプレイス上位拡張との共存テスト（Extension Sets）
- [ ] Block-based Cart/Checkout での動作確認
- [ ] Classic Cart/Checkout での動作確認（該当する場合）

### コード品質
- [ ] HPOS 互換宣言あり
- [ ] Blocks 互換宣言あり
- [ ] 全入力がサニタイズされている
- [ ] 全出力がエスケープされている
- [ ] Nonce 検証が全フォーム/AJAX に実装されている
- [ ] Capability チェックが全管理機能に実装されている
- [ ] テキストドメインがディレクトリ名と一致
- [ ] `Automattic\WooCommerce\Internal` 名前空間は未使用
- [ ] `changelog.txt` が正しいフォーマットで存在
- [ ] バージョン番号がヘッダーとchangelogで一致

### UX
- [ ] トップレベルメニュー未作成
- [ ] WP/WC 既存UIコンポーネント使用
- [ ] モバイルレスポンシブ
- [ ] セットアップフローが直感的
- [ ] 広告/バナー/ブランディングなし

---

## 最新情報の確認先

本スキルのコマンド・バージョン記述は 2026-07 時点（qit-cli 1.2.x）。QITの詳細が必要になったら、
まず **https://qit.woo.com/docs/llms.txt** を取得して該当ドキュメントを参照すること
（llms.txt標準のインデックスで、全ページの要約とURLが得られる）。

## 関連スキル

- `wc-development` — WooCommerce拡張の実装詳細（HPOS、Blocks、決済、REST API）
- `woo-marketplace-extension` — マーケットプレイス向け開発基準
- `woo-marketplace-submission` — 提出・審査・リリース後運用
