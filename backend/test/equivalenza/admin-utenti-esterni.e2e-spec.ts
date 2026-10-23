import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Utenti esterni dell'HR: commercialisti, consulenti del lavoro, responsabili formazione. */
const PW = 'Prova!Cora2026';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const testo = (s: string) => s.replace(/<[^>]+>/g, '').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&mdash;/g, '—').replace(/\s+/g, ' ').trim();

async function phpPost(p: Php, pagina: string, campi: Array<[string, string]>) {
  const html = (await p.b.chiama(pagina)).testo;
  const corpo = new URLSearchParams([['csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? ''], ...campi]).toString();
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
  if (r.location) { const u = new URL(r.location, `http://x${pagina}`); return { redirect: u.pathname + u.search }; }
  return {
    message: testo(/<div class="(?:alert alert-success"[^>]*|body)">([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
    error: testo(/alert alert-error"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
  };
}
async function nodePost(n: Node, api: string, campi: Array<[string, string]>) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + api, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { message: testo(j.V.message ?? ''), error: testo(j.V.error ?? '') };
}
const utente = (fn: typeof mariadb, u: string) => fn(
  `SELECT u.username, u.name, u.email, u.role, u.is_active, u.company_id, (SELECT GROUP_CONCAT_ FROM (SELECT 1) x) AS z FROM users u WHERE u.username = '${u}'`.replace(', (SELECT GROUP_CONCAT_ FROM (SELECT 1) x) AS z', ''))
  .map((r) => ({ ...r, is_active: ['1', 't'].includes(r.is_active), email: r.email === 'NULL' ? null : r.email }))[0];
const collegamenti = (fn: typeof mariadb, u: string) => fn(
  `SELECT uc.company_id FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE u.username = '${u}' ORDER BY uc.company_id`).map((r) => r.company_id);
const id = (fn: typeof mariadb, u: string) => fn(`SELECT id FROM users WHERE username = '${u}'`)[0]?.id;

beforeAll(() => ripristina());

describe('elenchi', () => {
  it.each([['accountant'], ['consulente-lavoro'], ['formatori']])('stessi utenti (%s)', async (pag) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(`/admin/${pag}.php`)).testo;
    const nomi = [...html.matchAll(/<div class="username">@([^<]*)</g)].map((m) => m[1]);
    const V = (await n.get(`/pagine/admin/${pag}`)).json.V;
    expect(V.users.map((u: any) => u.username)).toEqual(nomi);
  });
});

describe('ciclo di vita di un commercialista', () => {
  it('creazione con password scelta, modifica, disattivazione, eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const crea: Array<[string, string]> = [['action', 'create'], ['username', 'studio.verdi'], ['name', 'Studio Verdi'], ['email', 'verdi@studio.test'],
      ['password', 'Commercialista!2026'], ['company_ids[]', '1']];
    const a = await phpPost(p, '/admin/accountant.php', crea);
    expect(a.redirect).toMatch(/^\/admin\/accountant\.php\?message=created&email=(ok|fail)$/);
    expect((await nodePost(n, '/pagine/admin/accountant', crea)).redirect).toMatch(/^\/admin\/accountant\.php\?message=created&email=(ok|fail)$/);
    expect(utente(postgres, 'studio.verdi')).toEqual(utente(mariadb, 'studio.verdi'));
    expect(collegamenti(postgres, 'studio.verdi')).toEqual(collegamenti(mariadb, 'studio.verdi'));
    expect((await new Node().login('studio.verdi', 'Commercialista!2026')).esito).toBe('ok');

    const mod = (fn: typeof mariadb): Array<[string, string]> => [['action', 'update'], ['id', id(fn, 'studio.verdi')], ['username', 'studio.verdi'],
      ['name', 'Studio Verdi & Associati'], ['email', 'info@verdi.test'], ['company_ids[]', '1']];
    expect(await nodePost(n, '/pagine/admin/accountant', mod(postgres))).toEqual(await phpPost(p, '/admin/accountant.php', mod(mariadb)));
    expect(utente(postgres, 'studio.verdi')).toEqual(utente(mariadb, 'studio.verdi'));

    const del = (fn: typeof mariadb): Array<[string, string]> => [['action', 'delete'], ['id', id(fn, 'studio.verdi')]];
    expect(await nodePost(n, '/pagine/admin/accountant', del(postgres))).toEqual(await phpPost(p, '/admin/accountant.php', del(mariadb)));
    expect(id(postgres, 'studio.verdi')).toBe(id(mariadb, 'studio.verdi'));
  });

  it('errori di creazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const c of [
      [['action', 'create'], ['username', 'ab'], ['name', 'X'], ['email', 'x@x.test'], ['password', 'Valida!Password2026']],
      [['action', 'create'], ['username', 'studio.nuovo'], ['name', 'X'], ['email', 'studio.commercialista@cora.test'], ['password', 'Valida!Password2026']],
      [['action', 'create'], ['username', 'studio.nuovo'], ['name', 'X'], ['email', 'hr.alfa@cora.test'], ['password', 'Valida!Password2026']],
      [['action', 'create'], ['username', 'hr.alfa'], ['name', 'X'], ['email', 'nuovo@x.test'], ['password', 'Valida!Password2026']],
    ] as Array<Array<[string, string]>>) {
      const a = await phpPost(p, '/admin/accountant.php', c);
      expect(a.error).not.toBe('');
      expect(await nodePost(n, '/pagine/admin/accountant', c)).toEqual(a);
    }
  });

  it('collega un consulente esistente e rimuovilo dalle mie aziende', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.beta'), nodeEntra('hr.beta')]);
    // nel seed il consulente e' gia' collegato a entrambe: lo si toglie e lo si ricollega
    const unl = (fn: typeof mariadb): Array<[string, string]> => [['action', 'unlink_from_my'], ['id', id(fn, 'studio.consulente')]];
    expect(await nodePost(n, '/pagine/admin/consulente-lavoro', unl(postgres))).toEqual(await phpPost(p, '/admin/consulente-lavoro.php', unl(mariadb)));
    expect(collegamenti(postgres, 'studio.consulente')).toEqual(collegamenti(mariadb, 'studio.consulente'));
    const link: Array<[string, string]> = [['action', 'link_existing'], ['link_email', 'studio.consulente@cora.test'], ['link_company_ids[]', '2'], ['link_company_ids[]', '1']];
    expect(await nodePost(n, '/pagine/admin/consulente-lavoro', link)).toEqual(await phpPost(p, '/admin/consulente-lavoro.php', link));
    expect(collegamenti(postgres, 'studio.consulente')).toEqual(collegamenti(mariadb, 'studio.consulente'));
  });

  it('formatore: legato solo all\'azienda, password generata', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const crea: Array<[string, string]> = [['action', 'create'], ['username', 'formatore.due'], ['name', 'Secondo Formatore'], ['email', 'f2@cora.test'], ['autogen_password', '1']];
    const a = await phpPost(p, '/admin/formatori.php', crea);
    const b = await nodePost(n, '/pagine/admin/formatori', crea);
    const pulisci = (m?: string) => (m ?? '').replace(/Password generata: \S+/, 'Password generata: X').replace(/visualizzata\..*$/, 'visualizzata.');
    expect(pulisci(b.message)).toBe(pulisci(a.message));
    expect(utente(postgres, 'formatore.due')).toEqual(utente(mariadb, 'formatore.due'));
  });
});

describe('correzioni di sicurezza', () => {
  it('utenti di altri ruoli o aziende non sono toccabili', async () => {
    const n = await nodeEntra('hr.alfa');
    for (const u of ['connecteed', 'hr.beta', 'hr.alfa', 'giulia.ferraresi']) {
      for (const action of ['update', 'delete', 'reset_password', 'toggle_active']) {
        const r = await nodePost(n, '/pagine/admin/accountant', [['action', action], ['id', String(id(postgres, u) ?? 999999)], ['username', 'preso'], ['name', 'Preso'], ['email', 'preso@x.test']]);
        expect(r).toEqual({ message: '', error: 'Utente non trovato' });
      }
    }
    expect(utente(postgres, 'connecteed').is_active).toBe(true);
    expect((await new Node().login('hr.beta', PW)).esito).toBe('ok');
  });

  it('collegamenti solo alle aziende gestite', async () => {
    const n = await nodeEntra('hr.alfa');
    await nodePost(n, '/pagine/admin/accountant', [['action', 'create'], ['username', 'studio.furbo'], ['name', 'Furbo'], ['email', 'furbo@x.test'],
      ['password', 'Commercialista!2026'], ['company_ids[]', '1'], ['company_ids[]', '2']]);
    expect(collegamenti(postgres, 'studio.furbo')).toEqual(['1']);
  });

  it('reset password: cambio obbligatorio al primo accesso', async () => {
    const n = await nodeEntra('hr.alfa');
    const r = await nodePost(n, '/pagine/admin/formatori', [['action', 'reset_password'], ['id', String(id(postgres, 'formatore.alfa'))]]);
    expect(r.message).toMatch(/^Nuova password generata: \S{12} — copiala adesso\./);
    expect(postgres(`SELECT must_change_password FROM users WHERE username = 'formatore.alfa'`)[0].must_change_password).toBe('t');
  });
});
