<?php

namespace App\Support;

class HtmlSanitizer
{
    /**
     * @var array<int, string>
     */
    private array $allowedTags;

    /**
     * @var array<string, array<int, string>>
     */
    private array $allowedAttributes;

    /**
     * @param array<int, string> $allowedTags
     * @param array<string, array<int, string>> $allowedAttributes
     */
    public function __construct(
        array $allowedTags = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'a'],
        array $allowedAttributes = ['a' => ['href', 'target', 'rel']]
    ) {
        $this->allowedTags = $allowedTags;
        $this->allowedAttributes = $allowedAttributes;
    }

    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $this->sanitizeNode($document);

        $sanitized = $document->saveHTML() ?: '';
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $sanitized;
    }

    private function sanitizeNode(\DOMNode $node): void
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (!in_array($tag, $this->allowedTags, true)) {
                $this->unwrapNode($node);
                return;
            }

            if ($node->hasAttributes()) {
                $allowed = $this->allowedAttributes[$tag] ?? [];
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    if (!in_array($attribute->nodeName, $allowed, true)) {
                        $node->removeAttribute($attribute->nodeName);
                        continue;
                    }

                    if ($tag === 'a' && $attribute->nodeName === 'href') {
                        $href = trim((string) $attribute->nodeValue);
                        if (!preg_match('/^(https?:|mailto:)/i', $href)) {
                            $node->removeAttribute('href');
                        }
                    }
                }

                if ($tag === 'a' && $node->hasAttribute('href')) {
                    $node->setAttribute('rel', 'noopener noreferrer');
                    $node->setAttribute('target', '_blank');
                }
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeNode($child);
        }
    }

    private function unwrapNode(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            $node->parentNode?->removeChild($node);
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
