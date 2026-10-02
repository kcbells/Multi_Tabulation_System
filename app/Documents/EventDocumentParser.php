<?php
/**
 * Reads an event memo / program / proposal (scanned or typed) and pulls out:
 *   title, venue, start / end date-time and the list of activities,
 * each activity with a suggested format (score, bracket, round robin, ranking)
 * and Nature of Activity. Everything is a suggestion: the user confirms it.
 *
 * Understands labelled lines ("Title:", "Venue:", "Date:"), month-name and
 * numeric dates with ranges ("September 20–25, 2026", "Oct 5 to Oct 9, 2026",
 * "10/05/2026"), times ("7:30 AM – 5:00 PM"), and activity lists under a
 * heading ("Activities", "Program of Activities", "Competitions", …) written
 * as bullets, numbers or schedule rows ("8:00 AM  Basketball Eliminations").
 */
declare(strict_types=1);

namespace App\Documents;

final class EventDocumentParser
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];
    private const MONTH_RE = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';
    private const TIME_RE = '(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?|nn|noon)';

    /** Lines that are letterhead, signatures or filler, never a title or an activity. */
    private const NOISE = '/^(republic of|phinma|cagayan de oro college|coc\b|office of|memorandum|memo\b|to\s*:|from\s*:|subject\s*:|re\s*:|cc\s*:|prepared by|noted by|approved by|recommending|received by|page \d|www\.|http|tel\.?|email|e-mail|address|max\.?\s*st|carmen)/i';

    /** Program-flow items that are not competitions. */
    private const NOT_ACTIVITY = '/\b(registration|lunch|break|snacks?|merienda|prayer|invocation|national anthem|doxology|opening (program|remarks|ceremony)|welcome (address|remarks)|closing (program|remarks|ceremony)|awarding|intermission|message|introduction of|acknowledg|photo ?op|dismissal|arrival|departure|briefing|orientation|mass\b|parade|motorcade|clean ?up|ingress|egress|recap)\b/i';

    private const HEADINGS = '/^(list of |program of |schedule of |line-?up of |calendar of )?(activities|events|competitions|contests|games|sports events|academic events|cultural events|highlights|line-?up|program(me)?|schedule)\b\s*:?$/i';
    private const END_HEADINGS = '/^(criteria|mechanics|rules|guidelines|general guidelines|budget|committee|committees|objectives?|rationale|expected outputs?|prepared by|noted by|approved by|signatories|awards?|prizes?|requirements|contact)\b/i';

    private const TITLE_WORDS = '/\b(week|festival|fest|day|days|intramurals?|fair|night|pageant|competition|contest|celebration|search for|summit|congress|olympics?|games|league|cup|showdown|convention|anniversary|foundation|acquaintance|sportsfest|expo|exhibit|conference|camp|challenge|mr\.?\s*(and|&)\s*ms)\b/i';

    /**
     * @return array{title:string, venue:string, start_at:?string, end_at:?string,
     *               activities: array<int, array{title:string, format:string, nature:?string}>, found: string[]}
     */
    public static function parse(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $raw) {
            // tabs separate table cells (Word tables, OCR columns); keep them as " | "
            $line = str_replace("\t", ' | ', $raw);
            $line = trim(preg_replace('/[ \x{00A0}]+/u', ' ', $line));
            $line = trim($line, " |");
            $line = preg_replace('/^[•●○▪►✓*·]\s*/u', '- ', $line);
            $lines[] = $line;
        }

        $result = ['title' => '', 'venue' => '', 'start_at' => null, 'end_at' => null, 'head' => '', 'activities' => [], 'judges' => [], 'facilitators' => [], 'found' => []];

        $result['title'] = self::title($lines);
        $result['venue'] = self::venue($lines);
        [$result['start_at'], $result['end_at']] = self::dates($lines);
        $result['head'] = self::head($lines);
        [$result['activities'], $result['judges'], $result['facilitators']] = self::activities($lines, $result['title']);

        foreach (['title' => 'title', 'venue' => 'venue', 'start_at' => 'dates'] as $key => $label) {
            if (!empty($result[$key])) {
                $result['found'][] = $label;
            }
        }
        if ($result['activities']) {
            $result['found'][] = 'activities';
        }
        if (array_filter($result['activities'], fn($a) => $a['criteria'])) {
            $result['found'][] = 'criteria';
        }
        if ($result['head'] !== '') {
            $result['found'][] = 'head';
        }
        if ($result['judges'] || array_filter($result['activities'], fn($a) => $a['judges'])) {
            $result['found'][] = 'judges';
        }
        if ($result['facilitators'] || array_filter($result['activities'], fn($a) => $a['facilitators'])) {
            $result['found'][] = 'facilitators';
        }
        return $result;
    }

    /* ------------------------------------------------------------------ title */

    private static function title(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^(event\s*(title|name)?|title( of (the )?(event|activity))?|name of (the )?(event|activity)|theme|subject|re)\s*[:\-–]\s*(.{4,})$/i', $line, $m)) {
                $value = self::clean(end($m));
                if ($value !== '' && !preg_match('/^(proposal|request|invitation|memorandum)\b/i', $value)) {
                    return self::titleCase($value);
                }
            }
        }
        // strongest candidate: an early line that reads like an event name
        $best = '';
        $bestScore = 0;
        foreach (array_slice($lines, 0, 40) as $i => $line) {
            if (mb_strlen($line) < 5 || mb_strlen($line) > 90 || (preg_match(self::NOISE, $line) && !preg_match(self::TITLE_WORDS, $line)) || preg_match('/[:]\s*$/', $line)) {
                continue;
            }
            if (self::dateIn($line) || preg_match('/^\d/', $line)) {
                continue;
            }
            $score = 0;
            if (preg_match(self::TITLE_WORDS, $line)) $score += 5;
            if (preg_match('/\b20\d{2}\b/', $line)) $score += 2;
            $letters = preg_replace('/[^A-Za-z]/', '', $line);
            if ($letters !== '' && strtoupper($letters) === $letters) $score += 2;
            if (preg_match('/^["“].+["”]$/u', $line)) $score += 1;
            $score -= intdiv($i, 12);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$line, $score];
            }
        }
        return $bestScore >= 4 ? self::titleCase(self::clean($best)) : '';
    }

    /* ------------------------------------------------------------------ venue */

    private static function venue(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^(venue|place|location|where)\s*[:\-–]\s*(.{3,})$/i', $line, $m)) {
                return self::clean($m[2]);
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/\b(?:at|in)\s+(?:the\s+)?((?:[A-Z][\w.\']*\s+){0,5}(?:Gymnasium|Gym|Hall|Covered Court|Court|Auditorium|Oval|Field|Quadrangle|Grounds?|Campus|Theater|Theatre|Room|Center|Centre|Arena|Coliseum|Plaza|Park|Pavilion|Lobby|Library|Chapel))\b/', $line, $m)) {
                return self::clean($m[1]);
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------ dates & times */

    /** @return array{0:?string,1:?string} 'Y-m-d H:i:00' */
    private static function dates(array $lines): array
    {
        $labelled = [];
        $any = [];
        foreach ($lines as $i => $line) {
            if (!self::dateIn($line)) {
                continue;
            }
            $withNext = $line . ' ' . ($lines[$i + 1] ?? '');
            if (preg_match('/^(date|dates|when|schedule|date and time|date & time|inclusive dates?|duration)\s*[:\-–]/i', $line)) {
                $labelled[] = $withNext;
            } elseif (!preg_match('/^(dated?|date prepared|date received)\b/i', $line)) {
                $any[] = $withNext;
            }
        }
        foreach (array_merge($labelled, $any) as $candidate) {
            $range = self::dateRange($candidate);
            if ($range) {
                [$startDate, $endDate] = $range;
                [$startTime, $endTime] = self::times($candidate);
                return [$startDate . ' ' . ($startTime ?? '08:00') . ':00', $endDate . ' ' . ($endTime ?? '17:00') . ':00'];
            }
        }
        return [null, null];
    }

    private static function dateIn(string $line): bool
    {
        return (bool) preg_match('/\b' . self::MONTH_RE . '\s+\d{1,2}\b|\b\d{1,2}\s+' . self::MONTH_RE . '\s+20\d{2}|\b\d{1,2}[\/\-]\d{1,2}[\/\-]20\d{2}\b|\b20\d{2}-\d{2}-\d{2}\b/i', $line);
    }

    /** @return array{0:string,1:string}|null */
    private static function dateRange(string $s): ?array
    {
        $year = preg_match('/\b(20\d{2})\b/', $s, $y) ? (int) $y[1] : (int) date('Y');
        $M = self::MONTH_RE;

        // September 20 – October 2, 2026
        if (preg_match("/\\b$M\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s*(?:20\\d{2})?\\s*(?:-|–|—|to|until|through|thru)\\s*$M\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s*(20\\d{2})?/i", $s, $m)) {
            $endYear = !empty($m[5]) ? (int) $m[5] : $year;
            return self::pair(self::month($m[1]), (int) $m[2], $endYear, self::month($m[3]), (int) $m[4], $endYear);
        }
        // September 20–25, 2026  /  Sept. 20 to 25, 2026
        if (preg_match("/\\b$M\\s+(\\d{1,2})(?:st|nd|rd|th)?\\s*(?:-|–|—|to|until|through|thru|&|and)\\s*(\\d{1,2})(?:st|nd|rd|th)?,?\\s*(20\\d{2})?/i", $s, $m)) {
            $yr = !empty($m[4]) ? (int) $m[4] : $year;
            return self::pair(self::month($m[1]), (int) $m[2], $yr, self::month($m[1]), (int) $m[3], $yr);
        }
        // 20–25 September 2026
        if (preg_match("/\\b(\\d{1,2})\\s*(?:-|–|—|to)\\s*(\\d{1,2})\\s+$M,?\\s*(20\\d{2})?/i", $s, $m)) {
            $yr = !empty($m[4]) ? (int) $m[4] : $year;
            return self::pair(self::month($m[3]), (int) $m[1], $yr, self::month($m[3]), (int) $m[2], $yr);
        }
        // October 5, 2026
        if (preg_match("/\\b$M\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s*(20\\d{2})?/i", $s, $m)) {
            $yr = !empty($m[3]) ? (int) $m[3] : $year;
            return self::pair(self::month($m[1]), (int) $m[2], $yr, self::month($m[1]), (int) $m[2], $yr);
        }
        // 5 October 2026
        if (preg_match("/\\b(\\d{1,2})\\s+$M,?\\s*(20\\d{2})/i", $s, $m)) {
            return self::pair(self::month($m[2]), (int) $m[1], (int) $m[3], self::month($m[2]), (int) $m[1], (int) $m[3]);
        }
        // 2026-10-05 (– 2026-10-09)
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b(?:\s*(?:-|–|to)\s*(20\d{2})-(\d{2})-(\d{2}))?/', $s, $m)) {
            return !empty($m[4])
                ? self::pair((int) $m[2], (int) $m[3], (int) $m[1], (int) $m[5], (int) $m[6], (int) $m[4])
                : self::pair((int) $m[2], (int) $m[3], (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[1]);
        }
        // 10/05/2026 (– 10/09/2026), month first as used in PH memos
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](20\d{2})\b(?:\s*(?:-|–|to)\s*(\d{1,2})[\/\-](\d{1,2})[\/\-](20\d{2}))?/', $s, $m)) {
            return !empty($m[4])
                ? self::pair((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6])
                : self::pair((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return null;
    }

    private static function pair(int $m1, int $d1, int $y1, int $m2, int $d2, int $y2): ?array
    {
        if (!checkdate($m1, $d1, $y1) || !checkdate($m2, $d2, $y2)) {
            return null;
        }
        $start = sprintf('%04d-%02d-%02d', $y1, $m1, $d1);
        $end = sprintf('%04d-%02d-%02d', $y2, $m2, $d2);
        return $end < $start ? [$start, $start] : [$start, $end];
    }

    private static function month(string $name): int
    {
        return self::MONTHS[strtolower(substr($name, 0, 3))] ?? 1;
    }

    /** @return array{0:?string,1:?string} 'H:i' */
    private static function times(string $s): array
    {
        preg_match_all('/\b' . self::TIME_RE . '/i', $s, $all, PREG_SET_ORDER);
        $times = array_map(function ($m) {
            $h = (int) $m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $mer = strtolower(str_replace('.', '', $m[3]));
            if ($mer === 'nn' || $mer === 'noon') {
                $h = 12;
            } elseif ($mer === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($mer === 'am' && $h === 12) {
                $h = 0;
            }
            return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : null;
        }, $all);
        $times = array_values(array_filter($times));
        return [$times[0] ?? null, count($times) > 1 ? end($times) : null];
    }

    /* ------------------------------------------------------------------ activities (with category, criteria, segments, scoring) */

    private const CATEGORY_WORDS = 'e-?sports?|electronic sports|sports?|athletics|pageant(?:ry|s)?|beauty pageants?|cultural|culture and arts|arts?|visual arts|performing arts|literary|literary[- ]musical|literary arts|music(?:al)?|dance|academics?|academic contests?|quiz(?:zes)?|technology|ict|information technology|it|wellness|fitness|leadership|religious|spiritual|special events?|major events?|minor events?|indoor games|outdoor games|games|socio-?cultural|entertainment|community|outreach';

    private const LABEL_CRITERIA = '/^(?:criteria(?:\s+(?:for|of)\s+(?:judging|scoring|evaluation))?|judging\s+criteria|scoring\s+criteria|criteria\s+and\s+(?:weights?|percentage)|rubrics?|scoring\s+rubrics?|basis\s+(?:of|for)\s+(?:judging|scoring|evaluation)|judging|evaluation)\s*(?:\(\s*100\s*%\s*\))?\s*[:\-–—]?\s*(.*)$/iu';
    private const LABEL_SEGMENTS = '/^(?:segments?|rounds?|portions?|parts?|phases?|stages?|categories\s+of\s+(?:the\s+)?(?:pageant|competition|contest)|pageant\s+segments?|competition\s+proper)\s*[:\-–—]\s*(.*)$|^(?:segments?|rounds?|portions?)\s*$/iu';
    private const LABEL_SCORING = '/^(?:scoring(?:\s+(?:system|method|scheme|guidelines?))?|point\s+system|points?\s+system|format|game\s+format|tournament\s+format|mode\s+of\s+(?:play|competition)|mechanics|system|elimination|how\s+(?:it\s+is|to)\s+(?:decided|win))\s*[:\-–—]\s*(.*)$|^(?:scoring(?:\s+system)?|mechanics)\s*$/iu';
    private const LABEL_JUDGES = '/^(?:(?:board|panel|list|roster)\s+of\s+judges|judges?|adjudicators?|jurors?|jury|judging\s+panel)(?:\s+for\s+[^:]{1,60})?\s*[:\-–—]\s*(.*)$|^(?:(?:board|panel|list|roster)\s+of\s+judges|judges|judging\s+panel|adjudicators)\s*$/iu';
    private const LABEL_FACILITATORS = '/^(?:facilitators?|game\s+facilitators?|marshals?|game\s+masters?|scorekeepers?|score\s*keepers?|scorers?|tabulators?|referees?|umpires?|officiating\s+officials?|technical\s+officials?|in-?charge(?:\s+of\s+(?:the\s+)?(?:activity|game|contest|event))?|activity\s+in-?charge)(?:\s+for\s+[^:]{1,60})?\s*[:\-–—]\s*(.*)$|^(?:facilitators|marshals|game\s+masters|referees|technical\s+officials|tabulators)\s*$/iu';
    private const LABEL_CATEGORY = '/^(?:category|categories|cluster|division|event\s+type|type\s+of\s+(?:event|activity|contest)|area|classification)\s*[:\-–—]\s*(.+)$/iu';
    private const LABEL_ACTIVITY = '/^(?:activity|activity\s+name|event\s+name|contest|competition|game|title\s+of\s+(?:the\s+)?(?:activity|contest|competition)|name\s+of\s+(?:the\s+)?(?:activity|contest|competition))\s*[:\-–—]\s*(.{3,})$/iu';

    private static function activities(array $lines, string $title): array
    {
        $acts = [];          // in document order
        $doc = self::blankActivity('');   // criteria / segments written before any activity
        $cur = null;         // index into $acts
        $category = null;
        $mode = null;        // criteria | segments | scoring
        $inList = false;
        $sectionSeen = false;
        $blank = 0;
        $table = null;
        $sinceStart = 99;    // lines since the current activity started
        $expectActivity = false; // after an [ACTIVITY_START] marker
        $pending = null;     // a numbered line that may turn out to be an activity
        $lineNo = 0;
        $lines = self::joinWrapped($lines);

        $target = function () use (&$acts, &$cur, &$doc) {
            if ($cur !== null) {
                return $acts[$cur];
            }
            return $doc;
        };
        $store = function (array $a) use (&$acts, &$cur, &$doc) {
            if ($cur !== null) {
                $acts[$cur] = $a;
            } else {
                $doc = $a;
            }
        };
        $start = function (string $name, ?string $cat) use (&$acts, &$cur, &$mode, &$sinceStart, $title) {
            $clean = self::activityName($name);
            if ($clean === '' || preg_match(self::NOT_ACTIVITY, $clean) || preg_match(self::NOISE, $clean)) {
                return false;
            }
            $acts[] = self::blankActivity($clean, $cat);
            $cur = count($acts) - 1;
            $mode = null;
            $sinceStart = 0;
            return true;
        };

        foreach ($lines as $line) {
            if ($line === '') {
                if (++$blank >= 2 && $mode !== 'criteria') {
                    $mode = null;
                }
                continue;
            }
            $blank = 0;
            $sinceStart++;
            $lineNo++;

            // ---- structured markers: [ACTIVITY_START] … [IRR_START] … [ACTIVITY_END]
            if (preg_match('/^\[\s*([A-Z][A-Z_ \-]{2,40})\s*\]$/', $line, $mk)) {
                $marker = strtoupper(str_replace([' ', '-'], '_', $mk[1]));
                if (str_starts_with($marker, 'ACTIVITY_START') || str_starts_with($marker, 'CONTEST_START') || str_starts_with($marker, 'COMPETITION_START')) {
                    $cur = null;
                    $expectActivity = true;
                    $inList = true;
                    $sectionSeen = true;
                } elseif (str_ends_with($marker, '_END') && !str_starts_with($marker, 'IRR') && !str_starts_with($marker, 'RULES')) {
                    $cur = null;
                }
                $mode = str_starts_with($marker, 'IRR_START') || str_starts_with($marker, 'RULES_START') ? ($cur !== null ? 'rules' : null) : null;
                continue;
            }
            if ($expectActivity) {
                $expectActivity = false;
                if ($start(self::listItem($line) ?? $line, $category)) {
                    continue;
                }
            }

            $isBullet = (bool) preg_match('/^[-–—•●○▪►✓*]\s+/u', $line);
            $cells = self::cells($line);
            $bare = trim(rtrim(self::stripBullet($line), ':'));
            $labelish = (bool) preg_match('/^[\p{L}][\p{L}\d &\/().\'’-]{1,40}:\s*\S/u', $bare);

            // a numbered line followed at once by its details ("Category: …", "Criteria: …") is an activity
            if ($pending !== null && $lineNo - $pending['at'] <= 2 && $labelish && preg_match('/^(category|type|target|criteria|segments?|format|scoring|mechanics|rules|venue|participants?|eligibility|division)\b/i', $bare)) {
                $start($pending['name'], $category);
                $inList = true;
            }
            $pending = null;

            // ---- "Category: Cultural | Type: Solo | Target: All Students"
            $kv = count($cells) >= 2 ? self::keyValues($cells) : null;
            if ($kv) {
                foreach ($kv as [$key, $value]) {
                    $k = strtolower($key);
                    if (preg_match('/^(category|categories|cluster|division|classification)$/', $k)) {
                        $named = self::categoryName($value) ?? self::clean($value);
                        // right under an activity it is that activity's category, not the next ones'
                        if ($cur !== null && $sinceStart <= 4) {
                            $acts[$cur]['category'] = $named;
                        } else {
                            $category = $named;
                        }
                    } elseif ($cur !== null && preg_match('/^(type|entry|participants?|team size|members?|format of entry)$/', $k)) {
                        $acts[$cur]['details']['Type'] = $value;
                    } elseif ($cur !== null && preg_match('/^(target|eligibility|open to|who can join|level)$/', $k)) {
                        $acts[$cur]['details']['Target'] = $value;
                    } elseif ($cur !== null) {
                        $acts[$cur]['rules'][] = $key . ': ' . $value;
                        if (self::scoringKey($k)) {
                            $acts[$cur]['scoring'] = trim($acts[$cur]['scoring'] . ' ' . $value);
                        }
                    }
                }
                $mode = null;
                continue;
            }

            // ---- rules headings inside an activity ("INTERNAL RULES & REGULATIONS (IRR)", "Mechanics:")
            if ($cur !== null && preg_match('/^(?:internal\s+)?(?:rules?(?:\s*(?:&|and)\s*regulations?)?|regulations?|guidelines|general\s+guidelines|mechanics|house\s+rules|contest\s+rules|irr)(?:\s*\((?:irr)\))?$/i', $bare)) {
                $mode = 'rules';
                continue;
            }

            // ---- table with an "Activity" column (one activity per row)
            if (count($cells) >= 2 && ($map = self::tableHeader($cells))) {
                $table = $map;
                $inList = false;
                $mode = null;
                continue;
            }
            $filled = count(array_filter($cells, fn($c) => $c !== ''));
            if ($table && $filled < 2) {
                $table = null; // the table ended; read this line normally
            }
            if ($table) {
                $name = trim($cells[$table['activity']] ?? '');
                if ($name !== '' && $start($name, isset($table['category']) ? self::categoryName($cells[$table['category']] ?? '') ?? $category : $category)) {
                    $a = $acts[$cur];
                    foreach (['criteria', 'segments', 'scoring', 'format'] as $col) {
                        if (!isset($table[$col]) || ($cells[$table[$col]] ?? '') === '') {
                            continue;
                        }
                        $value = $cells[$table[$col]];
                        if ($col === 'criteria') {
                            $a['criteria_lines'][] = $value;
                        } elseif ($col === 'segments') {
                            $a['segments'] = array_merge($a['segments'], self::splitList($value));
                        } else {
                            $a['scoring'] = trim($a['scoring'] . ' ' . $value);
                        }
                    }
                    $acts[$cur] = $a;
                    $mode = null;
                }
                continue;
            }

            // ---- sign-off ends everything
            if (preg_match('/^(prepared|noted|approved|recommending approval|checked|submitted|endorsed)\s*(by)?\s*:?\s*$/i', $bare) || preg_match('/^(prepared|noted|approved)\s+by\b/i', $bare)) {
                $mode = null;
                $inList = false;
                $cur = null;
                continue;
            }

            // ---- category label or heading ("Category: Esports", "II. PAGEANTRY", "SPORTS EVENTS")
            if (preg_match(self::LABEL_CATEGORY, $bare, $m)) {
                $named = self::categoryName($m[1]) ?? self::clean($m[1]);
                if ($cur !== null && $sinceStart <= 4) {
                    $acts[$cur]['category'] = $named;
                } else {
                    $category = $named;
                }
                $mode = null;
                continue;
            }
            if (($heading = self::categoryHeading($bare)) !== null) {
                $category = $heading;
                $cur = null;
                $mode = null;
                $inList = true;
                $sectionSeen = true;
                continue;
            }

            // ---- activity list headings
            if (preg_match(self::HEADINGS, $bare)) {
                $inList = true;
                $sectionSeen = true;
                $mode = null;
                $cur = null;
                continue;
            }

            // ---- "Activity: Cosplay Competition"
            if (preg_match(self::LABEL_ACTIVITY, $bare, $m) && !preg_match('/%/', $m[1])) {
                $start($m[1], $category);
                $inList = true;
                $sectionSeen = true;
                continue;
            }

            // ---- detail labels
            if (preg_match(self::LABEL_CRITERIA, $bare, $m) && !preg_match('/^judging\s+(will|shall|is)\b/i', $bare)) {
                $mode = 'criteria';
                $rest = trim($m[1] ?? '');
                if ($rest !== '') {
                    $a = $target();
                    $a['criteria_lines'][] = $rest;
                    $store($a);
                }
                continue;
            }
            if (preg_match(self::LABEL_SEGMENTS, $bare, $m)) {
                $mode = 'segments';
                $rest = trim($m[1] ?? '');
                if ($rest !== '') {
                    $a = $target();
                    $a['segments'] = array_merge($a['segments'], self::splitList($rest));
                    if ($cur !== null && !str_contains($rest, '%')) {
                        $a['rules'][] = $bare;                     // e.g. "Rounds: Easy (10 Qs, 1 pt) …"
                        if (preg_match('/\d+\s*(pts?|points?)\b/i', $rest)) {
                            $a['scoring'] = trim($a['scoring'] . ' ' . $rest);
                        }
                    }
                    $store($a);
                }
                continue;
            }
            if (preg_match(self::LABEL_JUDGES, $bare, $m) && !preg_match('/%/', $bare)) {
                $mode = 'judges';
                $a = $target();
                $a['judges'] = array_merge($a['judges'], self::splitPeople($m[1] ?? ''));
                $store($a);
                continue;
            }
            if (preg_match(self::LABEL_FACILITATORS, $bare, $m) && !preg_match('/%/', $bare)) {
                $mode = 'facilitators';
                $a = $target();
                $a['facilitators'] = array_merge($a['facilitators'], self::splitPeople($m[1] ?? ''));
                $store($a);
                continue;
            }
            if (preg_match(self::LABEL_SCORING, $bare, $m)) {
                $mode = 'scoring';
                $rest = trim($m[1] ?? $m[2] ?? '');
                if ($rest !== '') {
                    $a = $target();
                    $a['scoring'] = trim($a['scoring'] . ' ' . $rest);
                    if ($cur !== null) {
                        $a['rules'][] = $bare;
                    }
                    $store($a);
                }
                continue;
            }
            // any other "Label: text" rule of an activity (time limits, penalties, rounds, system…)
            if ($cur !== null && $labelish && ($isBullet || $mode === 'rules')) {
                [$key, $value] = array_map('trim', explode(':', $bare, 2));
                $acts[$cur]['rules'][] = $key . ': ' . $value;
                if (self::scoringKey(strtolower($key))) {
                    $acts[$cur]['scoring'] = trim($acts[$cur]['scoring'] . ' ' . $value);
                }
                $mode = 'rules';
                continue;
            }
            if (preg_match(self::END_HEADINGS, $bare) && !preg_match('/^(criteria|mechanics)/i', $bare)) {
                $mode = null;
                $inList = false;
                continue;
            }

            // ---- activity name inline with its criteria: "Cosplay – Criteria: Craftsmanship (40%), …"
            if (preg_match('/^(.{3,80}?)\s*[-–—:|]\s*(?:criteria|judging criteria)\s*[:\-–—]\s*(.+)$/iu', self::stripBullet($line), $m)) {
                if ($start($m[1], $category)) {
                    $acts[$cur]['criteria_lines'][] = $m[2];
                    $mode = 'criteria';
                    continue;
                }
            }

            $item = self::listItem($line);
            $looksNew = $item !== null && self::weightOf($item) === null && self::plausibleName($item);

            // ---- lines that belong to the current detail block
            if ($mode === 'criteria') {
                $isNewActivity = $looksNew && ($inList || self::looksLikeCompetition($item)) && self::hasCriteria($target());
                if (!$isNewActivity) {
                    $a = $target();
                    $a['criteria_lines'][] = $line;
                    $store($a);
                    continue;
                }
                $mode = null;
            } elseif ($mode === 'segments') {
                $plain = self::stripBullet($line);
                if (!($looksNew && self::looksLikeCompetition($item)) && mb_strlen($plain) <= 80) {
                    $a = $target();
                    $a['segments'] = array_merge($a['segments'], self::splitList($plain));
                    $store($a);
                    continue;
                }
                $mode = null;
            } elseif ($mode === 'judges' || $mode === 'facilitators') {
                $plain = self::stripBullet($line);
                $people = self::weightOf($plain) === null && !($looksNew && self::looksLikeCompetition($item)) ? self::splitPeople($plain) : [];
                if ($people) {
                    $a = $target();
                    $a[$mode] = array_merge($a[$mode], $people);
                    $store($a);
                    continue;
                }
                $mode = null;
            } elseif ($mode === 'rules') {
                if ($cur !== null && ($isBullet || !$looksNew) && !preg_match(self::HEADINGS, $bare)) {
                    $acts[$cur]['rules'][] = $bare;
                    continue;
                }
                $mode = null;
            } elseif ($mode === 'scoring') {
                if (!$looksNew) {
                    $a = $target();
                    if (mb_strlen($a['scoring']) < 400) {
                        $a['scoring'] = trim($a['scoring'] . ' ' . self::stripBullet($line));
                    }
                    $store($a);
                    continue;
                }
                $mode = null;
            }

            // ---- a new activity
            if ($item === null && $inList && !preg_match('/[.:]$/', $line) && mb_strlen($line) <= 70 && !self::dateIn($line) && self::weightOf($line) === null && self::plausibleName($line)) {
                $item = $line; // tables and plain lists lose their bullets
            }
            if ($item !== null && self::weightOf($item) === null && ($inList || (!$sectionSeen && self::looksLikeCompetition($item)))) {
                $start($item, $category);
            } elseif ($item !== null && preg_match('/^\(?\d{1,2}[.)]\s/', $line) && self::plausibleName($item)) {
                $pending = ['name' => $item, 'at' => $lineNo];
            }
        }

        // ---- finish: parse criteria, infer format and nature, drop duplicates
        $titleKey = self::key($title);
        $seen = [];
        $out = [];
        foreach (self::splitSoloGroup($acts) as $a) {
            $k = self::key($a['title']);
            if ($k === '' || isset($seen[$k]) || ($titleKey !== '' && $k === $titleKey && count($acts) > 1)) {
                continue;
            }
            $seen[$k] = true;
            $out[] = self::finish($a);
            if (count($out) >= 40) {
                break;
            }
        }

        // criteria written for the whole document (single contest memos) become its one activity
        $docFinished = self::finish($doc);
        if (!$out && ($docFinished['criteria'] || $docFinished['segments'])) {
            $docFinished['title'] = $title !== '' ? $title : 'Main competition';
            $docFinished['nature'] = $docFinished['nature'] ?? self::nature($docFinished['title']);
            $out[] = $docFinished;
        } elseif (count($out) === 1 && !$out[0]['criteria'] && $docFinished['criteria']) {
            $out[0]['criteria'] = $docFinished['criteria'];
            $out[0]['format'] = 'score';
        }
        $judges = $docFinished['judges'];
        $facilitators = $docFinished['facilitators'];
        if (count($out) === 1 && ($out[0]['judges'] === $judges || !$out[0]['judges'])) {
            $out[0]['judges'] = $judges ?: $out[0]['judges'];
            $judges = [];
        }
        return [$out, $judges, $facilitators];
    }

    /**
     * "Dance Competition (Solo and Group)", "Singing – Solo/Group", "Solo & Group Singing"
     * are ranked separately: one activity each, sharing the criteria and rules.
     */
    private static function splitSoloGroup(array $acts): array
    {
        $both = '(solo|individual)\s*(?:and|&|\/|or|,)\s*(group|team|duo|ensemble)';
        $out = [];
        foreach ($acts as $a) {
            if (!preg_match('/\b' . $both . '\b/iu', $a['title'], $m)) {
                $out[] = $a;
                continue;
            }
            $base = preg_replace([
                '/\s*[(\[]\s*(?:categories?\s*[:\-]?\s*)?' . $both . '(?:\s+categor(?:y|ies))?\s*[)\]]/iu', // "Dance (Solo and Group)"
                '/\s*[-–—:|]\s*' . $both . '(?:\s+categor(?:y|ies))?\s*$/iu',                              // "Singing – Solo/Group"
                '/\b' . $both . '\b\s*/iu',                                                                 // "Solo & Group Singing"
            ], '', $a['title'], 1);
            $base = self::clean((string) $base);
            if ($base === '') {
                $out[] = $a;
                continue;
            }
            foreach ([$m[1], $m[2]] as $variant) {
                $variant = ucfirst(strtolower($variant));
                $copy = $a;
                $copy['title'] = $base . ' – ' . $variant;
                $copy['details']['Type'] = $copy['details']['Type'] ?? $variant;
                $out[] = $copy;
            }
        }
        return $out;
    }

    /** "Judges: Dr. Ana Reyes, Mr. Juan Dela Cruz, Jr. and Ms. Liza Tan" → names */
    private static function splitPeople(string $s): array
    {
        $s = trim(self::stripBullet($s));
        if ($s === '' || preg_match('/^(tba|tbd|to be (announced|determined)|n\/?a|none|see attached|\d+\s+(judges?|facilitators?))\.?$/i', $s)) {
            return [];
        }
        $parts = preg_split('/\s*(?:;|\||,|\s+and\s+|\s+&\s+)\s*/iu', $s);
        $names = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t.-–—:");
            if ($part === '') {
                continue;
            }
            // "Jr." / "PhD" after a comma belongs to the previous name
            if ($names && preg_match('/^(jr|sr|ii|iii|iv|phd|ph\.d|md|lpt|rn|cpa|ed\.?d|mba|maed|mat)\.?$/i', $part)) {
                $names[count($names) - 1] .= ', ' . $part;
                continue;
            }
            // drop a trailing role: "Ana Reyes – Chairman", "Ana Reyes (Head Judge)"
            $part = preg_replace('/\s*[–—-]\s*(chair(man|person)?|head judge|member|judge|facilitator|marshal|referee)\b.*$/iu', '', $part);
            $part = preg_replace('/\s*\((chair(man|person)?|head judge|member|judge|facilitator|marshal|referee)[^)]*\)\s*$/iu', '', $part);
            $part = self::clean($part);
            if (self::plausiblePerson($part)) {
                $names[] = self::titleCase($part);
            }
        }
        return $names;
    }

    private static function plausiblePerson(string $s): bool
    {
        $words = preg_split('/\s+/', trim($s));
        return mb_strlen($s) >= 3 && mb_strlen($s) <= 80 && count($words) <= 7
            && preg_match('/\p{L}{2,}/u', $s) && !preg_match('/\d{2,}|%/', $s)
            && !preg_match('/^(the|all|each|every|participants?|contestants?|teams?|players?|note|reminder|criteria|segments?|scoring|venue|date|time|category|format|mechanics|prepared|noted|approved)\b/i', $s);
    }

    private static function uniquePeople(array $names): array
    {
        $seen = [];
        $out = [];
        foreach ($names as $n) {
            $k = self::key(preg_replace('/^(dr|mr|mrs|ms|engr|atty|prof|sir|maam|ma\'am)\.?\s+/i', '', $n));
            if ($k !== '' && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $n;
            }
        }
        return array_slice($out, 0, 30);
    }

    /** Project / program head named in the document ("Project Head: …", or a signatory titled Program Head). */
    private static function head(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^(?:project\s+head|program\s+head|event\s+head|head\s+of\s+(?:the\s+)?(?:event|project)|event\s+(?:coordinator|chair(?:person)?|director|in-?charge)|overall\s+(?:in-?charge|coordinator|chair(?:person)?)|project\s+(?:coordinator|leader|director))\s*[:\-–—]\s*(.{3,80})$/iu', $line, $m)) {
                $names = self::splitPeople($m[1]);
                if ($names) {
                    return $names[0];
                }
            }
        }
        // signature block: a name followed by a line naming the role
        foreach ($lines as $i => $line) {
            if ($i > 0 && preg_match('/^(?:the\s+)?(?:program|project|event)\s+head\b/i', $line)) {
                $prev = trim($lines[$i - 1] ?? '');
                if ($prev !== '' && self::plausiblePerson($prev) && !preg_match('/:\s*$/', $prev)) {
                    return self::titleCase(self::clean($prev));
                }
            }
        }
        return '';
    }

    private static function blankActivity(string $title, ?string $category = null): array
    {
        return ['title' => $title, 'category' => $category, 'criteria_lines' => [], 'segments' => [], 'scoring' => '', 'judges' => [], 'facilitators' => [], 'details' => [], 'rules' => []];
    }

    /** @return array{title:string, format:string, nature:?string, category:?string, criteria:array, segments:string[], scoring:string} */
    private static function finish(array $a): array
    {
        $criteria = self::criteriaFrom($a['criteria_lines']);
        $segments = [];
        $segmentWeights = [];
        foreach ($a['segments'] as $s) {
            $w = self::weightOf($s);
            $name = self::clean(preg_replace('/\s*\(\s*\d{1,3}(?:\.\d+)?\s*%\s*\)|\s*[-–:]?\s*\d{1,3}(?:\.\d+)?\s*%/u', '', $s));
            if ($name === '' || mb_strlen($name) > 80) {
                continue;
            }
            $segments[] = self::titleCaseIfShouting($name);
            if ($w !== null && str_contains($s, '%')) {
                $segmentWeights[] = ['name' => self::titleCaseIfShouting($name), 'description' => '', 'max_score' => $w];
            }
        }
        $segments = array_values(array_unique($segments));
        // weighted segments with no separate criteria are the scoring criteria (e.g. pageant segments),
        // but only percentages that add up to about 100 — "Easy (1 pt)" rounds are not criteria
        $segmentTotal = array_sum(array_column($segmentWeights, 'max_score'));
        $percentSegments = array_filter($a['segments'], fn($x) => str_contains($x, '%'));
        if (!$criteria && count($segmentWeights) >= 2 && count($percentSegments) >= 2 && abs($segmentTotal - 100) <= 1) {
            $criteria = $segmentWeights;
        }

        $scoring = self::clean($a['scoring']);
        $rules = array_values(array_unique(array_filter(array_map(fn($x) => self::clean($x), $a['rules'] ?? []), fn($x) => mb_strlen($x) >= 3)));
        $allRules = $scoring . ' ' . implode(' ', $rules);
        $explicit = self::formatFromScoring($allRules);
        $format = $criteria
            ? (in_array($explicit, ['bracket', 'round_robin'], true) && !preg_match('/judges?|criteria/i', $allRules) ? $explicit : 'score')
            : ($explicit ?? self::format($a['title']));
        $nature = self::categoryNature($a['category']) ?? self::nature($a['title']);

        return [
            'title' => $a['title'],
            'format' => $format,
            'nature' => $nature,
            'category' => $a['category'],
            'criteria' => $criteria,
            'segments' => $segments,
            'scoring' => mb_substr($scoring, 0, 500),
            'details' => $a['details'] ?? [],
            'rules' => array_slice($rules, 0, 30),
            'score_label' => $format === 'score' ? '' : self::scoreLabel($allRules, $format, $a['title']),
            'judges' => self::uniquePeople($a['judges']),
            'facilitators' => self::uniquePeople($a['facilitators']),
        ];
    }

    /** Criteria from inline lists ("A (40%), B (30%)") and from tables / one-per-line layouts. */
    private static function criteriaFrom(array $lines): array
    {
        if (!$lines) {
            return [];
        }
        $inline = [];
        $block = [];
        foreach ($lines as $line) {
            $found = self::inlineCriteria($line);
            if (count($found) >= 2) {
                $inline = array_merge($inline, $found);
            } else {
                $block[] = $line;
            }
        }
        $fromBlock = $block ? CriteriaParser::parse(implode("\n", $block))['criteria'] : [];
        $all = array_merge($inline, $fromBlock);
        $seen = [];
        $out = [];
        foreach ($all as $c) {
            $k = self::key($c['name']);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = ['name' => self::titleCaseIfShouting($c['name']), 'description' => $c['description'] ?? '', 'max_score' => (float) $c['max_score']];
        }
        return $out;
    }

    /** "Craftsmanship & Accuracy (40%), Character Embodiment (30%) and Audience Impact – 10 pts" */
    private static function inlineCriteria(string $line): array
    {
        $line = preg_replace('/^\s*(?:criteria|judging criteria)\s*[:\-–—]\s*/i', '', $line);
        $re = '/([\p{L}][\p{L}\p{N}&\/\'’.\- ]{1,90}?)\s*(?:[:\-–—=]\s*|\(\s*|\[\s*)?(\d{1,3}(?:[.,]\d{1,2})?)\s*(%|percent\b|pts?\b\.?|points?\b)\s*[)\]]?/iu';
        if (!preg_match_all($re, $line, $all, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($all as $m) {
            $name = self::clean(preg_replace('/^(?:and|&|,|;)\s+/i', '', trim($m[1])));
            $name = preg_replace('/^(?:criteria|judging criteria)\s*[:\-–—]\s*/i', '', $name);
            $num = (float) str_replace(',', '.', $m[2]);
            if ($name === '' || $num <= 0 || $num > 100 || preg_match('/^(total|grand total)$/i', $name)) {
                continue;
            }
            [$n, $desc] = [$name, ''];
            if (preg_match('/^(.{2,60}?)\s*[–—:]\s*(.{3,})$/u', $name, $d)) {
                [$n, $desc] = [trim($d[1]), trim($d[2])];
            }
            $out[] = ['name' => $n, 'description' => $desc, 'max_score' => $num];
        }
        return $out;
    }

    /** PDF text wraps long bullet / label lines onto the next line: glue them back together. */
    private static function joinWrapped(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $prevIndex = count($out) - 1;
            $prev = $prevIndex >= 0 ? $out[$prevIndex] : '';
            $prevWraps = $prev !== '' && mb_strlen($prev) >= 55 && !preg_match('/[.!?:]$|%\)\.?$/u', $prev)
                && (preg_match('/^[-–—•●○▪►✓*]\s+/u', $prev) || preg_match('/^[\p{L}][\p{L}\d &\/().\'’-]{1,40}:\s+\S/u', $prev));
            $isContinuation = $line !== ''
                && !preg_match('/^[-–—•●○▪►✓*]\s+|^\(?\d{1,2}[.)]\s|^\(?[ivx]{1,4}[.)]\s|^\[/iu', $line)
                && !preg_match('/^[\p{L}][\p{L}\d &\/().\'’-]{1,40}:(\s*\S|\s*$)/u', $line) // "Label: …" or a bare "Criteria:" heading
                && !(preg_match('/\p{L}{3}/u', $line) && mb_strtoupper($line) === $line && mb_strlen($line) > 6);
            if ($prevWraps && $isContinuation) {
                $out[$prevIndex] = $prev . ' ' . $line;
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** ["Category: Esports", "Type: Solo"] → [["Category","Esports"], ["Type","Solo"]] when every cell is "Key: value" */
    private static function keyValues(array $cells): ?array
    {
        $pairs = [];
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            if (!preg_match('/^([\p{L}][\p{L}\d &\/().\'’-]{1,30}?)\s*:\s*(.+)$/u', $cell, $m)) {
                return null;
            }
            $pairs[] = [trim($m[1]), trim($m[2])];
        }
        return count($pairs) >= 2 ? $pairs : null;
    }

    private static function scoringKey(string $key): bool
    {
        return (bool) preg_match('/^(format|game format|tournament format|scoring|scoring system|point system|points?|system|rounds?|game mode|mode|match format|game duration|win condition|win conditions|tiebreakers?|elimination|seeding|ranking)$/', $key);
    }

    private static function scoreLabel(string $rules, string $format, string $title): string
    {
        $t = strtolower($rules . ' ' . $title);
        if (preg_match('/swiss/', $t)) return 'Points';
        if (preg_match('/\bsets?\b/', $t)) return 'Sets';
        if ($format !== 'ranking' && preg_match('/first to \d+ rounds|rounds per map|round differential/', $t)) return 'Rounds';
        if (preg_match('/\bbo\d\b|best[- ]of|games?\b.*\bwins?/', $t) && $format === 'bracket' && !preg_match('/points?|pts/', $t)) return 'Games';
        if (preg_match('/fastest|time trial|seconds|minutes/', $t) && $format === 'ranking' && !preg_match('/points?|pts/', $t)) return 'Time';
        if (preg_match('/points?|pts|swiss|quiz|score/', $t)) return 'Points';
        return '';
    }

    private static function hasCriteria(array $a): bool
    {
        foreach ($a['criteria_lines'] as $l) {
            if (self::weightOf($l) !== null) {
                return true;
            }
        }
        return false;
    }

    private static function weightOf(string $s): ?float
    {
        if (preg_match('/(\d{1,3}(?:[.,]\d{1,2})?)\s*(%|percent\b|pts?\b\.?|points?\b)/iu', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;
    }

    private static function plausibleName(string $s): bool
    {
        $s = trim($s);
        return mb_strlen($s) >= 3 && mb_strlen($s) <= 80 && preg_match('/\p{L}{2,}/u', $s) && !preg_match('/^(the|all|each|every|participants?|contestants?|teams?|players?|judges?|winners?|note|reminder)\b/i', $s) && substr_count($s, ' ') <= 9;
    }

    /** "  | a | b |" table rows → cells */
    private static function cells(string $line): array
    {
        if (!str_contains($line, '|')) {
            return [$line];
        }
        $cells = array_map('trim', explode('|', trim($line, " |")));
        return $cells;
    }

    private static function tableHeader(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $c) {
            $c = strtolower(trim($c, " :"));
            if (preg_match('/^(activity|activities|event|events|contest|competition|name of (activity|event|contest)|title)$/', $c)) $map['activity'] = $i;
            elseif (preg_match('/^(category|categories|cluster|division|type)$/', $c)) $map['category'] = $i;
            elseif (preg_match('/^(criteria|judging criteria|criteria for judging|rubric|basis of judging)$/', $c)) $map['criteria'] = $i;
            elseif (preg_match('/^(segments?|rounds?|portions?)$/', $c)) $map['segments'] = $i;
            elseif (preg_match('/^(scoring|scoring system|mechanics|format|system)$/', $c)) $map['scoring'] = $i;
        }
        return isset($map['activity']) && count($map) >= 2 ? $map : null;
    }

    private static function stripBullet(string $line): string
    {
        return trim(preg_replace('/^(?:[-–—•●○▪►✓*]|\(?\d{1,2}[.)]|\(?[a-z][.)]|\(?[ivx]{1,4}[.)])\s+/iu', '', trim($line)));
    }

    private static function splitList(string $s): array
    {
        $s = self::stripBullet($s);
        $parts = [];
        $depth = 0;
        $buf = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if ($ch === '(' || $ch === '[') $depth++;
            if (($ch === ')' || $ch === ']') && $depth > 0) $depth--;
            if ($depth === 0 && ($ch === ',' || $ch === ';' || $ch === '|')) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;
        return array_values(array_filter(array_map(fn($p) => self::clean($p), $parts), fn($p) => mb_strlen($p) >= 2 && mb_strlen($p) <= 80));
    }

    /** "II. PAGEANTRY", "SPORTS EVENTS", "Esports Category" → canonical category, otherwise null */
    private static function categoryHeading(string $line): ?string
    {
        $s = preg_replace('/^(?:[ivx]{1,4}|[a-h]|\d{1,2})[.)]\s+/i', '', trim($line));
        $s = trim($s, " :-–—");
        if (mb_strlen($s) > 45 || preg_match('/\d{2,}|%/', $s)) {
            return null;
        }
        if (!preg_match('/^(?:(' . self::CATEGORY_WORDS . ')(?:\s*(?:and|&|\/)\s*(' . self::CATEGORY_WORDS . '))?)(?:\s+(?:events?|competitions?|contests?|category|division|cluster|activities|games))?$/iu', $s)) {
            return null;
        }
        return self::categoryName($s);
    }

    private static function categoryName(string $raw): ?string
    {
        $s = strtolower(trim(preg_replace('/\s+(events?|competitions?|contests?|category|division|cluster|activities)$/i', '', trim($raw)), " :-–—"));
        $map = [
            'Esports' => '/^(e-?sports?|electronic sports|gaming)$/',
            'Pageantry' => '/^(pageant(ry|s)?|beauty pageants?)$/',
            'Sports' => '/^(sports?|athletics|indoor games|outdoor games|games)$/',
            'Cultural' => '/^(cultural|socio-?cultural|culture and arts)$/',
            'Arts & Literary' => '/^(arts?|visual arts|literary|literary arts|literary[- ]musical)$/',
            'Music & Dance' => '/^(music(al)?|dance|performing arts|music and dance)$/',
            'Academic' => '/^(academics?|academic contests?|quiz(zes)?)$/',
            'Technology / IT' => '/^(technology|ict|information technology|it)$/',
            'Wellness' => '/^(wellness|fitness)$/',
            'Leadership' => '/^leadership$/',
            'Religious' => '/^(religious|spiritual)$/',
            'Community / Outreach' => '/^(community|outreach)$/',
            'Special events' => '/^(special|major|minor) events?$/',
            'Entertainment' => '/^entertainment$/',
        ];
        foreach ($map as $name => $re) {
            if (preg_match($re, $s)) {
                return $name;
            }
        }
        return $s !== '' && mb_strlen($s) <= 40 ? self::titleCase(mb_strtoupper($s) === $s ? $s : ucwords($s)) : null;
    }

    private static function categoryNature(?string $category): ?string
    {
        return match ($category) {
            'Esports', 'Technology / IT' => 'Technology / IT',
            'Pageantry' => 'Pageant',
            'Sports' => 'Sports',
            'Cultural' => 'Cultural',
            'Arts & Literary' => 'Arts & Literary',
            'Music & Dance' => 'Music & Dance',
            'Academic' => 'Academic',
            'Wellness' => 'Wellness',
            'Leadership' => 'Leadership',
            'Religious' => 'Religious',
            'Community / Outreach' => 'Community / Outreach',
            null => null,
            default => $category,
        };
    }

    private static function formatFromScoring(string $s): ?string
    {
        $s = strtolower($s);
        if ($s === '') return null;
        if (preg_match('/round[- ]?robin|\bleague\b|everyone plays|wins? (grants?|earns?|gives?|gets?|=) ?\d+ ?(points?|pts)|\d+ ?(points?|pts) (per|for (a|each)) win/', $s)) return 'round_robin';
        if (preg_match('/single[- ]elimination|double[- ]elimination|knock-?out|bracket|elimination round|best[- ]of[- ]?\d|best \d+ (out )?of \d+|\(?\bbo\d\b\)?|winner advances|playoffs?/', $s)) return 'bracket';
        if (preg_match('/swiss( system)?|fastest|shortest time|lowest time|highest (score|points?|total)|most points|ranked by|ranking|placement|time trial|number of correct|\d+ ?pts?\b.*\d+ ?pts?\b/', $s)) return 'ranking';
        if (preg_match('/judges?|criteria|rubric|average of|percentage/', $s)) return 'score';
        return null;
    }

    private static function key(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
    }

    private static function titleCaseIfShouting(string $s): string
    {
        return preg_match('/\b[A-Z]{4,}\b/', $s) && mb_strtoupper($s) === $s ? self::titleCase($s) : $s;
    }

    private static function listItem(string $line): ?string
    {
        if (preg_match('/^(?:[-–—•●○▪►✓*]|\(?\d{1,2}[.)]|\(?[a-z][.)])\s+(.{3,})$/iu', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function activityName(string $item): string
    {
        $s = $item;
        // strip schedule columns: times, dates, day names
        $s = preg_replace('/^\s*' . self::TIME_RE . '(\s*(-|–|to)\s*' . self::TIME_RE . ')?\s*[-–:|]?\s*/i', '', $s);
        $s = preg_replace('/\s*[-–|(]\s*' . self::TIME_RE . '.*$/i', '', $s);
        $s = preg_replace('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b,?/i', '', $s);
        $s = preg_replace('/\b' . self::MONTH_RE . '\s+\d{1,2}(\s*[-–]\s*\d{1,2})?(,?\s*20\d{2})?/i', '', $s);
        // strip trailing venue / participants notes
        $s = preg_replace('/\s*[-–|]\s*(venue|at|@)\b.*$/i', '', $s);
        $s = preg_replace('/\s+@\s+.*$/', '', $s);
        $s = preg_replace('/\s*\((?:[^)]*\b(?:venue|gym|hall|court|room|participants?|per (college|department)|pax)\b[^)]*)\)\s*$/i', '', $s);
        $s = self::clean($s);
        if (mb_strlen($s) < 3 || mb_strlen($s) > 80 || !preg_match('/[a-z]/i', $s)) {
            return '';
        }
        return self::titleCase($s);
    }

    private static function looksLikeCompetition(string $s): bool
    {
        return self::format($s) !== 'score' || (bool) preg_match('/\b(contest|competition|search for|pageant|mr\.?\s*(and|&)\s*ms|dance|sing|quiz|bee|debate|poster|essay|battle of|showdown|tournament|cup|league|challenge|olympiad|hackathon)\b/i', $s);
    }

    public static function format(string $name): string
    {
        $n = strtolower($name);
        if (preg_match('/round ?robin|\bleague\b/', $n)) {
            return 'round_robin';
        }
        if (preg_match('/basketball|volleyball|chess|badminton|table tennis|ping ?pong|sepak|futsal|football|soccer|e-?sports?|mobile legends|\bml\b|dota|valorant|tekken|tug of war|arm ?wrestling|sipa|baseball|softball|frisbee|taekwondo|boxing|wrestling|scrabble|dama|word factory|tournament|\bvs\.?\b|elimination/', $n)) {
            return 'bracket';
        }
        if (preg_match('/quiz|relay|race|dash|marathon|fun run|sprint|spelling|math|swimming|shooting|typing|speed|amazing race|trivia|olympiad|\bbee\b|scavenger|obstacle|puzzle|rubik|long jump|high jump|shot put|javelin/', $n)) {
            return 'ranking';
        }
        return 'score';
    }

    public static function nature(string $name): ?string
    {
        $n = strtolower($name);
        $map = [
            'Technology / IT' => '/e-?sports?|mobile legends|dota|valorant|tekken|programming|coding|hackathon|web design|robotics|typing|it quiz|computer/',
            'Sports' => '/basketball|volleyball|chess|badminton|table tennis|sepak|futsal|football|soccer|relay|race|dash|marathon|fun run|sprint|swimming|tug of war|arm ?wrestling|sipa|baseball|softball|frisbee|taekwondo|boxing|athletics|jump|shot put|javelin|sports/',
            'Pageant' => '/pageant|search for|mr\.?\s*(and|&)\s*ms|\bmiss\b|\bmister\b|lakan|lakambini|king and queen/',
            'Music & Dance' => '/dance|sing|song|vocal|choir|chorale|band|music|hip ?hop|cheer|zumba contest|lip ?sync|karaoke|acapella/',
            'Arts & Literary' => '/poster|painting|drawing|photo|essay|poetry|poem|spoken word|slogan|story|film|video|art|calligraphy|sculpt|cosplay|costume/',
            'Academic' => '/quiz|debate|spelling|math|science|research|olympiad|trivia|extemp|oratorical|declamation|\bbee\b/',
            'Cultural' => '/cultural|folk|heritage|ethnic|festival dance|tribal|history/',
            'Community / Outreach' => '/outreach|tree planting|clean-?up drive|blood letting|donation|feeding/',
            'Wellness' => '/wellness|zumba|yoga|fitness|aerobics/',
            'Religious' => '/bible|praise|worship|gospel|religious/',
            'Leadership' => '/leadership|mock election|model un|parliament/',
        ];
        foreach ($map as $nature => $re) {
            if (preg_match($re, $n)) {
                return $nature;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ helpers */

    private static function clean(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s, " \t\"'“”‘’.,;:-–—|*•");
    }

    /** ALL-CAPS OCR lines become Title Case; mixed-case text is left alone. */
    private static function titleCase(string $s): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $s);
        if ($letters === '' || strtoupper($letters) !== $letters) {
            return $s;
        }
        $small = ['of', 'and', 'the', 'for', 'in', 'on', 'at', 'to', 'a', 'an', 'vs', 'de', 'ng', 'sa'];
        $words = explode(' ', mb_strtolower($s));
        foreach ($words as $i => &$w) {
            if ($i > 0 && in_array($w, $small, true)) {
                continue;
            }
            if (preg_match('/^(coc|phinma|it|ict|ml|ccje|cea|cit|cite|sccj|cma|cahs|cas|csdl|ssc|usg|mr|ms|ii|iii|iv)$/', $w)) {
                $w = strtoupper($w);
                continue;
            }
            $w = mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
        }
        return implode(' ', $words);
    }
}
