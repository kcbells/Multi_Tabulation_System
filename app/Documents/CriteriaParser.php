<?php
/**
 * Turns free text from a scanned/uploaded criteria sheet into structured criteria.
 *
 * Understands the common layouts of judging criteria:
 *   "Mastery of the piece ........ 40%"      "1. Content (30 points)"
 *   "Creativity – 25"                          "Stage Presence | 20%"   (tables)
 *   "Content\n40%"  and OCR tables where the names column and the
 *   percentage column come out as two separate runs of lines.
 * Lines without a score that follow a criterion become its description.
 * Parent criteria whose lettered sub-items add up to the parent's weight
 * are replaced by the sub-items (judges score the sub-items).
 */
declare(strict_types=1);

namespace App\Documents;

final class CriteriaParser
{
    private const NUM = '(\d{1,3}(?:[.,]\d{1,2})?)';

    /** @return array{criteria: array<int, array{name:string, description:string, max_score:float}>, total: float} */
    public static function parse(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/\n/', $text) as $raw) {
            $tok = self::classify($raw);
            if ($tok !== null) {
                $tokens[] = $tok;
            }
        }

        $items = [];
        $afterTotal = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            switch ($t['type']) {
                case 'total':
                    $afterTotal = true;
                    break;

                case 'crit':
                    $afterTotal = false;
                    $items[] = ['name' => $t['name'], 'description' => $t['desc'], 'max_score' => $t['num'], 'kind' => $t['kind']];
                    break;

                case 'num':
                    break; // orphan number (page numbers etc.)

                case 'text':
                    // Gather a run of text lines followed by a run of number-only lines (split table columns).
                    $j = $i;
                    while ($j < $count && $tokens[$j]['type'] === 'text') { $j++; }
                    $k = $j;
                    while ($k < $count && $tokens[$k]['type'] === 'num') { $k++; }
                    $texts = array_slice($tokens, $i, $j - $i);
                    $nums = array_slice($tokens, $j, $k - $j);

                    if ($nums) {
                        $pairCount = min(count($texts), count($nums));
                        $leading = array_slice($texts, 0, count($texts) - $pairCount);
                        foreach ($leading as $lt) {
                            self::attachDescription($items, $lt, $afterTotal);
                        }
                        $pairedTexts = array_slice($texts, count($texts) - $pairCount);
                        foreach ($pairedTexts as $idx => $pt) {
                            [$name, $desc] = self::splitName($pt['name']);
                            $items[] = ['name' => $name, 'description' => $desc, 'max_score' => $nums[$idx]['num'], 'kind' => $pt['kind']];
                        }
                        $afterTotal = false;
                        $i = $k - 1;
                    } else {
                        foreach ($texts as $lt) {
                            self::attachDescription($items, $lt, $afterTotal);
                        }
                        $i = $j - 1;
                    }
                    break;
            }
        }

        $items = self::collapseSubCriteria($items);

        // Fractions (0.40, 0.30...) -> percentages
        if ($items && max(array_column($items, 'max_score')) <= 1.0) {
            foreach ($items as &$it) { $it['max_score'] = round($it['max_score'] * 100, 2); }
            unset($it);
        }

        $criteria = [];
        foreach ($items as $it) {
            if ($it['max_score'] <= 0 || mb_strlen($it['name']) < 2) {
                continue;
            }
            $criteria[] = [
                'name'        => mb_substr($it['name'], 0, 200),
                'description' => mb_substr(trim($it['description']), 0, 1000),
                'max_score'   => (float) $it['max_score'],
            ];
        }
        return ['criteria' => $criteria, 'total' => round(array_sum(array_column($criteria, 'max_score')), 2)];
    }

    private static function classify(string $raw): ?array
    {
        $line = str_replace(["\t", '|', '¦'], ' | ', $raw);
        $line = preg_replace('/[._·…]{3,}|_{2,}/u', ' ', $line);
        $line = preg_replace('/\s+/u', ' ', $line);
        $line = self::utrim($line, '\s|');
        if ($line === '' || !preg_match('/[\p{L}\d]/u', $line)) {
            return null;
        }

        [$kind, $body] = self::stripEnumerator($line);

        // Signature/footer lines are never criteria
        if (preg_match('/^(prepared|noted|approved|checked|recommending)\s+(by|:)/i', $body)
            || preg_match('/^(signature|signed|judge\'?s?\s+(name|signature)|date|venue|time)\s*(:|_|$)/i', $body)) {
            return ['type' => 'total'];
        }

        $num = null;
        $nameOnly = $body;
        if (preg_match_all('/(?<![\d.,])' . self::NUM . '\s*(%|percent\b|pts?\b\.?|points?\b)/iu', $body, $m, PREG_OFFSET_CAPTURE)) {
            $last = count($m[0]) - 1;
            $num = self::toFloat($m[1][$last][0]);
            $nameOnly = substr($body, 0, $m[0][$last][1]) . substr($body, $m[0][$last][1] + strlen($m[0][$last][0]));
        } elseif (preg_match('/(?:^|[\s\-–—:=|(\[])' . self::NUM . '\s*[)\]]?\s*$/u', $body, $m, PREG_OFFSET_CAPTURE)) {
            $num = self::toFloat($m[1][0]);
            $nameOnly = substr($body, 0, $m[1][1]);
        }

        $isTotal = preg_match('/^\W*(grand\s+)?total\b/iu', $body)
            || (preg_match('/\btotal\b/iu', $body) && $num !== null && abs($num - 100) < 0.01);
        if ($isTotal) {
            return ['type' => 'total'];
        }

        $name = self::cleanName($nameOnly);

        // Column headers such as "Criteria | Percentage"
        $plain = preg_replace('/[\s|]+/', ' ', $name);
        if (preg_match('/^(criteria|criterion|category|categories|percentage|weight|points?|scores?|rubrics?)(\s+(for\s+judging|percentage|weight|points?|scores?|rating|in\s+%|%))*$/iu', $plain)) {
            return null;
        }

        if ($num === null) {
            return ['type' => 'text', 'name' => $name, 'kind' => $kind, 'raw' => $body];
        }
        if ($num > 100 || $num <= 0) {
            return ['type' => 'text', 'name' => $name, 'kind' => $kind, 'raw' => $body];
        }
        if (!preg_match('/\p{L}{2,}/u', $name)) {
            return ['type' => 'num', 'num' => $num];
        }
        // Titles like "Criteria for Judging (100%)" or dates like "September 14"
        if ((abs($num - 100) < 0.01 && preg_match('/criteria|judging|rubric|score\s*sheet/iu', $name))
            || preg_match('/^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?$/iu', $name)) {
            return null;
        }

        [$n, $desc] = self::splitName($name);
        return ['type' => 'crit', 'name' => $n, 'desc' => $desc, 'num' => $num, 'kind' => $kind];
    }

    /** @return array{0:string,1:string} [kind, rest] where kind is num|alpha|bullet|none */
    private static function stripEnumerator(string $line): array
    {
        if (preg_match('/^(?:[\-\*•●▪◦·–—>➢✓✔]+)\s*(.*)$/u', $line, $m)) {
            return ['bullet', $m[1]];
        }
        if (preg_match('/^\(?\d{1,2}\s*[.)]\s+(.*)$/u', $line, $m)) {
            return ['num', $m[1]];
        }
        if (preg_match('/^\(?(?:[IVX]{1,4})\s*[.)]\s+(.*)$/u', $line, $m)) {
            return ['num', $m[1]];
        }
        if (preg_match('/^\(?[a-hA-H]\s*[.)]\s+(.*)$/u', $line, $m)) {
            return ['alpha', $m[1]];
        }
        return ['none', $line];
    }

    private static function cleanName(string $name): string
    {
        $name = preg_replace('/\(\s*\)|\[\s*\]/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = self::utrim($name, '\s|:;=\-–—,.(');
        // Unbalanced closing parenthesis left after removing "(40%)"
        if (substr_count($name, ')') > substr_count($name, '(')) {
            $name = rtrim(preg_replace('/\)\s*$/', '', $name));
        }
        if (substr_count($name, '(') > substr_count($name, ')')) {
            $name = rtrim(preg_replace('/\(\s*$/', '', $name));
        }
        return self::utrim($name, '\s|:;=\-–—,.');
    }

    /** "Mastery: accuracy of lyrics" -> ["Mastery", "accuracy of lyrics"]; "Content (relevance)" likewise. */
    private static function splitName(string $name): array
    {
        $name = self::utrim(preg_replace('/\s*\|\s*/', ' – ', $name), '\s–');
        if (preg_match('/^(.{2,60}?)\s*(?::|\s[-–—]\s)\s*(.{3,})$/u', $name, $m) && preg_match('/\p{L}/u', $m[2])) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^(.{2,60}?)\s*\(([^()]{3,})\)$/u', $name, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$name, ''];
    }

    private static function attachDescription(array &$items, array $tok, bool $afterTotal): void
    {
        if ($afterTotal || !$items) {
            return;
        }
        $idx = count($items) - 1;
        $text = $tok['kind'] === 'bullet' || $tok['kind'] === 'alpha' ? '• ' . $tok['name'] : $tok['name'];
        if (mb_strlen($items[$idx]['description']) > 800) {
            return;
        }
        $items[$idx]['description'] = trim($items[$idx]['description'] . "\n" . $text);
    }

    private static function collapseSubCriteria(array $items): array
    {
        $out = [];
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            $parent = $items[$i];
            $children = [];
            $j = $i + 1;
            while ($j < $n && in_array($items[$j]['kind'], ['alpha', 'bullet'], true) && $items[$j]['kind'] !== $parent['kind']) {
                $children[] = $items[$j];
                $j++;
            }
            if (count($children) >= 2 && abs(array_sum(array_column($children, 'max_score')) - $parent['max_score']) < 0.01) {
                foreach ($children as $child) {
                    $child['description'] = trim($parent['name'] . ($child['description'] !== '' ? ' — ' . $child['description'] : ''));
                    $out[] = $child;
                }
                $i = $j - 1;
                continue;
            }
            $out[] = $parent;
        }
        return $out;
    }

    private static function utrim(string $s, string $class): string
    {
        return (string) preg_replace('/^[' . $class . ']+|[' . $class . ']+$/u', '', $s);
    }

    private static function toFloat(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }
}
