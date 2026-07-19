---
name: saai-block-scaffold
description: >
  saai-knowledge プラグインに新しい saai-knowledge/* dynamic block を追加するための雛形生成スキル。
  block.json・index.js・view.js・render.php・style.scss/editor.scss・PHPサービスクラス・
  includes/class-blocks.php への登録・PHPUnitテスト骨格までを、kb-sidebar ブロック（Issue #6）で
  確立した規約に沿って一括生成する。「新しいブロックを作って」「kb-toc ブロックを実装」
  「breadcrumbs ブロックの雛形」「faq-list ブロックを作成」「glossary-index ブロック」
  「block scaffold」といった依頼で使う。DEVELOPMENT-PLAN.md の M2〜M4 に列挙された
  saai-knowledge/* ブロック（kb-toc, breadcrumbs, faq-list, glossary-index, search）を
  実装する際は積極的にこのスキルを使うこと。一般的な WordPress ブロック開発の作法は
  `wp-block-development` を参照するが、こちらはこのプロジェクト固有の規約・落とし穴の適用を担う。
---

# saai-knowledge ブロック雛形生成

新規 `saai-knowledge/*` dynamic block を、kb-sidebar（Issue #6, PR #33）と kb-toc
（Issue #7, PR #34）で確立した規約通りにスキャフォールドする。両PRのレビューで
見つかった落とし穴を**あらかじめ踏まないための**チェックリストも兼ねる。

## 前提として押さえること

- 対応する GitHub Issue を先に確認する（`gh issue view <N>`）。受け入れ条件・参照ドキュメント
  （docs/DESIGN.md §4.2、docs/DESIGN-HOOKS-API.md）はそちらが正。
- ブランチは `issue-<番号>-<内容>` 形式で作業する。
- コミット・プッシュ・PR作成は明示的な指示がない限り実行しない（CLAUDE.md の Git 運用ルール）。

## 手順

### 1. 仕様をヒアリングする

AskUserQuestion または会話文脈から以下を確定する（Issue に書かれていれば流用し、曖昧な点だけ聞く）:

- **ブロック名**（kebab-case、例: `kb-toc`）→ `saai-knowledge/{name}` になる。
- **タイトル・説明**（block.json の `title`/`description`、日本語の意図を英語で書く）。
- **フロントエンドでインタラクションが要るか**（開閉・タブ切替・ライブ検索など）→ 要るなら
  Interactivity API（`viewScriptModule` + `view.js`）を使う。静的な出力のみなら不要。
- **「現在の記事」に依存するか**（kb-toc / breadcrumbs 型）→ 依存するなら block.json に
  `"usesContext": [ "postId" ]` を宣言し、render.php で `$block->context['postId']` を優先、
  `is_singular( '<cpt>' )` をフォールバックにする（§6-10 参照）。対象外の post type なら
  早期 `return`（render.php は render callback の `require` 内で実行されるため `return` 可）。
- **属性が要るか**（カテゴリー絞り込み・件数・並び順など。DESIGN.md §4.2 の説明を参照）。
  v1 は無属性でも構わない（kb-sidebar もそうだった）。
- **ツリー構築・クエリなど複雑なロジックがあるか** → あるなら `Sidebar_Tree` に倣い
  `includes/class-{Name}.php` にテスト可能な PHP サービスクラスとして分離する。render.php は
  そのクラスの出力を HTML 化するだけに留める。
- **公開フック**が必要か（DESIGN-HOOKS-API.md §3.2/§4 に記載のパターン: `saai_{block}_items`
  フィルター、`saai_{block}_before_*`/`after_*` アクション）。既存の型（node shape 等）と
  一貫性を取る。

### 2. ファイルを生成する

`plugins/saai-knowledge/src/{name}/` に以下を作る（kb-sidebar の実物をテンプレートとして
`plugins/saai-knowledge/src/kb-sidebar/` を必ず読んでから、命名だけ変えて流用する）:

- **block.json** — `apiVersion: 3`、`name: "saai-knowledge/{name}"`、`textdomain: "saai-knowledge"`、
  `editorScript: "file:./index.js"`、`editorStyle: "file:./index.css"`、`render: "file:./render.php"`。
  インタラクションが要る場合のみ `viewScriptModule: "file:./view.js"` と
  `style: "file:./style-view.css"`（`view.js` からの import なのでファイル名は `style-view.css`
  になる。`style-index.css` ではない — §6-2 参照）を追加。要らない場合は `editor.scss` からの
  import だけで済ませ、`style`/`viewScriptModule` フィールド自体を省く。
- **index.js** — `registerBlockType`。`edit` は名前付き関数コンポーネント（`function Edit() {...}`）
  として定義し、無名アロー関数を直接 `edit:` に渡さない（`react-hooks/rules-of-hooks` の
  eslint エラーになる）。`ServerSideRender` でプレビュー。`save: () => null`。
  `import './editor.scss';` を先頭で行う。
- **view.js**（インタラクション要のときのみ） — `@wordpress/interactivity` の `store()`。
  `import './style.scss';` を先頭で行う（block.json の `style` フィールド指定だけでは
  スタイルは自動検出されない）。
- **style.scss** / **editor.scss** — `.saai-` プレフィックスのクラス名。
- **render.php** — `Sidebar_Tree` 相当の PHP サービスクラスを `use` し、出力ロジックのみを持つ。
  ヘルパー関数は procedural に定義するが、**関数ごとに個別の `function_exists()` ガードで囲む**
  （1つの共有ガードで複数関数を囲むと、外部で片方だけ同名定義された場合に両方の宣言が
  スキップされる。§6 参照）。トップレベルの変数名は `$saai_` プレフィックスを付ける
  （PHPCS の `PrefixAllGlobals` が render.php のトップレベルスコープを "global" とみなすため）。
  **公開フィルター（`saai_{block}_items`）の適用はサービスクラス側に置く**（kb-toc の
  `Heading_Anchors::for_display()` 方式）。render.php に置くとフィルター契約（差し替え・
  非配列フォールバック）がユニットテスト不能になる（§6-11 参照）。
- **includes/class-{Name}.php**（ロジック分離する場合） — namespace `SAAI\Knowledge`。
  クラス名は `Post_Types` 形式のアンダースコア区切り PascalCase。ファイル名は
  `class-` + ハイフン区切り小文字（オートローダーの変換規則、CLAUDE.md 参照）。

### 3. ブロックを登録する

`includes/class-blocks.php` の `BLOCKS` 定数配列に `'{name}'` を追加する。他のファイルは
変更不要（`register_blocks()` は配列を汎用的に処理する）。

### 4. テストを書く

`tests/test-{name}.php` に `WP_UnitTestCase` 継承クラスを作る。ロジックを分離した場合は
PHPサービスクラスを直接ユニットテストする（render.php 自体は `require` されるまで procedural
関数が定義されないため、直接テストしにくい — kb-sidebar でも render.php のテストは無く、
`Sidebar_Tree` だけをテストしている。この設計を踏襲する）。

フィルターを `add_filter()` するテストは、既存の `remove_filter()` ペアリング規約
（`test-template-loader.php`, `test-kb-sidebar.php`）に合わせる。`find_node()` 的な
ID探索ヘルパーを書く場合、**タームIDと投稿IDなど別テーブルの自動採番は衝突しうる**ため、
複数タイプが混在するリストを検索する箇所では型も `assertSame` で確認する。

### 5. 検証する

- `vendor/bin/phpcs` / `vendor/bin/phpstan analyse --memory-limit=512M`（PHPStanが
  `apply_filters()` 直前のdocblockから戻り値型を推論する仕様に注意。§6参照）。
- `npm run build`（`WP_EXPERIMENTAL_MODULES=true` は package.json 側で `cross-env` 済みなので
  ブロック単位での追加設定は不要。ビルド後 `plugins/saai-knowledge/build/{name}/` に
  `view.js`/`style-view.css` が出力されているか確認 — 出ていなければ `viewScriptModule` の
  指定漏れ）。
- `npm run lint:js` / `npm run lint:css`。
- 実機確認は `verify-block` スキルの手順で行う（wp-env 起動 → テストデータ作成 →
  フロント curl + REST block-renderer（エディタープレビュー経路）の両方を確認 → データ削除）。
  「現在の記事」依存ブロックはフロント curl だけでは不十分で、block-renderer 経路
  （`is_singular()` が偽）の確認が必須（§6-10 の検出方法）。
- `package-lock.json` や `package.json` を触った場合は、macOSでの `npm ci` 成功を鵜呑みに
  せず `docker run --rm -v "$(pwd)":/work -w /work node:24 bash -c "npm ci"` で
  Linux CI 相当の検証を行う（§6参照、実際に3回再発した問題）。

### 6. 実装前チェックリスト（kb-sidebar の9ラウンドレビューで判明した落とし穴）

コードを書く際、以下を先回りで満たしておく（すべて実際にCopilotレビューで指摘され修正した項目）:

1. **`<button>` の中に `<a>` をネストしない**。開閉トグルとリンクは兄弟要素にする
   （`<button>` 内の `<a>` は無効なHTML）。
2. **`data-wp-bind--X` は初回描画に適用されない**。Interactivity API のバインドディレクティブは
   ハイドレーション後の状態変化にのみ反応する。折りたたみ要素の初期 `hidden` 属性などは
   SSR側で対応する生のHTML属性として出力し、`data-wp-bind--hidden` はその後の状態変化用に
   併記する。
3. **`aria-expanded` を状態にバインドする**（`data-wp-bind--aria-expanded="context.open"`）。
   静的な初期値だけでは2回目のクリックまで実際の状態と乖離する。
4. **トグルボタンには `aria-controls`** を、`wp_unique_id()` で生成した子要素の `id` と
   組みで付与する（WAI-ARIA disclosure pattern）。
5. **公開フィルター（`saai_{block}_items` 等）の戻り値を信用しない**。`is_array()` 等で
   ガードし、非配列ならフィルター前の値にフォールバックする（`saai_template` の既存パターンに
   倣う）。ツリー内の各ノードも `type`/必須キーの欠落・非配列エントリに対して防御的にする。
6. **`is_singular()` は必要なCPTに絞る**。「現在の記事」を判定する際、`saai_category` は
   `saai_faq`/`saai_kb` 双方に付くため、無条件の `is_singular()` は意図しないCPTでも真になる。
   `is_singular( 'saai_kb' )` のように対象を明示する。
7. **N+1クエリを避ける**。ツリー状の構造を組み立てる際、ノードごとに `get_terms()`/`WP_Query`
   を発行しない。全件を1〜2クエリで取得し、PHP側でグルーピングする
   （`Sidebar_Tree::terms_by_parent()`/`kb_posts_by_term()` を参照）。「N+1に見えるが
   WP coreの遅延キャッシュで実質発生しない」ケースもあるため、疑わしい場合は
   `get_num_queries()` で実測してから判断する。
8. **PHPStanの `apply_filters()` docblock推論**: `apply_filters()` 直前のdocblockに書いた
   最初の `@param` 型が、PHPStanにとってその呼び出しの戻り値型として採用される。防御的な
   `is_array()` ガードが「常に真」（`ternary.elseUnreachable`）と誤検知されることがあるが、
   ガード自体は必要なので、理由を添えた `// @phpstan-ignore <identifier>` で抑制する。
9. **`function_exists()` ガードは関数ごとに独立させる**（§2参照）。

以下は kb-toc（PR #34）のレビューで追加された項目:

10. **エディタープレビューを空にしない**。render.php が `is_singular()` / queried object にのみ
    依存すると、ServerSideRender のプレビュー（REST block-renderer 経由、`is_singular()` は偽）が
    常に空になる。`usesContext: [ "postId" ]` + `$block->context['postId']` 優先で解決する
    （block-renderer は post_id パラメータ→グローバル `$post`→`render_block()` の既定コンテキスト
    として postId を供給する）。
11. **フィルター適用はサービスクラスに置く**（§2参照。render.php 内のフィルターはテスト不能）。
12. **`empty()` で表示値を判定しない**。`"0"` というタイトル・IDが falsy として脱落する。
    `is_scalar()` + `'' === (string) $value` で判定する（フィルター経由で配列が混入した場合の
    "Array to string" warning も防げる）。
13. **現在位置表示の a11y**: ページ内ナビの現在項目は `aria-current="location"`（`"true"` ではなく）。
    スクロール系アクションは `prefers-reduced-motion: reduce` で `behavior: 'auto'` に
    フォールバックし、移動先要素へ `tabindex="-1"` + `focus( { preventScroll: true } )` で
    フォーカスを同期する。
14. **view.js のブラウザグローバル**（`document`、`IntersectionObserver` 等）は
    `plugins/saai-knowledge/.eslintrc.js` の `src/**/view.js` override で許可済み。
    lint-js が no-undef を出したらコード側ではなくこの override の対象パターンを確認する。

## 出力スタイル

- ファイル生成前に、対象ブロックの仕様（名前・属性・インタラクション有無・フック）を
  簡潔に確認してから着手する。
- 生成したファイル一覧と、上記チェックリストのうちこのブロックに関係する項目をどう
  満たしたかを短く報告する。
- 検証（lint/phpstan/build/wp-env確認）を実行し、結果を報告する。
- コミット・プッシュは行わず、コミットメッセージ案の提示で止まる。
