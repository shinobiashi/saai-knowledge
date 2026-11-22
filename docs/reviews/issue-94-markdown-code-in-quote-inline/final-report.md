# dev-cycle 最終報告: issue-94-markdown-code-in-quote-inline

## 開発内容

- タスク: Issue #94（無料版）— Markdown 出力で、引用の中（R2-X1）とインライン構文の中（R2-X2）のコードブロックがコードとして読まれず、コード例の `<script>` が生の HTML になる不具合を直す
- PR: #102 https://github.com/shinobiashi/saai-knowledge/pull/102
- 承認された計画の要約
  - 方針: リスト項目・表のセルですでに使っている「1 行のコードスパン」に揃える
  - 引用の中の `<pre>` は `render_inline_code()` のコードスパンにする（ユーザー判断。インデントのコードは、リストの後・畳まれる場所・入れ子の引用で生の HTML になるため不採用）
  - インライン構文と見出しの要素が `<pre>` を含むときは、中身の空白を畳む
  - リンク・強調は、コードを含んでも前に空行を補わない
  - 無料版を 1.0.4 に上げる
- コミット
  - 8ed9445 fix: write code blocks in quotes and inline syntax as code spans
  - 0101c74 chore: bump SAAI Knowledge to 1.0.4
  - a4648c7 docs: record backlog and dev-cycle state for #94
  - 4d386cd fix: keep empty code and href-less anchors from breaking code spans（review-loop R1）
  - a1b240b docs: record review-loop R1 for #94
  - 036b965 test: pin the empty-pre guard between quoted paragraphs（review-loop R2）
  - 36de14d docs: record review-loop R2 for #94
  - 10c80b7 docs: record dev-cycle gate round 1
- 設計ドキュメントからの逸脱
  - Issue の対象（`a` / `strong` / `em` / `summary`）に見出しを加えた（計画で明示し承認済み）。`<h2><pre>…` は 5 描画系すべてで生の `<script>` だった
  - 空のインライン `<code>`（R1-X1、差分範囲外の既存不具合）は、R1-1 と修正箇所が同じ（`render_inline_code()`）なので一緒に直した
  - DESIGN.md §7.3 は計画どおり更新済み

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | High 2・Medium 2（自己レビュー + 独立レビュー〔Fable〕） | 4（R1-1〜R1-4） | 対象外 4（R1-X1 は R1-1 で解消、R1-X2・R1-X3〔Medium〕・R1-X4〔Low〕） |
| R2 | Medium 1（テストの欠落）・Low 1。APPROVE | 1（R2-2） | 1（R2-1、Low） |

- R1-1: 引用の中の空の `<pre>` が `` `` `` になり、畳まれた行で後ろのコードと対になる。この差分による退行で、5 系で生の `<script>` になった
- R1-2: `href` の無い `<a>` の裸のコードスパンが、隣のインラインコードと続く。fenced_code の偽の開きフェンスにもなる。こちらも退行
- R1-3: テストの追加
- R1-4: DESIGN・readme の文言

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 0（レビュー本文 2 件。見出しは「Needs a closer look」、本文は「No open issues」） | 0 | 1（G1-1、対応済み） | 収束 |

### 修正した指摘

なし

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
| --- | --- | --- | --- | --- |
| G1-1 | Copilot | — | 具体的な指摘は無い（「No open issues」）。見出しの理由は「この環境ではレンダラーを再実行できないので、実機の出力を人手で確かめてほしい」。その確認は review-loop で 5 描画系・79 ケースについて実施済みで、ラウンドのサマリコメントに結果を書いた | スレッドなし（[レビュー 1](https://github.com/shinobiashi/saai-knowledge/pull/102#pullrequestreview-6011159558)・[レビュー 2](https://github.com/shinobiashi/saai-knowledge/pull/102#pullrequestreview-6011173259)） |

## 品質ゲート

- CI: 36de14d で green（`request-gate-review.sh --wait-ci` で確認）。最後の記録用コミットの CI は push 後に確認する
- 品質チェック: green
  - PHPCS・PHPStan・PHPUnit 655 件（`composer verify`、R1 の修正後）
  - R2 のテスト追加後は、`Test_Markdown_Converter` 54 件とテストファイルの PHPCS
- `bin/markdown-render-check.sh --from-html`: 5 描画系で 79 ケース。残る FAIL は 3 種類だけ
  - 拡張なしの Python-Markdown がトップレベルのフェンスを読まない（既知の制約）
  - チェッカーの誤判定（制御文字を含む入力）
  - 差分範囲外の R1-X2

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
  - 蒸留の候補（`.claude/rules/markdown-export.md`）: 引用の中・1 行の構文の中のコードをコードスパンにする規則、空のコードは何も出さない、`href` の無い `<a>` は `<span>` と同じく通す
- 1.0.4 のリリース前に、次の 2 件を入れるか判断が必要
  - R1-X2（Medium）: 行頭のインラインの `<code>` が、Python-Markdown の fenced_code に偽の開きフェンスとして読まれる
  - R1-X3（Medium）: `<li>` の中でインラインの `<code>` の直後に `<pre>` が続く、または隣り合うインラインの `<code>` 同士で、バッククォートの並びが 1 つになる
  - どちらも main でも生の `<script>` になる既存不具合で、#94 と同じ脅威モデル
  - 入れるなら Issue を起票し、別 PR にする（どちらも `Markdown_Converter` の小さな変更）
- Low の backlog（R1-X4・R2-1・Plan-X1）は、表示の忠実さの問題で生の HTML にはならない
- 両 bot とも G1 で収束したので、後日の `fix-copilot-review` による再確認は不要
