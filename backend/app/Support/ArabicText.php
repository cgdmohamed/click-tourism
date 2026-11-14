<?php

namespace App\Support;

/**
 * Arabic shaping and visual reordering for the PDF renderer.
 *
 * Dompdf draws glyphs left to right and neither joins Arabic letters nor runs
 * the bidirectional algorithm. This class converts logical-order text into
 * visual-order text made of Arabic Presentation Forms-B code points, which the
 * bundled DejaVu Sans font contains. It is used only for PDF output; CSV and
 * XLSX keep the logical text.
 *
 * Scope: Modern Standard Arabic letters, lam-alef ligatures and tashkeel.
 * Persian/Urdu-only letters are left unshaped.
 */
final class ArabicText
{
    /** base => [isolated, final, initial, medial] */
    private const FORMS = [
        0x0621 => [0xFE80, null, null, null],
        0x0622 => [0xFE81, 0xFE82, null, null],
        0x0623 => [0xFE83, 0xFE84, null, null],
        0x0624 => [0xFE85, 0xFE86, null, null],
        0x0625 => [0xFE87, 0xFE88, null, null],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E, null, null],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94, null, null],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA, null, null],
        0x0630 => [0xFEAB, 0xFEAC, null, null],
        0x0631 => [0xFEAD, 0xFEAE, null, null],
        0x0632 => [0xFEAF, 0xFEB0, null, null],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE, null, null],
        0x0649 => [0xFEEF, 0xFEF0, null, null],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** alef variant following a lam => [isolated, final] ligature */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<'];

    public static function hasArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    /**
     * Converts logical text to the visual order a left-to-right renderer needs.
     * Text without Arabic is returned unchanged.
     *
     * @param  bool|null  $rtlBase  paragraph direction; null picks it from the first strong character
     *                              (Arabic letter => right to left, Latin letter => left to right)
     */
    public static function visual(string $text, ?bool $rtlBase = null): string
    {
        if ($text === '' || ! self::hasArabic($text)) {
            return $text;
        }

        $rtlBase ??= preg_match('/^[^\p{L}]*(\p{Arabic})/u', $text) === 1;

        $clusters = self::clusters(self::shape($text));
        $dirs = self::resolve($clusters, $rtlBase);

        $runs = [];
        foreach ($clusters as $i => $cluster) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['rtl'] === $dirs[$i]) {
                $runs[$last]['items'][] = $cluster;
            } else {
                $runs[] = ['rtl' => $dirs[$i], 'items' => [$cluster]];
            }
        }

        if ($rtlBase) {
            $runs = array_reverse($runs);
        }

        $out = '';
        foreach ($runs as $run) {
            $items = $run['rtl'] ? array_reverse($run['items']) : $run['items'];
            foreach ($items as $item) {
                $out .= $run['rtl'] ? (self::MIRROR[$item] ?? $item) : $item;
            }
        }

        return $out;
    }

    /**
     * Splits text on spaces into lines of at most $max characters (logical order),
     * so each line can be reordered on its own.
     *
     * @return array<int, string>
     */
    public static function wrap(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max || ! str_contains($text, ' ')) {
            return [$text];
        }

        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && mb_strlen($candidate) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** Applies contextual forms and lam-alef ligatures; result is still logical order. */
    public static function shape(string $text): string
    {
        $cps = array_map(static fn (string $c): int => mb_ord($c), mb_str_split($text));
        $n = count($cps);
        $items = [];

        for ($i = 0; $i < $n; $i++) {
            $cp = $cps[$i];

            if (self::isMark($cp)) {
                $items[] = ['cp' => $cp, 'mark' => true];
            } elseif ($cp === 0x0640) {
                $items[] = ['cp' => $cp, 'join' => true, 'dual' => true, 'tatweel' => true];
            } elseif (! isset(self::FORMS[$cp])) {
                $items[] = ['cp' => $cp];
            } elseif ($cp === 0x0644 && $i + 1 < $n && isset(self::LAM_ALEF[$cps[$i + 1]])) {
                $items[] = ['cp' => $cp, 'join' => true, 'dual' => true, 'lig' => $cps[$i + 1]];
                $i++;
            } else {
                $items[] = ['cp' => $cp, 'join' => true, 'dual' => self::FORMS[$cp][2] !== null];
            }
        }

        $result = '';
        foreach ($items as $k => $item) {
            if (empty($item['join'])) {
                $result .= mb_chr($item['cp']);

                continue;
            }
            if (! empty($item['tatweel'])) {
                $result .= mb_chr(0x0640);

                continue;
            }

            $prev = self::neighbour($items, $k, -1);
            $next = self::neighbour($items, $k, 1);
            $joinPrev = $prev !== null && ! empty($prev['dual']);
            $joinNext = ! empty($item['dual']) && $next !== null && ! empty($next['join']);

            if (isset($item['lig'])) {
                // The ligature ends in an alef, so it never joins forward.
                [$iso, $fin] = self::LAM_ALEF[$item['lig']];
                $result .= mb_chr($joinPrev ? $fin : $iso);

                continue;
            }

            [$iso, $fin, $ini, $med] = self::FORMS[$item['cp']];
            $form = match (true) {
                $joinPrev && $joinNext && $med !== null => $med,
                $joinPrev && $fin !== null => $fin,
                $joinNext && $ini !== null => $ini,
                default => $iso,
            };
            $result .= mb_chr($form);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private static function neighbour(array $items, int $index, int $step): ?array
    {
        for ($j = $index + $step; $j >= 0 && $j < count($items); $j += $step) {
            if (! empty($items[$j]['mark'])) {
                continue;
            }

            return $items[$j];
        }

        return null;
    }

    private static function isMark(int $cp): bool
    {
        return ($cp >= 0x064B && $cp <= 0x065F) || $cp === 0x0670 || ($cp >= 0x06D6 && $cp <= 0x06ED);
    }

    /** @return array<int, string> a base character followed by its combining marks */
    private static function clusters(string $text): array
    {
        $clusters = [];
        foreach (mb_str_split($text) as $char) {
            if ($clusters !== [] && self::isMark(mb_ord($char))) {
                $clusters[count($clusters) - 1] .= $char;
            } else {
                $clusters[] = $char;
            }
        }

        return $clusters;
    }

    /**
     * Returns, per cluster, whether it is displayed right to left.
     *
     * @param  array<int, string>  $clusters
     * @return array<int, bool>
     */
    private static function resolve(array $clusters, bool $rtlBase): array
    {
        $n = count($clusters);
        $class = array_map(self::classify(...), $clusters);

        // Separators between digits stay inside the number.
        for ($i = 1; $i < $n - 1; $i++) {
            if ($class[$i] === 'N' && in_array($clusters[$i], ['.', ',', ':', '/', '-'], true)
                && $class[$i - 1] === 'D' && $class[$i + 1] === 'D') {
                $class[$i] = 'D';
            }
        }

        // A sign directly before a number belongs to it (+20 2 3333 4444, -150).
        for ($i = 0; $i < $n - 1; $i++) {
            if ($class[$i] === 'N' && in_array($clusters[$i], ['+', '-'], true) && $class[$i + 1] === 'D'
                && ($i === 0 || $class[$i - 1] !== 'D')) {
                $class[$i] = 'D';
            }
        }

        $dir = [];
        foreach ($class as $i => $c) {
            $dir[$i] = match ($c) {
                'R' => true,
                'L', 'D' => false,
                default => null,
            };
        }

        // A neutral run takes the direction of the strong text around it when both sides agree.
        for ($i = 0; $i < $n; $i++) {
            if ($dir[$i] !== null) {
                continue;
            }
            $start = $i;
            while ($i < $n && $dir[$i] === null) {
                $i++;
            }
            $before = $start > 0 ? $dir[$start - 1] : null;
            $after = $i < $n ? $dir[$i] : null;
            $value = $before !== null && $before === $after ? $before : $rtlBase;
            for ($k = $start; $k < $i; $k++) {
                $dir[$k] = $value;
            }
            $i--;
        }

        return $dir;
    }

    private static function classify(string $cluster): string
    {
        $cp = mb_ord(mb_substr($cluster, 0, 1));

        if (($cp >= 0x30 && $cp <= 0x39) || ($cp >= 0x660 && $cp <= 0x669) || ($cp >= 0x6F0 && $cp <= 0x6F9)) {
            return 'D';
        }
        if (($cp >= 0x600 && $cp <= 0x6FF) || ($cp >= 0xFB50 && $cp <= 0xFDFF) || ($cp >= 0xFE70 && $cp <= 0xFEFF)) {
            return 'R';
        }
        if (preg_match('/^[\p{L}\p{N}]/u', $cluster) === 1) {
            return 'L';
        }

        return 'N';
    }
}
