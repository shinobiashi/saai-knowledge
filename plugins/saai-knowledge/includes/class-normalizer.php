<?php
/**
 * Shared text normalization for kana/width folding, used by the glossary
 * index (bucket/sort keys) and reserved for the autolink engine's dictionary
 * matching (docs/DESIGN-AUTOLINK.md section 2.2).
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes Japanese/Latin text for comparison: width folding (fullwidth
 * Latin/digits <-> halfwidth katakana) via NFKC, and katakana/voiced-mark/
 * small-kana folding down to base hiragana.
 */
final class Normalizer {

	/**
	 * Katakana (including voiced, semi-voiced, and small forms) and small/
	 * archaic hiragana mapped to their base hiragana row character.
	 *
	 * Each key is a single, complete UTF-8 character, so strtr() with this
	 * table is byte-safe. `ん` and `を` are deliberately absent — they are
	 * their own glossary-index bucket entries, not folded into another row.
	 *
	 * @var array<string, string>
	 */
	private const KANA_FOLD = array(
		// Katakana, plain.
		'ア' => 'あ',
		'イ' => 'い',
		'ウ' => 'う',
		'エ' => 'え',
		'オ' => 'お',
		'カ' => 'か',
		'キ' => 'き',
		'ク' => 'く',
		'ケ' => 'け',
		'コ' => 'こ',
		'サ' => 'さ',
		'シ' => 'し',
		'ス' => 'す',
		'セ' => 'せ',
		'ソ' => 'そ',
		'タ' => 'た',
		'チ' => 'ち',
		'ツ' => 'つ',
		'テ' => 'て',
		'ト' => 'と',
		'ナ' => 'な',
		'ニ' => 'に',
		'ヌ' => 'ぬ',
		'ネ' => 'ね',
		'ノ' => 'の',
		'ハ' => 'は',
		'ヒ' => 'ひ',
		'フ' => 'ふ',
		'ヘ' => 'へ',
		'ホ' => 'ほ',
		'マ' => 'ま',
		'ミ' => 'み',
		'ム' => 'む',
		'メ' => 'め',
		'モ' => 'も',
		'ヤ' => 'や',
		'ユ' => 'ゆ',
		'ヨ' => 'よ',
		'ラ' => 'ら',
		'リ' => 'り',
		'ル' => 'る',
		'レ' => 'れ',
		'ロ' => 'ろ',
		'ワ' => 'わ',
		'ヲ' => 'を',
		'ン' => 'ん',
		// Katakana, voiced (dakuten).
		'ガ' => 'か',
		'ギ' => 'き',
		'グ' => 'く',
		'ゲ' => 'け',
		'ゴ' => 'こ',
		'ザ' => 'さ',
		'ジ' => 'し',
		'ズ' => 'す',
		'ゼ' => 'せ',
		'ゾ' => 'そ',
		'ダ' => 'た',
		'ヂ' => 'ち',
		'ヅ' => 'つ',
		'デ' => 'て',
		'ド' => 'と',
		'バ' => 'は',
		'ビ' => 'ひ',
		'ブ' => 'ふ',
		'ベ' => 'へ',
		'ボ' => 'ほ',
		'ヴ' => 'う',
		// Katakana, semi-voiced (handakuten).
		'パ' => 'は',
		'ピ' => 'ひ',
		'プ' => 'ふ',
		'ペ' => 'へ',
		'ポ' => 'ほ',
		// Katakana, small.
		'ァ' => 'あ',
		'ィ' => 'い',
		'ゥ' => 'う',
		'ェ' => 'え',
		'ォ' => 'お',
		'ッ' => 'つ',
		'ャ' => 'や',
		'ュ' => 'ゆ',
		'ョ' => 'よ',
		'ヮ' => 'わ',
		'ヵ' => 'か',
		'ヶ' => 'け',
		// Katakana, archaic wi/we.
		'ヰ' => 'い',
		'ヱ' => 'え',
		// Hiragana, voiced (dakuten).
		'が' => 'か',
		'ぎ' => 'き',
		'ぐ' => 'く',
		'げ' => 'け',
		'ご' => 'こ',
		'ざ' => 'さ',
		'じ' => 'し',
		'ず' => 'す',
		'ぜ' => 'せ',
		'ぞ' => 'そ',
		'だ' => 'た',
		'ぢ' => 'ち',
		'づ' => 'つ',
		'で' => 'て',
		'ど' => 'と',
		'ば' => 'は',
		'び' => 'ひ',
		'ぶ' => 'ふ',
		'べ' => 'へ',
		'ぼ' => 'ほ',
		'ゔ' => 'う',
		// Hiragana, semi-voiced (handakuten).
		'ぱ' => 'は',
		'ぴ' => 'ひ',
		'ぷ' => 'ふ',
		'ぺ' => 'へ',
		'ぽ' => 'ほ',
		// Hiragana, small.
		'ぁ' => 'あ',
		'ぃ' => 'い',
		'ぅ' => 'う',
		'ぇ' => 'え',
		'ぉ' => 'お',
		'っ' => 'つ',
		'ゃ' => 'や',
		'ゅ' => 'ゆ',
		'ょ' => 'よ',
		'ゎ' => 'わ',
		'ゕ' => 'か',
		'ゖ' => 'け',
		// Hiragana, archaic wi/we.
		'ゐ' => 'い',
		'ゑ' => 'え',
	);

	/**
	 * A generated fullwidth-ASCII -> halfwidth map, used only when neither
	 * the intl extension nor mbstring's mb_convert_kana() is available.
	 *
	 * Built once on first use rather than as a class constant: PHP constants
	 * can't be built from a loop, and hand-writing 63 entries invites a typo
	 * that a generated table can't have.
	 *
	 * @var array<string, string>|null
	 */
	private static $fallback_width_map = null;

	/**
	 * NFKC-equivalent normalization: fullwidth Latin/digits/punctuation and
	 * halfwidth katakana are folded to their standard-width form.
	 *
	 * Prefers the intl extension's actual Unicode NFKC when available, since
	 * it's the authoritative implementation; mb_convert_kana() is a
	 * mbstring-based approximation covering the same practical cases
	 * (fullwidth ASCII <-> halfwidth, halfwidth katakana -> fullwidth
	 * katakana with voiced marks merged); normalize_fallback() is a last
	 * resort covering only the common fullwidth-ASCII case.
	 *
	 * Deliberately calls the procedural normalizer_normalize() rather than
	 * `\Normalizer::normalize()`: inside this namespace a bare `Normalizer::`
	 * resolves to this very class, not intl's — the same pitfall
	 * WordPress's own remove_accents() sidesteps by using the procedural
	 * form.
	 *
	 * @param string $text Text to normalize.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		if ( function_exists( 'normalizer_normalize' ) ) {
			$normalized = normalizer_normalize( $text, \Normalizer::FORM_KC );

			if ( is_string( $normalized ) ) {
				return $normalized;
			}
		}

		if ( function_exists( 'mb_convert_kana' ) ) {
			// 'a': fullwidth alphanumerics -> halfwidth. 's': fullwidth
			// space -> halfwidth. 'K': halfwidth katakana -> fullwidth
			// katakana. 'V': merge halfwidth katakana voiced-mark sequences
			// into one fullwidth voiced character first, so 'K' doesn't
			// leave a dangling combining mark. Katakana -> hiragana folding
			// is intentionally NOT done here; fold_kana() owns it, so both
			// this branch and the intl branch converge on the same
			// downstream input.
			return mb_convert_kana( $text, 'asKV', 'UTF-8' );
		}

		return self::normalize_fallback( $text );
	}

	/**
	 * Best-effort width folding used only when neither intl nor mbstring's
	 * mb_convert_kana() is available — covers just fullwidth Latin letters,
	 * digits, and the fullwidth space.
	 *
	 * A public, separately callable method (rather than inlined into
	 * normalize()) so it has direct test coverage: the extensions it's a
	 * fallback for can't be unloaded at test run time to exercise this path
	 * through normalize() itself.
	 *
	 * @internal
	 *
	 * @param string $text Text to normalize.
	 * @return string
	 */
	public static function normalize_fallback( string $text ): string {
		return strtr( $text, self::fallback_width_map() );
	}

	/**
	 * Folds katakana (plain, voiced, semi-voiced, small) and small/archaic
	 * hiragana down to their base hiragana row character.
	 *
	 * @param string $text Text to fold, ideally already normalize()'d.
	 * @return string
	 */
	public static function fold_kana( string $text ): string {
		return strtr( $text, self::KANA_FOLD );
	}

	/**
	 * Builds (and memoizes) the fullwidth-ASCII -> halfwidth map used by
	 * normalize_fallback().
	 *
	 * Encodes each fullwidth codepoint's UTF-8 bytes by hand (rather than
	 * mb_chr()): this whole method only runs when mbstring itself is
	 * unavailable (see normalize()'s fallback chain), so it can't depend on
	 * any mbstring function either. Every codepoint in this range
	 * (U+FF01-U+FF5E) falls in the 3-byte UTF-8 encoding block.
	 *
	 * @return array<string, string>
	 */
	private static function fallback_width_map(): array {
		if ( null !== self::$fallback_width_map ) {
			return self::$fallback_width_map;
		}

		$map = array( "\xE3\x80\x80" => ' ' ); // U+3000 IDEOGRAPHIC SPACE -> halfwidth space.

		// U+FF01-U+FF5E is the fullwidth block mirroring ASCII 0x21-0x7E one
		// for one (fullwidth '!' through '~'); each fullwidth codepoint is
		// its halfwidth ASCII codepoint + 0xFEE0.
		for ( $code = 0xFF01; $code <= 0xFF5E; $code++ ) {
			$bytes = chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );

			$map[ $bytes ] = chr( $code - 0xFEE0 );
		}

		self::$fallback_width_map = $map;

		return $map;
	}
}
