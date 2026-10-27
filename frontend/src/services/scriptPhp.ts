/**
 * Effetti degli script del vecchio PHP (public/assets/js/* e script inline dei
 * template): listener su window/document/body, timer e nodi aggiunti al body.
 * Nel PHP sparivano a ogni cambio pagina; qui l'app resta aperta, quindi
 * all'uscita dalla pagina vengono tolti (`pulisciScriptPhp`) e la pagina
 * successiva parte pulita senza ricaricare tutto.
 *
 * Chi ha registrato cosa si riconosce dallo stack: script in /assets/js/ o
 * script inline (indirizzo della pagina, senza estensione). Il codice
 * dell'app (moduli /src, /node_modules, blocchi della build) e gli script di
 * altri domini (widget assistenza) non vengono toccati.
 */
type Ascolto = { dove: EventTarget; tipo: string; fn: EventListenerOrEventListenerObject; opzioni?: boolean | EventListenerOptions };

const ascolti: Ascolto[] = [];
const timer = new Set<number>();
const intervalli = new Set<number>();
const nodi = new Set<Node>();
let installato = false;

/** Vero se la chiamata in corso arriva da uno script del vecchio PHP. */
function daScriptPhp(): boolean {
  const pila = new Error().stack ?? '';
  for (const m of pila.matchAll(/(https?:\/\/[^\s()]+?):\d+:\d+/g)) {
    let u: URL;
    try { u = new URL(m[1]); } catch { continue; }
    if (u.origin !== location.origin) continue;
    if (u.pathname.startsWith('/assets/js/')) return true;
    const ultimo = u.pathname.split('/').pop() ?? '';
    if (!ultimo.includes('.') && !u.pathname.startsWith('/@') && !u.pathname.startsWith('/src/') && !u.pathname.startsWith('/node_modules/')) return true;
  }
  return false;
}

/** Bersagli globali: i listener sugli altri elementi spariscono con gli elementi. */
const globale = (t: EventTarget) => t === window || t === document || t === document.body || t === document.documentElement;

function installa(): void {
  if (installato) return;
  installato = true;
  const w = window as any;

  const aggiungi = EventTarget.prototype.addEventListener;
  EventTarget.prototype.addEventListener = function (this: EventTarget, tipo: string, fn: EventListenerOrEventListenerObject | null, opzioni?: boolean | AddEventListenerOptions) {
    if (fn && globale(this) && daScriptPhp()) ascolti.push({ dove: this, tipo, fn, opzioni });
    return aggiungi.call(this, tipo, fn, opzioni);
  };

  const st = w.setTimeout, si = w.setInterval, ct = w.clearTimeout, ci = w.clearInterval;
  w.setTimeout = function (...a: unknown[]) {
    const id = st.apply(window, a);
    if (daScriptPhp()) timer.add(id);
    return id;
  };
  w.setInterval = function (...a: unknown[]) {
    const id = si.apply(window, a);
    if (daScriptPhp()) intervalli.add(id);
    return id;
  };
  w.clearTimeout = function (id: number) { timer.delete(id); return ct.call(window, id); };
  w.clearInterval = function (id: number) { intervalli.delete(id); return ci.call(window, id); };

  // Nodi aggiunti al body (modali, toast, menu): appendChild, insertBefore, append, prepend, insertAdjacent*.
  const segna = (n: Node) => { if (daScriptPhp()) nodi.add(n); };
  const ac = Node.prototype.appendChild, ib = Node.prototype.insertBefore;
  Node.prototype.appendChild = function <T extends Node>(this: Node, n: T): T {
    if (this === document.body) segna(n);
    return ac.call(this, n) as T;
  };
  Node.prototype.insertBefore = function <T extends Node>(this: Node, n: T, rif: Node | null): T {
    if (this === document.body) segna(n);
    return ib.call(this, n, rif) as T;
  };
  for (const nome of ['append', 'prepend'] as const) {
    const orig = Element.prototype[nome];
    Element.prototype[nome] = function (this: Element, ...n: Array<Node | string>) {
      if (this === document.body) n.forEach((x) => { if (typeof x !== 'string') segna(x); });
      return orig.apply(this, n);
    };
  }
  const iae = Element.prototype.insertAdjacentElement;
  Element.prototype.insertAdjacentElement = function (this: Element, dove: InsertPosition, el: Element) {
    if (this === document.body && /^(afterbegin|beforeend)$/i.test(dove)) segna(el);
    return iae.call(this, dove, el);
  };
  const iah = Element.prototype.insertAdjacentHTML;
  Element.prototype.insertAdjacentHTML = function (this: Element, dove: InsertPosition, html: string) {
    if (this !== document.body || !/^(afterbegin|beforeend)$/i.test(dove) || !daScriptPhp()) return iah.call(this, dove, html);
    const prima = new Set(Array.from(document.body.childNodes));
    iah.call(this, dove, html);
    document.body.childNodes.forEach((x) => { if (!prima.has(x)) nodi.add(x); });
  };
}

/** Da chiamare prima di eseguire gli script di una pagina del vecchio PHP. */
export function tracciaScriptPhp(): void {
  installa();
}

/** Toglie listener, timer e nodi lasciati dagli script del vecchio PHP. */
export function pulisciScriptPhp(): void {
  for (const a of ascolti.splice(0)) a.dove.removeEventListener(a.tipo, a.fn, a.opzioni);
  timer.forEach((id) => clearTimeout(id));
  intervalli.forEach((id) => clearInterval(id));
  timer.clear();
  intervalli.clear();
  nodi.forEach((n) => n.parentNode?.removeChild(n));
  nodi.clear();
}

/**
 * Testo di uno script inline da rieseguire: dentro un blocco, cosi' const/let
 * globali non danno "already declared" quando la pagina si riapre (le
 * function restano globali, come servono agli attributi onclick).
 */
export function scriptInBlocco(testo: string): string {
  return '{\n' + testo + '\n}';
}
