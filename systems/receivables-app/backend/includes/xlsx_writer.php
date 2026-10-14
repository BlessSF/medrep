<?php
/**
 * Minimal, dependency-free XLSX (OOXML) writer.
 *
 * No Composer / PhpSpreadsheet required -- just PHP's built-in ZipArchive.
 * Supports exactly what the exports need: a styled bold header row, banded
 * rows, currency / date number formats, colored status "badges" that mirror
 * the web app's palette, a bold totals row, frozen header, and autofilter.
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $sheet = $xlsx->addSheet('Ledger', [
 *       ['header' => 'Company', 'width' => 22, 'type' => 'string'],
 *       ['header' => 'Amount',  'width' => 13, 'type' => 'money'],
 *   ]);
 *   $sheet->addRow(['ACME', 1234.5]);
 *   $sheet->setTotals(['TOTAL', 1234.5]);
 *   $xlsx->output('report.xlsx');
 */

/**
 * Minimal in-memory ZIP writer with zero PHP-extension dependencies.
 *
 * Replaces ZipArchive (which needs the php_zip extension enabled -- not
 * guaranteed on every host/XAMPP install). Uses gzdeflate() for real
 * compression when zlib is available, and falls back to STORED
 * (uncompressed) entries if it isn't. Only supports what an XLSX package
 * needs: adding string content as named entries and building the final
 * archive bytes.
 */
class MiniZipWriter
{
    /** @var array<int,array{name:string,data:string}> */
    private array $files = [];

    public function addFromString(string $name, string $data): void
    {
        $this->files[] = ['name' => $name, 'data' => $data];
    }

    public function build(): string
    {
        $localChunks = '';
        $centralChunks = '';
        $offset = 0;
        $useDeflate = function_exists('gzdeflate');

        foreach ($this->files as $file) {
            $name = $file['name'];
            $data = $file['data'];
            $crc = crc32($data);
            $uncompLen = strlen($data);

            if ($useDeflate) {
                $compressed = gzdeflate($data, 6);
                $method = 8; // deflate
            } else {
                $compressed = $data;
                $method = 0; // stored
            }
            $compLen = strlen($compressed);
            $nameLen = strlen($name);

            $local = pack('V', 0x04034b50)
                . pack('v', 20)      // version needed to extract
                . pack('v', 0)       // general purpose bit flag
                . pack('v', $method)
                . pack('v', 0)       // last mod file time
                . pack('v', 0)       // last mod file date
                . pack('V', $crc)
                . pack('V', $compLen)
                . pack('V', $uncompLen)
                . pack('v', $nameLen)
                . pack('v', 0)       // extra field length
                . $name
                . $compressed;

            $localChunks .= $local;

            $central = pack('V', 0x02014b50)
                . pack('v', 20)      // version made by
                . pack('v', 20)      // version needed to extract
                . pack('v', 0)       // general purpose bit flag
                . pack('v', $method)
                . pack('v', 0)       // last mod file time
                . pack('v', 0)       // last mod file date
                . pack('V', $crc)
                . pack('V', $compLen)
                . pack('V', $uncompLen)
                . pack('v', $nameLen)
                . pack('v', 0)       // extra field length
                . pack('v', 0)       // file comment length
                . pack('v', 0)       // disk number start
                . pack('v', 0)       // internal file attributes
                . pack('V', 0)       // external file attributes
                . pack('V', $offset) // relative offset of local header
                . $name;

            $centralChunks .= $central;
            $offset += strlen($local);
        }

        $centralOffset = $offset;
        $centralSize = strlen($centralChunks);
        $count = count($this->files);

        $end = pack('V', 0x06054b50)
            . pack('v', 0)          // number of this disk
            . pack('v', 0)          // disk where central directory starts
            . pack('v', $count)     // records on this disk
            . pack('v', $count)     // total records
            . pack('V', $centralSize)
            . pack('V', $centralOffset)
            . pack('v', 0);         // comment length

        return $localChunks . $centralChunks . $end;
    }
}

class XlsxStyleRegistry
{
    private array $fonts = [['bold' => false, 'color' => null]]; // idx0 default
    private array $fills = [null, 'gray125-placeholder'];        // idx0 none, idx1 reserved (spec requires it)
    private array $numFmts = [];      // code => id (custom ids start at 164)
    private array $xfs = [
        ['font' => 0, 'fill' => 0, 'numFmtId' => 0, 'border' => false, 'align' => null],
    ]; // idx0 default
    private array $xfCache = [];

    private function fontIdx(bool $bold, ?string $color): int
    {
        $key = ($bold ? 1 : 0) . '|' . ($color ?? '');
        foreach ($this->fonts as $i => $f) {
            if ($f['bold'] === $bold && $f['color'] === $color) {
                return $i;
            }
        }
        $this->fonts[] = ['bold' => $bold, 'color' => $color];
        return count($this->fonts) - 1;
    }

    private function fillIdx(?string $argb): int
    {
        if ($argb === null) {
            return 0;
        }
        foreach ($this->fills as $i => $f) {
            if ($f === $argb) {
                return $i;
            }
        }
        $this->fills[] = $argb;
        return count($this->fills) - 1;
    }

    private function numFmtId(?string $code): int
    {
        if ($code === null) {
            return 0; // General
        }
        if (isset($this->numFmts[$code])) {
            return $this->numFmts[$code];
        }
        $id = 164 + count($this->numFmts);
        $this->numFmts[$code] = $id;
        return $id;
    }

    /**
     * Returns a cellXfs index for the given style options, reusing an
     * existing entry when one already matches.
     *
     * @param array{bold?:bool,color?:?string,fill?:?string,numFmt?:?string,border?:bool,align?:?string} $opts
     */
    public function get(array $opts = []): int
    {
        $bold = $opts['bold'] ?? false;
        $color = $opts['color'] ?? null;
        $fill = $opts['fill'] ?? null;
        $numFmt = $opts['numFmt'] ?? null;
        $border = $opts['border'] ?? true;
        $align = $opts['align'] ?? null;

        $fontIdx = $this->fontIdx($bold, $color);
        $fillIdx = $this->fillIdx($fill);
        $numFmtId = $this->numFmtId($numFmt);

        $key = "$fontIdx|$fillIdx|$numFmtId|" . ($border ? 1 : 0) . '|' . ($align ?? '');
        if (isset($this->xfCache[$key])) {
            return $this->xfCache[$key];
        }
        $this->xfs[] = ['font' => $fontIdx, 'fill' => $fillIdx, 'numFmtId' => $numFmtId, 'border' => $border, 'align' => $align];
        $idx = count($this->xfs) - 1;
        $this->xfCache[$key] = $idx;
        return $idx;
    }

    public function renderStylesXml(): string
    {
        $numFmtsXml = '';
        foreach ($this->numFmts as $code => $id) {
            $numFmtsXml .= '<numFmt numFmtId="' . $id . '" formatCode="' . htmlspecialchars($code, ENT_QUOTES | ENT_XML1) . '"/>';
        }

        $fontsXml = '';
        foreach ($this->fonts as $f) {
            $fontsXml .= '<font><sz val="11"/><name val="Calibri"/>';
            if ($f['bold']) {
                $fontsXml .= '<b/>';
            }
            if ($f['color']) {
                $fontsXml .= '<color rgb="' . $f['color'] . '"/>';
            }
            $fontsXml .= '</font>';
        }

        $fillsXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach (array_slice($this->fills, 2) as $argb) {
            $fillsXml .= '<fill><patternFill patternType="solid"><fgColor rgb="' . $argb . '"/><bgColor indexed="64"/></patternFill></fill>';
        }

        $bordersXml = '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFE3E6EC"/></left><right style="thin"><color rgb="FFE3E6EC"/></right>'
            . '<top style="thin"><color rgb="FFE3E6EC"/></top><bottom style="thin"><color rgb="FFE3E6EC"/></bottom><diagonal/></border>';

        $xfsXml = '';
        foreach ($this->xfs as $xf) {
            $alignXml = '';
            if ($xf['align']) {
                $alignXml = '<alignment horizontal="' . $xf['align'] . '" vertical="center"/>';
            } else {
                $alignXml = '<alignment vertical="center"/>';
            }
            $applyNumFmt = $xf['numFmtId'] > 0 ? '1' : '0';
            $borderId = $xf['border'] ? 1 : 0;
            $xfsXml .= '<xf numFmtId="' . $xf['numFmtId'] . '" fontId="' . $xf['font'] . '" fillId="' . $xf['fill']
                . '" borderId="' . $borderId . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="' . $applyNumFmt . '" applyAlignment="1">'
                . $alignXml . '</xf>';
        }

        $numFmtsBlock = $numFmtsXml ? '<numFmts count="' . count($this->numFmts) . '">' . $numFmtsXml . '</numFmts>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmtsBlock
            . '<fonts count="' . count($this->fonts) . '">' . $fontsXml . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . $fillsXml . '</fills>'
            . '<borders count="2">' . $bordersXml . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . $xfsXml . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

class XlsxSheet
{
    public string $name;
    /** @var array<int,array{header:string,width:float,type:string,align?:string}> */
    public array $columns;
    /** @var array<int,array<int,array{value:mixed,style:int}>> */
    public array $rows = [];
    /** @var array<int,array{value:mixed,style:int}>|null */
    public ?array $totals = null;
    public bool $freezeHeader = true;
    public bool $freezeFirstCol = false;
    public bool $autofilter = true;

    private XlsxStyleRegistry $styles;
    private array $bandStyleCache = [];
    private array $overrideStyleCache = [];
    private int $headerStyle;

    /** Palette matching the app's CSS (assets/css/style.css) */
    public const PALETTE = [
        'accent' => 'FF2E6EE0',
        'band' => 'FFEEF0F4',
        'red' => 'FFC22B2B',
        'red_bg' => 'FFFBE7E7',
        'amber' => 'FFB4790C',
        'amber_bg' => 'FFFDF1DC',
        'orange' => 'FFC15A10',
        'orange_bg' => 'FFFDEAD9',
        'green' => 'FF1A8A4A',
        'green_bg' => 'FFE7F7EE',
        'gray' => 'FF6B7688',
    ];

    public function __construct(string $name, array $columns, XlsxStyleRegistry $styles)
    {
        $this->name = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name), 0, 31);
        $this->columns = $columns;
        $this->styles = $styles;
        $this->headerStyle = $styles->get([
            'bold' => true, 'color' => 'FFFFFFFF', 'fill' => self::PALETTE['accent'], 'align' => 'left', 'border' => true,
        ]);
    }

    private function numFmtFor(string $type): ?string
    {
        return match ($type) {
            'money' => '"₱"#,##0.00',
            'date' => 'm/d/yyyy',
            'int' => '#,##0',
            default => null,
        };
    }

    private function bandStyle(int $colIdx, bool $band): int
    {
        $key = $colIdx . '|' . ($band ? 1 : 0);
        if (isset($this->bandStyleCache[$key])) {
            return $this->bandStyleCache[$key];
        }
        $col = $this->columns[$colIdx];
        $idx = $this->styles->get([
            'numFmt' => $this->numFmtFor($col['type']),
            'fill' => $band ? self::PALETTE['band'] : null,
            'align' => $col['align'] ?? ($col['type'] === 'money' || $col['type'] === 'int' ? 'right' : ($col['type'] === 'date' ? 'center' : 'left')),
        ]);
        $this->bandStyleCache[$key] = $idx;
        return $idx;
    }

    /**
     * Add a data row. $values aligned to columns. Pass a badge key (e.g.
     * 'red', 'green', 'amber', 'orange', 'gray') in $highlights[$colIdx] to
     * color that specific cell like the app's status badges.
     */
    public function addRow(array $values, array $highlights = []): void
    {
        $band = count($this->rows) % 2 === 1;
        $cells = [];
        foreach ($this->columns as $i => $col) {
            $value = $values[$i] ?? null;
            if (isset($highlights[$i])) {
                $cells[] = ['value' => $value, 'style' => $this->highlightStyle($i, $highlights[$i])];
            } else {
                $cells[] = ['value' => $value, 'style' => $this->bandStyle($i, $band)];
            }
        }
        $this->rows[] = $cells;
    }

    private function highlightStyle(int $colIdx, string $badge): int
    {
        $key = $colIdx . '|badge|' . $badge;
        if (isset($this->overrideStyleCache[$key])) {
            return $this->overrideStyleCache[$key];
        }
        $col = $this->columns[$colIdx];
        $colorMap = [
            'red' => [self::PALETTE['red'], self::PALETTE['red_bg']],
            'amber' => [self::PALETTE['amber'], self::PALETTE['amber_bg']],
            'orange' => [self::PALETTE['orange'], self::PALETTE['orange_bg']],
            'green' => [self::PALETTE['green'], self::PALETTE['green_bg']],
            'gray' => [self::PALETTE['gray'], self::PALETTE['band']],
        ];
        [$color, $bg] = $colorMap[$badge] ?? [null, null];
        $idx = $this->styles->get([
            'bold' => true,
            'color' => $color,
            'fill' => $bg,
            'numFmt' => $this->numFmtFor($col['type']),
            'align' => $col['align'] ?? ($col['type'] === 'money' || $col['type'] === 'int' ? 'right' : ($col['type'] === 'date' ? 'center' : 'left')),
        ]);
        $this->overrideStyleCache[$key] = $idx;
        return $idx;
    }

    /** Bold totals row rendered at the bottom of the sheet. */
    public function setTotals(array $values): void
    {
        $cells = [];
        foreach ($this->columns as $i => $col) {
            $idx = $this->styles->get([
                'bold' => true,
                'fill' => self::PALETTE['band'],
                'numFmt' => $this->numFmtFor($col['type']),
                'align' => $col['align'] ?? ($col['type'] === 'money' || $col['type'] === 'int' ? 'right' : 'left'),
            ]);
            $cells[] = ['value' => $values[$i] ?? null, 'style' => $idx];
        }
        $this->totals = $cells;
    }

    public function headerStyleIdx(): int
    {
        return $this->headerStyle;
    }
}

class XlsxWriter
{
    private array $sheets = [];
    private XlsxStyleRegistry $styles;

    public function __construct()
    {
        $this->styles = new XlsxStyleRegistry();
    }

    public function addSheet(string $name, array $columns): XlsxSheet
    {
        $sheet = new XlsxSheet($name, $columns, $this->styles);
        $this->sheets[] = $sheet;
        return $sheet;
    }

    private static function colLetter(int $i): string
    {
        $letter = '';
        $i++;
        while ($i > 0) {
            $mod = ($i - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $i = intdiv($i - 1, 26);
        }
        return $letter;
    }

    private static function excelDate(string $ymd): ?float
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return null;
        }
        $epoch = strtotime('1899-12-30');
        return round(($ts - $epoch) / 86400);
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function renderSheetXml(XlsxSheet $sheet): string
    {
        $colCount = count($sheet->columns);
        $lastCol = self::colLetter($colCount - 1);

        $colsXml = '<cols>';
        foreach ($sheet->columns as $i => $col) {
            $letter = self::colLetter($i);
            $width = $col['width'] ?? 14;
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $rowNum = 1;
        $rowsXml = '<row r="1" ht="20" customHeight="1">';
        foreach ($sheet->columns as $i => $col) {
            $ref = self::colLetter($i) . '1';
            $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $sheet->headerStyleIdx() . '"><is><t xml:space="preserve">' . self::xmlEscape($col['header']) . '</t></is></c>';
        }
        $rowsXml .= '</row>';

        foreach ($sheet->rows as $row) {
            $rowNum++;
            $rowsXml .= '<row r="' . $rowNum . '">';
            foreach ($row as $i => $cell) {
                $ref = self::colLetter($i) . $rowNum;
                $type = $sheet->columns[$i]['type'];
                $value = $cell['value'];
                $style = $cell['style'];
                if ($value === null || $value === '') {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                } elseif ($type === 'money' || $type === 'int') {
                    $num = is_numeric($value) ? $value : 0;
                    $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $num . '</v></c>';
                } elseif ($type === 'date') {
                    $serial = self::excelDate((string) $value);
                    if ($serial === null) {
                        $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::xmlEscape((string) $value) . '</t></is></c>';
                    } else {
                        $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $serial . '</v></c>';
                    }
                } else {
                    $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::xmlEscape((string) $value) . '</t></is></c>';
                }
            }
            $rowsXml .= '</row>';
        }

        if ($sheet->totals !== null) {
            $rowNum++;
            $rowsXml .= '<row r="' . $rowNum . '">';
            foreach ($sheet->totals as $i => $cell) {
                $ref = self::colLetter($i) . $rowNum;
                $type = $sheet->columns[$i]['type'];
                $value = $cell['value'];
                $style = $cell['style'];
                if ($value === null || $value === '') {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                } elseif ($type === 'money' || $type === 'int') {
                    $num = is_numeric($value) ? $value : 0;
                    $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $num . '</v></c>';
                } else {
                    $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::xmlEscape((string) $value) . '</t></is></c>';
                }
            }
            $rowsXml .= '</row>';
        }

        $lastRow = $rowNum;
        $dim = 'A1:' . $lastCol . $lastRow;

        $paneXml = '';
        if ($sheet->freezeHeader) {
            $topLeft = ($sheet->freezeFirstCol ? 'B2' : 'A2');
            $xSplit = $sheet->freezeFirstCol ? '1' : '0';
            $paneXml = '<pane xSplit="' . $xSplit . '" ySplit="1" topLeftCell="' . $topLeft . '" activePane="bottomRight" state="frozen"/>';
        }

        $autofilterXml = $sheet->autofilter ? '<autoFilter ref="A1:' . $lastCol . '1"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dim . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">' . $paneXml . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $autofilterXml
            . '</worksheet>';
    }

    public function toZipBytes(): string
    {
        $zip = new MiniZipWriter();

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($this->sheets)))
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $sheetsXml = '';
        $relsXml = '';
        foreach ($this->sheets as $i => $sheet) {
            $sid = $i + 1;
            $sheetsXml .= '<sheet name="' . self::xmlEscape($sheet->name) . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
            $relsXml .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';
        }
        $relsXml .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relsXml
            . '</Relationships>');

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->renderSheetXml($sheet));
        }

        // styles.xml must be written last, after every sheet has registered its styles.
        $zip->addFromString('xl/styles.xml', $this->styles->renderStylesXml());

        return $zip->build();
    }

    public function output(string $filename): void
    {
        $bytes = $this->toZipBytes();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}
