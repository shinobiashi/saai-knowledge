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

## コーディング規約（基本方針）

- WordPress Coding Standards（WordPress-Extra + WordPress-Docs）。`composer lint` / `composer lint:fix` を使う。
- プレフィックスは `saai_`（フック・オプション・メタ・CPT・タクソノミー）、ブロックは `saai-knowledge/*`。
- PHP namespace: 無料版 `SAAI\Knowledge\`、有料版 `SAAI\KnowledgeWoo\`。
- Text domain: 各プラグインのスラッグと同一（`saai-knowledge` / `saai-knowledge-for-woocommerce`）。翻訳関数の text domain はリテラル文字列で書く。
- フロント JS は Interactivity API（`@wordpress/interactivity`）で統一。ブロックは全て dynamic（block.json + render.php）。
- ファイルロード時の副作用禁止。フック登録は `plugins_loaded` / `init` 以降。管理専用コードは `is_admin()` 配下でのみロード。
- セキュリティ: sanitize on input / escape on output、nonce + capability 両方のチェック、SQL は `$wpdb->prepare()`。
- 有料版は無料版の公開フック（`saai_*` filters/actions）のみに依存する。無料版の内部クラスを直接呼ばない。

## 分野別の規約・落とし穴（`.claude/rules/`）

- 分野ごとのハマりどころは `.claude/rules/*.md` に分けてある。各ファイルは frontmatter の `paths` に一致するファイルを読んだり編集したりしたときに自動で読み込まれる。
- 計画・レビュー・調査など、まだ該当するファイルを開いていない段階や、サブエージェント（パス指定のルールが読み込まれる保証が無い）では、関係するファイルを明示的に読む。独立レビューを依頼するときは、読むファイルをプロンプトで指定する。
- 新しい知見は該当する分野のファイルに追記し、CLAUDE.md には足さない。どの分野にも当てはまらないときは新しいファイルを作り、下の表にも足す。
- コードのコメントやスキルから規約を参照するときは、ルールファイル（`.claude/rules/testing.md` など）を名指しする。ルールを別のファイルへ移したら、`git grep -n '<旧ファイル名>'` で参照元（コード内コメント・`.claude/skills/`・README）も直す（CLAUDE.md を分割したとき、コメント4か所・スキル2つ・README の参照が古いまま残った）。

| ファイル | 内容 | 自動で読み込まれる対象（`paths`） |
| --- | --- | --- |
| `php-lint.md` | PHPCS / PHPStan の誤検知と回避 | PHP ファイル全般、PHPCS / PHPStan の設定 |
| `security.md` | 出力エスケープ・サニタイズ（URL・JSON-LD・ショートコード） | プラグインの PHP |
| `wordpress-core.md` | WordPress core の挙動（フック・クエリ・メタ・REST・管理画面・有効化 / 更新 / アンインストール） | プラグインの PHP |
| `rendering-cache.md` | 投機的レンダリング・ブロックテーマでの検出・キャッシュの無効化 | プラグインの PHP |
| `blocks-frontend.md` | ブロック・テンプレート・Interactivity API・CSS・エディター JS | `src/`・`assets/`・`templates/`、ブロック / ツールチップ / テンプレートのクラス |
| `markdown-export.md` | Markdown 出力・llms.txt・RAG エクスポート（Markdown / CSV のエスケープ、日付の比較） | Markdown / エクスポート / llms のクラスとテスト、`bin/markdown-render-check.sh` |
| `woocommerce.md` | WooCommerce 連携・有料版 | 有料版のすべて、無料版の FAQ リスト / オートリンク |
| `i18n.md` | 翻訳ファイルの生成・テキストドメインのロード・`wp_trim_words()` | `languages/`、`bin/i18n-*.sh`、本体ファイル、関係するクラス |
| `testing.md` | PHPUnit / Playwright の落とし穴 | `tests/`、PHPUnit / Playwright の設定、テスト用の `bin/` |
| `build-packaging.md` | composer / npm・配布 ZIP・Plugin Check・SVN デプロイ | `package.json`・`composer.json`・ロックファイル、`.github/workflows/`、`bin/`、`readme.txt`・`changelog.txt` |
| `docs-markdown.md` | ドキュメントの Markdown の書式 | `*.md` |

## Git 運用（重要）

- ブランチ名は `issue-<番号>-<内容>` 形式（例: `issue-3-bootstrap`）。Issue 単位の作業に対応させる。
- **ドキュメントのみの更新は `main` へ直接コミットしてよい**（`issue-<番号>` ブランチの作成と PR を省き、どちらにするかの確認もしない）。対象は `*.md` と `docs/` 配下だけを変更し、コード・設定・CI・配布物に一切触れない場合。`plugins/*/readme.txt` は WordPress.org の掲載内容そのものなので**ドキュメント扱いにしない**。コミットの実行自体は下記のとおりユーザーの明示指示が必要。
- **`git commit` / `git push` は実行しない。コミットは必ずユーザーが手動で行う。**
  - コミットメッセージの作成・提案、`git status` / `git diff` / `git log` 等の参照系、変更内容の整理は行ってよい。
  - コミット準備が整ったら「このメッセージでコミットしてください」とメッセージ案を提示して止まる。
- **PR の作成（`gh pr create` 等）も明示的な指示がない限り実行しない。** PR タイトル・本文の下書き作成は行ってよい。
- コミットメッセージは英語（グローバルルールどおり）。

## コマンド

```sh
npm run start / npm run build   # wp-scripts（workspaces で各プラグイン）
npx wp-env start                # ローカル環境（無料版 + 有料版 + WooCommerce をマウント）
composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit（ローカルは wp-env の tests-cli コンテナ、CI は bin/install-wp-tests.sh + WP_TESTS_DIR でホスト直実行）
composer verify                 # lint + analyze + test を一括実行（bin/verify.sh。wp-env が未起動なら起動し、このコマンドが起動した場合のみ終了時に停止）
bin/markdown-render-check.sh    # Markdown を commonmark.js・markdown-it・Python-Markdown（拡張なし / fenced_code / extra）で描画し、生の HTML 要素が出ないか確かめる（--from-html で HTML を Markdown_Converter に通してから。Markdown 出力を変えたら CommonMark だけで済ませない）
```

ローカルでの `composer test` 実行例（`.wp-env.json` の `mappings.saai-monorepo` によりリポジトリルートはコンテナの `wp-content/` 配下ではなく `saai-monorepo/` 直下にマウントされる）:

```sh
npx wp-env run tests-cli --env-cwd=saai-monorepo bash -c "composer test"
```

`wp-env run` はスペース区切りの複数語コマンドを直接渡すと失敗するため `bash -c "..."` で包む。`composer analyze` がメモリ不足で落ちる場合は `composer exec phpstan analyse -- --memory-limit=3G` を使う（ファイル数が増えた現状では512M/1Gでも落ちる実績あり）。

wp-env のポートは `.wp-env.json` でスロット 03（dev 10030 / tests 10031 / phpMyAdmin 10032・10033）に固定している（dev-env スキル〔claude-skills〕の台帳。WordPress Studio が自動で割り当てる 8881〜8999 と、wp-env の既定 8888/8889 を避けるため）。`WP_ENV_PORT` / `WP_ENV_TESTS_PORT` でずらさない（どのファイルにも残らず、他リポジトリとの照合から見えなくなる）。起動が「port is already allocated」で失敗したら、dev-env の `ports.js check` で誰がそのポートを持っているかを確かめる（wp-env インスタンスはディレクトリ単位で独立しており、他プロジェクト側を止める必要はない）。

## CI（GitHub Actions）

- `ci-php.yml`（PHPCS / PHPStan / PHPUnit、PHP 8.2–8.4 × WP 6.9–latest）、`ci-js.yml`（Lint JS/CSS・Build〔有料版の配布 ZIP を組み立てて `bin/verify-woo-zip.sh` で中身・版数を検証〕に加え、`wp-env start` → `npm run test:e2e` を実行する E2E ジョブ）、`release.yml`（`v*` タグ push で無料版 ZIP を GitHub Release に添付）。
- `bin/install-wp-tests.sh` は wp-cli scaffold の移植。改変時は必ず canonical と突き合わせる（ABSPATH sed の末尾スラッシュ欠落で全マトリクスが落ちた実績）。ubuntu-latest ランナーに svn は無い（ワークフロー側で apt install 済み）。
- Codex（`chatgpt-codex-connector`）の push 時の自動レビューは走らないことがある（PR #67 では CI green 後 35 分待っても未着で、`@codex review` の手動投稿には数分で応答）。dev-cycle のゲートでは `request-gate-review.sh` に `--request-codex` を付けて依頼する。

## リリース

- リリース前の配布 ZIP の検証（Plugin Check のかけ方）と SVN デプロイの注意は `.claude/rules/build-packaging.md`。
- 無料版: readme.txt の Stable tag 更新 → `v<x.y.z>` タグを push（`release.yml` が GitHub Release に ZIP を添付）→ `deploy-wporg.yml` を `version=<x.y.z>` で**手動実行**して WordPress.org SVN へデプロイ（タグ push だけでは SVN に乗らない）。タグは `v` 付きで統一する（`deploy-wporg.yml` が `v${version}` を checkout する）。手順はスキル `release-free`（`.claude/skills/release-free/`。`bump` / `prep` / `ship` / `verify`）にまとめてある。
- 共有スキル `release-bump` はこのモノレポでは**そのまま使わない**。スクリプトが git のルートへ `cd` して `readme.txt` / `package.json` をルート直下にしか探さないため `plugins/saai-knowledge/readme.txt` の `Stable tag` が書き換え対象から漏れ（下記3箇所ルールの1つが欠ける）、`tag` モードは `v` 無しタグしか push しない（`release.yml` の `v*` に掛からない）。版数の書き換えと下記3箇所の照合は `release-free` のスクリプト（`bump` / `check`）で行う。
- 有料版: WooCommerce.com Marketplace（ライセンス・更新配信は Marketplace 任せ。自前ライセンス実装は行わない）。版数は `Version:` ヘッダー / `SAAI_KNOWLEDGE_WOO_VERSION` / readme.txt の `Stable tag` / `changelog.txt` の最新エントリの4箇所、WC 要件は本体と readme の `WC requires at least` / `SAAI_WOO_MIN_WC_VERSION` を揃える（`bin/verify-woo-zip.sh` が検証）。`changelog.txt` は WooCommerce.com の公式書式 `YYYY-MM-DD - version x.y.z` + `* Added - …` で書く。WordPress.org 風の `= x.y.z - date =` だとアップロード時に拒否される。申請前のチェック手順と QIT の知見は `docs/MARKETPLACE-PREFLIGHT.md`。
- 無料版のバージョン番号は3箇所を必ず揃える: `saai-knowledge.php` の `Version:` ヘッダー / 同ファイルの `SAAI_KNOWLEDGE_VERSION` / `readme.txt` の `Stable tag`。`Version:` ヘッダーだけ上げて定数を忘れると、`maybe_flush_rewrite_rules_on_upgrade()` が `get_option()` の保存値と `SAAI_KNOWLEDGE_VERSION` を比較する実装のため、WordPress.org 自動更新時の rewrite flush が無言でスキップされる。`deploy-wporg.yml` が検証するのは `Stable tag` とタグ名の一致だけなので、この不整合は CI では捕まらない。逆に `phpstan-bootstrap.php` の同名定数（静的解析用スタブ）と `SAAI_WOO_MIN_BASE_VERSION`（アドオンが要求する無料版の最低バージョン）は据え置く。
