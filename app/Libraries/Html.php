<?php

namespace App\Libraries;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class Html
{

    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li',
        'blockquote', 'code', 'pre',
        'h3', 'h4',
        'a', 'img',
    ];

    private const ALLOWED_ATTRS = [
        'a'   => ['href', 'title'],
        'img' => ['src', 'alt'],
    ];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private const IMAGE_PREFIX = '/uploads/tickets/';

    public function clean(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="uhd-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        $xpath = new DOMXPath($doc);
        $root  = $xpath->query('//div[@id="uhd-root"]')->item(0);

        if (! $root instanceof DOMElement) {
            return '';
        }

        foreach ($xpath->query('.//script | .//style | .//iframe | .//object | .//embed | .//form | .//comment()', $root) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $this->scrub($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out) === '' ? '' : trim($out);
    }

    public function toText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = preg_replace('/<img\b[^>]*>/i', ' [image] ', $html) ?? $html;
        $text = preg_replace('#<\s*/?\s*(p|div|br|li|h3|h4|blockquote|pre|tr)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function scrub(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->scrub($child);
                $this->unwrap($child);

                continue;
            }

            $this->scrubAttributes($child, $tag);

            if ($tag === 'img' && ! $child->hasAttribute('src')) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            $this->scrub($child);
        }
    }

    private function scrubAttributes(DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);

            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }

            $value = trim($attr->nodeValue ?? '');

            if ($name === 'href' && ! $this->safeHref($value)) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }

            if ($name === 'src' && ! str_starts_with($value, self::IMAGE_PREFIX)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
            $el->setAttribute('target', '_blank');
        }
    }

    private function safeHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, self::ALLOWED_SCHEMES, true);
    }

    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;

        if ($parent === null) {
            return;
        }

        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }
}
