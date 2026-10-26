import { layoutSicuro, pulisciHtml } from './attestati.service';

/** Testo degli elementi dei modelli attestato: niente script nell'editor dell'HR. */
describe('pulisciHtml (modelli attestato)', () => {
  it('tiene i tag di testo e gli stili ammessi', () => {
    expect(pulisciHtml('<b>Ciao</b> <span style="color: #0b3aa4; font-weight: bold">{NOME}</span><br>'))
      .toBe('<b>Ciao</b> <span style="color:#0b3aa4;font-weight:bold">{NOME}</span><br />');
    expect(pulisciHtml('<font color="#ff0000" size="3">x</font>')).toBe('<font color="#ff0000" size="3">x</font>');
  });

  it('correzione: attributi evento senza spazio prima (PHP li lasciava passare)', () => {
    const fuori = pulisciHtml('<div/tabindex=0/autofocus/onfocus=alert(1)>x</div>');
    expect(fuori).not.toMatch(/onfocus|autofocus|tabindex/i);
  });

  it('toglie script, link, immagini e stili non di testo', () => {
    const fuori = pulisciHtml('<script>alert(1)</script><a href="javascript:x">a</a><img src=x onerror=alert(1)><span style="background:url(javascript:x)">s</span><font color="javascript:x">f</font>');
    expect(fuori).toBe('a<span>s</span><font>f</font>');
  });

  it('i modelli gia salvati (anche dal PHP) escono ripuliti', () => {
    const salvato = JSON.stringify([{ type: 'text', content: '<p/onmouseover=alert(1)>t</p>' }]);
    expect(layoutSicuro(salvato)).not.toMatch(/onmouseover/);
    expect(layoutSicuro('non json')).toBe('[]');
  });
});
