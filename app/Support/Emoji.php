<?php

namespace App\Support;

/** Emoji used as post reactions: any single emoji, plus a few suggested ones. */
final class Emoji
{
    /** Shown first in the picker: beer mug, fire, star, confetti, "yummy" face. */
    public const SUGGESTED = ['🍺', '🔥', '⭐', '🎉', '😋'];

    /** Longest emoji sequences (ZWJ families, flags with tags) stay well under this. */
    public const MAX_BYTES = 64;

    /**
     * One emoji: a flag (two regional indicators), or a pictograph with optional
     * presentation selector / skin tone / tag sequence, joined by ZWJ to more of them.
     */
    private const SINGLE = '/^(?:\p{Regional_Indicator}{2}'
        .'|\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*[\x{E0020}-\x{E007F}]*'
        .'(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*)*)$/u';

    /**
     * The canonical form of $input when it is exactly one emoji, null otherwise.
     *
     * Keyboards differ in the U+FE0F (emoji presentation) selector they send, so
     * "❤" and "❤️" would count as two reactions; it is dropped and added back
     * only where the emoji needs it to render as a picture.
     */
    public static function normalize(string $input): ?string
    {
        $value = trim($input);

        if ($value === '' || strlen($value) > self::MAX_BYTES || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        // Exactly one emoji. Spelled out because PCRE's \X doesn't split "🍺🍺" and intl isn't installed.
        if (! preg_match(self::SINGLE, $value)) {
            return null;
        }

        // Only single-symbol emoji are normalized: sequences (❤️‍🔥, 👨‍👩‍👧) need their selectors in place.
        $base = str_replace("\u{FE0F}", '', $value);

        if (mb_strlen($base) !== 1) {
            return $value;
        }

        return preg_match('/\p{Emoji_Presentation}/u', $base) ? $base : $base."\u{FE0F}";
    }
}
