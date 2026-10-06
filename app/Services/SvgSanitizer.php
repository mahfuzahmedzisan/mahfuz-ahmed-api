<?php

namespace App\Services;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class SvgSanitizer
{
    /**
     * @var list<string>
     */
    private const SVG_MIMES = [
        'image/svg+xml',
        'image/svg',
        'text/svg',
    ];

    public function sanitize(string $rawSvg): string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($rawSvg);

        if ($clean === false || trim($clean) === '') {
            throw new RuntimeException('The SVG file could not be sanitized and was rejected.');
        }

        return $clean;
    }

    public function isSvg(UploadedFile|string $source): bool
    {
        if ($source instanceof UploadedFile) {
            $extension = strtolower($source->getClientOriginalExtension());
            $mime = strtolower((string) ($source->getMimeType() ?: $source->getClientMimeType()));

            if ($extension === 'svg' || in_array($mime, self::SVG_MIMES, true) || str_contains($mime, 'svg')) {
                return true;
            }

            $path = $source->getRealPath() ?: $source->getPathname();

            return $this->looksLikeSvg($path);
        }

        $detected = @mime_content_type($source);
        $mime = is_string($detected) ? strtolower($detected) : '';

        if (str_contains($mime, 'svg') || str_ends_with(strtolower($source), '.svg')) {
            return true;
        }

        return $this->looksLikeSvg($source);
    }

    private function looksLikeSvg(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 256);
        fclose($handle);

        if (! is_string($header)) {
            return false;
        }

        $trimmed = ltrim($header);

        return str_starts_with($trimmed, '<svg')
            || (str_starts_with($trimmed, '<?xml') && str_contains($header, '<svg'));
    }
}
