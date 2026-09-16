<?php
/**
 * U EPMS - Pure-PHP PDF Generator (zero dependencies)
 * Produces PDF 1.4 documents with the built-in Helvetica core fonts.
 * No extensions required (no GD, no dompdf, no ZipArchive).
 *
 * Units: millimetres. Page: A4 portrait.
 * Metrics: standard Adobe Helvetica widths (per 1000 units); bold is
 * approximated at +8% so text can wrap slightly early but never overflow.
 */

class UePdf
{
    public const A4_W = 210.0;
    public const A4_H = 297.0;

    private float $k = 2.834645669; // scale factor: mm -> PDF points (1mm = 72/25.4 pt)

    private float $pageW = 210.0;
    private float $pageH = 297.0;
    private float $marginL = 14.0;
    private float $marginT = 14.0;
    private float $marginR = 14.0;
    private float $marginB = 18.0;

    /** @var array<int, array<string, mixed>> */
    private array $pages = [];
    private string $buffer = '';
    private int $pageNo = 0;

    private float $x = 14.0;
    private float $y = 14.0;
    private string $style = '';
    private float $sizePt = 10.0;

    /** @var array<int, array<string, float>> */
    private array $fillStack = [];

    /** @var callable|null */
    private $headerFn = null;
    /** @var callable|null */
    private $footerFn = null;

    /** Standard Helvetica widths (chars 32..126), units per 1000. */
    private const CW = [
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

    public function __construct()
    {
        $this->addPage();
    }

    public function setHeaderCallback(callable $fn): void { $this->headerFn = $fn; }
    public function setFooterCallback(callable $fn): void { $this->footerFn = $fn; }

    public function addPage(): void
    {
        /* Close out the previous page: draw its footer before switching. */
        if ($this->pageNo > 0 && $this->footerFn) {
            ($this->footerFn)($this);
        }
        $this->pageNo++;
        $this->pages[$this->pageNo] = ['content' => '', 'len' => 0];
        $this->x = $this->marginL;
        $this->y = $this->marginT;
        if ($this->headerFn) {
            ($this->headerFn)($this);
        }
    }

    public function pageNo(): int { return $this->pageNo; }
    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }
    public function setX(float $x): void { $this->x = $x; }
    public function setY(float $y): void { $this->y = $y; }
    public function pageWidth(): float { return $this->pageW; }
    public function contentWidth(): float { return $this->pageW - $this->marginL - $this->marginR; }
    public function bottomLimit(): float { return $this->pageH - $this->marginB; }

    public function setFont(float $sizePt, string $style = ''): void
    {
        $this->sizePt = $sizePt;
        $this->style = in_array($style, ['', 'B', 'I'], true) ? $style : '';
    }

    public function getStringWidth(string $text): float
    {
        $s = $this->toWinAnsi($text);
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $cw = self::CW[$c] ?? 556;
            if ($this->style === 'B') {
                $cw = (int)ceil($cw * 1.08);
            }
            $w += $cw;
        }
        return $w * ($this->sizePt / $this->k) / 1000.0;
    }

    /** Word-wrap $text to fit $width (current font). Returns lines. @return string[] */
    public function wrapText(string $text, float $width): array
    {
        if ($this->getStringWidth($text) <= $width) {
            return [$text];
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($this->getStringWidth($candidate) <= $width || $line === '') {
                if ($line !== '' && $this->getStringWidth($candidate) > $width) {
                    // single word longer than the column: hard-break it
                    while ($this->getStringWidth($word) > $width && $word !== '') {
                        $cut = strlen($word);
                        while ($cut > 1 && $this->getStringWidth(substr($word, 0, $cut)) > $width) {
                            $cut--;
                        }
                        $lines[] = substr($word, 0, $cut);
                        $word = substr($word, $cut);
                    }
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    public function ln(float $h): void { $this->y += $h; $this->x = $this->marginL; }

    /** True when a block of $h would cross the bottom margin; starts a new page. */
    public function needSpace(float $h): bool
    {
        if ($this->y + $h > $this->pageH - $this->marginB) {
            $this->addPage();
            return true;
        }
        return false;
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null): void
    {
        $ops = 'q ';
        if ($fill !== null) {
            $ops .= $this->color($fill) . ' rg ';
        }
        $ops .= sprintf(
            '%.2F %.2F %.2F %.2F re %s Q',
            $x * $this->k,
            ($this->pageH - $y - $h) * $this->k,
            $w * $this->k,
            $h * $this->k,
            $fill !== null ? 'f' : 'S'
        );
        $this->out($ops);
    }

    private function color(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /**
     * Text cell. $w = 0 means "extend to right margin".
     * align: L | C | R. Vertical centering within $h.
     */
    public function cell(float $w, float $h, string $text, string $align = 'L', ?array $textColor = null): void
    {
        if ($w === 0.0) {
            $w = $this->pageW - $this->marginR - $this->x;
        }
        $x = $this->x;
        $y = $this->y;

        $s = $this->toWinAnsi($text);
        $textW = $this->getStringWidth($text);

        $tx = match ($align) {
            'R' => $x + $w - $textW - 1.0,
            'C' => $x + ($w - $textW) / 2,
            default => $x + 1.0,
        };
        $ty = $y + $h / 2 + ($this->sizePt / $this->k) * 0.32;

        $font = match ($this->style) {
            'B' => '/F2',
            'I' => '/F3',
            default => '/F1',
        };
        $color = $textColor !== null ? sprintf('%s rg ', $this->color($textColor)) : '';

        $this->out(sprintf(
            'BT %s %.2F Tf %s%.2F %.2F Td (%s) Tj ET',
            $font,
            $this->sizePt,
            $color,
            $tx * $this->k,
            ($this->pageH - $ty) * $this->k,
            $this->escape($s)
        ));
        $this->x = $x + $w;
    }

    /** Write wrapped lines inside a fixed column; advances y by the lines used. */
    public function multiCell(float $w, float $lineH, string $text, string $align = 'L'): int
    {
        $lines = $this->wrapText($text, $w);
        foreach ($lines as $line) {
            $this->cell($w, $lineH, $line, $align);
            $this->x -= $w;
            $this->y += $lineH;
        }
        return count($lines);
    }

    private function out(string $s, bool $newLine = true): void
    {
        if ($newLine) {
            $s .= "\n";
        }
        $this->pages[$this->pageNo]['content'] .= $s;
        $this->pages[$this->pageNo]['len'] += strlen($s);
    }

    private function escape(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function toWinAnsi(string $utf8): string
    {
        if (preg_match('//u', $utf8) !== 1) {
            return preg_replace('/[\x80-\xFF]/', '?', $utf8) ?? '';
        }
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $utf8);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'CP1252//IGNORE', $utf8);
        }
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $utf8);
    }

    public function output(string $filename): string
    {
        /* Footer for the final page (earlier pages get theirs in addPage). */
        if ($this->footerFn) {
            ($this->footerFn)($this);
        }

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = [];
        $nPages = count($this->pages);
        // object numbering: 3 + (i-1)*2 = page, 4 + (i-1)*2 = content
        for ($i = 1; $i <= $nPages; $i++) {
            $kids[] = (3 + ($i - 1) * 2) . ' 0 R';
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$nPages} >>";

        $fontObjBase = 3 + $nPages * 2;
        $f1 = $fontObjBase;
        $f2 = $fontObjBase + 1;
        $f3 = $fontObjBase + 2;

        for ($i = 1; $i <= $nPages; $i++) {
            $contentObj = 4 + ($i - 1) * 2;
            $objects[3 + ($i - 1) * 2] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R >> >> /Contents %d 0 R >>",
                $this->pageW * $this->k,
                $this->pageH * $this->k,
                $f1,
                $f2,
                $f3,
                $contentObj
            );
            $stream = $this->pages[$i]['content'];
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objects[$f1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$f2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objects[$f3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        if ($filename !== '' && $filename !== 'php://output') {
            file_put_contents($filename, $pdf);
        }
        return $pdf;
    }
}
