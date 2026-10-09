<?php
declare(strict_types=1);

/**
 * Minimal pure-PHP PDF writer: text lines, table rows, page breaks, Helvetica.
 * Output is PDF 1.4 with a single xref table.
 * ponytail: no images/compression/word-wrap; add when a report needs them.
 */
class SimplePDF
{
    private const FONT = '/F1';
    private const FONT_BOLD = '/F2';

    private array $pages = [];
    private int $current = -1;
    private float $y = 0.0;
    private float $W;
    private float $H;
    private const MT = 40.0;
    private const MB = 40.0;
    private const ML = 40.0;
    private const MR = 40.0;

    private string $title;
    private string $author;

    public function __construct(string $title = '', string $author = '', bool $landscape = false)
    {
        $this->title = $title;
        $this->author = $author;
        if ($landscape) {
            $this->W = 842.0;
            $this->H = 595.0;
        } else {
            $this->W = 595.0;
            $this->H = 842.0;
        }
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->current++;
        $this->pages[$this->current] = ['content' => ''];
        $this->y = self::MT;
    }

    public function text(string $text, float $fontSize = 11.0, bool $bold = false): void
    {
        $lineHeight = $fontSize * 1.35;
        if ($this->y + $lineHeight > $this->H - self::MB) {
            $this->addPage();
        }
        $this->drawLine($text, self::ML, $fontSize, $bold);
        $this->y += $lineHeight;
    }

    public function heading(string $text, int $level = 1): void
    {
        $sizes = [1 => 18.0, 2 => 13.0, 3 => 11.5];
        $this->text($text, $sizes[$level] ?? 11.0, true);
        $this->ln(4.0);
    }

    public function table(array $headers, array $rows, ?array $weights = null): void
    {
        $cols = count($headers);
        $avail = $this->W - self::ML - self::MR;
        $weights = $weights ?: array_fill(0, $cols, 1.0);
        $sum = array_sum($weights) ?: 1.0;
        $widths = [];
        foreach ($weights as $i => $w) {
            $widths[$i] = $avail * $w / $sum;
        }

        $rowHeight = 14.0;
        $this->tableRow($headers, $widths, true, $rowHeight);
        foreach ($rows as $row) {
            if ($this->y + $rowHeight > $this->H - self::MB) {
                $this->addPage();
                $this->tableRow($headers, $widths, true, $rowHeight);
            }
            $this->tableRow(array_values($row), $widths, false, $rowHeight);
        }
        $this->ln(10.0);
    }

    public function ln(float $height = 5.0): void
    {
        $this->y += $height;
        if ($this->y > $this->H - self::MB) {
            $this->addPage();
        }
    }

    private function tableRow(array $cells, array $widths, bool $bold, float $rowHeight): void
    {
        $x = self::ML;
        foreach ($cells as $i => $cell) {
            $this->drawLine((string) $cell, $x, 8.5, $bold);
            $x += $widths[$i] ?? 0.0;
        }
        $this->y += $rowHeight;
    }

    private function drawLine(string $text, float $x, float $fontSize, bool $bold): void
    {
        $font = $bold ? self::FONT_BOLD : self::FONT;
        $this->pages[$this->current]['content'] .= sprintf(
            "BT%s %s Tf%s %s Td(%s) TjET\n",
            $font,
            $this->fmt($fontSize),
            $this->fmt($x),
            $this->fmt($this->H - $this->y),
            $this->escape($this->winAnsi($text))
        );
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function winAnsi(string $s): string
    {
        if (!preg_match('/[\x80-\xFF]/', $s)) {
            return $s;
        }
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $out .= $s[$i];
                continue;
            }
            if (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (ord($s[++$i]) & 0x3F);
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12) | ((ord($s[++$i]) & 0x3F) << 6) | (ord($s[++$i]) & 0x3F);
            } else {
                $out .= '?';
                continue;
            }
            if ($cp < 0x80) {
                $out .= chr($cp);
            } elseif ($cp >= 0xA0 && $cp <= 0xFF) {
                $out .= chr($cp);
            } else {
                $out .= '?';
            }
        }
        return $out;
    }

    public function output(): string
    {
        $catalogId = 1;
        $pagesId = 2;
        $fontId = 3;
        $fontBoldId = 4;
        $infoId = 5;
        $nextId = 6;

        $pageIds = [];
        $contentIds = [];
        foreach ($this->pages as $i => $p) {
            $pageIds[$i] = $nextId++;
            $contentIds[$i] = $nextId++;
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        $offsets[$catalogId] = strlen($pdf);
        $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";

        $pageRefs = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $offsets[$pagesId] = strlen($pdf);
        $pdf .= sprintf("2 0 obj<</Type/Pages/Kids[%s]/Count %d>>endobj\n", $pageRefs, count($this->pages));

        $offsets[$fontId] = strlen($pdf);
        $pdf .= "3 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n";

        $offsets[$fontBoldId] = strlen($pdf);
        $pdf .= "4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica-Bold>>endobj\n";

        $offsets[$infoId] = strlen($pdf);
        $pdf .= sprintf(
            "5 0 obj<</Title(%s)/Author(%s)/Producer(SimplePDF)>>endobj\n",
            $this->escape($this->winAnsi($this->title)),
            $this->escape($this->winAnsi($this->author))
        );

        foreach ($this->pages as $i => $page) {
            $pageId = $pageIds[$i];
            $contentId = $contentIds[$i];

            $offsets[$pageId] = strlen($pdf);
            $pdf .= sprintf(
                "%d 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 %s %s]/Contents %d 0 R/Resources<</Font<</F1 3 0 R/F2 4 0 R>>>>>>endobj\n",
                $pageId,
                $this->fmt($this->W),
                $this->fmt($this->H),
                $contentId
            );

            $stream = $page['content'];
            $offsets[$contentId] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj<</Length %d>>stream\n%sendstream\nendobj\n", $contentId, strlen($stream), $stream);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($nextId) . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $nextId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer<</Size " . $nextId . "/Root 1 0 R/Info 5 0 R>>\nstartxref\n$xrefOffset\n%%EOF\n";
        return $pdf;
    }

    public function download(string $filename): void
    {
        $ascii = preg_replace('/[^a-zA-Z0-9_.\\-]/', '_', $filename);
        $encoded = rawurlencode($filename);
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$encoded}");
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $this->output();
        exit;
    }
}
