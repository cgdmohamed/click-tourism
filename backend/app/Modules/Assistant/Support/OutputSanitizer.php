<?php

namespace App\Modules\Assistant\Support;

/**
 * Makes the model's answer safe to show: plain text only. No HTML, no markdown images or links
 * and no external URLs survive (blueprint threat T3). Record links are never taken from the
 * model: the client builds them from the server-built citations.
 */
final class OutputSanitizer
{
    public function clean(string $answer, int $maxChars): string
    {
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $answer);

        // Markdown images and links keep their label only (the target is where data would leak).
        $text = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text);

        // HTML: drop script/style bodies, then every tag.
        $text = (string) preg_replace('#<(script|style|iframe|object|embed)\b.*?(</\1\s*>|$)#is', '', $text);
        $text = strip_tags($text);

        // Anything that still looks like an external address or a dangerous scheme.
        $text = (string) preg_replace('~\b(?:https?|ftp|file|data|javascript|vbscript):[^\s)>\]]*~iu', '[link removed]', $text);
        $text = (string) preg_replace('~(?<![\w.])(?:www\.|//)[^\s)>\]]+~iu', '[link removed]', $text);

        $text = trim($text);

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).'…' : $text;
    }
}
