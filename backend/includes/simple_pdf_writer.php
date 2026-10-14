<?php
// ============================================================
//  simple_pdf_writer.php — tiny, dependency-free PDF writer for
//  a single customer's transaction statement.
// ------------------------------------------------------------
//  Builds a real PDF (v1.4) by hand, one object/content-stream at
//  a time — no Composer, no TCPDF/mPDF/dompdf. Only uses the
//  standard 14 PDF fonts (Helvetica / Helvetica-Bold), so it needs
//  nothing beyond core PHP to run on shared hosting.
//
//  Layout mirrors the old print view: a bold NAME / COMPANY /
//  summary block up top, then a colored table of transactions.
//  Colors are themeable per branch (HERO = red, STELLA = green),
//  same idea as SimpleXlsxWriter.
//
//  Note: the standard 14 fonts use WinAnsiEncoding, which has no
//  peso sign (₱). Amounts are written as "PHP 1,234.00" instead of
//  using the ₱ glyph, so every PDF viewer renders them correctly.
//
//  Usage:
//      $pdf = new SimplePdfWriter(SimplePdfWriter::colorsForBranch($branch));
//      $pdf->addStatement(
//          ['NAME: JEZ', 'COMPANY: NESTLE PHILS. INC.'],
//          ['Total Balance' => 'PHP 0.00', 'Record Total (Card)' => 'PHP 0.00'],
//          $columns,   // flat array of column headers
//          $rows,      // array of rows, each a flat array of cell strings
//          $colAlign   // optional: 'L'|'R' per column, defaults to 'L'
//      );
//      $pdf->output('statement.pdf');
// ============================================================

class SimplePdfWriter
{
    // Page geometry (A4 landscape, in points) — a 10-column transaction
    // table needs the extra width; portrait forced font sizes/columns
    // too tight to stay legible without overlapping.
    private const PAGE_W = 841.89;
    private const PAGE_H = 595.28;
    private const MARGIN = 36;

    private const FONT_SIZE_BODY   = 8.5;
    private const FONT_SIZE_HEAD   = 9;
    private const FONT_SIZE_TITLE  = 11;
    private const ROW_HEIGHT       = 16;
    private const HEADER_ROW_HEIGHT = 20;

    // Default theme: HERO red.
    private const DEFAULT_HEADER_RGB = [0.7529, 0.2235, 0.1686]; // C0392B
    private const DEFAULT_BAND_RGB   = [0.9843, 0.9216, 0.9176]; // FBEBEA
    private const DEFAULT_BORDER_RGB = [0.9333, 0.7765, 0.7608]; // EEC6C2

    private array $headerRgb;
    private array $bandRgb;
    private array $borderRgb;

    // Standard Adobe AFM advance widths (in 1/1000 em) for the printable
    // ASCII range (32-126), which is all WinAnsiEncoding needs here. Using
    // real metrics (instead of a flat per-character average) is what keeps
    // right-aligned numeric columns from drifting into their neighbors.
    private const HELV_WIDTHS = [
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,
        42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
        52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,
        62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,
        82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,
        92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,
        102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
        111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
        120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
    ];
    private const HELV_BOLD_WIDTHS = [
        32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,
        42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
        52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,
        62=>584,63=>611,64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,80=>667,81=>778,
        82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,
        92=>278,93=>333,94=>584,95=>556,96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,
        102=>333,103=>611,104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,
        111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,
        120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
    ];

    /** @var array<int, string> raw content stream text per page */
    private array $pageStreams = [];

    public function __construct(array $colors = [])
    {
        $this->headerRgb = $colors['header'] ?? self::DEFAULT_HEADER_RGB;
        $this->bandRgb   = $colors['band']   ?? self::DEFAULT_BAND_RGB;
        $this->borderRgb = $colors['border'] ?? self::DEFAULT_BORDER_RGB;
    }

    /**
     * Branch -> color theme, mirroring BRANCH_THEMES in
     * frontend-next/src/components/Layout.tsx and
     * SimpleXlsxWriter::colorsForBranch(), so PDF statements match
     * each branch's sidebar/brand color.
     */
    public static function colorsForBranch(?string $branch): array
    {
        $themes = [
            'HERO'   => [
                'header' => [0.7529, 0.2235, 0.1686], // C0392B
                'band'   => [0.9843, 0.9216, 0.9176], // FBEBEA
                'border' => [0.9333, 0.7765, 0.7608], // EEC6C2
            ],
            'STELLA' => [
                'header' => [0.1059, 0.2627, 0.1961], // 1B4332
                'band'   => [0.9137, 0.9608, 0.9255], // E9F5EC
                'border' => [0.7882, 0.8706, 0.8157], // C9DED0
            ],
        ];
        $key = strtoupper((string)$branch);
        return $themes[$key] ?? $themes['STELLA'];
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Lay out one customer's statement across as many pages as needed.
     *
     * @param string[] $titleLines   e.g. ['NAME: JEZ', 'COMPANY: NESTLE PHILS. INC.']
     * @param array<string,string> $summary  e.g. ['Total Balance' => 'PHP 0.00']
     * @param string[] $columns      column headers, left to right
     * @param array<int,string[]> $rows  each row is a flat array of cell text, same order as $columns
     * @param array<int,string> $colAlign  optional per-column alignment ('L' or 'R'); defaults to 'L'
     */
    public function addStatement(array $titleLines, array $summary, array $columns, array $rows, array $colAlign = []): void
    {
        $usableWidth = self::PAGE_W - 2 * self::MARGIN;
        $colWidths = $this->columnWidths($columns, $rows, $usableWidth);

        $y = self::PAGE_H - self::MARGIN;
        $page = $this->newPageBuffer();

        // ---- Title block (first page only) ----
        $y -= self::FONT_SIZE_TITLE + 4;
        foreach ($titleLines as $line) {
            $page .= $this->textOp(self::MARGIN, $y, $line, 'B', self::FONT_SIZE_TITLE + 3, [0.09, 0.14, 0.10]);
            $y -= (self::FONT_SIZE_TITLE + 3) + 6;
        }
        $y -= 2;
        foreach ($summary as $label => $value) {
            $page .= $this->textOp(self::MARGIN, $y, $label . ': ' . $value, 'B', self::FONT_SIZE_TITLE, [0.09, 0.14, 0.10]);
            $y -= self::FONT_SIZE_TITLE + 7;
        }
        $y -= 6;

        // Section label + accent underline, like the old print view.
        $page .= $this->textOp(self::MARGIN, $y, 'Transaction History', 'B', self::FONT_SIZE_TITLE, [0.09, 0.14, 0.10]);
        $y -= 4;
        $page .= $this->lineOp(self::MARGIN, $y, self::PAGE_W - self::MARGIN, $y, $this->headerRgb, 1.5);
        $y -= 14;

        // ---- Table header ----
        [$page, $y] = $this->drawTableHeader($page, $y, $columns, $colWidths);

        // ---- Rows, paginating as needed ----
        $rowIndex = 0;
        foreach ($rows as $r => $row) {
            if ($y - self::ROW_HEIGHT < self::MARGIN + 24) {
                $this->pageStreams[] = $page;
                $page = $this->newPageBuffer();
                $y = self::PAGE_H - self::MARGIN;
                [$page, $y] = $this->drawTableHeader($page, $y, $columns, $colWidths);
            }
            $band = ($rowIndex % 2 === 1);
            $page = $this->drawTableRow($page, $y, $row, $colWidths, $band, $colAlign);
            $y -= self::ROW_HEIGHT;
            $rowIndex++;
        }

        if (empty($rows)) {
            $emptyY = $y - self::ROW_HEIGHT;
            $page .= $this->textOp(self::MARGIN + 4, $emptyY + 4.5, 'No transactions in this period.', 'R', self::FONT_SIZE_BODY, [0.5, 0.5, 0.5]);
            $y = $emptyY;
        }

        $this->pageStreams[] = $page;

        // Footer (page numbers + generated timestamp) on every page.
        $total = count($this->pageStreams);
        $generated = 'Generated ' . date('Y-m-d H:i');
        foreach ($this->pageStreams as $i => &$stream) {
            $stream .= $this->textOp(self::MARGIN, self::MARGIN - 18, $generated, 'R', 7.5, [0.55, 0.5, 0.45]);
            $label = 'Page ' . ($i + 1) . ' of ' . $total;
            $labelW = $this->textWidth($label, 'R', 7.5);
            $stream .= $this->textOp(self::PAGE_W - self::MARGIN - $labelW, self::MARGIN - 18, $label, 'R', 7.5, [0.55, 0.5, 0.45]);
        }
        unset($stream);
    }

    /** Sends the assembled PDF to the browser as a download and exits. */
    public function output(string $filename): void
    {
        $pdf = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $pdf;
        exit;
    }

    /** Returns the raw PDF bytes without sending headers (useful for tests). */
    public function bytes(): string
    {
        return $this->build();
    }

    // ------------------------------------------------------------------
    // Layout helpers
    // ------------------------------------------------------------------

    private function newPageBuffer(): string
    {
        return '';
    }

    private function columnWidths(array $columns, array $rows, float $usableWidth): array
    {
        // Natural width per column = the widest of its (bold) header and
        // its (regular) cell contents, using real glyph metrics, plus
        // fixed left+right padding. Computed BEFORE proportional scaling
        // so padding is never squeezed out — that's what previously let
        // right-aligned numbers drift past their column's left edge.
        $padding = 8.0; // 4pt each side
        $natural = [];
        foreach ($columns as $i => $h) {
            $natural[$i] = $this->textWidth((string)$h, 'B', self::FONT_SIZE_HEAD) + $padding;
        }
        foreach ($rows as $row) {
            foreach ($columns as $i => $h) {
                $val = (string)($row[$i] ?? '');
                $w = $this->textWidth($val, 'R', self::FONT_SIZE_BODY) + $padding;
                if ($w > $natural[$i]) $natural[$i] = $w;
            }
        }

        $totalNatural = array_sum($natural) ?: 1;
        $scale = $usableWidth / $totalNatural;

        $widths = [];
        foreach ($natural as $i => $w) {
            $widths[$i] = $w * $scale;
        }
        return $widths;
    }

    private function drawTableHeader(string $page, float $y, array $columns, array $colWidths): array
    {
        $rowTop = $y;
        $rowBottom = $y - self::HEADER_ROW_HEIGHT;
        $page .= $this->rectOp(self::MARGIN, $rowBottom, array_sum($colWidths), self::HEADER_ROW_HEIGHT, $this->headerRgb, true);

        $x = self::MARGIN;
        foreach ($columns as $i => $h) {
            $w = $colWidths[$i];
            $page .= $this->textOp($x + 4, $rowBottom + 6, (string)$h, 'B', self::FONT_SIZE_HEAD, [1, 1, 1]);
            $x += $w;
        }
        return [$page, $rowBottom];
    }

    private function drawTableRow(string $page, float $y, array $row, array $colWidths, bool $band, array $colAlign): string
    {
        $rowBottom = $y - self::ROW_HEIGHT;
        $totalWidth = array_sum($colWidths);

        if ($band) {
            $page .= $this->rectOp(self::MARGIN, $rowBottom, $totalWidth, self::ROW_HEIGHT, $this->bandRgb, true);
        }
        // Bottom border line under each row for a clean grid look.
        $page .= $this->lineOp(self::MARGIN, $rowBottom, self::MARGIN + $totalWidth, $rowBottom, $this->borderRgb, 0.6);

        $x = self::MARGIN;
        foreach ($row as $i => $val) {
            $w = $colWidths[$i] ?? 40;
            $align = $colAlign[$i] ?? 'L';
            $text = (string)$val;
            if ($align === 'R') {
                $tw = $this->textWidth($text, 'R', self::FONT_SIZE_BODY);
                $tx = $x + $w - 4 - $tw;
            } else {
                $tx = $x + 4;
            }
            $page .= $this->textOp($tx, $rowBottom + 4.5, $text, 'R', self::FONT_SIZE_BODY, [0.09, 0.09, 0.09]);
            $x += $w;
        }
        return $page;
    }

    // ------------------------------------------------------------------
    // Low-level content-stream operators
    // ------------------------------------------------------------------

    /** 'R' = Helvetica (regular), 'B' = Helvetica-Bold. */
    private function textOp(float $x, float $y, string $text, string $font, float $size, array $rgb): string
    {
        $fontTag = $font === 'B' ? '/F2' : '/F1';
        [$r, $g, $b] = $rgb;
        $escaped = $this->escapeText($text);
        return sprintf(
            "BT %s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $fontTag, $size, $r, $g, $b, $x, $y, $escaped
        );
    }

    private function rectOp(float $x, float $y, float $w, float $h, array $rgb, bool $fill): string
    {
        [$r, $g, $b] = $rgb;
        $op = $fill ? 're f' : 're S';
        return sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f %s\n", $r, $g, $b, $x, $y, $w, $h, $op);
    }

    private function lineOp(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width): string
    {
        [$r, $g, $b] = $rgb;
        return sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $r, $g, $b, $width, $x1, $y1, $x2, $y2
        );
    }

    /** Accurate text width using real Helvetica/Helvetica-Bold AFM advance widths. */
    private function textWidth(string $text, string $font, float $size): float
    {
        $table = $font === 'B' ? self::HELV_BOLD_WIDTHS : self::HELV_WIDTHS;
        $total = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($text[$i]);
            $total += $table[$code] ?? 556;
        }
        return $total / 1000 * $size;
    }

    private function escapeText(string $text): string
    {
        // WinAnsiEncoding only — strip anything outside printable ASCII/Latin-1
        // that base-14 fonts can't render, so a stray Unicode char never
        // corrupts the content stream.
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    // ------------------------------------------------------------------
    // PDF file assembly (objects, xref, trailer)
    // ------------------------------------------------------------------

    private function build(): string
    {
        $objects = [];   // 1-based index => object body (without "N 0 obj"/"endobj")
        $pageIds = [];

        // Reserve: 1 = Catalog, 2 = Pages, 3 = Font Helvetica, 4 = Font Helvetica-Bold
        $catalogId = 1;
        $pagesId   = 2;
        $fontRId   = 3;
        $fontBId   = 4;
        $nextId    = 5;

        $objects[$fontRId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fontBId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $contentIds = [];
        foreach ($this->pageStreams as $stream) {
            $cid = $nextId++;
            $objects[$cid] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
            $contentIds[] = $cid;
        }

        foreach ($contentIds as $cid) {
            $pid = $nextId++;
            $objects[$pid] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                $pagesId, self::PAGE_W, self::PAGE_H, $fontRId, $fontBId, $cid
            );
            $pageIds[] = $pid;
        }

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $objects[$pagesId] = "<< /Type /Pages /Kids [$kids] /Count " . count($pageIds) . " >>";
        $objects[$catalogId] = "<< /Type /Catalog /Pages $pagesId 0 R >>";

        ksort($objects);

        // ---- Serialize with an accurate xref table ----
        $out = "%PDF-1.4\n";
        // Binary marker comment recommended by spec for 8-bit clean files.
        $out .= "%\xE2\xE3\xCF\xD3\n";

        $offsets = [];
        $maxId = max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            if (!isset($objects[$id])) continue;
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xrefStart = strlen($out);
        $count = $maxId + 1;
        $out .= "xref\n0 $count\n";
        $out .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            if (isset($offsets[$id])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
            } else {
                $out .= "0000000000 00000 f \n";
            }
        }

        $out .= "trailer\n<< /Size $count /Root $catalogId 0 R >>\n";
        $out .= "startxref\n$xrefStart\n%%EOF";

        return $out;
    }
}