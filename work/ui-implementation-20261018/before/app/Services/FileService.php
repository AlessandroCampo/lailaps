<?php

namespace App\Services;

final class FileService
{
    public function getLines(string $content, int $start, int $end): string
    {
        $start = max(1, $start);
        if ($end < $start) {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);

        return implode("\n", array_slice(
            $lines,
            $start - 1,
            $end - $start + 1
        ));
    }
}
