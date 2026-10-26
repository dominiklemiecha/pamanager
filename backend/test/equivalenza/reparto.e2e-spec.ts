import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Area del responsabile di reparto (public/admin-reparto/). */
const PW = 'Prova!Cora2026';
const U = 'resp.commerciale';
async function phpEntra() { const p = new Php(); await p.login(U, PW); return p; }
async function nodeEntra() { const n = new Node(); await n.login(U, PW); return n; }
const testo = (s: string) => s.replace(/<[^>]+>/g, '').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();

async function phpPost(p: Php, pagina: string, campi: Array<[string, string]>) {
  const html = (await p.b.chiama(pagina)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: fd as any });
  return {
    redirect: r.location ? new URL(r.location, `http://x${pagina}`).pathname + new URL(r.location, `http://x${pagina}`).search : null,
    message: testo(/<div class="alert alert-success"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
    error: testo(/<div class="alert alert-(?:error|danger)"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
    status: r.status, testo: r.testo,
  };
}
async function nodePost(n: Node, pagina: string, campi: Array<[string, string]>) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  const r = await n.b.chiama(pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  let j: any = null;
  try { j = JSON.parse(r.testo); } catch { j = r.testo; }
  return {
    redirect: r.location ? new URL(r.location, 'http://x').pathname + new URL(r.location, 'http://x').search : null,
    message: testo(j?.V?.message ?? ''), error: testo(j?.V?.error ?? ''), status: r.status, json: j,
  };
}
const stato = (fn: typeof mariadb, id: number) => fn(`SELECT status FROM leave_requests WHERE id = ${id}`)[0].status;

beforeAll(() => ripristina());

describe('ferie del reparto', () => {
  it('stesse richieste in elenco', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    for (const q of ['', '?status=all', '?status=approved']) {
      const html = (await p.b.chiama(`/admin-reparto/leave-requests.php${q}`)).testo;
      const V = (await n.get(`/pagine/admin-reparto/leave-requests${q}`)).json.V;
      for (const r of V.requests) expect(html).toContain(r.last_name);
    }
  });

  it('approvazione di una richiesta del reparto', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    const c: Array<[string, string]> = [['action', 'approve'], ['request_id', '3']];
    expect((await nodePost(n, '/admin-reparto/leave-requests.php', c)).redirect).toBe((await phpPost(p, '/admin-reparto/leave-requests.php', c)).redirect);
    expect(stato(postgres, 3)).toBe(stato(mariadb, 3));
  });

  it('richiesta di un altro reparto: stesso errore, nessuna modifica', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    const c: Array<[string, string]> = [['action', 'approve'], ['request_id', '7']];
    const a = await phpPost(p, '/admin-reparto/leave-requests.php', c);
    const b = await nodePost(n, '/admin-reparto/leave-requests.php', c);
    expect(b.error).toBe(a.error);
    expect(stato(postgres, 7)).toBe('pending');
  });

  it('correzione: richiesta di un\'altra azienda (stesso id di reparto) respinta', async () => {
    const n = await nodeEntra();
    postgres(`UPDATE employees SET department_id = 11 WHERE id = 11`);
    const b = await nodePost(n, '/admin-reparto/leave-requests.php', [['action', 'approve'], ['request_id', '15']]);
    postgres(`UPDATE employees SET department_id = 13 WHERE id = 11`);
    expect(b.error).toBe('Richiesta non trovata o non appartenente al tuo reparto');
    expect(stato(postgres, 15)).toBe('pending');
  });
});

describe('comunicazioni del reparto', () => {
  it('creazione, pubblicazione, eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    const c: Array<[string, string]> = [['action', 'create'], ['title', 'Turni di dicembre'], ['content', 'Calendario dei turni'], ['priority', 'high'],
      ['publish_date', '2026-10-01'], ['expire_date', ''], ['is_published', '1']];
    expect((await nodePost(n, '/admin-reparto/communications.php', c)).redirect).toBe((await phpPost(p, '/admin-reparto/communications.php', c)).redirect);
    const riga = (fn: typeof mariadb) => fn(`SELECT title, priority, is_published, is_global, department_id FROM communications WHERE title = 'Turni di dicembre'`)
      .map((r) => ({ ...r, is_published: ['1', 't'].includes(r.is_published), is_global: ['1', 't'].includes(r.is_global) }))[0];
    expect(riga(postgres)).toEqual(riga(mariadb));
    const id = (fn: typeof mariadb) => fn(`SELECT id FROM communications WHERE title = 'Turni di dicembre'`)[0].id;
    // correzione: nel PHP togliere la pubblicazione falliva (booleano false scritto come stringa vuota)
    const at = await phpPost(p, '/admin-reparto/communications.php', [['action', 'toggle'], ['communication_id', id(mariadb)]]);
    expect(at.error).toBe("Errore durante l'aggiornamento");
    expect((await nodePost(n, '/admin-reparto/communications.php', [['action', 'toggle'], ['communication_id', id(postgres)]])).redirect).toBe('/admin-reparto/communications.php?message=toggled');
    expect(riga(postgres).is_published).toBe(false);
    // Comunicazione globale dell'HR: non modificabile dal reparto
    const globale = (fn: typeof mariadb) => fn(`SELECT id FROM communications WHERE is_global = ${fn === mariadb ? 1 : 'true'} ORDER BY id LIMIT 1`)[0].id;
    const a = await phpPost(p, '/admin-reparto/communications.php', [['action', 'delete'], ['communication_id', globale(mariadb)]]);
    const b = await nodePost(n, '/admin-reparto/communications.php', [['action', 'delete'], ['communication_id', globale(postgres)]]);
    expect(b.error).toBe(a.error);
    expect((await nodePost(n, '/admin-reparto/communications.php', [['action', 'delete'], ['communication_id', id(postgres)]])).redirect)
      .toBe((await phpPost(p, '/admin-reparto/communications.php', [['action', 'delete'], ['communication_id', id(mariadb)]])).redirect);
    expect(postgres(`SELECT id FROM communications WHERE title = 'Turni di dicembre'`)).toEqual([]);
  });
});

describe('reset password del reparto', () => {
  it('reset manuale di un dipendente del reparto', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    const a = await phpPost(p, '/admin-reparto/password-resets.php', [['action', 'manual_reset'], ['employee_id', '1']]);
    const b = await nodePost(n, '/admin-reparto/password-resets.php', [['action', 'manual_reset'], ['employee_id', '1']]);
    expect(a.message).toMatch(/^Password resettata\. Nuova password temporanea: /);
    expect(b.message).toMatch(/^Password resettata\. Nuova password temporanea: /);
    expect(postgres('SELECT must_change_password FROM employees WHERE id = 1')[0].must_change_password).toBe('t');
  });

  it('dipendente di un altro reparto: stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    const a = await phpPost(p, '/admin-reparto/password-resets.php', [['action', 'manual_reset'], ['employee_id', '3']]);
    const b = await nodePost(n, '/admin-reparto/password-resets.php', [['action', 'manual_reset'], ['employee_id', '3']]);
    expect(b.error).toBe(a.error);
  });

  it('richiesta di reset: approvazione e rifiuto solo nel reparto', async () => {
    const [p, n] = await Promise.all([phpEntra(), nodeEntra()]);
    for (const fn of [mariadb, postgres]) {
      fn(`INSERT INTO password_reset_requests (user_type, user_id, status, requested_ip, created_at) VALUES ('employee', 2, 'pending', '203.0.113.5', CURRENT_TIMESTAMP), ('employee', 3, 'pending', '203.0.113.5', CURRENT_TIMESTAMP)`);
    }
    const id = (fn: typeof mariadb, uid: number) => fn(`SELECT id FROM password_reset_requests WHERE user_id = ${uid}`)[0].id;
    const a = await phpPost(p, '/admin-reparto/password-resets.php', [['action', 'approve'], ['request_id', id(mariadb, 2)]]);
    const b = await nodePost(n, '/admin-reparto/password-resets.php', [['action', 'approve'], ['request_id', id(postgres, 2)]]);
    // L'alert del PHP contiene un <div>: dall'HTML se ne legge solo l'inizio
    expect(b.message.startsWith(a.message)).toBe(true);
    expect(b.message).toContain('Il link scade tra 24 ore.');
    const a2 = await phpPost(p, '/admin-reparto/password-resets.php', [['action', 'reject'], ['request_id', id(mariadb, 3)], ['notes', 'x']]);
    const b2 = await nodePost(n, '/admin-reparto/password-resets.php', [['action', 'reject'], ['request_id', id(postgres, 3)], ['notes', 'x']]);
    expect(b2.error).toBe(a2.error);
    expect(postgres(`SELECT status FROM password_reset_requests WHERE user_id = 3`)[0].status).toBe('pending');
  });
});

describe('documenti dei dipendenti del reparto', () => {
  it('fuori reparto: accesso negato', async () => {
    const n = await nodeEntra();
    expect((await n.b.chiama('/admin-reparto/employee-documents.php?employee_id=3', { header: { Accept: 'application/json' } })).status).toBe(403);
    const b = await nodePost(n, '/admin-reparto/employee-documents.php', [['action', 'delete'], ['employee_id', '3'], ['document_id', '1']]);
    expect(b.status).toBe(403);
  });
});

describe('academy in sola lettura', () => {
  it('le azioni sono rifiutate', async () => {
    const n = await nodeEntra();
    const b = await nodePost(n, '/admin-reparto/academy.php', [['action', 'save_course'], ['title', 'Abusivo']]);
    expect(b.status).toBe(403);
    expect(postgres(`SELECT id FROM academy_courses WHERE title = 'Abusivo'`)).toEqual([]);
  });
});
