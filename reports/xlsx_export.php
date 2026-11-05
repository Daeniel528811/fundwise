<?php
/**
 * Fundwise XLSX exporter. No Composer packages required.
 * Called from reports/index.php after per-user queries and date validation.
 * Requires PHP's Zip extension (ZipArchive).
 */
if (!isset($user_id, $start_date, $end_date, $currency_code, $transactions, $category_breakdown)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel export requires PHP Zip. Enable extension=zip in XAMPP php.ini and restart Apache.');
}

function fw_xlsx_xml($text) {
    $text = (string) $text;
    // Remove control characters not permitted in XML 1.0.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 32767, 'UTF-8') : substr($text, 0, 32767);
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function fw_xlsx_cell($column, $row, $value, $style = 0, $numeric = false) {
    $ref = $column . $row;
    if ($numeric) {
        $number = (float) $value;
        if (!is_finite($number)) $number = 0;
        return '<c r="' . $ref . '" s="' . (int) $style . '"><v>' . $number . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . (int) $style . '" t="inlineStr"><is><t xml:space="preserve">'
        . fw_xlsx_xml($value) . '</t></is></c>';
}

function fw_xlsx_row($number, $cells, $height = null) {
    return '<row r="' . $number . '"' . ($height === null ? '' : ' ht="' . (int) $height . '" customHeight="1"')
        . '>' . implode('', $cells) . '</row>';
}

function fw_xlsx_excel_date($date) {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, new DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d') !== $date) return null;
    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
    return (int) $base->diff($dt)->format('%r%a');
}

$rows = [];
$rows[] = fw_xlsx_row(1, [fw_xlsx_cell('A', 1, 'FUNDWISE  |  FINANCIAL REPORT', 1)], 34);
$rows[] = fw_xlsx_row(2, [fw_xlsx_cell('A', 2, 'Your income, expenses, and transactions at a glance', 2)], 23);
$rows[] = fw_xlsx_row(4, [fw_xlsx_cell('A', 4, 'Currency', 3), fw_xlsx_cell('B', 4, $currency_code, 4)]);
$rows[] = fw_xlsx_row(5, [fw_xlsx_cell('A', 5, 'Start Date', 3), fw_xlsx_cell('B', 5, date('F j, Y', strtotime($start_date)), 4)]);
$rows[] = fw_xlsx_row(6, [fw_xlsx_cell('A', 6, 'End Date', 3), fw_xlsx_cell('B', 6, date('F j, Y', strtotime($end_date)), 4)]);
$rows[] = fw_xlsx_row(8, [fw_xlsx_cell('A', 8, 'FINANCIAL SUMMARY', 5)], 26);
$rows[] = fw_xlsx_row(9, [fw_xlsx_cell('A', 9, 'Total Income', 3), fw_xlsx_cell('B', 9, $total_income, 6, true)]);
$rows[] = fw_xlsx_row(10, [fw_xlsx_cell('A', 10, 'Total Expenses', 3), fw_xlsx_cell('B', 10, $total_expenses, 7, true)]);
$rows[] = fw_xlsx_row(11, [fw_xlsx_cell('A', 11, 'Remaining Balance', 3), fw_xlsx_cell('B', 11, $remaining_balance, 8, true)]);

$rows[] = fw_xlsx_row(13, [fw_xlsx_cell('A', 13, 'EXPENSE BREAKDOWN', 5)], 26);
$rows[] = fw_xlsx_row(14, [fw_xlsx_cell('A', 14, 'Category', 9), fw_xlsx_cell('B', 14, 'Total Expenses', 9), fw_xlsx_cell('C', 14, 'Share of Expenses', 9)], 25);
$next = 15;
if (!$category_breakdown) {
    $rows[] = fw_xlsx_row($next, [fw_xlsx_cell('A', $next, 'No expenses for this period', 2)]);
    $next++;
} else {
    foreach ($category_breakdown as $category) {
        $category_total = (float) $category['total_amount'];
        $percentage = $total_expenses > 0 ? $category_total / $total_expenses : 0;
        $rows[] = fw_xlsx_row($next, [
            fw_xlsx_cell('A', $next, $category['category_name'] ?? 'Uncategorized', 10),
            fw_xlsx_cell('B', $next, $category_total, 11, true),
            fw_xlsx_cell('C', $next, $percentage, 12, true)
        ]);
        $next++;
    }
}

$section_row = $next + 2;
$head_row = $section_row + 1;
$rows[] = fw_xlsx_row($section_row, [fw_xlsx_cell('A', $section_row, 'TRANSACTION HISTORY', 5)], 27);
$headers = ['ID', 'Date', 'Description', 'Category', 'Type', 'Currency', 'Amount'];
$head_cells = [];
foreach ($headers as $i => $head) $head_cells[] = fw_xlsx_cell(chr(65 + $i), $head_row, $head, 9);
$rows[] = fw_xlsx_row($head_row, $head_cells, 26);

$first_data_row = $head_row + 1;
$row_num = $first_data_row;
foreach ($transactions as $transaction) {
    $day = fw_xlsx_excel_date((string) $transaction['transaction_date']);
    $is_income = $transaction['transaction_type'] === 'income';
    $rows[] = fw_xlsx_row($row_num, [
        fw_xlsx_cell('A', $row_num, (int) $transaction['transaction_id'], 13, true),
        $day === null ? fw_xlsx_cell('B', $row_num, $transaction['transaction_date'], 10)
            : fw_xlsx_cell('B', $row_num, $day, 14, true),
        fw_xlsx_cell('C', $row_num, $transaction['description'] ?: 'No description', 10),
        fw_xlsx_cell('D', $row_num, $transaction['category_name'] ?? 'Uncategorized', 10),
        fw_xlsx_cell('E', $row_num, ucfirst($transaction['transaction_type']), $is_income ? 15 : 16),
        fw_xlsx_cell('F', $row_num, $currency_code, 10),
        fw_xlsx_cell('G', $row_num, (float) $transaction['amount'], $is_income ? 6 : 7, true),
    ], 23);
    $row_num++;
}
if (!$transactions) {
    $rows[] = fw_xlsx_row($row_num, [fw_xlsx_cell('C', $row_num, 'No transactions for this period', 2)]);
}
$last_data_row = max($head_row, $row_num - 1);
$max_row = max($head_row, $row_num);

// Styling: purple section headers, contrasting labels, numeric and date formats.
$styles = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00;[Red](#,##0.00)"/><numFmt numFmtId="165" formatCode="mmm d, yyyy"/><numFmt numFmtId="166" formatCode="0.0%"/></numFmts>
<fonts count="7"><font><sz val="11"/><name val="Aptos"/></font><font><b/><sz val="19"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/></font><font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Aptos"/></font><font><b/><sz val="11"/><color rgb="FF334155"/><name val="Aptos"/></font><font><sz val="11"/><color rgb="FF1E293B"/><name val="Aptos"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font><font><b/><sz val="11"/><color rgb="FF166534"/><name val="Aptos"/></font></fonts>
<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF4C1D95"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDD6FE"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="17">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="5" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
<xf numFmtId="164" fontId="6" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>
<xf numFmtId="164" fontId="3" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>
<xf numFmtId="164" fontId="3" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/>
<xf numFmtId="0" fontId="5" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="164" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="166" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="165" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>
</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;

$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<dimension ref="A1:G' . $max_row . '"/>'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="19"/>'
    . '<cols><col min="1" max="1" width="19" customWidth="1"/><col min="2" max="2" width="21" customWidth="1"/>'
    . '<col min="3" max="3" width="43" customWidth="1"/><col min="4" max="4" width="25" customWidth="1"/>'
    . '<col min="5" max="5" width="17" customWidth="1"/><col min="6" max="6" width="16" customWidth="1"/>'
    . '<col min="7" max="7" width="22" customWidth="1"/></cols>'
    . '<sheetData>' . implode('', $rows) . '</sheetData>'
    . '<mergeCells count="4"><mergeCell ref="A1:G1"/><mergeCell ref="A2:G2"/>'
    . '<mergeCell ref="A8:G8"/><mergeCell ref="A13:G13"/></mergeCells>';
// Transaction section title is also merged. 
$sheet_xml = str_replace('mergeCells count="4"', 'mergeCells count="5"', $sheet_xml);
$sheet_xml = str_replace('</mergeCells>', '<mergeCell ref="A' . $section_row . ':G' . $section_row . '"/></mergeCells>', $sheet_xml);
$sheet_xml .= '<autoFilter ref="A' . $head_row . ':G' . $last_data_row . '"/>'
    . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
    . '</worksheet>';

$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Financial Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml' => $sheet_xml,
    'xl/styles.xml' => $styles,
];

$tmp = tempnam(sys_get_temp_dir(), 'fw_xlsx_');
if ($tmp === false) {
    http_response_code(500);
    exit('Could not create temporary report file.');
}
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    http_response_code(500);
    exit('Could not generate Excel file.');
}
foreach ($files as $path => $contents) {
    $zip->addFromString($path, $contents);
}
$zip->close();

$filename = 'fundwise_report_' . $start_date . '_to_' . $end_date . '.xlsx';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
exit;
