<?php
/**
 * Sanitizzatore HTML basato su whitelist.
 * Pensato per contenuti rich text di comunicazioni (no JS, no stili pericolosi).
 */

function sanitizeRichHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';

    $allowedTags = [
        'p','br','strong','b','em','i','u','s','strike',
        'ul','ol','li',
        'h1','h2','h3','h4',
        'blockquote',
        'a','img',
        'span','div'
    ];
    $allowedAttrs = [
        'a'   => ['href','title','target','rel'],
        'img' => ['src','alt','title','width','height'],
        'span'=> [],
        'div' => [],
        'p'   => [],
    ];

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    // wrap to force UTF-8 + parsing as fragment
    $wrapped = '<?xml encoding="UTF-8"?><div id="__root__">' . $html . '</div>';
    if (!@$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        libxml_clear_errors();
        return e(strip_tags($html));
    }
    libxml_clear_errors();

    $root = $doc->getElementById('__root__');
    if (!$root) {
        // fallback
        $nodes = $doc->getElementsByTagName('div');
        $root = $nodes->length > 0 ? $nodes->item(0) : null;
    }
    if (!$root) return e(strip_tags($html));

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $allowedAttrs) {
        if (!$node->hasChildNodes()) return;
        $toRemove = [];
        $toUnwrap = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                if (!in_array($tag, $allowedTags, true)) {
                    // unwrap: keep text content, remove element
                    $toUnwrap[] = $child;
                    continue;
                }
                // strip non-whitelisted attributes
                $okAttrs = $allowedAttrs[$tag] ?? [];
                if ($child->hasAttributes()) {
                    foreach (iterator_to_array($child->attributes) as $attr) {
                        $aname = strtolower($attr->nodeName);
                        $aval  = $attr->nodeValue;
                        if (!in_array($aname, $okAttrs, true)) {
                            $child->removeAttribute($attr->nodeName);
                            continue;
                        }
                        // validate URLs
                        if (($aname === 'href' || $aname === 'src')) {
                            $clean = trim($aval);
                            $low = strtolower($clean);
                            $ok = (str_starts_with($low, 'http://')
                                || str_starts_with($low, 'https://')
                                || str_starts_with($low, '/')
                                || str_starts_with($low, 'mailto:')
                                || str_starts_with($low, 'data:image/'));
                            if (!$ok) {
                                $child->removeAttribute($attr->nodeName);
                            }
                        }
                    }
                    // force safe target on links
                    if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }
                $sanitizeNode($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $n) { $n->parentNode->removeChild($n); }
        foreach ($toUnwrap as $n) {
            while ($n->firstChild) { $n->parentNode->insertBefore($n->firstChild, $n); }
            $n->parentNode->removeChild($n);
        }
    };

    $sanitizeNode($root);

    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return $out;
}
