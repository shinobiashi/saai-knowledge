---
name: release-free
description: >
  無料版 saai-knowledge を WordPress.org へリリースするスキル。版数を上げる `bump`、
  リリース前の確認と準備（最新の WordPress / WooCommerce への対応・テスト・配布 ZIP の
  Plugin Check・Changelog と Upgrade Notice）の `prep`、マージ → `v` 付きタグ →
  GitHub Release → `deploy-wporg.yml` → 公開の確認までの `ship`、公開後の確認だけの
  `verify` の4モード。「1.0.4 をリリース」「リリースの準備」「版数を上げて」
  「最新版に対応しているか確認」「WordPress.org にデプロイ」といった依頼で使う。
  共有スキル release-bump はこのモノレポに合わないので使わない（CLAUDE.md のリリース節）。
  有料版（WooCommerce.com Marketplace）は対象外。
argument-hint: "bump <x.y.z> | prep <x.y.z> | ship <x.y.z> | verify <x.y.z>"
---

# 無料版のリリース（WordPress.org）

1.0.2・1.0.3 を手作業でリリースしたときの流れを手順にしたもの。版数の照合・最新版との比較・
配布 ZIP の検証・公開の確認といった機械的な部分は `scripts/release-free.sh` に寄せ、判断の要る部分
（Changelog と Upgrade Notice の文、どの項目を次の版に回すか、`Tested up to` を上げるか、
タグを push する瞬間）だけをユーザーと相談する。

## 権限

CLAUDE.md のとおり、commit・push・PR の作成・マージ・タグの push はユーザーの明示的な指示があるまで
行わない。**スキルの起動はこれらの指示とみなさない。** 次の段で AskUserQuestion を使って承認を取り、
承認された範囲だけを実行する。

| 段 | 承認を取る操作 |
| --- | --- |
| `prep` | Issue の起票（リリース準備用・次の版に回す項目用）、作業ブランチの commit・push・PR の作成 |
| `ship` | PR のマージ、`v<x.y.z>` タグの push、`deploy-wporg.yml` の実行（1 回の質問にまとめてよい。1.0.3 は「デプロイまで進める」で一括承認だった） |

- タグの push には PreToolUse フック（`~/.claude/hooks/guard-tag-push.py`）でも確認が出る。
- リモートのタグの削除は、デプロイに失敗したときだけ、理由を示して確認を取ってから行う。

## スクリプト

```sh
R=.claude/skills/release-free/scripts/release-free.sh
"$R" bump <x.y.z>            # 3 箇所の版数を書き換え、Changelog に空の見出しを足す
"$R" check <x.y.z> [--tag]   # 3 箇所の版数・Changelog・Upgrade Notice（300 文字以内）・タグの有無
                             # --tag: main 上・未コミットなし・HEAD = origin/main・HEAD の CI が success
"$R" latest [--env]          # 最新の WordPress / WooCommerce とヘッダーの比較（L-2 も計算する）
                             # --env: wp-env の dev・tests の版数も照合する
"$R" strings [<前のタグ>]    # 前のタグ以降に足された翻訳関数の行
"$R" zip                     # build → 配布 ZIP → dist/free-check に展開 → 中身と版数 → Plugin Check
"$R" verify <x.y.z>          # GitHub Release の ZIP・SVN の trunk と tags・plugins API・ダウンロード
```

- 出力は `OK` / `FAIL` / `WARN` / `INFO` の行で、`FAIL` が 1 つでもあれば exit 1。
- 書き換えるのは `bump` だけ（`zip` は gitignore 済みの `saai-knowledge.zip` と `dist/` に書く）。
- `zip` の Plugin Check は wp-env の dev（`cli`）で走らせる。`wp plugin check` はエラーがあっても
  exit 0 で、メッセージはサイトの言語（dev は日本語）になるので、`--format=csv` の type 列で判定する。

## モード

引数の最初の語で決める。省略されたら状態から推定して確認する（リリース準備の PR が open なら `ship`、
main が最後のタグより先に進んでいれば `prep`）。

### `bump <x.y.z>` — 版数を上げる

前回のリリースのあと、利用者に見える変更を最初に入れる PR で版数を上げる（1.0.2 は #69、1.0.3 は #76 の
修正 PR で上げた）。このモード自体はコミットしない（コミットはその PR の作業の一部）。

1. `"$R" bump <x.y.z>`。`Version:` ヘッダー・`SAAI_KNOWLEDGE_VERSION`・`Stable tag` を書き換え、
   `== Changelog ==` の直後に `= <x.y.z> =` を足す
2. 見出しのすぐ下に、その PR の Changelog の行を書く。既存の行の書式（`* Fixed: …` / `* Changed: …`、
   英語、内部名ではなく利用者から見た変化）に合わせる
3. `git diff` が本体と `readme.txt` の版数の行と Changelog だけであることを確かめる

- Upgrade Notice はここでは書かない。`prep` でその版の全体を見てから書く（それまで `check` は FAIL する）。
- `phpstan-bootstrap.php` の `SAAI_KNOWLEDGE_VERSION`（静的解析用のスタブ）と有料版の
  `SAAI_WOO_MIN_BASE_VERSION`（アドオンが要求する無料版の最低版数）は上げない。

### `prep <x.y.z>` — リリース前の確認と準備

1. **状態**: main 上・未コミットなし・origin と一致していることを確かめる。`git log --oneline v<前の版>..origin/main`
   でこの版に入る変更を、`gh pr list --state open` でマージ待ちの修正 PR が無いかを見る
2. **範囲の判断（停止）**: `docs/review-backlog.md` で解消していない Medium 以上の項目（起票待ち・判断待ち）と、
   open な bug の Issue を拾う。この版に入れるか次の版に回すかを AskUserQuestion で確認する。回すなら Issue を
   起票し、backlog の状態欄に書く（1.0.3 は R2-X1・R2-X2 を #94 で 1.0.4 に回した）
3. **最新版**: `npx wp-env start --update` で起動し直してから `"$R" latest --env`。`--update` を付けないと、
   前に取得した WooCommerce を使い回す（1.0.3 のときは 11.2.0 のままだった）
   - `WARN` が出たら `Tested up to`（x.y で書く）、有料版の `WC tested up to` と `WC requires at least`
     （L-2）を上げるかを判断する。有料版を変えるなら、本体ヘッダー・readme・`SAAI_WOO_MIN_WC_VERSION` を揃え、
     有料版の ZIP を組んで `bin/verify-woo-zip.sh` で確かめる
   - 依存している WooCommerce の関数（`.claude/rules/woocommerce.md`）が最新版にも残っているかを見る
4. **テスト**: `composer verify`（lint・PHPStan・PHPUnit）。PHPUnit は tests サイトの DB を作り直すので、
   E2E の前に有効化・テーマ・パーマリンクを戻す（`.claude/rules/testing.md`）:

   ```sh
   npx wp-env run tests-cli wp plugin activate woocommerce saai-knowledge saai-knowledge-for-woocommerce
   npx wp-env run tests-cli wp theme activate twentytwentyfive
   bash bin/wp-env-configure-permalinks.sh
   npm run test:e2e
   ```

5. **配布 ZIP**: `"$R" zip`。Plugin Check はエラー 0 が条件。警告は 1 件ずつ判断する
6. **翻訳**: `"$R" strings`。翻訳文字列が増えていれば、言語パックが追いつくまで英語で出ることを報告に書く
7. **readme.txt**:
   - `= <x.y.z> =` の Changelog を通して読む。利用者から見た変化になっているか、重複、並び順を確かめる
     （「in the same Markdown」のように前の行を受ける書き方なら、その連なりを切らない位置に足す）
   - Upgrade Notice を起草する（300 文字以内。直したことの要点と、更新後に利用者がすること）
   - AskUserQuestion で文を承認してもらう（停止）
8. **照合**: `"$R" check <x.y.z>` が `DONE` になること
9. **PR（停止）**: 変更があれば、承認を取ってから Issue（「無料版 <x.y.z> のリリース準備」）→ ブランチ
   `issue-<n>-release-<x-y-z>` → commit → push → PR（本文に `Closes #<n>`）の順に進める。CI（`ci-js.yml` の
   E2E を含む）が green になるのを待つ。`readme.txt` は配布物なので main へ直接コミットしない
10. **報告**: 互換確認の表（最新版・各テスト・Plugin Check・CI）、ヘッダーを上げたかどうか、翻訳、次は `ship`

### `ship <x.y.z>` — マージからデプロイ、公開の確認まで

1. **承認（停止）**: AskUserQuestion で「PR #<n> をマージ → マージコミットに `v<x.y.z>` を打って push →
   `deploy-wporg.yml` を実行」「マージとタグまで」「中断」から選んでもらう。準備の PR が無ければ、タグから始める
2. **マージ**: `gh pr merge <n> --merge`（このリポジトリはマージコミット）→ `git switch main && git pull`
3. **マージコミットの CI**: `gh api "repos/{owner}/{repo}/actions/runs?head_sha=$(git rev-parse HEAD)"` で run を
   見つけ、`gh run watch <id> --exit-status` を背景で待つ。完了したら `"$R" check <x.y.z> --tag` が `DONE`
   になることを確かめる
4. **タグ**: 注釈付きで打って push する

   ```sh
   git tag -a v<x.y.z> -m "SAAI Knowledge <x.y.z>" <sha>
   git push origin v<x.y.z>
   ```

5. **GitHub Release**: `gh run list --workflow release.yml --limit 1` で run を見つけて `gh run watch <id> --exit-status`。
   `gh release view v<x.y.z>` で `saai-knowledge.zip` が付いたことを確かめる
6. **デプロイ**: `gh workflow run deploy-wporg.yml -f version=<x.y.z>`。`gh run list --workflow deploy-wporg.yml --limit 1`
   で run を見つけて背景で待ち、`gh run view <id> --log | grep -E 'Committed revision'` で SVN のリビジョンを控える
7. **公開の確認**: `"$R" verify <x.y.z>`。plugins API とダウンロードの `WARN` は、WordPress.org 側の反映待ち
   （最大 24 時間）。数時間おきに `verify` を回し、遅いだけで再デプロイしない
8. **後始末と報告**: 準備の PR のブランチは `/post-merge` で片付ける。報告には Release の URL、SVN のリビジョン、
   plugins API の版数、Issue の状態、残っていること（次の版の Issue、言語パック）を書く

失敗したとき:

- `release.yml` が落ちた: Release も SVN へのデプロイもまだ無い。直す修正が main に要るなら、修正のマージ後に
  タグを打ち直す（リモートのタグの削除は確認を取ってから）
- `deploy-wporg.yml` が `Stable tag` の照合で落ちた: タグの時点の `readme.txt` が違う。直してタグを打ち直す
  （`check --tag` を通していれば起きない）
- `deploy-wporg.yml` が SVN の段で落ちた（secrets・ネットワーク）: ログで SVN に何がコミットされたかを確かめてから
  `gh run rerun <id>`（同じタグをもう一度デプロイする）

### `verify <x.y.z>` — 公開後の確認だけ

`"$R" verify <x.y.z>` を実行し、結果を表にして報告する。

## 注意（このリポジトリ固有）

- **タグは `v` 付きの注釈付き**: `release.yml` は `v*` の push で走り、`deploy-wporg.yml` は `v${version}` を
  checkout する。共有スキル `release-bump` の `tag` は `v` 無しで打つので使わない。
- **タグの push だけでは WordPress.org に載らない**: `deploy-wporg.yml` を手動で実行する。
- **`deploy-wporg.yml` が照合するのは `Stable tag` だけ**: `SAAI_KNOWLEDGE_VERSION` を上げ忘れると、自動更新時の
  rewrite flush が黙ってスキップされる（CLAUDE.md のリリース節）。タグの前に `check` で 3 箇所を照合する。
- **配布物は ZIP を展開したもの**: `deploy-wporg.yml` は展開した ZIP を SVN の trunk に同期する。Plugin Check も
  ソースではなく展開した ZIP にかける（`.claude/rules/build-packaging.md`）。
- **有料版は対象外**: Marketplace へのアップロードは `bin/verify-woo-zip.sh` と `docs/MARKETPLACE-PREFLIGHT.md`。

## 最終報告の形

```markdown
## release-free <モード>: <x.y.z>
- 互換: WordPress <ver> / WooCommerce <ver>（Tested up to: <x.y>、WC tested up to: <x.y>）
- テスト: PHPUnit <n> / E2E <n> / Plugin Check エラー <n>・警告 <n> / CI <n> ジョブ
- PR / Release: <URL>
- SVN: r<rev>（tags/<x.y.z>）/ plugins API: <ver>
- 翻訳: 追加 <n> 行
- 次: ship <x.y.z>（または 残っていること）
```
