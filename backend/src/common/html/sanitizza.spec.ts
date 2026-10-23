import { sanitizzaHtmlRicco } from './sanitizza';

describe('sanitizzaHtmlRicco', () => {
  it('lascia la formattazione ammessa', () => {
    expect(sanitizzaHtmlRicco('<p>Ciao <strong>tutti</strong></p><ul><li>uno</li></ul>')).toBe('<p>Ciao <strong>tutti</strong></p><ul><li>uno</li></ul>');
    expect(sanitizzaHtmlRicco('<img src="/communication_file.php?id=3&inline=1" alt="x">')).toBe('<img src="/communication_file.php?id=3&amp;inline=1" alt="x" />');
  });

  it('vuoto resta vuoto', () => {
    expect(sanitizzaHtmlRicco('   ')).toBe('');
    expect(sanitizzaHtmlRicco(null)).toBe('');
  });

  it.each([
    ['<img src=x onerror=alert(1)>', 'onerror'],
    ['<x><img src="/a.png" onerror="alert(1)"></x>', 'onerror'], // falla del PHP: contenuto dei tag sostituiti non ripulito
    ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
    ['<a href=" JaVaScRiPt:alert(1)">x</a>', 'avascript'],
    ['<a href="vbscript:msgbox(1)">x</a>', 'vbscript'],
    ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:text'],
    ['<svg><script>alert(1)</script></svg>', 'alert'],
    ['<iframe src="https://x.test"></iframe>', 'iframe'],
    ['<p style="background:url(javascript:alert(1))">x</p>', 'style'],
    ['<div onmouseover="alert(1)">x</div>', 'onmouseover'],
    ['<form action="https://x.test"><input name="p"></form>', '<input'],
    ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>', 'onerror'],
  ])('toglie %s', (html, vietato) => {
    expect(sanitizzaHtmlRicco(html).toLowerCase()).not.toContain(vietato.toLowerCase());
  });

  it('link in nuova scheda sempre con noopener', () => {
    expect(sanitizzaHtmlRicco('<a href="https://x.test" target="_blank" rel="opener">x</a>')).toBe('<a href="https://x.test" target="_blank" rel="noopener noreferrer">x</a>');
  });

  it('indirizzi relativi tolti come nel PHP, immagini data: ammesse', () => {
    expect(sanitizzaHtmlRicco('<a href="pagina.html">x</a>')).toBe('<a>x</a>');
    expect(sanitizzaHtmlRicco('<img src="data:image/png;base64,AAAA">')).toBe('<img src="data:image/png;base64,AAAA" />');
  });
});
