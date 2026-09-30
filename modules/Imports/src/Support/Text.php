<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

final class Text
{
    /**
     * HTML from a rich-text editor as plain text with paragraph breaks kept.
     */
    public static function plain(?string $html, int $max): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $text = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $text) ?? $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
