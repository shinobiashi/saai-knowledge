# 詳細設計: 用語自動リンクエンジン

親ドキュメント: `DESIGN.md` 4.3 / 対象フェーズ: M3 / ステータス: Draft v1

本文中に登場する用語集の用語を自動検出し、ツールチップ付きリンクに置換するエンジンの詳細設計。**日本語には単語境界（`\b`）が存在しない**ため、マッチング戦略が本設計の中心課題。

## 1. 要件（再掲 + 詳細化）

- 対象: 設定で指定した post type（既定: `post`, `page`, `saai_kb`, `saai_faq`）の `the_content`。
- 用語のタイトルと別表記（`saai_synonyms`）にマッチ。**同一用語は初出のみ**、1記事あたり最大リンク数（既定 20、設定可）。
- 除外: 見出し（h1–h6）・`a`・`code`/`pre`/`kbd`/`samp`・`script`/`style`・`button`・自身の用語ページ・`saai_no_autolink` メタが立っている投稿。
- 出力: 用語ページへのリンク + ツールチップ（excerpt）。HTMLを壊さない。エスケープ徹底。
- パフォーマンス: キャッシュヒット時の追加コスト目安 +10ms 以内（用語500件）。

## 2. 辞書（Dictionary）

### 2.1 エントリー形状（公開契約 — `saai_autolink_dictionary` フィルターの入出力）

```php
[
    'post_id'  => 123,               // 用語投稿ID
    'url'      => 'https://.../glossary/ssl/',
    'label'    => 'SSL',             // 正規名（タイトル）
    'patterns' => [ 'SSL', 'Secure Sockets Layer' ], // タイトル + synonyms
    'excerpt'  => '通信を暗号化する…', // ツールチップ本文（プレーンテキスト）
]
```

### 2.2 構築と正規化

- 公開中（`publish`）の `saai_glossary` 全件から構築。
- 正規化: NFKC 相当（`Normalizer` クラスが使える場合。なければ全角英数→半角の簡易変換にフォールバック）+ ラテン文字は case-insensitive 比較。**本文側とパターン側を同じ正規化関数に通す**（実装は1関数に集約: `Normalizer::normalize()` ラッパー）。
- パターンは**文字数の降順（longest-first）**で並べる。「WooCommerce Subscriptions」と「WooCommerce」が両方あるとき長い方が先に勝つ。
- 上限: 辞書は 2,000 パターンまで（超過分は文字数降順で切り捨て + 管理画面に注意表示）。

### 2.3 キャッシュ

| レイヤー | 実装 | 無効化 |
| --- | --- | --- |
| 辞書 | オプション `saai_autolink_dict`（autoload: no）+ オブジェクトキャッシュ。世代番号 `saai_dict_generation` を併せて保存 | `save_post_saai_glossary` / 用語の削除・ステータス変更で世代番号をインクリメントし再構築 |
| 処理済み本文 | オブジェクトキャッシュのみ（キー: `saai_al_{post_id}_{hash(世代番号 + post_modified_gmt + 設定rev)}`） | キー自体が世代を含むため明示削除不要 |

処理済み本文をポストメタに永続化**しない**（コンテンツの二重保存・stale リスク回避）。永続オブジェクトキャッシュがない環境では毎回計算になるが、エンジン単体で十分速くする（§5）。

## 3. マッチング戦略（本設計の核心）

### 3.1 スクリプト境界ヒューリスティック

`\b` の代替として、**マッチ候補の両端の文字と、その外側に隣接する文字の「文字種クラス」が同じ場合はマッチを棄却**する。

| 文字種クラス | 定義（Unicode プロパティ） | 例 |
| --- | --- | --- |
| `latin` | `[\p{Latin}\p{N}_]` | SSL, API, HTTP2 |
| `kanji` | `\p{Han}` | 保証, 決済 |
| `katakana` | `[\p{Katakana}ー]` | クーポン, サーバー |
| `hiragana` | `\p{Hiragana}` | （用語の端に来ることは稀） |

判定ルール:

1. **latin 端**: 隣接が latin なら棄却。`\b` 相当（「API」は「APIs」「WEBAPI」にマッチしない）。
2. **kanji 端**: 隣接が kanji なら棄却。→「保証」は「保証書」「品質保証」に**マッチしない**（複合語の一部と判断）。「保証は」「保証を」にはマッチする（助詞はひらがな = 別クラス）。
3. **katakana 端**: 隣接が katakana・長音符なら棄却。→「クーポン」は「クーポンコード」にマッチしない。
4. **hiragana 端**: 棄却**しない**（例:「お問い合わせ」+ 助詞「は」を棄却すると再現率が下がりすぎるため）。

誤検出と取りこぼしのバランスは完璧にはならないため、逃げ道を用意する:

- 「保証書」も用語として登録されていれば longest-first で正しくそちらが勝つ（利用者への推奨運用としてドキュメント化）。
- サイト単位の調整: `saai_autolink_match_rejected` フィルター（マッチ棄却の最終判定を上書き可能）。
- 投稿単位: `saai_no_autolink` メタ。

### 3.2 走査アルゴリズム

1. パターンを先頭文字の文字種でグループ化し、グループごとに **1本の合成正規表現**（`u` フラグ、longest-first の alternation、`preg_quote` 済み）にコンパイルする。latin グループのみ `i` フラグ。
2. テキストセグメント（§4）ごとに `preg_match_all( PREG_OFFSET_CAPTURE )` で候補を収集 → スクリプト境界判定 → 採用リストへ。
3. 採用済み範囲と重なる候補は棄却（同一セグメント内の重複防止）。用語ごとの初出管理は post 全体で1つの `used[post_id]` セットで行う。
4. 採用候補を**後ろから前へ**置換（オフセットずれ防止）。`mb_*` ではなくバイトオフセット（PREG_OFFSET_CAPTURE はバイト単位）で `substr_replace`。

### 3.3 置換後のマークアップ

```html
<a href="{url}" class="saai-term"
   data-wp-interactive="saai-knowledge/tooltip"
   data-wp-init="callbacks.initTooltipListeners"
   data-wp-on--mouseenter="actions.show" data-wp-on--focus="actions.show"
   data-wp-on--mouseleave="actions.hide" data-wp-on--blur="actions.hide"
   data-wp-on--touchstart="actions.handleTouchStart"
   data-wp-on--click="actions.handleClick"
   data-saai-term-id="{post_id}" data-saai-tooltip="{excerpt}"
   aria-describedby="saai-tooltip">{元のテキストそのまま}</a>
```

- ツールチップ本体はページに **1つのシングルトン要素**（`#saai-tooltip`, `role="tooltip"`, `hidden` 属性で初期非表示）を footer に出力し（`Tooltip` サービス、`wp_footer` の**既定優先度**）、表示時に該当用語の excerpt を差し込む（excerpt は `data-saai-tooltip` 属性に `esc_attr` で埋め込み。JSON を script タグで持たない）。footer 出力・スクリプトモジュール（`saai-knowledge/tooltip`）・スタイルの enqueue は、`Autolinker::has_rendered_links()`（そのリクエストで実際にリンクを1件でも生成したか）が true の場合のみ行う — ほとんどのページは自動リンクを生成しないため。優先度は既定のまま据え置く: WordPress core 自身がこの enqueue を実際に印字する `WP_Script_Modules::print_enqueued_script_modules()` とスタイルの late-capture（`script-loader.php`）はいずれも `wp_footer` の既定〜優先度20に固定されており、より遅い優先度から enqueue するとどちらの印字経路にも間に合わず出力自体が消える（実機検証で確認済み）。
- `data-wp-on--touchstart="actions.handleTouchStart"` + `data-wp-on--click="actions.handleClick"`: 一部のモバイルブラウザは1回のタップで `mouseenter`/`focus` も合成発火するため、クリック時点の「ツールチップが非表示か」だけでは実際のタップ起点かを判定できない。`touchstart`（実タップにしか発火せず、常に `click` より先に届く）でアンカーに一時マークを付け（750ms で自己失効。タッチがスクロール/ドラッグに化けて `click` が来ない場合の残留対策）、`click` はそのマークの有無で「タッチの1タップ目（`preventDefault()` して表示のみ）」「タッチの2タップ目（マークが既に消費済み→遷移）」「マウス/キーボード（マークなし→常に即遷移）」を判別する。1タップ目で表示済みマーク（`saaiTapConfirmed`）を立てる際も5秒で自己失効させる — タッチ環境では `mouseleave`/`blur` が確実に発火するとは限らず（触れた後スクロールで離れる等）、失効させないと「表示済み」状態が残り続け、しばらく後に戻ってきた1タップ目が誤って「2タップ目」として即遷移してしまう。
- `data-wp-init="callbacks.initTooltipListeners"`: ページ内のどれか1つの用語リンクがハイドレートした時点で、`document` への Esc キー（`keydown`）リスナーを1度だけ登録する（モジュールスコープのフラグで重複登録を防止）。押下時はシングルトン要素を非表示に戻す。
- シングルトン要素の `id` はアンカーの `data-saai-term-id`（用語の投稿ID）から生成しない — 同じ用語が複数記事から自動リンクされるページ（アーカイブ等）では同一 `data-saai-term-id` を持つアンカーが複数存在しうるため、代わりにページ全体で1つのカウンターから発番する（DOM上の既存idとの衝突もチェックする）。
- ツールチップはビューポートの上下左右いずれもはみ出さないようクランプする（横方向は `left` を再計算、縦方向は下に収まらない場合のみアンカー上側へフリップ）。ビューポート幅によるキャップは `positionTooltip()` が `document.documentElement.clientWidth` から `max-width` をインラインで設定して行う（CSS側の `max-width: 20rem` はJS実行前の静的フォールバックに過ぎない）。CSS `vw` 単位は使わない — `100vw` は縦スクロールバーの占有幅を含むため、スクロールバーがある環境では `clientWidth` と食い違い、幅キャップが横方向クランプの想定より広くなってしまう。
- excerpt が空（本文もタイトルのみのスタブ用語等）の場合、`show()` は何もしない（空のバブルを出さない）。`handleClick` も同じ条件でタップをインターセプトしない — インターセプトだけしてツールチップを出さないと、遷移もツールチップ表示もされない行き止まりになるため。
- アニメーションは行わない（`hidden` 属性による表示/非表示の切り替えのみ）ため `prefers-reduced-motion` を考慮する対象がない。

## 4. HTML 安全な走査（タグを壊さない）

`WP_HTML_Tag_Processor` はテキストノードの置換 API を持たないため、**軽量トークナイザー**方式を採用:

1. `preg_split( '/(<(?:[^"\'>]++|"[^"]*+"|\'[^\']*+\')*+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE )` でタグ/テキストのセグメント列に分割。単純な `[^>]*+` は引用符付き属性値内の `>`（例: `<span title="x > API">`）もタグ終端と誤認し、属性値の残りをテキストセグメントとして誤って走査対象にしてしまう（属性値内へのリンク挿入によるマークアップ破壊）ため、引用符を認識する形にする。
2. タグセグメントで除外要素の**深度スタック**を管理（`<code>` で push、`</code>` で pop。void 要素・自己終了は無視）。`a` タグも同様にスタック管理（リンク内リンク防止）。
3. スタックが空のテキストセグメントのみ §3.2 の走査対象にする。
4. コメント・CDATA・`<script>`/`<style>` の中身はタグとして分割されないケースがあるため、事前に `<script>…</script>`・`<style>…</style>`・`<!-- … -->` をプレースホルダー退避 → 最後に復元。

実装時に `WP_HTML_Processor` にテキスト置換 API が追加されていればそちらへ移行する（M3 着手時に再確認。トークナイザーは interface `Saai_Content_Walker` の背後に隠して差し替え可能にする）。

注意: テキストセグメントには HTML エンティティ（`&amp;` 等）が生のまま含まれる。エンティティを含む用語（例: `AT&T`）対応として、パターン側のみ `&` → `&amp;` 等の変換を掛けた変種も alternation に含める（本文側はデコードしない — オフセットが狂うため）。

## 5. 実行フローとパフォーマンス

```text
the_content (priority 50)
  └ 早期 return 判定（軽い順）:
      is_admin() / feed / REST → skip
      対象 post type でない → skip
      saai_no_autolink → skip
      自身が saai_glossary → skip
      辞書が空 → skip
      オブジェクトキャッシュ hit → キャッシュ返却
  └ エンジン実行 → キャッシュ保存 → 返却
```

- 計測基準（M3 完了条件）: 用語500件・本文 3,000 文字で、キャッシュミス時 50ms 以内 / ヒット時 1ms 以内。
- 正規表現は 1 リクエスト内で static キャッシュ（コンパイル1回）。
- `preg_*` が `PREG_BACKTRACK_LIMIT_ERROR` 等で失敗したら**元の本文をそのまま返す**（絶対に本文を失わない）。`preg_last_error()` をチェックし、失敗時のみ `error_log`。

## 6. 有料版との接続

- 商品ページ（`product` post type）は無料版の対象外。有料版が `saai_autolink_post_types` フィルターで `product` を追加し、`saai_autolink_dictionary` で「その商品に紐づく用語のみ」に辞書を絞り込む（context に `post_id` / `post_type` が渡る）。
- WooCommerce の説明文フィルター（short description 等）への適用は有料版側で該当フィルターに同エンジンを接続（エンジンは `Saai_Autolinker::process( string $html, array $context ): string` として単体で呼べる公開サービスにする）。

## 7. テスト計画（PHPUnit）

| カテゴリ | ケース |
| --- | --- |
| マッチング | latin 境界（API / APIs）、kanji 境界（保証 / 保証書 / 保証は）、katakana 境界（クーポン / クーポンコード）、longest-first（WooCommerce Subscriptions vs WooCommerce）、初出のみ、最大リンク数、全角/半角・大文字小文字の正規化 |
| HTML 安全性 | 見出し・リンク内・code/pre 内で置換されない、属性値内のテキストが対象にならない、script/style/コメント退避、壊れた HTML 入力で例外を出さない |
| キャッシュ | 用語保存で世代番号が上がり再構築される、キャッシュキーが post 更新で変わる |
| フェイルセーフ | preg エラー時に原文が返る、辞書 0 件で no-op |
| パフォーマンス | 500用語 × 3,000字の実行時間アサーション（CI では閾値緩め） |
