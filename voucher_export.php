<?php
// ============================================================
//  voucher_export.php — downloads ALL vouchers as one Excel file
// ------------------------------------------------------------
//  One worksheet per voucher type, laid out like the original
//  NEW_STELLA_VOUCHER_MONITORING_2026.xlsx:
//
//      row 1      voucher name (title bar)
//      rows 2-5   BEGINNING / TOTAL OUT / ENDING / SERIES NO.  (+ AVAILED)
//      row 6      column headings
//      row 7 ...  one row per series number
//
//  TOTAL OUT, ENDING and AVAILED are live Excel formulas, so they
//  keep working if the sheet is edited after downloading.
//  Needs PHP's ZipArchive (same requirement as export_transactions.php).
// ============================================================
session_start();

require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
if (!isset($_SESSION['username']) || (empty($_SESSION['admin']) && empty($_SESSION['cashier']))) {   // admin or cashier
    header("Location: index.php");
    exit();
}
if (!class_exists('ZipArchive')) {
    die('Excel export needs the PHP ZipArchive extension, which is not enabled on this server.');
}

// ---- cell styles (indexes into cellXfs in styles.xml below) ----
const S_TITLE = 1, S_HEADER = 2, S_LABEL = 3, S_VALUE = 4,
      S_TEXT = 5, S_TEXT_B = 6, S_CENTER = 7, S_CENTER_B = 8,
      S_DATE = 9, S_DATE_B = 10, S_SERIES = 11, S_SERIES_B = 12,
      S_OUT = 13, S_IN = 14, S_IN_B = 15, S_RANGE = 16;

$HEADERS = ['DATE', 'SOLD OUT', 'SERIES NO.', 'CUSTOMER NAME', 'REDEEMED BY',
            'MODE OF PAYMENT (MOP)', 'REMARKS (SPONSORSHIP OR SOLD)', 'DATE AVAILED', 'NOTE (C/O ACCTNG)'];
$WIDTHS  = [22, 13, 12, 30, 26, 22, 28, 22, 30];
const FIRST_DATA_ROW = 7;

function col_letter($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m - 1, 26); } return $s; }
function x($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function xl_date($d) { return $d ? (strtotime($d . ' 00:00:00 UTC') / 86400) + 25569 : null; }
function cell_str($ref, $style, $text) {
    return $text === null || $text === ''
        ? '<c r="' . $ref . '" s="' . $style . '"/>'
        : '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . x($text) . '</t></is></c>';
}
function cell_num($ref, $style, $n) {
    return $n === null ? '<c r="' . $ref . '" s="' . $style . '"/>' : '<c r="' . $ref . '" s="' . $style . '"><v>' . $n . '</v></c>';
}

// ---- load every voucher type and its rows ----
$types = [];
$tr = $conn->query("SELECT id, name, beginning FROM voucher_types ORDER BY sort_order, id");
if ($tr) { while ($r = $tr->fetch_assoc()) { $types[] = $r; } }
if (!$types) {
    die('There are no vouchers to export yet.');
}

$usedNames = [];
function sheet_name($name, &$used) {
    $n = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name));
    $n = mb_substr($n === '' ? 'Voucher' : $n, 0, 31);
    $base = $n; $i = 2;
    while (isset($used[mb_strtolower($n)])) { $suffix = ' (' . $i++ . ')'; $n = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix; }
    $used[mb_strtolower($n)] = true;
    return $n;
}

$sheetsXml = []; $sheetNames = [];
foreach ($types as $t) {
    $stmt = $conn->prepare("SELECT * FROM vouchers WHERE type_id = ? ORDER BY series_no");
    $stmt->bind_param('i', $t['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $n      = count($rows);
    $first  = FIRST_DATA_ROW;
    $last   = max($first, $first + $n - 1);
    $beg    = (int)$t['beginning'];
    $out    = 0; $availed = 0; $maxSeries = $beg;
    foreach ($rows as $r) {
        if ((int)$r['sold_out'] === 1) $out++;
        if (!empty($r['date_availed'])) $availed++;
        $maxSeries = max($maxSeries, (int)$r['series_no']);
    }
    $seriesLabel = '001-' . str_pad((string)$maxSeries, 3, '0', STR_PAD_LEFT);
    $sheetNames[] = sheet_name($t['name'], $usedNames);

    $x = '';
    // row 1: title bar
    $x .= '<row r="1" ht="36" customHeight="1">' . cell_str('A1', S_TITLE, $t['name']);
    for ($c = 1; $c < 9; $c++) $x .= '<c r="' . col_letter($c) . '1" s="' . S_TITLE . '"/>';
    $x .= '</row>';
    // rows 2-5: summary block
    $x .= '<row r="2" ht="22" customHeight="1">' . cell_str('A2', S_LABEL, 'BEGINNING') . cell_num('B2', S_VALUE, $beg) . '<c r="C2" s="' . S_VALUE . '"/>'
        . cell_str('E2', S_LABEL, 'AVAILED')
        . '<c r="F2" s="' . S_VALUE . '"><f>COUNT(H' . $first . ':H' . $last . ')</f><v>' . $availed . '</v></c></row>';
    $x .= '<row r="3" ht="22" customHeight="1">' . cell_str('A3', S_LABEL, 'TOTAL OUT')
        . '<c r="B3" s="' . S_VALUE . '"><f>COUNTIF(B' . $first . ':B' . $last . ',"OUT")</f><v>' . $out . '</v></c><c r="C3" s="' . S_VALUE . '"/></row>';
    $x .= '<row r="4" ht="22" customHeight="1">' . cell_str('A4', S_LABEL, 'ENDING')
        . '<c r="B4" s="' . S_VALUE . '"><f>B2-B3</f><v>' . ($beg - $out) . '</v></c><c r="C4" s="' . S_VALUE . '"/></row>';
    $x .= '<row r="5" ht="22" customHeight="1">' . cell_str('A5', S_LABEL, 'SERIES NO.') . cell_str('B5', S_VALUE, $seriesLabel) . '<c r="C5" s="' . S_VALUE . '"/></row>';
    // row 6: headings
    $x .= '<row r="6" ht="38" customHeight="1">';
    foreach ($HEADERS as $i => $h) $x .= cell_str(col_letter($i) . '6', S_HEADER, $h);
    $x .= '</row>';
    // data rows
    $rn = $first;
    foreach ($rows as $i => $r) {
        $band = ($i % 2 === 1);
        $isOut = (int)$r['sold_out'] === 1;
        $x .= '<row r="' . $rn . '" ht="20" customHeight="1">';
        $x .= ($r['date_sold'] ? cell_num('A' . $rn, $band ? S_DATE_B : S_DATE, xl_date($r['date_sold'])) : '<c r="A' . $rn . '" s="' . ($band ? S_DATE_B : S_DATE) . '"/>');
        $x .= cell_str('B' . $rn, $isOut ? S_OUT : ($band ? S_IN_B : S_IN), $isOut ? 'OUT' : 'IN STOCK');
        $x .= cell_num('C' . $rn, $band ? S_SERIES_B : S_SERIES, (int)$r['series_no']);
        $x .= cell_str('D' . $rn, $band ? S_TEXT_B : S_TEXT, $r['customer_name']);
        $x .= cell_str('E' . $rn, $band ? S_TEXT_B : S_TEXT, $r['redeemed_by'] ?? '');
        $x .= cell_str('F' . $rn, $band ? S_CENTER_B : S_CENTER, $r['mop']);
        $x .= cell_str('G' . $rn, $band ? S_CENTER_B : S_CENTER, $r['remarks']);
        $x .= ($r['date_availed'] ? cell_num('H' . $rn, $band ? S_DATE_B : S_DATE, xl_date($r['date_availed'])) : '<c r="H' . $rn . '" s="' . ($band ? S_DATE_B : S_DATE) . '"/>');
        $x .= cell_str('I' . $rn, $band ? S_TEXT_B : S_TEXT, $r['note']);
        $x .= '</row>';
        $rn++;
    }

    $cols = '';
    foreach ($WIDTHS as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';

    $sheetsXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><tabColor rgb="FF2E7D32"/><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:I' . $last . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
        . '<pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A7" sqref="A7"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $x . '</sheetData>'
        . '<autoFilter ref="A6:I' . $last . '"/>'
        . '<mergeCells count="5"><mergeCell ref="A1:I1"/><mergeCell ref="B2:C2"/><mergeCell ref="B3:C3"/><mergeCell ref="B4:C4"/><mergeCell ref="B5:C5"/></mergeCells>'
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
}

// ---- workbook-level parts ----
$count = count($sheetsXml);
$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
for ($i = 1; $i <= $count; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
$ct .= '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

$wbSheets = ''; $wbRels = ''; $defined = '';
foreach ($sheetNames as $i => $nm) {
    $id = $i + 1;
    $wbSheets .= '<sheet name="' . x($nm) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
    $wbRels   .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
    $q = "'" . str_replace("'", "''", $nm) . "'";
    $defined  .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . x($q) . '!$A$6:$I$' . 6 . '</definedName>';
    $defined  .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . x($q) . '!$6:$6</definedName>';
}
$wbRels .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets><definedNames>' . $defined . '</definedNames></workbook>';
$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>';

// styles: green title/header, light-green labels, banded rows, amber OUT pill, long dates
$border = '<border><left style="thin"><color rgb="FFC9DED0"/></left><right style="thin"><color rgb="FFC9DED0"/></right><top style="thin"><color rgb="FFC9DED0"/></top><bottom style="thin"><color rgb="FFC9DED0"/></bottom><diagonal/></border>';
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  . '<numFmts count="2"><numFmt numFmtId="164" formatCode="mmmm\ d\,\ yyyy"/><numFmt numFmtId="165" formatCode="000"/></numFmts>'
  . '<fonts count="9">'
  .   '<font><sz val="10"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="10"/><color rgb="FF1B5E20"/><name val="Arial"/></font>'
  .   '<font><sz val="10"/><color rgb="FF1B3A22"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="10"/><color rgb="FF1B3A22"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="9"/><color rgb="FF8A5A00"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="9"/><color rgb="FF1B5E20"/><name val="Arial"/></font>'
  .   '<font><b/><sz val="12"/><color rgb="FF1B3A22"/><name val="Arial"/></font>'
  . '</fonts>'
  . '<fills count="7">'
  .   '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
  .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1B5E20"/><bgColor indexed="64"/></patternFill></fill>'
  .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE6F4E8"/><bgColor indexed="64"/></patternFill></fill>'
  .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF3FAF4"/><bgColor indexed="64"/></patternFill></fill>'
  .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1D6"/><bgColor indexed="64"/></patternFill></fill>'
  .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF6EC"/><bgColor indexed="64"/></patternFill></fill>'
  . '</fills>'
  . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
  . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
  . '<cellXfs count="17">'
  .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                                                       // 0
  .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                      // 1 title
  .   '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 2 header
  .   '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'   // 3 label
  .   '<xf numFmtId="0" fontId="8" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                    // 4 value
  .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'            // 5 text
  .   '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>' // 6 text band
  .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                    // 7 center
  .   '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'        // 8 center band
  .   '<xf numFmtId="164" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'     // 9 date
  .   '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 10 date band
  .   '<xf numFmtId="165" fontId="5" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'     // 11 series
  .   '<xf numFmtId="165" fontId="5" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 12 series band
  .   '<xf numFmtId="0" fontId="6" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 13 OUT
  .   '<xf numFmtId="0" fontId="7" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                     // 14 IN STOCK
  .   '<xf numFmtId="0" fontId="7" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'         // 15 IN STOCK band
  .   '<xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                                                              // 16 spare
  . '</cellXfs>'
  . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
  . '</styleSheet>';

// FilterDatabase ranges must match each sheet's data height, so patch them in now
foreach ($sheetNames as $i => $nm) {
    $lastRow = (int)preg_replace('/^.*<autoFilter ref="A6:I(\d+)"\/>.*$/s', '$1', $sheetsXml[$i]);
    $q = x("'" . str_replace("'", "''", $nm) . "'");
    $workbook = str_replace($q . '!$A$6:$I$6</definedName>', $q . '!$A$6:$I$' . $lastRow . '</definedName>', $workbook);
}

// ---- zip it up and send ----
$tmp = tempnam(sys_get_temp_dir(), 'vxlsx_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
    die('Could not create the Excel file (temp folder not writable).');
}
$zip->addFromString('[Content_Types].xml', $ct);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
foreach ($sheetsXml as $i => $sx) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sx);
$zip->close();

$ls = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
if ($ls) { $a = 'Voucher export'; $d = "Exported $count voucher sheets to Excel"; $ls->bind_param('sss', $a, $_SESSION['username'], $d); $ls->execute(); }

$filename = 'Voucher_Monitoring_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Pragma: no-cache');
header('Expires: 0');
readfile($tmp);
unlink($tmp);
exit;