<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Track;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

class TrackSvg
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan',
        'title', 'desc', 'defs', 'linearGradient', 'radialGradient', 'stop', 'use',
    ];

    private const ATTRIBUTES = [
        'd', 'points', 'x', 'y', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'width', 'height',
        'viewBox', 'preserveAspectRatio', 'transform', 'fill', 'stroke', 'opacity', 'fill-opacity',
        'stroke-opacity', 'font-size', 'font-family', 'text-anchor', 'dominant-baseline', 'class', 'id',
        'role', 'data-attribution', 'xmlns', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits',
        'gradientTransform', 'fx', 'fy', 'dx', 'dy', 'fill-rule', 'clip-rule',
    ];

    private const ATTRIBUTE_PREFIXES = ['stroke-', 'aria-'];

    /**
     * @return array{markup: string, attribution: string|null}|null
     */
    public function forTrack(?Track $track): ?array
    {
        $markup = $this->load($this->slugFor($track));

        if ($markup === null) {
            return null;
        }

        return ['markup' => $markup, 'attribution' => $this->attributionOf($markup)];
    }

    public function slugFor(?Track $track): ?string
    {
        if ($track === null) {
            return null;
        }

        if ($track->svg_slug !== null) {
            return $track->svg_slug;
        }

        $slug = config('mvp.tracks.slugs')[$track->track.'|'.$track->layout] ?? null;

        return is_string($slug) ? $slug : null;
    }

    public function has(?string $slug): bool
    {
        return $this->load($slug) !== null;
    }

    public function load(?string $slug): ?string
    {
        if ($slug === null || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }

        $path = rtrim((string) config('mvp.tracks.path'), '/').'/'.$slug.'.svg';

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $this->sanitize($contents);
    }

    public function sanitize(string $svg): ?string
    {
        return $this->report($svg)['markup'] ?? null;
    }

    /**
     * @return array{markup: string, removed: int}|null
     */
    public function report(string $svg): ?array
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg) === 1) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || $document->doctype !== null || $root === null || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return null;
        }

        $removed = 0;
        $this->clean($root, $removed);
        $markup = $document->saveXML($root);

        return $markup === false ? null : ['markup' => $markup, 'removed' => $removed];
    }

    private function attributionOf(string $markup): ?string
    {
        $document = new DOMDocument;
        $document->loadXML($markup);
        $text = trim($document->documentElement?->getAttribute('data-attribution') ?? '');

        return $text === '' ? null : $text;
    }

    private function clean(DOMElement $element, int &$removed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (! $this->attributeAllowed($attribute, $element)) {
                $element->removeAttributeNode($attribute);
                $removed++;
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! $this->elementAllowed($child)) {
                    $element->removeChild($child);
                    $removed++;

                    continue;
                }

                $this->clean($child, $removed);
            } elseif (! $this->textLike($child)) {
                $element->removeChild($child);
                $removed++;
            }
        }
    }

    private function textLike(DOMNode $node): bool
    {
        return in_array($node->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true);
    }

    private function elementAllowed(DOMElement $element): bool
    {
        return $element->namespaceURI === self::SVG_NS && in_array($element->localName, self::ELEMENTS, true);
    }

    private function attributeAllowed(DOMAttr $attribute, DOMElement $element): bool
    {
        $isHref = $attribute->localName === 'href'
            && in_array($attribute->namespaceURI, [null, '', self::XLINK_NS], true);

        if ($isHref) {
            return $element->localName === 'use' && preg_match('/^#[A-Za-z0-9_.:-]+$/', $attribute->value) === 1;
        }

        if ($attribute->namespaceURI !== null && $attribute->namespaceURI !== '') {
            return false;
        }

        $name = $attribute->name;

        if (! in_array($name, self::ATTRIBUTES, true) && ! $this->hasAllowedPrefix($name)) {
            return false;
        }

        return $this->valueSafe($attribute->value);
    }

    private function hasAllowedPrefix(string $name): bool
    {
        foreach (self::ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function valueSafe(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

        if (preg_match('/javascript:|data:|expression\s*\(|@import/i', $decoded) === 1) {
            return false;
        }

        if (preg_match_all('/url\s*\(\s*([^)]*)\)/i', $decoded, $matches) === 0) {
            return true;
        }

        foreach ($matches[1] as $target) {
            if (preg_match('/^[\'"]?#[A-Za-z0-9_.:-]+[\'"]?$/', trim($target)) !== 1) {
                return false;
            }
        }

        return true;
    }
}
