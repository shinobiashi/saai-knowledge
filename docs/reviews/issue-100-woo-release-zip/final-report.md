# dev-cycle 最終報告: issue-100-woo-release-zip

## 開発内容

- タスク: Issue #100（M5）— 有料版の配布 ZIP を `woo-v<x.y.z>` タグの push で CI がビルド・検証し、draft Release に sha256 と一緒に保管する
- PR: #101 https://github.com/shinobiashi/saai-knowledge/pull/101
- 承認された計画の要約
  - `.github/workflows/release-woo.yml` を新設し、`bin/verify-woo-zip.sh` に `--tag` / `--notes` を足す
  - ドキュメントを直す: CLAUDE.md・build-packaging.md・DESIGN.md・MARKETPLACE-PREFLIGHT.md・release-free スキル
  - 計画の段階でユーザー判断により、「同じ版の ZIP は差し替えずパッチ版を上げる」に変えた（Issue の `-rc.N` 案は取りやめ。Issue の本文も直した）
- コミット

| sha | メッセージ |
| --- | --- |
| af43846 | ci: build the add-on's release ZIP from woo-v tags into a draft release |
| 32786cf | ci: say why another run gives a different add-on ZIP |
| f23fa50 | docs: document the add-on's release ZIP flow |
| 7b0be02 | ci: keep the release token from build steps and check the draft（R1-1〜R1-3） |
| 22b6fff | fix: reject option-like and repeated values in verify-woo-zip.sh（G1-2） |
| 5b15d5f | docs: clear the download directory before fetching the add-on ZIP（G1-1） |
| 55c8c2a | ci: run release-woo one tag at a time（G2-1） |
| 8503bef | fix: stop release-woo on lookup and upload errors（G3-1〜G3-3） |

ほかに記録用の `docs:` コミット（review-loop・PR・実地確認・G1・G2・最終報告）がある。

- 設計ドキュメントからの逸脱: なし。DESIGN.md §6.3 に配布 ZIP の保管を、§8 に有料版のタグを書き足した

## 実地確認（PR ブランチに試しタグ。ユーザーが一括で承認）

| 対象 | タグ | 結果 |
| --- | --- | --- |
| 4dd6eec | `woo-v1.0.0` | success（run 44963096304）。動いたのは `release-woo.yml` だけで、`release.yml` は動いていない。draft に ZIP と `.sha256` が付き、本文の先頭に CAUTION（compare: ahead）。ダウンロードした ZIP は sha256 一致・`verify-woo-zip.sh --tag` も OK。添付の URL（`untagged-…`）は匿名で 404 |
| 4dd6eec | `woo-v9.9.9` | verify の手順で版数違いの失敗（run 44963493423）。Release はできない |
| 8503bef | `woo-v1.0.0` | ゲートの修正後に再確認。success（run 44990225260）。既存 Release の確認がランナーの gh でも `release not found` で先へ進んだ。draft・添付 2 つ（sha256 一致）・匿名で 404 |

試しの draft Release とタグはすべて消した（`woo-v*` のタグも Release も残っていない）。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
| --- | --- | --- | --- |
| R1 | Medium 3・Low 7・対象外 1 | 3（R1-1〜R1-3） | 8（Low 7・R1-X1） |
| R2 | 新規なし（R1 の 3 件すべて解消） | 0 | 0 |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
| --- | --- | --- | --- | --- | --- |
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G2 | Copilot | 1 | 1 | 0 | 未収束 |
| G3 | Copilot | 3 | 3 | 0 | 上限（3 回目でも新規指摘あり。修正は push して CI を確認、再依頼なし） |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
| --- | --- | --- | --- | --- | --- |
| G1-1 | Copilot | Medium（R1-L7 から引き上げ） | 提出する ZIP の手順が、前回のファイルが残っていると失敗する（1.0.1 以降のたびに） | 5b15d5f | https://github.com/shinobiashi/saai-knowledge/pull/101#discussion_r4655398548（Resolve 済み） |
| G1-2 | Copilot | Medium（R1-L4 から引き上げ） | オプションの値が別のオプション名でも拒否されない | 22b6fff | レビュー本文（スレッドなし） |
| G2-1 | Copilot | Medium（R1-L6 から引き上げ） | タグ単位の `concurrency` が無く、同じ版に draft が 2 つできうる | 55c8c2a | レビュー本文 |
| G3-1 | Copilot | Medium（R1-L1 から引き上げ） | 既存 Release の確認が API・認証のエラーも「なし」と読む | 8503bef | レビュー本文 |
| G3-2 | Copilot | Medium（R1-L2 から引き上げ） | 添付の失敗で作りかけの draft が残り、再実行が止まる | 8503bef | レビュー本文 |
| G3-3 | Copilot | Medium（R1-L3 から引き上げ） | bash 3.2 で `--notes` の書き込み失敗を見逃す | 8503bef | レビュー本文 |

### 修正しなかった指摘

なし（PR 上の未解決スレッドは 0）。backlog に残っているのは R1-L5（シンボリックリンク経由の `--notes`、Low）と R1-X1（無料版 `release.yml` の同じトークン露出、Medium、差分範囲外）。

## 品質ゲート

- CI: green（8503bef: [JS Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/44983045005)・[PHP Quality](https://github.com/shinobiashi/saai-knowledge/actions/runs/44983045006)）
- 品質チェック: `composer verify`（PHPCS / PHPStan / PHPUnit 649 件）green。PHP / JS は変えていない。shellcheck・actionlint はクリーン

## 次にできること（人間の判断）

- G3-2（作りかけの draft を消す処理）は、失敗を起こしての実測をしていない。ロジックの確認だけ
- R1-X1（無料版 `release.yml` のトークン露出）を Issue にするか
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- マージ後の #24 では、版数を揃えた main のコミットに `woo-v1.0.0` を付けて push し、draft Release の ZIP を提出する（`docs/MARKETPLACE-PREFLIGHT.md` の「提出する ZIP」節）
