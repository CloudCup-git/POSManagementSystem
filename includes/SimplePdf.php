<?php
// ── MINIMAL, DEPENDENCY-FREE PDF WRITER ─────────────────────────────────
// Generates single/multi-page text PDFs (Letter size) using only the PDF
// spec's built-in Helvetica / Helvetica-Bold fonts — no Composer, no
// external library, nothing to install. Good enough for letters and
// contracts; not a general-purpose PDF engine (no images, no tables).
//
// Usage:
//   $pdf = new SimplePdf();
//   $pdf->addPage();
//   $pdf->setFont('B', 16);
//   $pdf->writeLine('EMPLOYMENT CONTRACT');
//   $pdf->setFont('', 11);
//   $pdf->writeParagraph('Some long paragraph of body text that will '
//       . 'automatically word-wrap and flow onto new pages as needed.');
//   $bytes = $pdf->output(); // raw PDF bytes, ready to save or email

class SimplePdf {
    private float $pageW = 612; // US Letter, points
    private float $pageH = 792;
    private float $marginL = 64;
    private float $marginR = 64;
    private float $marginT = 64;
    private float $marginB = 64;

    private array $pages = [];      // each: array of content-stream strings
    private array $pageAnnots = []; // reserved (unused, kept for future links)
    private float $x = 0;
    private float $y = 0;
    private string $fontStyle = '';  // '' = Helvetica, 'B' = Helvetica-Bold
    private float $fontSize = 11;
    private float $leading = 14.5;
    private bool $usedBold = false;
    private bool $usedRegular = false;

    // Standard Helvetica / Helvetica-Bold advance widths (1/1000 em),
    // per the PDF spec's built-in AFM metrics for the base-14 fonts,
    // indexed by ASCII code 32–255 (WinAnsiEncoding covers the range we need).
    private static array $widthsHelvetica = [];
    private static array $widthsHelveticaBold = [];

    public function __construct() {
        if (empty(self::$widthsHelvetica)) {
            self::$widthsHelvetica = self::buildHelveticaWidths();
            self::$widthsHelveticaBold = self::buildHelveticaBoldWidths();
        }
    }

    public function addPage(): void {
        $this->pages[] = '';
        $this->x = $this->marginL;
        $this->y = $this->pageH - $this->marginT;
    }

    public function setFont(string $style, float $size): void {
        $this->fontStyle = $style === 'B' ? 'B' : '';
        $this->fontSize  = $size;
        $this->leading   = round($size * 1.32, 2);
        if ($this->fontStyle === 'B') $this->usedBold = true; else $this->usedRegular = true;
    }

    public function setLeading(float $pt): void { $this->leading = $pt; }

    /** Vertical whitespace. */
    public function spacer(float $pt): void {
        $this->y -= $pt;
        $this->ensureRoom(0);
    }

    private function textWidth(string $text): float {
        $widths = $this->fontStyle === 'B' ? self::$widthsHelveticaBold : self::$widthsHelvetica;
        $w = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($text[$i]);
            $w += ($widths[$code] ?? 556);
        }
        return $w * $this->fontSize / 1000;
    }

    private function ensureRoom(float $needed): void {
        if ($this->y - $needed < $this->marginB) {
            $this->addPage();
        }
    }

    /** Writes one line as-is (no wrapping) at the current position, then advances. */
    public function writeLine(string $text): void {
        $this->ensureRoom($this->leading);
        $this->emit($this->marginL, $this->y, $text);
        $this->y -= $this->leading;
    }

    /** Word-wraps text to the content width and writes each resulting line. */
    public function writeParagraph(string $text, float $spaceAfter = 8): void {
        $maxWidth = $this->pageW - $this->marginL - $this->marginR;
        foreach ($this->wrap($text, $maxWidth) as $line) {
            $this->ensureRoom($this->leading);
            $this->emit($this->marginL, $this->y, $line);
            $this->y -= $this->leading;
        }
        $this->y -= $spaceAfter;
    }

    /** A left label + right-aligned-ish value on one line, e.g. "Position:   Barista". */
    public function writeField(string $label, string $value): void {
        $this->ensureRoom($this->leading);
        $savedStyle = $this->fontStyle;
        $this->fontStyle = 'B';
        $this->emit($this->marginL, $this->y, $label);
        $labelW = $this->textWidth($label);
        $this->fontStyle = $savedStyle;
        $this->emit($this->marginL + $labelW + 6, $this->y, $value);
        $this->y -= $this->leading;
    }

    private function wrap(string $text, float $maxWidth): array {
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $current = '';
            foreach ($words as $word) {
                $trial = $current === '' ? $word : $current . ' ' . $word;
                if ($this->textWidth($trial) > $maxWidth && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $trial;
                }
            }
            if ($current !== '') $lines[] = $current;
            if ($para === '') $lines[] = '';
        }
        return $lines;
    }

    private function emit(float $x, float $y, string $text): void {
        $idx = count($this->pages) - 1;
        $font = $this->fontStyle === 'B' ? '/F2' : '/F1';
        $this->pages[$idx] .= sprintf(
            "BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $font, $this->fontSize, $x, $y, $this->escape($text)
        );
    }

    private function escape(string $s): string {
        // The built-in Helvetica font only supports single-byte WinAnsiEncoding
        // (~= Windows-1252), so UTF-8 input (e.g. "ñ", curly quotes) must be
        // transliterated down first. Characters with no CP1252 equivalent
        // (emoji, ₱, CJK, etc.) fall back to '?' rather than corrupting the file.
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '', $s);
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /** Builds the full PDF byte stream. */
    public function output(): string {
        if (empty($this->pages)) $this->addPage();

        $objects = [];
        $n = 1;
        $catalogId = $n++;
        $pagesId   = $n++;
        $fontRegId = $n++;
        $fontBoldId = $n++;

        $pageIds = [];
        $contentIds = [];
        foreach ($this->pages as $content) {
            $pageIds[] = $n++;
            $contentIds[] = $n++;
        }

        $objects[$fontRegId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fontBoldId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $kids = '[' . implode(' ', array_map(fn($id) => "$id 0 R", $pageIds)) . ']';
        $objects[$pagesId] = "<< /Type /Pages /Kids $kids /Count " . count($pageIds) . " >>";
        $objects[$catalogId] = "<< /Type /Catalog /Pages $pagesId 0 R >>";

        foreach ($this->pages as $i => $content) {
            $pid = $pageIds[$i];
            $cid = $contentIds[$i];
            $objects[$pid] = "<< /Type /Page /Parent $pagesId 0 R "
                . "/MediaBox [0 0 {$this->pageW} {$this->pageH}] "
                . "/Resources << /Font << /F1 $fontRegId 0 R /F2 $fontBoldId 0 R >> >> "
                . "/Contents $cid 0 R >>";
            $stream = $content;
            $objects[$cid] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $count = $n; // object numbers 0..n-1
        $pdf .= "xref\n0 $count\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $off = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size $count /Root $catalogId 0 R >>\n";
        $pdf .= "startxref\n$xrefStart\n%%EOF";

        return $pdf;
    }

    // Standard Helvetica AFM advance widths (WinAnsiEncoding codes 32-255).
    private static function buildHelveticaWidths(): array {
        $w = array_fill(32, 224, 556);
        $map = [
            32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,
            40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
            48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
            56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,
            64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
            72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,
            80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,
            88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,
            96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,
            104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,
            112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
            120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
            145=>222,146=>222,147=>333,148=>333,150=>556,151=>1000,
        ];
        foreach ($map as $c => $wd) $w[$c] = $wd;
        return $w;
    }

    private static function buildHelveticaBoldWidths(): array {
        $w = array_fill(32, 224, 611);
        $map = [
            32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,
            40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
            48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
            56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,
            64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,
            72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,
            80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,
            88=>667,89=>667,90=>611,91=>333,92=>278,93=>333,94=>584,95=>556,
            96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,
            104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,111=>611,
            112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,
            120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
            145=>278,146=>278,147=>500,148=>500,150=>556,151=>1000,
        ];
        foreach ($map as $c => $wd) $w[$c] = $wd;
        return $w;
    }
}
