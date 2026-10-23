import { NODE_URL, PHP_URL } from './ambiente';

/** Browser minimo: tiene i cookie e non segue i redirect (si confrontano). */
class Browser {
  private cookie = new Map<string, string>();
  constructor(private readonly base: string, private readonly ip: string, readonly userAgent = 'Equivalenza/1.0') {}

  async chiama(percorso: string, opz: { metodo?: string; corpo?: string; tipo?: string; header?: Record<string, string> } = {}) {
    // Una connessione tenuta aperta dal client puo' essere gia' chiusa dal server
    // (dopo un ripristino lungo): un secondo tentativo ne apre una nuova.
    const prova = (i: number): Promise<Response> => fetch(this.base + percorso, {
      method: opz.metodo || 'GET',
      redirect: 'manual',
      headers: {
        'User-Agent': this.userAgent,
        // Indirizzo del client come lo metterebbe il proxy: ogni test ha il suo.
        'X-Forwarded-For': this.ip,
        Cookie: [...this.cookie].map(([k, v]) => `${k}=${v}`).join('; '),
        ...(opz.tipo ? { 'Content-Type': opz.tipo } : {}),
        ...(opz.header || {}),
      },
      body: opz.corpo,
    }).catch((e) => { if (i < 2) return prova(i + 1); throw e; });
    const res = await prova(0);
    for (const c of res.headers.getSetCookie()) {
      const [kv, ...attr] = c.split(';');
      const [k, ...v] = kv.split('=');
      const scaduto = attr.some((a) => /max-age=0|expires=thu, 01 jan 1970/i.test(a.trim())) || v.join('=') === '' || v.join('=') === 'deleted';
      if (scaduto) this.cookie.delete(k.trim());
      else this.cookie.set(k.trim(), v.join('='));
    }
    return { status: res.status, location: res.headers.get('location'), testo: await res.text() };
  }

  valoreCookie(nome: string): string | undefined { return this.cookie.get(nome); }
}

let contatoreIp = 10;
const nuovoIp = () => `203.0.113.${contatoreIp++ % 250}`;

export interface EsitoAccesso { esito: 'ok' | 'errore'; destinazione?: string; messaggio?: string }

/** Login sul vecchio PHP: pagina con token CSRF, POST del modulo, lettura del redirect o dell'errore. */
export class Php {
  readonly b: Browser;
  constructor(ip = nuovoIp(), userAgent?: string) { this.b = new Browser(PHP_URL, ip, userAgent); }

  async login(username: string, password: string, ritorno?: string): Promise<EsitoAccesso> {
    const pagina = await this.b.chiama('/auth/login.php' + (ritorno ? `?return=${encodeURIComponent(ritorno)}` : ''));
    const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
    const corpo = new URLSearchParams({ csrf_token: csrf, username, password }).toString();
    const r = await this.b.chiama('/auth/login.php' + (ritorno ? `?return=${encodeURIComponent(ritorno)}` : ''),
      { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
    if (r.status === 302 && r.location) {
      return { esito: 'ok', destinazione: r.location.replace(PHP_URL, '') };
    }
    const errore = /class="login-err"[\s\S]*?<span>([^<]+)<\/span>/.exec(r.testo)?.[1];
    return { esito: 'errore', messaggio: errore ? decodeHtml(errore) : `HTTP ${r.status}` };
  }

  /** Stato di una pagina protetta: 200, redirect al login o 403. */
  async pagina(percorso: string) {
    const r = await this.b.chiama(percorso);
    return { status: r.status, versoLogin: !!r.location?.includes('/auth/login.php') };
  }
}

/** Lo stesso sul nuovo backend: API JSON con cookie di sessione e token CSRF. */
export class Node {
  readonly b: Browser;
  constructor(ip = nuovoIp(), userAgent?: string) { this.b = new Browser(NODE_URL, ip, userAgent); }

  private async csrf(): Promise<string> {
    if (!this.b.valoreCookie('cora_csrf')) await this.b.chiama('/api/v1/auth/sessione');
    return this.b.valoreCookie('cora_csrf') ?? '';
  }

  async post(percorso: string, dati: unknown) {
    const token = await this.csrf();
    const r = await this.b.chiama('/api/v1' + percorso, { metodo: 'POST', corpo: JSON.stringify(dati), tipo: 'application/json', header: { 'X-CSRF-Token': token } });
    return { status: r.status, json: r.testo ? JSON.parse(r.testo) : null };
  }

  async get(percorso: string) {
    const r = await this.b.chiama('/api/v1' + percorso);
    let json: any = null;
    try { json = r.testo ? JSON.parse(r.testo) : null; } catch { json = { testo: r.testo }; }
    return { status: r.status, json };
  }

  async login(username: string, password: string, ritorno?: string): Promise<EsitoAccesso> {
    const r = await this.post('/auth/login', { username, password, ...(ritorno ? { ritorno } : {}) });
    if (r.status === 200 && r.json?.destinazione) return { esito: 'ok', destinazione: r.json.destinazione };
    return { esito: 'errore', messaggio: r.json?.message };
  }
}

function decodeHtml(s: string): string {
  return s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
}
