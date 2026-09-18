<?php
declare(strict_types=1);

namespace App\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use ZipArchive;

/** Reads paragraphs and tables (as tab-separated rows) from a .docx file. */
final class DocxTextExtractor
{
    public function extract(string $file): string
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('The Word file could not be opened (is it a real .docx?).');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('The Word file has no document body.');
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('The Word file is damaged.');
        }
        $body = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'body')->item(0);
        if (!$body) {
            return '';
        }
        $lines = [];
        $this->block($body, $lines);
        return implode("\n", $lines);
    }

    private function block(DOMNode $node, array &$lines): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    $lines[] = $this->paragraph($child);
                    break;
                case 'tbl':
                    foreach ($child->childNodes as $row) {
                        if (!$row instanceof DOMElement || $row->localName !== 'tr') {
                            continue;
                        }
                        $cells = [];
                        foreach ($row->childNodes as $cell) {
                            if ($cell instanceof DOMElement && $cell->localName === 'tc') {
                                $inner = [];
                                $this->block($cell, $inner);
                                $cells[] = trim(implode(' ', array_filter($inner, fn($l) => trim($l) !== '')));
                            }
                        }
                        $lines[] = implode("\t", $cells);
                    }
                    break;
                case 'sdt':
                case 'sdtContent':
                case 'customXml':
                    $this->block($child, $lines);
                    break;
            }
        }
    }

    private function paragraph(DOMElement $p): string
    {
        $text = '';
        $walk = function (DOMNode $n) use (&$walk, &$text): void {
            foreach ($n->childNodes as $c) {
                if (!$c instanceof DOMElement) {
                    continue;
                }
                switch ($c->localName) {
                    case 't':         $text .= $c->textContent; break;
                    case 'tab':       $text .= "\t"; break;
                    case 'br':
                    case 'cr':        $text .= "\n"; break;
                    case 'delText':
                    case 'instrText': break;
                    default:          $walk($c);
                }
            }
        };
        $walk($p);
        return $text;
    }
}
