import sanitizeHtml = require('sanitize-html');

const URL_AMMESSI = ['http://', 'https://', '/', 'mailto:', 'data:image/'];
const urlAmmesso = (v: string) => URL_AMMESSI.some((p) => v.trim().toLowerCase().startsWith(p));

/** Toglie href/src con indirizzi non ammessi (javascript:, vbscript:, relativi...). */
function filtraUrl(attributo: 'href' | 'src') {
  return (tag: string, attribs: sanitizeHtml.Attributes): sanitizeHtml.Tag => {
    const a = { ...attribs };
    if (a[attributo] !== undefined && !urlAmmesso(a[attributo])) delete a[attributo];
    if (tag === 'a' && a.target === '_blank') a.rel = 'noopener noreferrer';
    return { tagName: tag, attribs: a };
  };
}

/**
 * HTML delle comunicazioni (editor dell'HR), come sanitizeRichHtml() del PHP:
 * stessi tag e attributi ammessi, stessi indirizzi (http, https, /, mailto,
 * immagini data:), commenti tolti, tag non ammessi sostituiti dal loro contenuto.
 *
 * Correzione: il PHP non ripuliva il contenuto dei tag sostituiti, per cui
 * <x><img src=x onerror=...></x> passava intatto. Qui ogni livello e' ripulito;
 * script e stili spariscono del tutto invece di diventare testo.
 */
export function sanitizzaHtmlRicco(html: string | null | undefined): string {
  const testo = String(html ?? '').trim();
  if (testo === '') return '';
  return sanitizeHtml(testo, {
    allowedTags: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'a', 'img', 'span', 'div'],
    allowedAttributes: { a: ['href', 'title', 'target', 'rel'], img: ['src', 'alt', 'title', 'width', 'height'] },
    disallowedTagsMode: 'discard',
    nonTextTags: ['script', 'style', 'textarea', 'option', 'noscript', 'title', 'template', 'iframe', 'object'],
    allowedSchemes: ['http', 'https', 'mailto', 'data'],
    allowedSchemesByTag: {},
    allowProtocolRelative: true,
    transformTags: { a: filtraUrl('href'), img: filtraUrl('src') },
  });
}
