<?php
/**
 * CloudCup — Minimal native XLSX writer
 * -------------------------------------------------------------
 * Builds a single-sheet .xlsx file directly from the raw OOXML parts
 * via ZipArchive and streams it to the browser. No Composer / external
 * library needed (PhpSpreadsheet etc. aren't available in this install),
 * so this only supports what the Finance report exports actually need:
 * a title row, a bold header row, and a plain data grid.
 */

function _xlsx_col_letter(int $n): string {
    $letter = '';
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $n = intdiv($n - 1, 26);
    }
    return $letter;
}

function _xlsx_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Stream an .xlsx file to the browser and exit.
 *
 * @param string $filename   download filename, e.g. "Revenue_2026-08-01_to_2026-08-07.xlsx"
 * @param array  $headers    column header strings, in order
 * @param array  $rows       list of rows; each row is a plain array of values in header order
 * @param string $sheetTitle optional title text shown above the header row
 */
function export_finance_xlsx(string $filename, array $headers, array $rows, string $sheetTitle = ''): void {
    // The zip extension isn't enabled on every PHP install (XAMPP ships it
    // disabled by default). Rather than a fatal error, degrade to a plain
    // CSV — it still opens directly in Excel, just without native .xlsx
    // formatting. Enable php.ini's "extension=zip" to get real .xlsx back.
    if (!class_exists('ZipArchive')) {
        _export_finance_csv_fallback($filename, $headers, $rows, $sheetTitle);
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>');

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="3">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '</cellXfs>' .
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>');

    $rowIdx = 1;
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>';

    if ($sheetTitle !== '') {
        $sheetXml .= '<row r="' . $rowIdx . '"><c r="A' . $rowIdx . '" t="inlineStr" s="2"><is><t>' . _xlsx_escape($sheetTitle) . '</t></is></c></row>';
        $rowIdx += 2; // leave a blank spacer row
    }

    $sheetXml .= '<row r="' . $rowIdx . '">';
    foreach ($headers as $i => $h) {
        $col = _xlsx_col_letter($i + 1);
        $sheetXml .= '<c r="' . $col . $rowIdx . '" t="inlineStr" s="1"><is><t>' . _xlsx_escape((string) $h) . '</t></is></c>';
    }
    $sheetXml .= '</row>';
    $rowIdx++;

    foreach ($rows as $row) {
        $sheetXml .= '<row r="' . $rowIdx . '">';
        $i = 0;
        foreach ($row as $val) {
            $col = _xlsx_col_letter($i + 1);
            if (is_int($val) || is_float($val)) {
                $sheetXml .= '<c r="' . $col . $rowIdx . '" t="n"><v>' . (0 + $val) . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $col . $rowIdx . '" t="inlineStr"><is><t>' . _xlsx_escape((string) $val) . '</t></is></c>';
            }
            $i++;
        }
        $sheetXml .= '</row>';
        $rowIdx++;
    }

    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $data = file_get_contents($tmp);
    unlink($tmp);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename) . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: max-age=0');
    echo $data;
    exit;
}

/**
 * Fallback used only when the PHP zip extension isn't available.
 * CSV opens fine in Excel/Sheets, just without native .xlsx styling.
 */
function _export_finance_csv_fallback(string $filename, array $headers, array $rows, string $sheetTitle = ''): void {
    $csvName = preg_replace('/\.xlsx$/i', '.csv', $filename);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $csvName) . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads ₱ etc. correctly

    if ($sheetTitle !== '') {
        fputcsv($out, [$sheetTitle]);
        fputcsv($out, []);
    }
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => is_scalar($v) ? $v : (string) $v, $row));
    }

    fclose($out);
    exit;
}
