---
name: verify-block
description: >
  saai-knowledge/* ブロックを wp-env 実機で検証するスキル。テストデータ作成 →
  フロントエンド curl でのマークアップ確認 → REST block-renderer（エディターの
  ServerSideRender プレビュー経路）確認 → テストデータ削除までを一気通貫で行う。
  「ブロックの動作確認」「実機確認」「レンダリング確認」「verify block」
  「エディタープレビューの確認」といった依頼、および saai-block-scaffold での
  ブロック実装後の検証ステップで使う。引数: ブロック名（例: kb-toc）。
---

# saai-knowledge ブロック実機検証

対象ブロック（引数、例: `kb-toc`）を wp-env 上で実際にレンダリングして確認する。
ユニットテストが通っていても、フロントの `the_content` フィルター連鎖やエディターの
ServerSideRender（REST block-renderer）経路はユニットテストでは踏めないため、
ブロック実装・変更時はこの実機確認まで行う。

## 前提

- 事前に `npm run build` 済みであること（`build/{name}/` にビルド成果物が無いと
  ブロック未登録のまま検証して空振りする）。
- wp-env が未起動なら `npx wp-env start`（このスキルが起動した場合は最後に stop する。
  元から起動していた場合は起動したままにする）。

## 手順

### 1. テストデータを作成する

ブロックの依存に応じて wp-cli で最小構成を作る。ID は後の削除のため控えておく。

```sh
npx wp-env run cli wp term create saai_category "Verify Term" --porcelain
npx wp-env run cli wp post create --post_type=saai_kb --post_title="Verify Post" \
  --post_status=publish --post_content='<コンテンツ>' --porcelain
```

- コンテンツには対象ブロック `<!-- wp:saai-knowledge/{name} /-->` と、そのブロックが
  消費する題材（見出し・カテゴリー割当など）を含める。
- **エッジケースも1つは混ぜる**（例: kb-toc なら重複タイトル見出し・空見出し・
  ネスト見出し。実際にこの構成で重複ID解決のバグ検出実績がある）。
- `wp-env run` は複数語コマンドを `bash -c "..."` で包む（CLAUDE.md 参照）。

### 2. フロントエンドを確認する

```sh
npx wp-env run cli wp post list --post_type=saai_kb --field=url
curl -s <URL> | grep -oE '<対象マークアップのパターン>'
```

確認観点:

- ブロックのラッパー要素と `data-wp-*` ディレクティブが期待どおり出力されているか。
- SSR 要件: 折りたたみ・目次等の中身が初期 HTML に完全に含まれているか（JS の役割は
  状態変化のみ）。
- ブロックと本文の対応（kb-toc なら目次の `#id` と本文側 `<h2 id>` の一致）。

### 3. エディタープレビュー経路（REST block-renderer）を確認する

「現在の記事」に依存するブロック（`usesContext: postId` を使うもの）は必須。
フロント curl では `is_singular()` が真のため、プレビュー経路の退行を検出できない。

リポジトリルートに一時 PHP ファイルを作り、`wp eval-file` で実行する
（リポジトリルートはコンテナに `saai-monorepo/` としてマウント済み。マウントが
見えない場合は `docker cp` でコンテナへ配置してもよい）:

```php
<?php
// tmp-renderer-check.php — ServerSideRender と同じ REST 経路をシミュレート。
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/saai-knowledge/{name}' );
$request->set_param( 'post_id', <投稿ID> );
$request->set_param( 'context', 'edit' );
$response = rest_do_request( $request );
$data     = $response->get_data();
echo isset( $data['rendered'] ) ? $data['rendered'] : wp_json_encode( $data );
```

```sh
npx wp-env run cli bash -c "wp eval-file saai-monorepo/tmp-renderer-check.php"
```

`rendered` が空文字列なら、render.php が `is_singular()` / queried object に依存して
プレビューを描画できていないサイン（saai-block-scaffold §6-10 参照）。

### 4. 片付ける

- 作成した投稿・タームを削除する（`wp post delete <ID> --force` / `wp term delete saai_category <ID>`）。
- 一時 PHP ファイル（`tmp-renderer-check.php`）を削除する。
- このスキルが wp-env を起動した場合のみ `npx wp-env stop` する。

## 出力スタイル

- 各確認（フロント / block-renderer）の実際の出力の要点を引用して合否を報告する。
- 期待と異なる出力が出た場合は、修正せずにまず差異を報告して指示を仰ぐ
  （検証スキルであり、修正は本体タスク側の責務）。
