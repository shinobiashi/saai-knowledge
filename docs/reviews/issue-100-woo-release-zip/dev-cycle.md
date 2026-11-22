# dev-cycle 状態: issue-100-woo-release-zip

- CI: あり
- 記録: docs/reviews/issue-100-woo-release-zip/
- タスク: Issue #100（M5）— 有料版の配布 ZIP を `woo-v<x.y.z>` タグの push で CI がビルド・検証し、draft Release に sha256 と一緒に保管する（`release-woo.yml` 新設、`bin/verify-woo-zip.sh` に `--tag` / `--notes`、ドキュメント）
- 開始: 2026-11-22
- PR: #101 https://github.com/shinobiashi/saai-knowledge/pull/101
- 現在のステップ: **完了**（マージ待ち）
- モード: `auto-commit`（確認ゲートなし。ラウンド報告のみ）、Codex は `--request-codex`
- Copilot: 依頼 3 回 / 上限（G3 でも新規指摘あり。すべて修正）
- Codex: 依頼 1 回 / 収束（G1 で新規指摘なし）

## ログ

| 日時(JST) | ステップ | 内容 |
| --- | --- | --- |
| 2026-11-22 | 0 | main 60958e4 から開始（Opus 5.5、auto-commit はユーザー選択）。作業ツリー clean |
| 2026-11-22 | 1 | 計画承認。計画中にユーザー判断で方針を変更: 同じ版の ZIP は差し替えずパッチ版を上げる（Issue の `-rc.N` 案は、最終タグでビルドし直した ZIP がアップロードした ZIP と一致しないため取りやめ）。実地確認は PR ブランチに試しタグ `woo-v1.0.0`（成功）・`woo-v9.9.9`（失敗）を push して行い、push・削除はそのつど承認を取る |
| 2026-11-22 | 2 | 実装コミット 3 件（af43846 ワークフロー + スクリプト、32786cf ワークフローのコメント、f23fa50 ドキュメント）。Issue #100 の本文を新方針に更新。ローカル確認: `verify-woo-zip.sh` を引数なし・`--tag woo-v1.0.0`（OK）・`woo-v1.0.1`（版数違い）・`v1.0.0` / `woo-v1.0` / `woo-v1.0.0-rc.1`（形式違い）・値なしの `--tag` / `--notes`・不明なオプション・ZIP 2 つ（usage、exit 2）で確認。`--notes` の SHA-256 は `shasum -a 256` と一致。同じファイルから ZIP を組み直すとバイト単位で一致する（時刻はファイルの更新時刻）ことを確認し、コメントと build-packaging.md の説明を「CI は run ごとに時刻が変わる」に直した。shellcheck・actionlint クリーン。品質チェック green（`composer verify`: PHPCS / PHPStan / PHPUnit 649 件） |
| 2026-11-22 | 3 | review-loop R1: APPROVE（Critical / High なし。自己レビュー + 独立レビュー〔Fable〕）。Medium 3 件を 7b0be02 で修正: R1-1 トークンを gh の手順だけに渡し checkout は `persist-credentials: false`、R1-2 最後の確認を「draft・添付 2 つ・Release から取った各添付の URL が匿名で 404」に（タグ名の URL は Release が無くても 404 だった）、R1-3 main に無いコミットのタグは警告 + 本文の先頭に CAUTION。Low 7 件・範囲外 1 件（R1-X1、無料版 `release.yml` の同じトークン露出、Medium）を backlog へ。R2: APPROVE（3 件解消・新規なし。独立検証〔Sonnet〕）。ループ終了 |
| 2026-11-22 | 4 | 初回 push（HEAD 7fe48b6、T=2026-11-21T22:46:50Z）、PR #101 作成 |
| 2026-11-22 | 5 | CI green（4dd6eec、2 workflow run）。実地確認（ユーザーが一括承認）: 4dd6eec に `woo-v1.0.0` を push → `release-woo.yml` success（run 44963096304、`release.yml` は動かず）、draft に ZIP と `.sha256`、本文の先頭に CAUTION（compare: ahead）、ダウンロードした ZIP が sha256 一致・`verify-woo-zip.sh --tag` OK、draft の添付 URL（`untagged-…`）はログインなしで 404。`woo-v9.9.9` → verify で版数違いの失敗、Release なし（run 44963493423）。draft と両タグを削除。結果を PR 本文に記録 |
| 2026-11-22 | 6-7 | G1: CI green の後に両 bot へ依頼（T=2026-11-22T02:32:29Z）。Codex は指摘なし（収束）。Copilot は 2 件: G1-1（PR 作成直後の自動レビューのスレッド。提出する ZIP の手順が再実行で失敗、R1-L7）、G1-2（レビュー本文。オプションの値の検証、R1-L4）。どちらも Medium に引き上げて修正（22b6fff・5b15d5f）、スレッドに返信して Resolve、まとめのコメントを投稿。backlog の R1-L4・R1-L7 を解消に |
| 2026-11-22 | 6-7 | G2: Copilot のみに依頼（T=2026-11-22T02:47:52Z）。新規 1 件（G2-1、previously missed: タグ単位の `concurrency` が無い、R1-L6）を Medium に引き上げて修正（55c8c2a）、まとめのコメントを投稿。G1-1 は resolved since last review。backlog の R1-L6 を解消に |
| 2026-11-22 | 6-7 | G3: Copilot のみに依頼（T=2026-11-22T03:29:11Z、3 回目）。レビュー本文の見出しが 3 件（R1-L1〜L3 と同じ）。エラー処理の不足として Medium に引き上げて修正（8503bef）、まとめのコメントを投稿。backlog の R1-L1〜L3 を解消に。CI green（8503bef） |
| 2026-11-22 | 7 | ゲートの修正でワークフローが変わったので、ユーザーの承認を取って 8503bef に `woo-v1.0.0` を push して再確認。success（run 44990225260）、既存 Release の確認がランナーの gh でも通った。draft とタグを削除 |
| 2026-11-22 | 8 | 最終報告（final-report.md） |
