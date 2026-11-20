<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use InvalidArgumentException;

class EscPosRenderer
{
    public function columnsForWidth(int $paperWidthMm): int
    {
        return match ($paperWidthMm) {
            58 => 32,
            80 => 48,
            default => throw new InvalidArgumentException('Unsupported thermal paper width.'),
        };
    }

    /** Render conservative ESC/POS bytes from the immutable text stored with a job. */
    public function render(string $text, int $paperWidthMm, bool $cutAfterPrint): string
    {
        $columns = $this->columnsForWidth($paperWidthMm);
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalized);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $lines = array_map(fn(string $line): string => $this->ascii($line), $lines);

        $bytes = "\x1B@\x1Ba\x01\x1BE\x01";
        $headerCount = min(3, count($lines));
        for ($index = 0; $index < $headerCount; $index++) {
            $bytes .= $this->renderLine($lines[$index], $columns) . "\n";
        }
        $bytes .= "\x1BE\x00\x1Ba\x00";
        for ($index = $headerCount; $index < count($lines); $index++) {
            $bytes .= $this->renderLine($lines[$index], $columns) . "\n";
        }
        if ($cutAfterPrint) {
            $bytes .= "\n\x1DV\x00";
        }
        return $bytes;
    }

    private function ascii(string $line): string
    {
        $line = str_replace(['₹', '×', '–', '—', '·'], ['Rs. ', 'x', '-', '-', '-'], $line);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
            if (is_string($converted)) {
                $line = $converted;
            }
        }
        $line = preg_replace('/[^\x20-\x7E\t]/', '?', $line) ?? '';
        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $line) ?? '';
    }

    private function wrapped(string $line, int $columns): string
    {
        if (trim($line) === '') {
            return '';
        }
        return wordwrap($line, $columns, "\n", true);
    }

    private function renderLine(string $line, int $columns): string
    {
        if (!str_starts_with($line, 'TOKEN ')) {
            return $this->wrapped($line, $columns);
        }

        // ESC/POS GS ! 0x11 selects double width and height; reset immediately
        // after the token so subsequent item lines use the normal character size.
        $tokenColumns = max(1, intdiv($columns, 2));
        return "\x1D!\x11" . $this->wrapped($line, $tokenColumns) . "\x1D!\x00";
    }
}
