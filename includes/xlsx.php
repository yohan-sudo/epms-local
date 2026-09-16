<?php
/**
 * U EPMS - Pure-PHP XLSX Generator (zero dependencies)
 * Creates real Excel files without ZipArchive, using a minimal
 * in-house ZIP (STORE, no compression - Excel opens these fine).
 * Sheets, styled header rows, typed numeric/date cells.
 */

class UeXlsx
{
    private string $sharedStrings = '';
    private int $sharedCount = 0;
    /** @var array<string, int> */
    private array $ssIndex = [];
    /** @var array<int, string> */
    private array $sheets = [];
    /** @var array<int, string> */
    private array $sheetNames = [];

    private string $appName = 'EPMS';

    /** @var array<int, array<int, float>> per-sheet column widths (0-based col => width) */
    private array $colWidths = [];
    /** @var array<int, int> per-sheet row below which everything is frozen */
    private array $freezeRows = [];
    /** @var array<int, string> per-sheet auto-filter range (e.g. "A3:F9") */
    private array $autoFilters = [];
    /** @var array<int, string[]> per-sheet merged cell ranges */
    private array $merges = [];
    /** @var array<int, int> per-sheet highest column count seen (0-based) */
    private array $maxCols = [];

    public function __construct()
    {
        $this->appName = defined('APP_NAME') ? (string)APP_NAME : 'EPMS';
    }

    /* ---------- public API ---------- */

    public function addSheet(string $name): void
    {
        $this->sheetNames[] = $this->sanitizeSheetName($name);
        $this->sheets[] = '';
        $this->rowCounter[] = 0;
        $this->colWidths[] = [];
        $this->freezeRows[] = 1;
        $this->autoFilters[] = '';
        $this->merges[] = [];
        $this->maxCols[] = 0;
    }

    /** Set column widths (chars) for a sheet: index 0 => column A, etc. */
    public function setColumnWidths(int $sheetIndex, array $widths): void
    {
        $this->colWidths[$sheetIndex] = $widths;
    }

    /** Freeze everything above $headerRow (1-based); data scrolls under it. */
    public function setFreezeHeaderRow(int $sheetIndex, int $headerRow): void
    {
        $this->freezeRows[$sheetIndex] = max(1, $headerRow);
    }

    /** Attach an auto-filter across $colCount columns from row $fromRow to $toRow (1-based). */
    public function setAutoFilter(int $sheetIndex, int $fromRow, int $toRow, int $colCount): void
    {
        if ($colCount < 1) {
            return;
        }
        $lastCol = $this->colLetter($colCount - 1);
        $this->autoFilters[$sheetIndex] = 'A' . $fromRow . ':' . $lastCol . $toRow;
    }

    /** Merge a range like "A1:H1" (title bars). */
    public function mergeCells(int $sheetIndex, string $range): void
    {
        $this->merges[$sheetIndex][] = $range;
    }

    public function writeRow(int $sheetIndex, array $cells, array $opts = []): void
    {
        $row = ++$this->rowCounter[$sheetIndex];
        $this->maxCols[$sheetIndex] = max($this->maxCols[$sheetIndex], count($cells));
        $ht = isset($opts['ht']) ? ' ht="' . sprintf('%.2F', (float)$opts['ht']) . '" customHeight="1"' : '';
        $xml = '<row r="' . $row . '"' . $ht . '>';
        $col = 0;
        foreach ($cells as $cell) {
            if (is_array($cell)) {
                $value = $cell['v'] ?? '';
                $style = $cell['s'] ?? 0;
            } else {
                $value = $cell;
                $style = 0;
            }
            $ref = $this->colLetter($col) . $row;
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . $value . '</v></c>';
            } elseif ($value instanceof DateTimeImmutable || $value instanceof DateTime) {
                $serial = $this->dateSerial($value);
                $xml .= '<c r="' . $ref . '" s="' . ($style ?: 1) . '"><v>' . $serial . '</v></c>';
            } elseif ($value === '' || $value === null) {
                if ($style) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                }
            } else {
                $idx = $this->shareString((string)$value);
                $xml .= '<c r="' . $ref . '" t="s"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . $idx . '</v></c>';
            }
            $col++;
        }
        $xml .= '</row>';
        $this->sheets[$sheetIndex] .= $xml;
    }

    /** @var array<int, int> */
    private array $rowCounter = [];

    /** 1-based number of the most recently written row on a sheet. */
    public function lastRow(int $sheetIndex): int
    {
        return $this->rowCounter[$sheetIndex] ?? 0;
    }

    public function output(string $filename): string
    {
        $files = [];
        $files['[Content_Types].xml'] = $this->contentTypes();
        $files['_rels/.rels'] = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
XML;
        $files['docProps/core.xml'] = $this->coreProps();
        $files['docProps/app.xml'] = $this->appProps();

        $sheetXmlParts = [];
        $sheetRels = [];
        $n = count($this->sheets);
        foreach ($this->sheetNames as $i => $name) {
            $rid = $i + 1;
            $sheetRels[] = '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
            $dim = $this->rowCounter[$i] ?? 0;
            $sheetXmlParts[] = '<sheet name="' . $this->xmlEsc($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . $rid . '"/>';
            $this->sheets[$i] = $this->sheetXml($this->sheets[$i], $dim, $i);
        }

        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' . implode('', $sheetXmlParts) . '</sheets></workbook>';

        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $sheetRels) .
            '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' .
            '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

        $files['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->sharedCount . '" uniqueCount="' . count($this->ssIndex) . '">' .
            $this->sharedStrings . '</sst>';

        $files['xl/styles.xml'] = $this->stylesXml();

        foreach ($this->sheetNames as $i => $name) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheets[$i];
        }

        $xlsx = $this->buildZip($files);
        if ($filename !== '' && $filename !== 'php://output') {
            file_put_contents($filename, $xlsx);
        }
        return $xlsx;
    }

    /* ---------- styles (s= indexes) ---------- */

    private function stylesXml(): string
    {
        // 0 default | 1 date (yyyy-mm-dd) | 2 header (blue bg, white bold)
        // 3 title (14 bold) | 4 subtotal (bold, top border) | 5 money 2-dec
        // 6 meta (italic gray) | 7 data text (hairline bottom) | 8 data money (hairline bottom)
        // 9/10 = zebra variants of 7/8 | 11/12 = int data plain/zebra (#,##0)
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="3"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="#,##0.00"/><numFmt numFmtId="166" formatCode="#,##0"/></numFmts>
<fonts count="5">
<font><sz val="11"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
<font><b/><sz val="14"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><name val="Calibri"/></font>
<font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>
</fonts>
<fills count="4">
<patternFill patternType="none"/>
<patternFill patternType="gray125"/>
<patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor rgb="FF1D4ED8"/></patternFill>
<patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor rgb="FFF1F5F9"/></patternFill>
</fills>
<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF94A3B8"/></top><bottom/><diagonal/></border></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFD7DEE8"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="13">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="0" fillId="3" borderId="2" xfId="0" applyFill="1" applyBorder="1"/>
<xf numFmtId="165" fontId="0" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="166" fontId="0" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="166" fontId="0" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }

    /* ---------- internals ---------- */

    private function sheetXml(string $rows, int $lastRow, int $sheetIndex): string
    {
        /* Element order follows the CT_Worksheet schema sequence:
         * dimension, sheetViews, sheetFormatPr, cols, sheetData, autoFilter, mergeCells. */
        $maxCol = max(1, $this->maxCols[$sheetIndex] ?? 1);
        $dim = 'A1';
        if ($lastRow > 0) {
            $dim = 'A1:' . $this->colLetter($maxCol - 1) . $lastRow;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<dimension ref="' . $dim . '"/>';

        $freeze = $this->freezeRows[$sheetIndex] ?? 1;
        if ($freeze > 1) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . ($freeze - 1) . '" topLeftCell="A' . $freeze . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        } else {
            $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        }

        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if (!empty($this->colWidths[$sheetIndex])) {
            $xml .= '<cols>';
            foreach ($this->colWidths[$sheetIndex] as $ci => $w) {
                if ($w === null || $w <= 0) {
                    continue;
                }
                $xml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . sprintf('%.2F', (float)$w) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>' . $rows . '</sheetData>';

        if (!empty($this->autoFilters[$sheetIndex])) {
            $xml .= '<autoFilter ref="' . $this->autoFilters[$sheetIndex] . '"/>';
        }
        if (!empty($this->merges[$sheetIndex])) {
            $xml .= '<mergeCells count="' . count($this->merges[$sheetIndex]) . '">';
            foreach ($this->merges[$sheetIndex] as $ref) {
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }
            $xml .= '</mergeCells>';
        }
        $xml .= '</worksheet>';
        return $xml;
    }

    private function contentTypes(): string
    {
        $overrides = '';
        foreach ($this->sheetNames as $i => $name) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' .
            $overrides .
            '</Types>';
    }

    private function coreProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:creator>' . $this->xmlEsc($this->appName) . '</dc:creator>' .
            '<cp:lastModifiedBy>' . $this->xmlEsc($this->appName) . '</cp:lastModifiedBy>' .
            '</cp:coreProperties>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
            '<Application>' . $this->xmlEsc($this->appName) . '</Application></Properties>';
    }

    private function shareString(string $s): int
    {
        $key = $s;
        if (!isset($this->ssIndex[$key])) {
            $this->ssIndex[$key] = count($this->ssIndex);
            $this->sharedStrings .= '<si><t xml:space="preserve">' . $this->xmlEsc($s) . '</t></si>';
        }
        $this->sharedCount++;
        return $this->ssIndex[$key];
    }

    private function dateSerial(DateTime|DateTimeImmutable $d): string
    {
        $utc = new DateTimeZone('UTC');
        $d = new DateTime('@' . $d->getTimestamp(), $utc);
        $epoch = new DateTime('1899-12-30', $utc);
        $diff = $d->diff($epoch);
        $days = (int)$diff->format('%a');
        return (string)round($days + ($d->format('H') * 3600 + $d->format('i') * 60 + $d->format('s')) / 86400, 6);
    }

    private function colLetter(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $s = chr(65 + $mod) . $s;
            $index = intdiv($index - $mod, 26);
        }
        return $s;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $name) ?? 'Sheet';
        return mb_substr($name, 0, 31);
    }

    private function xmlEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /* ---------- minimal ZIP writer (STORE method) ---------- */

    private function buildZip(array $files): string
    {
        $out = '';
        $central = '';
        $offset = 0;
        foreach ($files as $path => $content) {
            $pathBytes = $path;
            $crc = crc32($content);
            $size = strlen($content);
            $local = "PK\x03\x04"
                . "\x14\x00"        // version needed
                . "\x00\x00"        // flags
                . "\x00\x00"        // method: store
                . "\x00\x00\x00\x00" // dos time/date (0 fine)
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($pathBytes))
                . "\x00\x00"
                . $pathBytes;
            $out .= $local . $content;

            $central .= "PK\x01\x02"
                . "\x14\x00\x14\x00"
                . "\x00\x00\x00\x00\x00\x00\x00\x00"
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($pathBytes))
                . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
                . pack('V', $offset)
                . $pathBytes;

            $offset += strlen($local) + $size;
        }
        $out .= $central;
        $out .= "PK\x05\x06"
            . "\x00\x00\x00\x00"
            . pack('v', count($files))
            . pack('v', count($files))
            . pack('V', strlen($central))
            . pack('V', $offset)
            . "\x00\x00";
        return $out;
    }
}
