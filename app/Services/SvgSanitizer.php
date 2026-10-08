<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Keeps the drawing and drops anything that can run in the browser.
 */
final class SvgSanitizer
{
    /** @var list<string> */
    private const REMOVED_TAGS = [
        'script',
        'foreignobject',
        'iframe',
        'embed',
        'object',
        'link',
        'meta',
        'handler',
        'set',
    ];

    public function sanitize(string $sourcePath): string
    {
        $xml = file_get_contents($sourcePath);

        if (! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $xml = (string) preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $this->strip($dom);

        $clean = $dom->saveXML($root);

        if (! is_string($clean) || $clean === '') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $target = tempnam(sys_get_temp_dir(), 'media-svg');

        if ($target === false) {
            throw new RuntimeException('Unable to prepare a sanitized image.');
        }

        $destination = $target.'.svg';
        @unlink($target);

        if (file_put_contents($destination, $clean) === false) {
            @unlink($destination);

            throw new RuntimeException('This image could not be sanitized.');
        }

        return $destination;
    }

    private function strip(DOMDocument $dom): void
    {
        $remove = [];

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($element->localName), self::REMOVED_TAGS, true)) {
                $remove[] = $element;

                continue;
            }

            $drop = [];

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');

                if (str_starts_with($name, 'on') || ($name === 'style' && preg_match('/javascript:|expression\s*\(/i', $value) === 1)) {
                    $drop[] = $attribute->nodeName;

                    continue;
                }

                if (str_ends_with($name, 'href') && $value !== '' && ! str_starts_with($value, '#')) {
                    $drop[] = $attribute->nodeName;
                }
            }

            foreach ($drop as $name) {
                $element->removeAttribute($name);
            }
        }

        foreach ($remove as $element) {
            $element->parentNode?->removeChild($element);
        }
    }
}
