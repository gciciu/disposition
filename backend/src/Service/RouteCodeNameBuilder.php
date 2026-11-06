<?php

namespace App\Service;

/**
 * Builds route display codes like "KW 26/45" from a source filename + scheduled date.
 *
 * Letter code comes from the filename (e.g. "… KW 40.pdf" → KW); year/week come from the date.
 */
class RouteCodeNameBuilder
{
    public function build(string $filename, \DateTimeImmutable $scheduledDate, ?string $fallbackName = null): string
    {
        $letterCode = $this->extractLetterCode($filename, $fallbackName);

        // ISO-8601 week-numbering year + week (o / W), matching PDF "KW 26/40" = yy/ww.
        $shortYear = ((int) $scheduledDate->format('o')) % 100;
        $week = (int) $scheduledDate->format('W');

        return sprintf('%s %02d/%02d', $letterCode, $shortYear, $week);
    }

    public function extractLetterCode(string $filename, ?string $fallbackName = null): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $fromFile = $this->matchLetterCode($base);
        if (null !== $fromFile) {
            return $fromFile;
        }

        if (null !== $fallbackName && '' !== trim($fallbackName)) {
            $fromFallback = $this->matchLetterCode($fallbackName);
            if (null !== $fromFallback) {
                return $fromFallback;
            }
        }

        return 'KW';
    }

    private function matchLetterCode(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        // "… KW 40 …", "KW 26/40 …", "KW40" — letters next to a week(/year) number
        if (preg_match('/\b([A-Za-zÄÖÜäöü]{1,10})\s*\d{1,2}(?:\s*\/\s*\d{2})?\b/u', $value, $m)) {
            return mb_strtoupper($m[1]);
        }

        return null;
    }
}
