/**
 * Toast della vista Cora (stesso markup e stessi tempi di cora.js), per le
 * pagine React che non caricano piu' lo script.
 */
const CHECK = '<svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><path d="M3.75 9.375l3.375 3.375 7.125-7.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const ALERT = '<svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><path d="M9 5.25v4.5M9 12.375h.008" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>';

const esc = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));

export function toastCora(titolo: string, testo = '', tipo = ''): void {
  const host = document.getElementById('coraToasts');
  if (!host) return;
  const el = document.createElement('div');
  el.className = 'cora-toast' + (tipo === 'error' ? ' is-error' : '');
  el.setAttribute('role', 'status');
  el.innerHTML = '<span class="cora-toast-ic">' + (tipo === 'error' ? ALERT : CHECK) + '</span>'
    + '<div><div class="cora-toast-t">' + esc(titolo) + '</div>'
    + (testo ? '<div class="cora-toast-m">' + esc(testo) + '</div>' : '') + '</div>';
  host.appendChild(el);
  setTimeout(() => {
    el.classList.add('is-leaving');
    setTimeout(() => el.remove(), 260);
  }, tipo === 'error' ? 7000 : 5000);
}
