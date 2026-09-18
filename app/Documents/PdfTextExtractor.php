<?php
/**
 * Dependency-free PDF text extractor.
 *
 * Supports: classic and compressed (ObjStm) object layouts, FlateDecode /
 * ASCIIHex / ASCII85 streams, page-tree traversal with inherited resources,
 * ToUnicode CMaps (incl. 2-byte Identity-H fonts), WinAnsi fallback, and
 * position-aware line reconstruction so table rows ("Content ..... 40%")
 * come out on one line. Scanned PDFs have no text layer and return '' —
 * callers fall back to OCR for those.
 */
declare(strict_types=1);

namespace App\Documents;

use RuntimeException;
use Throwable;

final class PdfRef  { public function __construct(public int $n) {} }
final class PdfName { public function __construct(public string $v) {} }
final class PdfStr  { public function __construct(public string $v, public bool $hex = false) {} }
final class PdfOp   { public function __construct(public string $v) {} }
final class PdfDict
{
    public function __construct(public array $v = []) {}
    public function get(string $k) { return $this->v[$k] ?? null; }
}

final class PdfTextExtractor
{
    private string $data = '';
    /** @var array<int, array{0:mixed,1:?string}> object number => [value, raw stream] */
    private array $objects = [];
    private array $fontCache = [];

    public static function extract(string $path): string
    {
        $self = new self();
        $self->data = (string) file_get_contents($path);
        if (strncmp($self->data, '%PDF', 4) !== 0 && strpos(substr($self->data, 0, 1024), '%PDF') === false) {
            return '';
        }
        $self->loadObjects();
        return $self->run();
    }

    /* ------------------------------------------------------------ objects */

    private function loadObjects(): void
    {
        preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $match) {
            $num = (int) $m[1][$i][0];
            $pos = $match[1] + strlen($match[0]);
            try {
                $value = $this->parseValue($this->data, $pos);
            } catch (Throwable $e) {
                continue;
            }
            $stream = null;
            $save = $pos;
            $this->skipWs($this->data, $pos);
            if (substr($this->data, $pos, 6) === 'stream') {
                $pos += 6;
                if (($this->data[$pos] ?? '') === "\r") { $pos++; }
                if (($this->data[$pos] ?? '') === "\n") { $pos++; }
                $len = $value instanceof PdfDict ? $value->get('Length') : null;
                $end = false;
                if (is_int($len) && substr($this->data, $pos + $len, 20) !== false
                    && preg_match('/^\s*endstream/', substr($this->data, $pos + $len, 20))) {
                    $end = $pos + $len;
                }
                if ($end === false) {
                    $end = strpos($this->data, 'endstream', $pos);
                    if ($end === false) { continue; }
                    // trim the EOL that precedes "endstream"
                    if ($end > $pos && $this->data[$end - 1] === "\n") { $end--; }
                    if ($end > $pos && $this->data[$end - 1] === "\r") { $end--; }
                }
                $stream = substr($this->data, $pos, $end - $pos);
            } else {
                $pos = $save;
            }
            $this->objects[$num] = [$value, $stream];
        }

        // Objects packed inside object streams (PDF 1.5+)
        foreach ($this->objects as [$dict, $stream]) {
            if (!$dict instanceof PdfDict || $stream === null) { continue; }
            $type = $dict->get('Type');
            if (!$type instanceof PdfName || $type->v !== 'ObjStm') { continue; }
            $decoded = $this->decodeStream($dict, $stream);
            if ($decoded === null) { continue; }
            $n = (int) $this->resolve($dict->get('N'));
            $first = (int) $this->resolve($dict->get('First'));
            $header = preg_split('/\s+/', trim(substr($decoded, 0, $first)));
            for ($i = 0; $i + 1 < count($header) && $i / 2 < $n; $i += 2) {
                $objNum = (int) $header[$i];
                $offset = $first + (int) $header[$i + 1];
                if (isset($this->objects[$objNum])) { continue; }
                try {
                    $p = $offset;
                    $this->objects[$objNum] = [$this->parseValue($decoded, $p), null];
                } catch (Throwable $e) {
                }
            }
        }
    }

    private function resolve($v, int $depth = 0)
    {
        while ($v instanceof PdfRef && $depth++ < 32) {
            $v = $this->objects[$v->n][0] ?? null;
        }
        return $v;
    }

    private function streamOf($ref): ?string
    {
        if (!$ref instanceof PdfRef || !isset($this->objects[$ref->n])) { return null; }
        [$dict, $raw] = $this->objects[$ref->n];
        if ($raw === null || !$dict instanceof PdfDict) { return null; }
        return $this->decodeStream($dict, $raw);
    }

    private function decodeStream(PdfDict $dict, string $raw): ?string
    {
        $filters = $this->resolve($dict->get('Filter'));
        if ($filters === null) { return $raw; }
        if (!is_array($filters)) { $filters = [$filters]; }
        $data = $raw;
        foreach ($filters as $f) {
            $f = $this->resolve($f);
            $name = $f instanceof PdfName ? $f->v : '';
            switch ($name) {
                case 'FlateDecode':
                case 'Fl':
                    $out = @gzuncompress($data);
                    if ($out === false) { $out = @gzinflate(substr($data, 2)); }
                    if ($out === false) { $out = @gzinflate($data); }
                    if ($out === false) { return null; }
                    $parms = $this->resolve($dict->get('DecodeParms'));
                    if ($parms instanceof PdfDict && (int) $this->resolve($parms->get('Predictor')) >= 10) {
                        $out = $this->pngUnpredict($out, (int) ($this->resolve($parms->get('Columns')) ?: 1));
                    }
                    $data = $out;
                    break;
                case 'ASCIIHexDecode':
                case 'AHx':
                    $hex = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]);
                    if (strlen($hex) % 2) { $hex .= '0'; }
                    $data = (string) hex2bin($hex);
                    break;
                case 'ASCII85Decode':
                case 'A85':
                    $data = $this->ascii85($data);
                    break;
                default:
                    return null; // images (DCT, JPX, CCITT...) are not text
            }
        }
        return $data;
    }

    private function pngUnpredict(string $data, int $columns): string
    {
        $rowLen = $columns + 1;
        $out = '';
        $prev = str_repeat("\0", $columns);
        for ($i = 0; $i + $rowLen <= strlen($data); $i += $rowLen) {
            $type = ord($data[$i]);
            $row = substr($data, $i + 1, $columns);
            $cur = '';
            for ($j = 0; $j < $columns; $j++) {
                $left = $j > 0 ? ord($cur[$j - 1]) : 0;
                $up = ord($prev[$j]);
                $x = ord($row[$j]);
                switch ($type) {
                    case 1: $x += $left; break;
                    case 2: $x += $up; break;
                    case 3: $x += intdiv($left + $up, 2); break;
                    case 4:
                        $ul = $j > 0 ? ord($prev[$j - 1]) : 0;
                        $p = $left + $up - $ul;
                        $pa = abs($p - $left); $pb = abs($p - $up); $pc = abs($p - $ul);
                        $x += ($pa <= $pb && $pa <= $pc) ? $left : ($pb <= $pc ? $up : $ul);
                        break;
                }
                $cur .= chr($x & 0xFF);
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out;
    }

    private function ascii85(string $data): string
    {
        $data = preg_replace('/\s+/', '', $data);
        if (str_starts_with($data, '<~')) { $data = substr($data, 2); }
        $end = strpos($data, '~>');
        if ($end !== false) { $data = substr($data, 0, $end); }
        $out = '';
        $tuple = [];
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            if ($data[$i] === 'z' && !$tuple) { $out .= "\0\0\0\0"; continue; }
            $tuple[] = ord($data[$i]) - 33;
            if (count($tuple) === 5) {
                $v = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85 + $tuple[4]);
                $out .= pack('N', $v);
                $tuple = [];
            }
        }
        if ($tuple) {
            $count = count($tuple);
            while (count($tuple) < 5) { $tuple[] = 84; }
            $v = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85 + $tuple[4]);
            $out .= substr(pack('N', $v), 0, $count - 1);
        }
        return $out;
    }

    /* ------------------------------------------------------------ tokenizer */

    private function skipWs(string $s, int &$p): void
    {
        $n = strlen($s);
        while ($p < $n) {
            $c = $s[$p];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
                $p++;
            } elseif ($c === '%') {
                while ($p < $n && $s[$p] !== "\n" && $s[$p] !== "\r") { $p++; }
            } else {
                break;
            }
        }
    }

    /** Parses one PDF value (or operator keyword) starting at $p. */
    private function parseValue(string $s, int &$p)
    {
        $this->skipWs($s, $p);
        $n = strlen($s);
        if ($p >= $n) { throw new RuntimeException('eof'); }
        $c = $s[$p];

        if ($c === '<' && ($s[$p + 1] ?? '') === '<') {
            $p += 2;
            $dict = [];
            while (true) {
                $this->skipWs($s, $p);
                if ($p >= $n) { break; }
                if ($s[$p] === '>' && ($s[$p + 1] ?? '') === '>') { $p += 2; break; }
                $key = $this->parseValue($s, $p);
                if (!$key instanceof PdfName) { continue; }
                $dict[$key->v] = $this->parseValue($s, $p);
            }
            return new PdfDict($dict);
        }
        if ($c === '<') {
            $end = strpos($s, '>', $p);
            if ($end === false) { $end = $n; }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $p + 1, $end - $p - 1));
            $p = $end + 1;
            if (strlen($hex) % 2) { $hex .= '0'; }
            return new PdfStr((string) hex2bin($hex), true);
        }
        if ($c === '[') {
            $p++;
            $arr = [];
            while (true) {
                $this->skipWs($s, $p);
                if ($p >= $n) { break; }
                if ($s[$p] === ']') { $p++; break; }
                $arr[] = $this->parseValue($s, $p);
            }
            return $arr;
        }
        if ($c === '(') {
            return new PdfStr($this->parseLiteral($s, $p));
        }
        if ($c === '/') {
            $p++;
            $start = $p;
            while ($p < $n && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$p]) === false) { $p++; }
            $name = substr($s, $start, $p - $start);
            $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $name);
            return new PdfName($name);
        }
        if ($c === ']' || $c === '>' || $c === ')' || $c === '{' || $c === '}') {
            $p++;
            return new PdfOp($c);
        }
        if (preg_match('/\G[+\-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $isInt = strpbrk($m[0], '.') === false;
            // "n g R" indirect reference
            if ($isInt && preg_match('/\G\s+(\d+)\s+R(?![A-Za-z])/', $s, $r, 0, $p)) {
                $p += strlen($r[0]);
                return new PdfRef((int) $m[0]);
            }
            return $isInt ? (int) $m[0] : (float) $m[0];
        }
        $start = $p;
        while ($p < $n && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$p]) === false) { $p++; }
        if ($p === $start) { $p++; }
        $word = substr($s, $start, $p - $start);
        if ($word === 'true') { return true; }
        if ($word === 'false') { return false; }
        if ($word === 'null') { return null; }
        return new PdfOp($word);
    }

    private function parseLiteral(string $s, int &$p): string
    {
        $p++; // (
        $depth = 1;
        $out = '';
        $n = strlen($s);
        while ($p < $n) {
            $c = $s[$p++];
            if ($c === '\\') {
                $d = $s[$p++] ?? '';
                switch ($d) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\f"; break;
                    case "\r": if (($s[$p] ?? '') === "\n") { $p++; } break;
                    case "\n": break;
                    default:
                        if ($d >= '0' && $d <= '7') {
                            $oct = $d;
                            while (strlen($oct) < 3 && ($s[$p] ?? '') >= '0' && ($s[$p] ?? '') <= '7') { $oct .= $s[$p++]; }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $d;
                        }
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0) { break; }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ pages */

    private function run(): string
    {
        $pages = [];
        $root = null;
        if (preg_match_all('/\/Root\s+(\d+)\s+\d+\s+R/', $this->data, $m)) {
            $root = $this->resolve(new PdfRef((int) end($m[1])));
        }
        if (!$root instanceof PdfDict) {
            foreach ($this->objects as [$v]) {
                if ($v instanceof PdfDict && ($v->get('Type') instanceof PdfName) && $v->get('Type')->v === 'Catalog') {
                    $root = $v;
                }
            }
        }
        if ($root instanceof PdfDict) {
            $this->collectPages($root->get('Pages'), null, $pages, 0);
        }

        $text = [];
        foreach ($pages as [$page, $resources]) {
            $contents = $page->get('Contents');
            $refs = is_array($this->resolveKeepRef($contents)) ? $this->resolveKeepRef($contents) : [$contents];
            $stream = '';
            foreach ($refs as $ref) {
                $decoded = $this->streamOf($ref);
                if ($decoded !== null) { $stream .= $decoded . "\n"; }
            }
            if ($stream !== '') {
                $text[] = $this->renderContent($stream, $resources, 0);
            }
        }
        return trim(implode("\n\n", $text));
    }

    /** Resolves references but keeps array elements as refs (Contents arrays). */
    private function resolveKeepRef($v)
    {
        if ($v instanceof PdfRef) {
            $r = $this->objects[$v->n][0] ?? null;
            return is_array($r) ? $r : $v;
        }
        return $v;
    }

    private function collectPages($node, ?PdfDict $inheritedRes, array &$pages, int $depth): void
    {
        if ($depth > 50) { return; }
        $dict = $this->resolve($node);
        if (!$dict instanceof PdfDict) { return; }
        $res = $this->resolve($dict->get('Resources'));
        if (!$res instanceof PdfDict) { $res = $inheritedRes; }
        $type = $dict->get('Type');
        $kids = $this->resolve($dict->get('Kids'));
        if (is_array($kids) && (!$type instanceof PdfName || $type->v === 'Pages')) {
            foreach ($kids as $kid) {
                $this->collectPages($kid, $res, $pages, $depth + 1);
            }
        } else {
            $pages[] = [$dict, $res ?? new PdfDict()];
        }
    }

    /* ------------------------------------------------------------ fonts */

    private function fontInfo(PdfDict $resources, string $name): array
    {
        $fonts = $this->resolve($resources->get('Font'));
        $ref = $fonts instanceof PdfDict ? $fonts->get($name) : null;
        $key = $ref instanceof PdfRef ? 'r' . $ref->n : 'n' . $name . spl_object_id($resources);
        if (isset($this->fontCache[$key])) { return $this->fontCache[$key]; }

        $info = ['map' => [], 'bytes' => 1, 'diff' => []];
        $font = $this->resolve($ref);
        if ($font instanceof PdfDict) {
            $subtype = $this->resolve($font->get('Subtype'));
            if ($subtype instanceof PdfName && $subtype->v === 'Type0') {
                $info['bytes'] = 2;
            }
            $toUni = $font->get('ToUnicode');
            $cmap = $this->streamOf($toUni);
            if ($cmap !== null) {
                $this->parseCMap($cmap, $info);
            }
            $enc = $this->resolve($font->get('Encoding'));
            if ($enc instanceof PdfDict) {
                $diffs = $this->resolve($enc->get('Differences'));
                if (is_array($diffs)) {
                    $code = 0;
                    foreach ($diffs as $d) {
                        if (is_int($d)) { $code = $d; }
                        elseif ($d instanceof PdfName) { $info['diff'][$code++] = $d->v; }
                    }
                }
            }
        }
        return $this->fontCache[$key] = $info;
    }

    private function parseCMap(string $cmap, array &$info): void
    {
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
            $info['bytes'] = max(1, intdiv(strlen($m[1]), 2));
        }
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $info['map'][hexdec($pair[1])] = $this->utf16hex($pair[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]);
                    $hi = min(hexdec($r[2]), $lo + 65535);
                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $items);
                        foreach ($items[1] as $i => $hex) {
                            $info['map'][$lo + $i] = $this->utf16hex($hex);
                        }
                    } else {
                        $hex = trim($r[3], '<>');
                        $base = hexdec($hex);
                        $width = strlen($hex);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $info['map'][$c] = $this->utf16hex(str_pad(dechex($base + $c - $lo), $width, '0', STR_PAD_LEFT));
                        }
                    }
                }
            }
        }
    }

    private function utf16hex(string $hex): string
    {
        if ($hex === '') { return ''; }
        if (strlen($hex) % 4) { $hex = str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT); }
        return (string) mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }

    private const GLYPHS = [
        'space' => ' ', 'period' => '.', 'comma' => ',', 'colon' => ':', 'semicolon' => ';', 'hyphen' => '-',
        'endash' => '–', 'emdash' => '—', 'percent' => '%', 'parenleft' => '(', 'parenright' => ')',
        'slash' => '/', 'ampersand' => '&', 'quoteright' => '’', 'quoteleft' => '‘', 'bullet' => '•',
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5',
        'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'fi' => 'fi', 'fl' => 'fl',
    ];

    private function decodeString(string $bytes, array $font): string
    {
        $out = '';
        if ($font['bytes'] >= 2) {
            for ($i = 0; $i + 1 < strlen($bytes); $i += 2) {
                $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
                $out .= $font['map'][$code] ?? '';
            }
            return $out;
        }
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $code = ord($bytes[$i]);
            if (isset($font['map'][$code])) {
                $out .= $font['map'][$code];
            } elseif (isset($font['diff'][$code])) {
                $g = $font['diff'][$code];
                $out .= self::GLYPHS[$g] ?? (strlen($g) === 1 ? $g : (preg_match('/^uni([0-9A-F]{4})$/', $g, $u)
                    ? mb_chr(hexdec($u[1]), 'UTF-8') : mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252')));
            } else {
                $out .= mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252');
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ content streams */

    private static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    private function renderContent(string $s, PdfDict $resources, int $depth, array $baseCtm = [1, 0, 0, 1, 0, 0]): string
    {
        $segments = [];
        $this->walkContent($s, $resources, $depth, $baseCtm, $segments);
        return $this->layout($segments);
    }

    private function walkContent(string $s, PdfDict $resources, int $depth, array $ctm, array &$segments): void
    {
        $p = 0;
        $n = strlen($s);
        $ops = [];
        $stack = [];
        $tm = $tlm = [1, 0, 0, 1, 0, 0];
        $font = ['map' => [], 'bytes' => 1, 'diff' => []];
        $size = 10.0;
        $leading = 0.0;

        $emit = function (string $text) use (&$segments, &$tm, &$ctm, &$size) {
            if ($text === '') { return; }
            $m = self::mul($tm, $ctm);
            $scale = sqrt($m[2] * $m[2] + $m[3] * $m[3]) ?: 1.0;
            $fs = abs($size * $scale) ?: 10.0;
            $segments[] = ['x' => $m[4], 'y' => $m[5], 'size' => $fs, 'text' => $text];
            // advance roughly so consecutive Tj calls on one line stay ordered
            $tm[4] += mb_strlen($text) * $size * 0.5;
        };

        while ($p < $n) {
            try {
                $tok = $this->parseValue($s, $p);
            } catch (Throwable $e) {
                break;
            }
            if (!$tok instanceof PdfOp) {
                $ops[] = $tok;
                if (count($ops) > 64) { array_shift($ops); }
                continue;
            }
            switch ($tok->v) {
                case 'q': $stack[] = $ctm; break;
                case 'Q': if ($stack) { $ctm = array_pop($stack); } break;
                case 'cm':
                    if (count($ops) >= 6) {
                        $ctm = self::mul(array_map('floatval', array_slice($ops, -6)), $ctm);
                    }
                    break;
                case 'BT': $tm = $tlm = [1, 0, 0, 1, 0, 0]; break;
                case 'Tf':
                    if (count($ops) >= 2) {
                        $nm = $ops[count($ops) - 2];
                        $size = (float) end($ops);
                        if ($nm instanceof PdfName) { $font = $this->fontInfo($resources, $nm->v); }
                    }
                    break;
                case 'TL': $leading = (float) end($ops); break;
                case 'Td':
                case 'TD':
                    if (count($ops) >= 2) {
                        [$tx, $ty] = array_map('floatval', array_slice($ops, -2));
                        if ($tok->v === 'TD') { $leading = -$ty; }
                        $tlm = self::mul([1, 0, 0, 1, $tx, $ty], $tlm);
                        $tm = $tlm;
                    }
                    break;
                case 'Tm':
                    if (count($ops) >= 6) {
                        $tlm = $tm = array_map('floatval', array_slice($ops, -6));
                    }
                    break;
                case 'T*':
                    $tlm = self::mul([1, 0, 0, 1, 0, -$leading], $tlm);
                    $tm = $tlm;
                    break;
                case "'":
                case '"':
                    $tlm = self::mul([1, 0, 0, 1, 0, -$leading], $tlm);
                    $tm = $tlm;
                    $str = end($ops);
                    if ($str instanceof PdfStr) { $emit($this->decodeString($str->v, $font)); }
                    break;
                case 'Tj':
                    $str = end($ops);
                    if ($str instanceof PdfStr) { $emit($this->decodeString($str->v, $font)); }
                    break;
                case 'TJ':
                    $arr = end($ops);
                    if (is_array($arr)) {
                        $buf = '';
                        foreach ($arr as $item) {
                            if ($item instanceof PdfStr) {
                                $buf .= $this->decodeString($item->v, $font);
                            } elseif ((is_int($item) || is_float($item)) && $item < -180 && $buf !== '' && !str_ends_with($buf, ' ')) {
                                $buf .= ' ';
                            }
                        }
                        $emit($buf);
                    }
                    break;
                case 'Do':
                    $nm = end($ops);
                    if ($depth < 4 && $nm instanceof PdfName) {
                        $xobjs = $this->resolve($resources->get('XObject'));
                        $ref = $xobjs instanceof PdfDict ? $xobjs->get($nm->v) : null;
                        $x = $this->resolve($ref);
                        if ($x instanceof PdfDict && $x->get('Subtype') instanceof PdfName && $x->get('Subtype')->v === 'Form') {
                            $formStream = $this->streamOf($ref);
                            if ($formStream !== null) {
                                $formRes = $this->resolve($x->get('Resources'));
                                $matrix = $this->resolve($x->get('Matrix'));
                                $formCtm = is_array($matrix) && count($matrix) === 6
                                    ? self::mul(array_map('floatval', $matrix), $ctm) : $ctm;
                                $this->walkContent($formStream, $formRes instanceof PdfDict ? $formRes : $resources, $depth + 1, $formCtm, $segments);
                            }
                        }
                    }
                    break;
                case 'BI':
                    $idPos = strpos($s, 'ID', $p);
                    $eiPos = $idPos === false ? false : strpos($s, 'EI', $idPos + 2);
                    while ($eiPos !== false && !preg_match('/\sEI(\s|$)/', substr($s, $eiPos - 1, 4))) {
                        $eiPos = strpos($s, 'EI', $eiPos + 2);
                    }
                    $p = $eiPos === false ? $n : $eiPos + 2;
                    break;
            }
            $ops = [];
        }
    }

    /** Groups positioned text runs into lines (top to bottom, left to right). */
    private function layout(array $segments): string
    {
        if (!$segments) { return ''; }
        usort($segments, fn($a, $b) => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));
        $lines = [];
        foreach ($segments as $seg) {
            $placed = false;
            $tol = max(2.0, $seg['size'] * 0.45);
            foreach ($lines as &$line) {
                if (abs($line['y'] - $seg['y']) <= $tol) {
                    $line['items'][] = $seg;
                    $placed = true;
                    break;
                }
            }
            unset($line);
            if (!$placed) {
                $lines[] = ['y' => $seg['y'], 'items' => [$seg]];
            }
        }
        usort($lines, fn($a, $b) => $b['y'] <=> $a['y']);
        $out = [];
        foreach ($lines as $line) {
            usort($line['items'], fn($a, $b) => $a['x'] <=> $b['x']);
            $text = '';
            $prevEnd = null;
            foreach ($line['items'] as $it) {
                if ($prevEnd !== null) {
                    $gap = $it['x'] - $prevEnd;
                    if ($gap > $it['size'] * 2.5) {
                        $text = rtrim($text) . "\t";
                    } elseif ($gap > $it['size'] * 0.2 && !str_ends_with($text, ' ')) {
                        $text .= ' ';
                    }
                }
                $text .= $it['text'];
                $prevEnd = $it['x'] + mb_strlen($it['text']) * $it['size'] * 0.48;
            }
            $text = trim(preg_replace('/[ ]{2,}/', ' ', $text));
            if ($text !== '') { $out[] = $text; }
        }
        return implode("\n", $out);
    }
}
