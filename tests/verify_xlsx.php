<?php
/**
 * XLSX structural verifier (pure PHP, zero dependencies).
 * Usage: php tests/verify_xlsx.php [path-to-file.xlsx]
 */
$file = $argv[1] ?? (__DIR__ . '/../data/_xlsx_test.xlsx');
$d = file_get_contents($file);
$len = strlen($d);
$fail = 0;

function check(bool $cond, string $label, int &$fail): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$cond) {
        $fail++;
    }
}

/** Walk all local entries once. @return array<string, string> name => content */
function walkEntries(string $d, int $cdOff): array
{
    $out = [];
    $pos = 0;
    while ($pos < $cdOff && substr($d, $pos, 4) === "PK\x03\x04") {
        // local header: sig(0)...crc(14) csize(18) usize(22) fnlen(26) extralen(28) name(30...)
        $h = unpack('Vcsize/Vusize/vfn/vextra', substr($d, $pos + 18, 12));
        $name = substr($d, $pos + 30, $h['fn']);
        $out[$name] = substr($d, $pos + 30 + $h['fn'] + $h['extra'], $h['csize']);
        $pos += 30 + $h['fn'] + $h['extra'] + $h['csize'];
    }
    return $out;
}

check(substr($d, -22, 4) === "PK\x05\x06", 'EOCD signature present', $fail);
$e = unpack('vdisk/vcddisk/ventries1/ventries2/Vcdsize/Vcdoff', substr($d, $len - 18, 16));
check($e['entries1'] === $e['entries2'], 'EOCD entry counts agree (' . $e['entries1'] . ')', $fail);
check($e['cdoff'] === $len - 22 - $e['cdsize'], 'central directory offset is exact', $fail);

$entries = walkEntries($d, $e['cdoff']);
check(count($entries) === $e['entries1'], 'walked ' . count($entries) . ' local entries matching EOCD count', $fail);

$required = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml', 'xl/styles.xml'];
foreach ($required as $r) {
    check(isset($entries[$r]), "required part present: $r", $fail);
}
$sheetCount = 0;
foreach (array_keys($entries) as $en) {
    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $en)) {
        $sheetCount++;
    }
}
check($sheetCount >= 1, "worksheet parts present ($sheetCount)", $fail);

// XML well-formedness of every xml/rels part
$xmlTotal = 0;
$xmlOk = 0;
$doc = new DOMDocument();
foreach ($entries as $name => $content) {
    if (!str_ends_with($name, '.xml') && !str_ends_with($name, '.rels')) {
        continue;
    }
    $xmlTotal++;
    libxml_use_internal_errors(true);
    $ok = $doc->loadXML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    if ($ok) {
        $xmlOk++;
    } else {
        echo "  -> INVALID XML in $name\n";
    }
}
check($xmlOk === $xmlTotal, "all $xmlOk/$xmlTotal XML parts well-formed", $fail);

// workbook declares the sheets
preg_match_all('#<sheet name="([^"]+)"#', $entries['xl/workbook.xml'] ?? '', $sm);
echo 'sheets found: ' . implode(', ', $sm[1]) . PHP_EOL;
check(count($sm[1]) === $sheetCount, 'workbook sheet declarations match worksheet parts', $fail);

// sharedStrings contains expected cell text from the fixture
$ss = $entries['xl/sharedStrings.xml'] ?? '';
check(str_contains($ss, 'REQ-2026-0001'), 'shared strings hold fixture data', $fail);

echo $fail === 0 ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED";
echo PHP_EOL;
exit($fail === 0 ? 0 : 1);
