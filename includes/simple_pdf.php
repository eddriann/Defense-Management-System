<?php

final class SimplePdfDocument
{
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;

    private array $pages = [];
    private int $current_page = -1;
    private string $logo_data;
    private int $logo_width;
    private int $logo_height;
    private string $logo_color_space;

    public function __construct(string $logo_path)
    {
        $image_data = file_get_contents($logo_path);
        $image_info = getimagesize($logo_path);
        if ($image_data === false || $image_info === false || $image_info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('The school logo could not be loaded as a JPEG image.');
        }

        $this->logo_data = $image_data;
        $this->logo_width = (int)$image_info[0];
        $this->logo_height = (int)$image_info[1];
        $this->logo_color_space = ($image_info['channels'] ?? 3) === 1 ? '/DeviceGray' : '/DeviceRGB';
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current_page = count($this->pages) - 1;
    }

    public function text(
        float $x,
        float $y_from_top,
        string $text,
        float $font_size = 10,
        bool $bold = false,
        array $color = [0, 0, 0]
    ): void
    {
        if (count($color) !== 3 || min($color) < 0 || max($color) > 1) {
            throw new InvalidArgumentException('PDF text color components must be between zero and one.');
        }
        $font = $bold ? 'F2' : 'F1';
        $y = self::PAGE_HEIGHT - $y_from_top;
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            throw new RuntimeException('Report text could not be encoded for PDF output.');
        }

        $escaped = '';
        for ($index = 0, $length = strlen($encoded); $index < $length; $index++) {
            $byte = ord($encoded[$index]);
            if ($byte < 32 || $byte > 126) {
                $escaped .= sprintf('\\%03o', $byte);
            } elseif ($encoded[$index] === '\\' || $encoded[$index] === '(' || $encoded[$index] === ')') {
                $escaped .= '\\' . $encoded[$index];
            } else {
                $escaped .= $encoded[$index];
            }
        }

        $this->pages[$this->current_page] .= sprintf(
            "%.3F %.3F %.3F rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $color[0],
            $color[1],
            $color[2],
            $font,
            $font_size,
            $x,
            $y,
            $escaped
        );
    }

    public function line(float $x1, float $y1_from_top, float $x2, float $y2_from_top, float $width = 0.7): void
    {
        $this->pages[$this->current_page] .= sprintf(
            "%.2F w %.2F %.2F m %.2F %.2F l S\n",
            $width,
            $x1,
            self::PAGE_HEIGHT - $y1_from_top,
            $x2,
            self::PAGE_HEIGHT - $y2_from_top
        );
    }

    public function logo(float $x, float $y_from_top, float $width, float $height): void
    {
        $this->pages[$this->current_page] .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /Im0 Do Q\n",
            $width,
            $height,
            $x,
            self::PAGE_HEIGHT - $y_from_top - $height
        );
    }

    public function build(): string
    {
        if (!$this->pages) {
            throw new LogicException('A PDF report must contain at least one page.');
        }

        $page_count = count($this->pages);
        $objects = [];
        $page_ids = [];
        $content_ids = [];
        $next_id = 6;
        for ($index = 0; $index < $page_count; $index++) {
            $page_ids[] = $next_id++;
            $content_ids[] = $next_id++;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' .
            implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $page_ids)) .
            '] /Count ' . $page_count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /XObject /Subtype /Image /Width ' . $this->logo_width .
            ' /Height ' . $this->logo_height . ' /ColorSpace ' . $this->logo_color_space .
            ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($this->logo_data) .
            " >>\nstream\n" . $this->logo_data . "\nendstream";

        foreach ($this->pages as $index => $content) {
            $page_id = $page_ids[$index];
            $content_id = $content_ids[$index];
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' .
                self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT .
                '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> ' .
                '/XObject << /Im0 5 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
            $objects[$content_id] = '<< /Length ' . strlen($content) . " >>\nstream\n" .
                $content . "endstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) .
            " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF\n";

        return $pdf;
    }
}
