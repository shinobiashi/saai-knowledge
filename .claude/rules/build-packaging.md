---
paths:
  - "**/package.json"
  - "**/package-lock.json"
  - "composer.{json,lock}"
  - ".github/workflows/**"
  - "bin/**"
  - "plugins/*/readme.txt"
  - "plugins/*/changelog.txt"
  - "plugins/*/webpack.config.js"
---

# ビルド・パッケージング・配布 ZIP

- `composer.json` の `config.platform.php` は必ずプロジェクトの最小PHP要件（8.2.0）に固定する。外すと `composer.lock` が開発機のPHPバージョンに引きずられ、8.2/8.3環境で `composer install` が壊れうる（doctrine/instantiatorで実際に発生）。
- macOSでの `npm ci` 成功はLinux CI（`ci-js.yml`）での成功を保証しない。`fsevents`（macOS専用のオプション依存。`@wordpress/scripts` 経由でplaywright/jest-haste-map/webpack-dev-serverなどが要求）のOS別解決エントリは、ローカルの `npm install` 実行のたびに `package-lock.json` から意図せずdropされ、Linux上の `npm ci` の厳密な整合性チェックだけが失敗する状態になりうる（実際に3回連続で再発）。`package.json`/`package-lock.json` を触った後は、`docker run --rm -v "$(pwd)":/work -w /work node:24 bash -c "npm ci"` で `ci-js.yml` と同じNode版のLinuxコンテナで実際に検証してからpushする。また、macOSローカルでは `npm run lint:js` が `unrs-resolver` のネイティブバインディング欠落（コード起因ではない環境問題）で失敗することがある。その場合も同じDockerコンテナで `npm ci && npm run lint:js` を実行して判定する。
- `@wordpress/scripts` の `wp-scripts plugin-zip` は、`package.json` に `files` フィールドが無いと WordPress Plugin Handbook のベストプラクティスに基づくハードコードされたグロブ（`admin/**`, `build/**`, `includes/**`, `languages/**`, `public/**` + `{name}.php`/`uninstall.php`等の個別ファイルのみ）にフォールバックする。`templates/`・`assets/` のような他の実行時必須ディレクトリはこのグロブに含まれず、実際にビルドしたZIPから黙って欠落する（`unzip -l` で中身を確認しないと気づけない）。カスタムの実行時ディレクトリを持つプラグインでは、`package.json` に明示的な `files` 配列（npmの `files` フィールド、`npm-packlist` 経由で解決される）を必ず設定し、CIでもZIPを実際に組み立てて必須ディレクトリの存在を検証する（「入っていること」だけでなく「入っていないこと」の検証も同じ場所に置く）。なお `files` に列挙しなくても npm-packlist が `package.json` を常に含めるため、ZIPには必ず入る。
- Plugin Check は**配布ZIPを展開したディレクトリ**に対して実行する（`wp plugin check <展開先> --slug=saai-knowledge`）。ソースディレクトリに直接かけると `tests/`・`.eslintrc.js`・生成済み `saai-knowledge.zip` 由来のエラー（`compressed_files` / `hidden_files` / `Offloading` 等）が並ぶが、いずれも `files` により配布物へ含まれず審査対象外なので、実物との切り分けが付かない。既定では一部のチェックしか走らないため `--categories=general,plugin_repo,security,performance,accessibility --include-experimental` を付ける。`Domain Path` が実在しないディレクトリを指す場合は `plugin_header_nonexistent_domain_path` で警告される。結果は終了コードでも文言でも判定できない。エラーがあっても exit 0 を返し、メッセージはサイトの言語で出る（dev サイトは日本語）。`--format=csv` を付け、type 列（`ERROR` / `WARNING`。ロケールに関係なく英語）の行を数える。終了コードが 0 以外なら Plugin Check 自体が動いていない。`release-free.sh zip`（`.claude/skills/release-free/scripts/`）はこの方法で判定する。
- `10up/action-wordpress-plugin-deploy` の `BUILD_DIR` にプラグインのソースディレクトリを直接指定すると、`package.json` の `files`（npm-packlist）を一切参照せず、そのディレクトリ配下を丸ごとSVN trunkに同期する。`src/`・`tests/`・`.wordpress-org/`（本来は別の `ASSETS_DIR` でSVNの `assets/` に渡すマーケティング素材）まで公開リポジトリに紛れ込むため、事前に `wp-scripts plugin-zip` でビルド・検証済みのZIPを展開したディレクトリを `BUILD_DIR` に指定する（`deploy-wporg.yml` が確立パターン）。
- リリース前に最新の WordPress / WooCommerce で確かめるときは `npx wp-env start --update` で起動し直す。`--update` を付けないと、前に取得した core と `.wp-env.json` の ZIP（WooCommerce）を使い回す（1.0.3 の確認では最新の 11.2.1 に対し 11.2.0 のままだった）。dev・tests の両方の版数と WordPress.org の最新版との照合は `release-free.sh latest --env` で行う。
- `readme.txt` の Upgrade Notice は 1 版あたり 300 文字以内（WordPress.org の readme 規定）。`release-free.sh check <x.y.z>` が文字数（バイト数ではない）で数える。
