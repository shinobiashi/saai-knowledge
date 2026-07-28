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
- PHPクラス名は WordPress 標準の `Post_Types` 形式（アンダースコア区切りPascalCase）を使う。カスタムオートローダー（`saai-knowledge.php`）はアンダースコア→ハイフン変換のみでファイルパスを解決し、WPCSの `WordPress.Files.FileName` sniffも同じ変換を期待するため、`PostTypes` のような純CamelCaseクラス名は両方と不整合になる（lint失敗・オートロード不可）。
- `register_post_meta()` の `auth_callback` は WordPress が `auth_{$object_type}_meta_{$meta_key}` フィルタ経由で6引数（`$allowed, $meta_key, $object_id, $user_id, $cap, $caps`）を渡して呼び出す。シグネチャをこれに合わせる（PHPは超過引数を無視するため実害はないが契約を明確にする）。
- `register_activation_hook()` のコールバックは、プラグイン本体ファイルが `activate_plugin()` 内で（WordPress自身の `init` より後に）はじめて `include` される関係で、同一リクエスト内では通常の `init`/`plugins_loaded` フックがまだ発火しない。`activate()` で `flush_rewrite_rules()` する場合、CPT/タクソノミー登録を `init` に任せず、`activate()` 内で直接呼んでから flush する。
- WP core test framework は各テスト後に `tear_down()` で登録済みメタキーを全て消去する（`unregister_all_meta_keys()`）。`register_post_meta()` に依存するテストは、ブートストラップ時の `init` 一度きりの登録に頼らず、テストクラスの `set_up()` で明示的に再登録する。
- `register_block_template()` はブロックテーマでのみ意味を持つ。無条件に `init` へフックすると、クラシックテーマでも毎リクエスト無駄なファイル読み込み・レジストリ登録が発生する。`wp_is_block_theme()` は `plugins_loaded` の時点でも信頼できる（テーマは `plugins_loaded` より前に読み込まれるため）ので、フック登録時点でガードできる。
- `WP_Block_Templates_Registry` は `register_post_meta` と異なりテスト間でリセットされない。同じテンプレート名で複数回 `register_block_template()` を呼ぶと「already registered」の incorrect usage 通知が出てテストが失敗する。テストでは登録処理を直接1回だけ呼び出す設計にする。
- サードパーティが実装しうる公開フィルター（`saai_*`）は契約と異なる型（非string等）を返す可能性がある前提で、`file_exists()` など型に敏感な組み込み関数へ渡す前に `is_string()` 等のガードを入れる（PHP 8+ はTypeErrorで即死するため）。
- テンプレートファイル名（`single-{$post_type}.php` 等）はCPTスラッグ（アンダースコア含む）と一致させる必要があり、PHPCSの `WordPress.Files.FileName`（ハイフン強制）と衝突する。`templates/` ディレクトリは当該sniffの除外対象に追加済み。
- `@wordpress/scripts`（現行 v30系）で `viewScriptModule`（Interactivity API）を含むブロックをビルドするには `WP_EXPERIMENTAL_MODULES=true` 環境変数が必要。省略してもエラーにならず、該当エントリのビルドが無言でスキップされるだけなので気づきにくい。`start`/`build` スクリプトに `cross-env` 経由で設定する（Windows含むクロスプラットフォーム対応のため）。
- ブロックのフロントエンド用 `style.scss` は block.json の `style` フィールド指定だけでは自動検出されず、JSエントリ側で `import './style.scss'` する必要がある。生成されるCSSファイル名はインポート元エントリ名に連動するため、block.json の `style` もそれに合わせる（`view.js` からのimportなら `style-view.css`、`view.js` を持たない非インタラクティブブロックで `index.js` からimportするなら `style-index.css`）。エディタ用 `editor.scss` も同様に `index.js` 側でimportする。フロント用スタイルを付け忘れると、`<ol>` が番号付きリストのまま出るなど素の見た目で表示される。
- Interactivity APIの `data-wp-bind--X` ディレクティブは、ハイドレーション後の状態変化にのみ反応し、初回描画（SSR出力）には適用されない。折りたたみ要素などの初期表示状態は、SSR側で対応する生のHTML属性（例: `hidden`）を出力して一致させる必要がある（さもないと初回クリックで見た目が変わらず2回目で追いつくような壊れた挙動になる）。
- 「現在の記事」に依存する dynamic block の `render.php` は `is_singular()` だけに頼らず、block.json に `"usesContext": [ "postId" ]` を宣言して `$block->context['postId']` を優先する（`is_singular` はフォールバック）。エディターの ServerSideRender プレビューは REST block-renderer 経由で `is_singular()` が偽だが、post_id パラメータ→グローバル `$post`→`render_block()` の既定コンテキストとして postId が供給されるため、これでプレビューが正しく描画される。
- フロント実行の `view.js` でブラウザグローバル（`document`、`IntersectionObserver` 等）を使うと wp-scripts 同梱の ESLint 設定では `no-undef` になる。`plugins/saai-knowledge/.eslintrc.js` が `src/**/view.js` にのみ browser env を許可済みなので、追加の許可もそこに足す。
- `esc_url()` / `esc_url_raw()` は許可外プロトコル（`javascript:`、`data:` 等）を空文字に落とすため、URLの有無は**サニタイズ後の値で判定する**。生値で判定して出力時にエスケープすると `href=""` の壊れたリンクや、構造化データへの不正値混入になる。なお `esc_url_raw( '0' )` は `'http://0'` を返す（空にはならない）ので、"0" を空扱いする実装は不要。
- JSON-LD は `wp_json_encode()` の出力をそのまま `<script type="application/ld+json">` に入れる（`esc_html()` を通すとJSONが壊れる）。`wp_json_encode()` は既定でスラッシュをエスケープするため、値に `</script>` が含まれてもタグを閉じられない。日本語を読める形で出すなら `JSON_UNESCAPED_UNICODE` を付ける。
- `szepeviktor/phpstan-wordpress` は `apply_filters()` 呼び出し直前のdocblockコメントの最初の `@param` 型を、その呼び出しの戻り値型として採用する。サードパーティ由来の戻り値を実行時に `is_array()` 等でガードしていても、PHPStanはdocblockの型を信頼して「常に真」（`ternary.elseUnreachable`等）と誤検知することがある。ガードの必要性自体は変わらないため、該当行に理由を添えた `// @phpstan-ignore <identifier>` を付ける。
- `register_term_meta()` / `register_post_meta()` で `default` を設定すると、`get_term_meta()` 等は meta 行が無くても default を返すため「未設定」と「明示的な default 値」を区別できない。UI で未設定を空欄表示する場合や行の有無で分岐する場合は `metadata_exists()` で判定する。未設定に default を表示すると、フォーム保存の往復で不要な meta 行が実体化する（saai_order の編集フォームと一覧列で2回指摘された実績あり）。
- `WP_Term_Query` の `orderby` は文字列のみ対応（配列 orderby は `WP_Query` のみ）。core の `parse_orderby()` が値を直接 `strtolower()` に渡すため、配列を渡すと PHP 8+ では `terms_clauses` フィルター到達前に TypeError になる。
- macOSでの `npm ci` 成功はLinux CI（`ci-js.yml`）での成功を保証しない。`fsevents`（macOS専用のオプション依存。`@wordpress/scripts` 経由でplaywright/jest-haste-map/webpack-dev-serverなどが要求）のOS別解決エントリは、ローカルの `npm install` 実行のたびに `package-lock.json` から意図せずdropされ、Linux上の `npm ci` の厳密な整合性チェックだけが失敗する状態になりうる（実際に3回連続で再発）。`package.json`/`package-lock.json` を触った後は、`docker run --rm -v "$(pwd)":/work -w /work node:24 bash -c "npm ci"` で `ci-js.yml` と同じNode版のLinuxコンテナで実際に検証してからpushする。また、macOSローカルでは `npm run lint:js` が `unrs-resolver` のネイティブバインディング欠落（コード起因ではない環境問題）で失敗することがある。その場合も同じDockerコンテナで `npm ci && npm run lint:js` を実行して判定する。
- `container-type` を設定した要素自身は、その要素に対する `@container` クエリの対象にできない（コンテナは自分自身の子孫のみクエリ可能で、自分自身のスタイルを自分のサイズで条件分岐させることはできない）。`.saai-kb-layout { container-type: inline-size; }` に対して `.saai-kb-layout--article { grid-template-columns: ... }` を同じ要素に書いても、ブラウザは黙って無視する（エラーは出ないが常に不成立）。グリッド化したい要素は、コンテナ要素とは別の子孫要素（例: `.saai-kb-layout__grid`）に分離し、そちらを `@container` の対象にする。
- ネイティブ `<details>` の折りたたみコンテンツを「デスクトップ幅では常に開いた状態に見せる」目的でCSSから強制表示しようとする場合、`details:not([open]) > *:not(summary) { display: none }` という古典的な想定は最近のChromium（`::details-content` 擬似要素による開閉のアニメーション対応後）ではもう成立しない。子要素に直接 `display: block` を当てても親の `<details>` 自体の高さが0のまま伸びず、実質非表示になる（Playwrightの `toBeVisible()` で「hidden」と判定されて顕在化した）。CSSで開閉状態を上書きしようとせず、`open` 属性をサーバーサイドで最初から出力し（内容は常にSSR済みでJS不要要件も満たす）、デスクトップ幅では `<summary>` トグル自体を非表示にする設計にする。
- `composer test`（PHPUnit）を wp-env の `tests-cli` に対して実行すると、テストサイトのDBがWP coreのテストブートストラップにより再インストールされ、`saai-knowledge` プラグインの有効化状態と `permalink_structure`（パーマリンク設定）が両方ともリセットされる。同じ `tests-cli`（ポート8889）に対してPlaywright E2E（`npm run test:e2e`）を実行する場合、直前に `composer test` を走らせていたら、`wp plugin activate saai-knowledge` と `bash bin/wp-env-configure-permalinks.sh` を実行し直してからでないとCPT/タクソノミーのREST・pretty permalink URLが404/`rest_no_route`になる。

## Git 運用（重要）

- ブランチ名は `issue-<番号>-<内容>` 形式（例: `issue-3-bootstrap`）。Issue 単位の作業に対応させる。
- **`git commit` / `git push` は実行しない。コミットは必ずユーザーが手動で行う。**
  - コミットメッセージの作成・提案、`git status` / `git diff` / `git log` 等の参照系、変更内容の整理は行ってよい。
  - コミット準備が整ったら「このメッセージでコミットしてください」とメッセージ案を提示して止まる。
- **PR の作成（`gh pr create` 等）も明示的な指示がない限り実行しない。** PR タイトル・本文の下書き作成は行ってよい。
- コミットメッセージは英語（グローバルルールどおり）。

## コマンド

```sh
npm run start / npm run build   # wp-scripts（workspaces で各プラグイン）
npx wp-env start                # ローカル環境（無料版 + WooCommerce。有料版は M5 でマウント追加）
composer lint / lint:fix        # PHPCS / PHPCBF
composer analyze                # PHPStan
composer test                   # PHPUnit（ローカルは wp-env の tests-cli コンテナ、CI は bin/install-wp-tests.sh + WP_TESTS_DIR でホスト直実行）
composer verify                 # lint + analyze + test を一括実行（bin/verify.sh。wp-env が未起動なら起動し、このコマンドが起動した場合のみ終了時に停止）
```

ローカルでの `composer test` 実行例（`.wp-env.json` の `mappings.saai-monorepo` によりリポジトリルートはコンテナの `wp-content/` 配下ではなく `saai-monorepo/` 直下にマウントされる）:

```sh
npx wp-env run tests-cli --env-cwd=saai-monorepo bash -c "composer test"
```

`wp-env run` はスペース区切りの複数語コマンドを直接渡すと失敗するため `bash -c "..."` で包む。`composer analyze` がメモリ不足で落ちる場合は `composer exec phpstan analyse -- --memory-limit=512M` を使う。

他プロジェクトの wp-env がポート 8888/8889 を使用中で起動が「port is already allocated」で失敗する場合は、`WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8892 composer verify` のように環境変数でポートをずらして並行起動する（wp-env インスタンスはディレクトリ単位で独立しており、衝突するのはポートのみ。他プロジェクト側を止める必要はない）。

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
