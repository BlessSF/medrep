<?php
// ============================================================
//  simple_xlsx_writer.php — tiny multi-sheet, styled .xlsx writer
// ------------------------------------------------------------
//  Builds a real Excel workbook (one worksheet per company) using
//  only PHP's built-in ZipArchive extension — no Composer, no
//  PhpSpreadsheet. An .xlsx file is just a zip of XML files, so
//  this assembles that structure by hand.
//
//  Styling: a colored header row (bold white text), thin borders,
//  a lightly-tinted banded row, frozen header + autofilter, and
//  auto-sized columns — colors are themeable per branch (e.g. HERO
//  is red, STELLA is green) so the exported file matches whichever
//  branch generated it.
//
//  Usage:
//      $writer = new SimpleXlsxWriter(SimpleXlsxWriter::colorsForBranch($branch));
//      $writer->addSheet('Acme Corp', $headers, $rows);
//      $writer->addSheet('Other Co',  $headers, $rows2);
//      $writer->output('transactions_by_company.xlsx');
//
//  $headers is a flat array of column titles.
//  $rows is an array of rows, each row a flat array of values in
//  the same order as $headers. Numeric-looking values are written
//  as numbers; everything else as text.
// ============================================================

class SimpleXlsxWriter
{
    // Default theme (used when no colors are passed in): matches the
    // app's HERO branch red (#c0392b).
    private const DEFAULT_HEADER_FILL_RGB = 'FFC0392B';
    private const DEFAULT_BAND_FILL_RGB   = 'FFFBEBEA';
    private const DEFAULT_BORDER_RGB      = 'FFEEC6C2';

    // Per-instance colors — set in the constructor, defaulting to the
    // HERO red theme above when nothing is passed in.
    private string $headerFillRgb;
    private string $bandFillRgb;
    private string $borderRgb;

    // Style (cellXfs) indices — fixed layout, see stylesXml().
    private const STYLE_HEADER      = 1;
    private const STYLE_TEXT        = 2;
    private const STYLE_TEXT_BAND   = 3;
    private const STYLE_NUMBER      = 4;
    private const STYLE_NUMBER_BAND = 5;

    /** @var array<int, array{name:string, headers:array, rows:array}> */
    private $sheets = [];

    /**
     * @param array{header?:string, band?:string, border?:string} $colors
     *        ARGB hex strings (e.g. 'FFC0392B'). Any key left out falls
     *        back to the default red theme. Use colorsForBranch() to
     *        build this from a branch name instead of hardcoding it.
     */
    public function __construct(array $colors = [])
    {
        $this->headerFillRgb = $colors['header'] ?? self::DEFAULT_HEADER_FILL_RGB;
        $this->bandFillRgb   = $colors['band']   ?? self::DEFAULT_BAND_FILL_RGB;
        $this->borderRgb     = $colors['border'] ?? self::DEFAULT_BORDER_RGB;
    }

    /**
     * Branch -> color theme, mirroring BRANCH_THEMES in
     * frontend-next/src/components/Layout.tsx so Excel exports match
     * each branch's sidebar/brand color. Add new branches to both places.
     */
    public static function colorsForBranch(?string $branch): array
    {
        $themes = [
            'HERO'   => ['header' => 'FFC0392B', 'band' => 'FFFBEBEA', 'border' => 'FFEEC6C2'], // red
            'STELLA' => ['header' => 'FF1B4332', 'band' => 'FFE9F5EC', 'border' => 'FFC9DED0'], // green
        ];
        $key = strtoupper((string)$branch);
        return $themes[$key] ?? $themes['STELLA']; // unlisted branches fall back to green, same as the frontend default theme
    }

    /**
     * Queue up a worksheet. Sheet names are sanitized and de-duplicated
     * automatically (Excel forbids \ / ? * [ ] : and caps names at 31 chars).
     */
    public function addSheet(string $name, array $headers, array $rows): void
    {
        $this->sheets[] = [
            'name'    => $this->sanitizeSheetName($name),
            'headers' => $headers,
            'rows'    => $rows,
        ];
    }


    /** Sends the workbook to the browser as a download and exits. */
    public function output(string $filename): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->write($tmpPath);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpPath));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($tmpPath);
        unlink($tmpPath);
        exit;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function write(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($path, ZipArchive::CREATE);
        if ($openResult !== true) {
            throw new RuntimeException("Could not create xlsx temp file (ZipArchive::open error code {$openResult}). Check that PHP's temp directory ({$path}) is writable.");
        }

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->sheetXml($sheet['headers'], $sheet['rows']));
        }

        $zip->close();
    }

    private function sanitizeSheetName(string $name): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name);
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }
        $clean = substr($clean, 0, 31);

        // De-duplicate against sheets already queued.
        $base   = $clean;
        $suffix = 2;
        $used   = array_column($this->sheets, 'name');
        while (in_array($clean, $used, true)) {
            $tag   = ' (' . $suffix . ')';
            $clean = substr($base, 0, 31 - strlen($tag)) . $tag;
            $suffix++;
        }
        return $clean;
    }

    private static function columnLetter(int $index): string
    {
        // 1-based column index -> spreadsheet column letters (1 -> A, 27 -> AA, ...)
        $letter = '';
        while ($index > 0) {
            $mod    = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index  = intdiv($index - $mod, 26);
        }
        return $letter;
    }

    private static function escapeXml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function isNumericValue($v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }
        // Avoid treating things like phone numbers / IDs with leading
        // zeros as numeric, but let normal amounts through.
        if (is_int($v) || is_float($v)) {
            return true;
        }
        if (!is_string($v)) {
            return false;
        }
        if (preg_match('/^0[0-9]/', $v)) {
            return false;
        }
        return is_numeric($v);
    }

    /**
     * Reasonable auto-width per column, in Excel's "character" width
     * units, based on the longest value (header included) capped to a
     * sane range so one long remark doesn't blow out the whole sheet.
     */
    private function columnWidths(array $headers, array $rows): array
    {
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = strlen((string)$h);
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $val) {
                $len = strlen((string)$val);
                if (!isset($widths[$i]) || $len > $widths[$i]) {
                    $widths[$i] = $len;
                }
            }
        }
        foreach ($widths as $i => $len) {
            $widths[$i] = min(max($len + 3, 10), 32);
        }
        return $widths;
    }

    private function sheetXml(array $headers, array $rows): string
    {
        $lastCol   = self::columnLetter(count($headers));
        $lastRow   = count($rows) + 1;
        $colWidths = $this->columnWidths($headers, $rows);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetPr><tabColor rgb="' . $this->headerFillRgb . '"/></sheetPr>';
        $xml .= '<dimension ref="A1:' . $lastCol . $lastRow . '"/>';
        $xml .= '<sheetViews><sheetView showGridLines="0" workbookViewId="0">'
              . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
              . '</sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="16"/>';

        // Column widths
        $xml .= '<cols>';
        foreach ($colWidths as $i => $w) {
            $c = $i + 1;
            $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        // Header row — bold white text on red fill
        $xml .= '<row r="1" ht="20" customHeight="1">';
        foreach ($headers as $i => $h) {
            $col = self::columnLetter($i + 1) . '1';
            $xml .= '<c r="' . $col . '" t="inlineStr" s="' . self::STYLE_HEADER . '"><is><t xml:space="preserve">'
                . self::escapeXml((string)$h) . '</t></is></c>';
        }
        $xml .= '</row>';

        $r = 2;
        foreach ($rows as $row) {
            $isBand = ($r % 2 === 0); // banded (light red/pink) on even rows
            $xml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $val) {
                $col = self::columnLetter($i + 1) . $r;
                if ($val === null || $val === '') {
                    $style = $isBand ? self::STYLE_TEXT_BAND : self::STYLE_TEXT;
                    $xml .= '<c r="' . $col . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve"></t></is></c>';
                } elseif (self::isNumericValue($val)) {
                    $style = $isBand ? self::STYLE_NUMBER_BAND : self::STYLE_NUMBER;
                    $xml .= '<c r="' . $col . '" s="' . $style . '"><v>' . self::escapeXml((string)$val) . '</v></c>';
                } else {
                    $style = $isBand ? self::STYLE_TEXT_BAND : self::STYLE_TEXT;
                    $xml .= '<c r="' . $col . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
                        . self::escapeXml((string)$val) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData>';
        $xml .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
        $xml .= '</worksheet>';
        return $xml;
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $sheetsXml .= '<sheet name="' . self::escapeXml($sheet['name']) . '" sheetId="' . $n
                . '" r:id="rId' . $n . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        $n = 0;
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $rels .= '<Relationship Id="rId' . $n . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $stylesRid = $n + 1;
        $rels .= '<Relationship Id="rId' . $stylesRid . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        // Fonts: 0 = default, 1 = bold white (header)
        // Fills: 0 = none, 1 = gray125 (reserved slot Excel expects), 2 = red (header), 3 = light red/pink (banded rows)
        // Borders: 0 = none, 1 = thin light-red border on all sides
        //
        // cellXfs (referenced by cell s="N"):
        //   0 default          — unused directly, base style
        //   1 STYLE_HEADER      — bold white on red, centered, thin border
        //   2 STYLE_TEXT        — thin border, plain white background
        //   3 STYLE_TEXT_BAND   — thin border, light-red/pink banded background
        //   4 STYLE_NUMBER      — thin border, number format, plain background
        //   5 STYLE_NUMBER_BAND — thin border, number format, light-red/pink banded background
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><color rgb="FF1E1E1E"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . $this->headerFillRgb . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . $this->bandFillRgb . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="' . $this->borderRgb . '"/></left>'
            . '<right style="thin"><color rgb="' . $this->borderRgb . '"/></right>'
            . '<top style="thin"><color rgb="' . $this->borderRgb . '"/></top>'
            . '<bottom style="thin"><color rgb="' . $this->borderRgb . '"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center"/></xf>' // 1 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>' // 2 text
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>' // 3 text banded
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>' // 4 number
            . '<xf numFmtId="4" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"/>' // 5 number banded
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}