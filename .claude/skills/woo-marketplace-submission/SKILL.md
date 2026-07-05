---
name: woo-marketplace-submission
description: >
  WooCommerce.com Marketplaceへのプラグイン提出から審査通過、リリース後の継続運用までをカバーするスキル。
  Vendor Dashboard の Submit Product フォーム全フィールド（Name, Short description, Best features,
  Benefits, Rationale, Competitive comparison, Testing instructions, Notes for reviewers 等）への
  適切な英語テキスト生成、提出パッケージの準備（ZIP命名、changelog形式、バージョン整合性）、3段階の審査プロセス
  （ビジネス/コード/UX）の対応戦略、価格設定ルール（70/30収益分配、SaaS Billing API）、Freemiumモデル構成、
  リリース後のアップデート義務（最低6ヶ月、四半期メジャー推奨）、ドキュメント要件を網羅する。
  「マーケットプレイス提出」「Woo提出」「審査対応」「Vendor Dashboard」「提出フォーム」「Submit Product」
  「価格設定」「収益分配」「Freemium」「アップデート義務」「商標ガイドライン」「SaaS Billing」
  「提出フォームの英語」「申請フォーム」といったキーワードが出た場合に使用する。
  WooCommerceプラグインの販売戦略やビジネスモデル設計、提出フォームの記入について相談された場合にも積極的に参照すること。
---

# WooCommerce Marketplace Submission & Operations

Vendor登録が完了した後の、製品提出から審査通過、リリース後の運用までの全プロセスを解説する。

---

## 提出フォーム記入テキストの生成

Vendor Dashboard の Submit Product フォームの全フィールドに対して、
プラグイン情報をヒアリングした上で、審査に通りやすい適切な英語テキストを生成する。

Product Details（Name, Category, Short description）、Business Details（Best features,
Benefits, Rationale, Monthly sales, Competitive comparison）、Pricing、Languages、
Integrations、Testing（Instructions, Video, Demo URL, Credentials）、
Product Upload（Slug）、Notes for reviewers の全セクションをカバー。

使い方: 「提出フォームの記入内容を作って」「Submit Productの英語を書いて」と指示する。

See: `references/submission-form.md`

---

## 提出パッケージの準備

### ZIP ファイル要件

WooCommerce.com はアップロードされたZIPファイルの名前とフォルダ構造を検証する。

```
my-extension.zip
└── my-extension/           # ディレクトリ名 = プラグインスラッグ
    ├── my-extension.php    # メインファイル名 = ディレクトリ名
    ├── changelog.txt       # 必須
    ├── readme.txt
    ├── includes/
    ├── build/              # ビルド済みJS/CSS（ソースマップは除外）
    ├── assets/
    ├── languages/
    └── ...
```

Vendor Dashboard でアップロードすると、期待されるZIPファイル名が表示される。
この名前と一致しない場合はアップロードエラーになる。

### ZIP 作成の自動化

```bash
#!/bin/bash
# build-zip.sh

PLUGIN_SLUG="my-extension"
VERSION=$(grep "Version:" "${PLUGIN_SLUG}.php" | awk '{print $NF}')

# ビルド
npm ci
npm run build
composer install --no-dev --optimize-autoloader

# ZIP作成（不要ファイルを除外）
zip -r "${PLUGIN_SLUG}.zip" . \
  -x ".git/*" \
  -x ".github/*" \
  -x "node_modules/*" \
  -x "tests/*" \
  -x "src/*" \
  -x ".wp-env*" \
  -x "phpcs.xml" \
  -x "phpstan.neon" \
  -x ".eslintrc*" \
  -x "*.config.ts" \
  -x "*.config.js" \
  -x "tsconfig.json" \
  -x "build-zip.sh" \
  -x ".editorconfig" \
  -x "*.map"

echo "Created ${PLUGIN_SLUG}.zip (v${VERSION})"
```

### changelog.txt のフォーマット

```
*** My Extension Changelog ***

= 1.1.0 - 2025-06-01 =
* Feature - Added new payment method support
* Tweak - Improved order processing performance
* Fix - Resolved checkout validation issue with block editor

= 1.0.1 - 2025-05-15 =
* Fix - Fixed compatibility issue with WooCommerce 9.5
* Fix - Corrected translation string for Japanese locale

= 1.0.0 - 2025-04-01 =
* Feature - Initial release
```

エントリタイプ: `Feature`, `Tweak`, `Fix`, `Dev`, `Update`

バージョン番号が以下の3箇所で全て一致している必要がある:
1. プラグインヘッダーの `Version:` フィールド
2. `changelog.txt` の最新エントリ
3. アップロード時に入力するバージョン番号

不一致があるとアップロードエラーになる。

---

## 審査プロセス

提出後、3段階の審査を通過する必要がある:

### 1. 自動テスト（即時）

提出すると QIT が自動でテストを実行する（2026年時点で以下が対象）:
- Activation Test
- Security Test
- Malware Test
- PHP Compatibility Test
- Validation Test（readme.txt、HPOS/Blocks互換宣言などのメタデータ検証）
- Woo API / Woo E2E Test

これらに通過しないとレビューキューに入れない。
失敗した場合は Vendor Dashboard の Submission progress タブに結果が表示される。

QIT テスト結果のリンクは一時的な署名付きURLであり、期限切れになることがある。
新しい結果が必要な場合はテストを再実行する。

### 2. ビジネスレビュー（正式決定まで最大30日）

審査チームが以下を評価する:

**収益分配モデル**: マーケットプレイスは70/30の収益分配（ベンダー70%、Woo 30%）。
製品が収益を共有する構造になっているか確認される。

**価格設定**: マーケットプレイスでの価格は、他の販売チャネル（自社サイト等）での
価格以下でなければならない。自社サイトで$99なら、マーケットプレイスも$99以下。

**商標ガイドライン**: プラグイン名がWooの商標ガイドラインに違反していないか。
「WooCommerce」をプラグイン名に含める場合のルールに注意する。

**製品の独自性**: 既存のマーケットプレイス製品と過度に重複しないか。
差別化要因が明確か。

**禁止事項**:
- マーケットプレイス外へのアップセルリンク
- アフィリエイトリンク
- スパムリンク
- 他のマーケットプレイスへの誘導

### 3. コードレビュー

提出コードのオリジナリティ、セキュリティ、WordPress/WooCommerce品質基準への準拠を確認:

- コードがオリジナルであること
- セキュリティベストプラクティスに準拠
- WordPress / WooCommerce コーディングスタンダード準拠
- HPOS 互換性（新規提出では必須）
- Block Editor / Block Cart & Checkout 互換性（新規提出では必須）
- 適切なデータバリデーション/サニタイズ

**技術要件（公式）**: PHP 7.4+ で動作すること、WordPress / WooCommerce それぞれ
**直近2メジャーバージョン**をサポートすること。

### 4. UXレビュー

2024年以降、UXレビューが審査に追加され、審査期間が長くなっている。

確認項目:
- 製品のクリティカルフローが正しく動作するか
- UXガイドラインに準拠しているか（メニュー配置、UIコンポーネント、レスポンシブ等）
- セットアップフローが直感的か
- アクセシビリティ対応
- WooCommerce のルック&フィールと整合しているか
- **ドキュメントの充実度**: WooCommerce公式ドキュメントポータルにドキュメントが未掲載・未整備の場合、UXレビューで審査がブロックされる。コードレビュー通過後、UXレビュー開始前までにドキュメントを作成・公開しておくことが必須条件。

UXレビューを迅速に通過するためのポイント:
- **ドキュメントを先に公開する**: UXレビュー前にWooCommerce公式ドキュメントポータルへドキュメントを掲載していないと審査がそこで止まる（実績あり）
- 事前に自社でクリティカルフローのテストを徹底する
- WooCommerce Core のクリティカルフロー定義を参考にする
- 既存の WordPress/WooCommerce UIコンポーネントを最大限活用する

### フィードバック対応

審査中にフィードバックが返ってくる場合がある:
- Vendor Dashboard の Submission progress タブでフィードバックを確認
- 修正後に再提出（ステータスが "Changes required" の場合のみZIP差し替え可能）
- 他のステータスの場合はコメントでステータス変更を依頼する
- 以前のフィードバックを全て反映した上で再提出すること

---

## 価格設定とビジネスモデル

### 収益分配

- ベンダー: 70%
- WooCommerce: 30%

### 価格戦略

**年間サブスクリプション（標準モデル）**:
マーケットプレイスの標準的な価格モデル。年間ライセンスでサポートとアップデートを提供。

**月額サブスクリプション**:
月額の場合は SaaS Billing API の実装が必須。APIキーとサンドボックスアクセスは
製品提出後に手動で付与される。

```
月額サブスクリプションの場合:
→ SaaS Billing API 実装が必須
→ APIキー/サンドボックスは提出後に付与
→ 年額より月額の方が1ユーザーあたりの総支払額は高くなるよう設計
```

**ティアード価格（標準、プロ、エンタープライズ等）**:
機能制限やサイト数で差別化する場合、各ティアは個別のプラグインファイルか
ライセンスキーによる機能制御で実装する。

### 他チャネルとの価格整合

マーケットプレイスでの価格 ≤ 他チャネルでの価格

自社サイトでも販売する場合は、マーケットプレイスの価格以下にはできない。
マーケットプレイスを最安チャネルにするか、同一価格にする。

---

## Freemium モデル

無料版（WordPress.org）と有料版（WooCommerce.com）の組み合わせ。

### 提出の流れ

Freemium の場合、2つの別々の提出が必要:

1. **無料版**: WordPress.org プラグインディレクトリに提出
2. **有料版**: WooCommerce.com マーケットプレイスに提出

それぞれ独立したレビュープロセスを通過する。

### 設計パターン

```php
// 無料版のメインファイル
// 基本機能を提供、Pro版への自然な導線

if ( ! defined( 'MY_EXTENSION_PRO' ) ) {
    // 無料版の機能制限表示
    add_action( 'my_extension_settings_after', function() {
        echo '<div class="my-extension-upgrade-notice">';
        printf(
            /* translators: %s: upgrade URL */
            esc_html__( 'Unlock advanced features with %s', 'my-extension' ),
            '<a href="https://woocommerce.com/products/my-extension/">'
                . esc_html__( 'My Extension Pro', 'my-extension' )
                . '</a>'
        );
        echo '</div>';
    });
}
```

```php
// 有料版のメインファイル
// 無料版を含む or 完全に別プラグインとして独立
define( 'MY_EXTENSION_PRO', true );

// Pro機能の読み込み
require_once plugin_dir_path( __FILE__ ) . 'includes/pro/class-pro-features.php';
```

### 注意点

- 無料版からのアップセル導線はマーケットプレイス内のURLのみ許可
- 外部サイトへのアフィリエイトリンクやトラッキングリンクは禁止
- 無料版に過度な広告/バナーを表示しない
- 無料版だけでも実用的な価値を提供すること

---

## 提出手順（Vendor Dashboard）

1. Vendor Dashboard にログイン
2. **Submissions > Submit Product** に移動
3. 製品タイプを選択（Extension / Theme / SaaS / Business Service）
4. 製品情報を入力:
   - プラグイン名（商標ガイドライン準拠）
   - 説明文
   - カテゴリ
   - 価格設定
   - スクリーンショット / デモ動画
   - デモサイトURL（審査チームがUXを確認するため重要）
   - テスト手順（審査チームへの指示）
5. ZIP ファイルをアップロード
6. 提出

### デモ環境の準備

審査チームが製品を実際に操作してUXを確認するため、デモ環境を用意する:

- WooCommerce + 製品がインストール済みの状態
- テストデータ（商品、注文等）が入った状態
- 管理者アカウント情報の提供
- wp-env や InstaWP を使ったデモ環境が便利

---

## リリース後の運用

### アップデート義務

マーケットプレイスに掲載された製品には継続的なメンテナンスが求められる:

- **最低6ヶ月ごとのアップデート**: 更新が6ヶ月以上ない製品は掲載取り下げの対象
- **四半期メジャーリリース推奨**: WooCommerce Core のリリースカレンダーと同期
- **セキュリティ修正**: 必要に応じて緊急リリース
- **マイナー改善**: 月次または機能/修正の準備ができ次第

### バージョンアップロードの流れ

1. QIT 全テスト通過を確認（Vendor Dashboard の Quality Insights メニュー）
2. Vendor Dashboard の **Versions** タブに移動
3. 新バージョンの ZIP をアップロード
4. 自動テスト（Activation / Security / Malware）が実行される
5. テスト通過後、自動的にデプロイされる

アップロード時のエラー原因:
- ZIPファイル名の不一致
- `changelog.txt` の欠如または不正フォーマット
- changelog のフォーマットが無効
- バージョンヘッダーとアップロード時のバージョンの不一致
- ヘッダーと `changelog.txt` のバージョン不一致

### WooCommerce Core リリースへの追従

WooCommerce はおよそ四半期ごとにメジャーリリースを行う。
新バージョンのリリース前にベータ/RC版でテストし、互換性を確認する:

```bash
# ベータ/RC版でのテスト（QITはrun:woo-e2eでCore E2Eを実行）
qit run:woo-e2e my-extension \
  --zip=./my-extension.zip \
  --woocommerce_version=rc

# または wp-env で指定（バージョンは最新のpre-releaseに置き換える）
# .wp-env.override.json
{
  "plugins": [
    ".",
    "https://github.com/woocommerce/woocommerce/releases/download/<latest-beta-tag>/woocommerce.zip"
  ]
}
```

### サポート

マーケットプレイスベンダーは購入者へのサポートを提供する義務がある:
- WooCommerce.com のヘルプデスク経由でチケットが来る
- 24時間以内の初回応答を目標
- ドキュメントを充実させてセルフサービスの割合を上げる

---

## ドキュメント要件

### 製品ページ用ドキュメント

マーケットプレイスの製品ページに表示されるドキュメント:
- インストール手順
- 初期セットアップガイド
- 機能の使い方
- FAQ
- トラブルシューティング

### 開発者向けドキュメント

フックやフィルター、テンプレートオーバーライドの説明:

```markdown
## Hooks Reference

### Actions
- `my_extension_before_process` — 処理開始前に実行
  - Parameters: `$order` (WC_Order)
- `my_extension_after_process` — 処理完了後に実行
  - Parameters: `$order` (WC_Order), `$result` (array)

### Filters
- `my_extension_default_settings` — デフォルト設定値をフィルター
  - Parameters: `$settings` (array)
  - Return: array
```

### Changelog の公開

`changelog.txt` の内容は製品ページにも表示されるため、
ユーザーが理解しやすい形で記述する。技術的な内部変更よりも
ユーザーに影響する変更を中心に。

---

## 提出～リリースのタイムライン目安

```
提出
 ├─ 自動テスト（即時～数時間）
 ├─ ビジネスレビュー（最大30日）
 ├─ コードレビュー（フィードバック込みで1～3週間）
 ├─ UXレビュー（1～2週間）
 ├─ フィードバック対応・再提出（0～数週間）
 └─ 承認 → ローンチ準備 → 公開
```

初回提出から公開まで1～3ヶ月を見込んでおくと安全。
フィードバックへの迅速な対応が期間短縮の鍵になる。

---

## よくある審査リジェクト理由と対策

| リジェクト理由 | 対策 |
|---------------|------|
| HPOS非互換 | `declare_compatibility('custom_order_tables')` を宣言し、直接DBクエリを排除 |
| セキュリティ問題 | 全入力のサニタイズ、全出力のエスケープ、nonce検証、capability check |
| 商標違反 | プラグイン名からWooCommerce商標の不適切な使用を除去 |
| 外部リンク | マーケットプレイス外へのアップセル/アフィリエイトリンクを除去 |
| トップレベルメニュー | WooCommerce配下のサブメニューに変更 |
| 独自テレメトリ | 独自のトラッキング/テレメトリコードを除去 |
| バージョン不一致 | ヘッダー、changelog、アップロード時のバージョンを統一 |
| changelog未設置 | `changelog.txt` を正しいフォーマットで作成 |
| 国際化不備 | 全テキストを翻訳関数でラップ、テキストドメインの一致確認 |
| デモ環境なし | 審査チーム用のデモサイトを準備 |
| ドキュメント未掲載 | UXレビュー前にWooCommerce公式ドキュメントポータルへGutenbergブロック形式でドキュメントを作成・公開する |

---

## 最新情報の確認先

本スキルの記述は 2026-07 時点（収益分配70/30、ビジネスレビュー最大30日を公式ドキュメントで確認済み）。
提出前に最新の公式要件を確認すること:

- 提出ガイド: https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product/
- マーケットプレイス開発者ドキュメント: https://developer.woocommerce.com/docs/woo-marketplace/

## 関連スキル

- `woo-marketplace-extension` — マーケットプレイス向け開発基準（提出前のコード品質）
- `woo-marketplace-qit` — QITテスト（提出時の自動テスト対策）
- `woo-marketplace-content` — 製品ページ・ドキュメント作成（UXレビュー必須要件）
- `woo-marketplace-pricing` — 価格設定（ビジネスレビュー対策）
