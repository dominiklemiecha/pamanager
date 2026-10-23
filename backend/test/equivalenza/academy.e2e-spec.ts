import { AcademyService } from '../../src/modules/academy/academy.service';
import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';
import { php } from './php-cli';

/**
 * Academy del dipendente: elenco, avvio, apertura materiali (registrata),
 * completamento, attestato, promemoria di scadenza e registro a catena di
 * hash, sul PHP e sul nuovo backend.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

async function phpPost(p: Php, campi: Record<string, string>) {
  const pagina = await p.b.chiama(`/employee/academy.php?course=${campi.course_id}`);
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
  const r = await p.b.chiama('/employee/academy.php', { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, ...campi }).toString(), tipo: 'application/x-www-form-urlencoded' });
  return r.location ? new URL(r.location, 'http://x').search : '';
}

async function prendi(base: string, b: Php['b'] | Node['b'], percorso: string, header: Record<string, string> = {}) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, { redirect: 'manual', headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; '), ...header } });
  return { status: r.status, location: r.headers.get('location'), referrer: r.headers.get('referrer-policy'), tipo: r.headers.get('content-type'), byte: Buffer.from(await r.arrayBuffer()) };
}

const corso = (fn: typeof mariadb) => Number(fn(`SELECT id FROM academy_courses WHERE title = 'Sicurezza sul lavoro'`)[0].id);
const mat = (fn: typeof mariadb, kind: string) => Number(fn(`SELECT id FROM academy_materials WHERE kind = '${kind}'`)[0].id);
const eventi = (fn: typeof mariadb) => fn(`SELECT event, actor_type, employee_id IS NOT NULL AS dip, material_id IS NOT NULL AS mat, details FROM academy_events ORDER BY id`)
  .map((r) => ({ ...r, dip: ['1', 't'].includes(r.dip), mat: ['1', 't'].includes(r.mat) }));
const stato = (fn: typeof mariadb, cognome: string) => {
  const [r] = fn(`SELECT a.started_at IS NOT NULL AS iniziato, a.completed_at IS NOT NULL AS completato, a.declaration_text,
      (SELECT COUNT(*) FROM academy_material_views v WHERE v.assignment_id = a.id) AS aperti
    FROM academy_assignments a JOIN employees e ON e.id = a.employee_id WHERE e.last_name = '${cognome}'`);
  return { ...r, iniziato: ['1', 't'].includes(r.iniziato), completato: ['1', 't'].includes(r.completato) };
};

beforeAll(() => ripristina());

describe('elenco', () => {
  it.each(['giulia.ferraresi', 'marco.bellandi', 'elena.gualtieri'])('%s: stesso stato e avanzamento', async (u) => {
    const [p, n] = await Promise.all([phpEntra(u), nodeEntra(u)]);
    const html = (await p.b.chiama('/employee/academy.php')).testo;
    const a = [...html.matchAll(/<h4>([^<]+)<\/h4>[\s\S]*?badge-dot [a-z-]+">([^<]+)<[\s\S]*?width: (\d+)%/g)].map((m) => [m[1], m[2], Number(m[3])]);
    const d = (await n.get('/dipendente/academy')).json;
    const b = d.corsi.map((c: any) => {
      const tot = Number(c.material_count);
      const pct = c.stato === 'completed' ? 100 : tot > 0 ? Math.round((Math.min(Number(c.opened_count), tot) / tot) * 100) : 0;
      return [c.title, d.etichette[c.stato], pct];
    });
    expect(b).toEqual(a);
  });
});

describe('percorso del corso', () => {
  it('avvio', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    await phpPost(p, { action: 'start', course_id: String(corso(mariadb)) });
    expect((await n.post(`/dipendente/academy/${corso(postgres)}/avvia`, {})).status).toBe(200);
    expect(stato(postgres, 'Ferraresi')).toEqual(stato(mariadb, 'Ferraresi'));
    expect(eventi(postgres)).toEqual(eventi(mariadb));
  });

  it('completamento senza aver aperto tutto: rifiutato con lo stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const a = await phpPost(p, { action: 'complete', course_id: String(corso(mariadb)), declaration: '1' });
    const b = (await n.post(`/dipendente/academy/${corso(postgres)}/completa`, { declaration: true })).json;
    expect(decodeURIComponent(new URLSearchParams(a).get('err') ?? '')).toBe('Apri tutti i materiali del corso prima di completarlo');
    expect(b).toEqual({ ok: false, errore: 'materiali' });
  });

  it('apertura dei materiali: file, link e registro', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const [fa, fb] = [await prendi(PHP_URL, p.b, `/academy-file.php?m=${mat(mariadb, 'file')}`), await prendi(NODE_URL, n.b, `/academy-file.php?m=${mat(postgres, 'file')}`)];
    expect(fb.status).toBe(200);
    expect(fb.byte.equals(fa.byte)).toBe(true);
    expect(fb.tipo).toContain('application/pdf');
    // Richiesta successiva di un video: non conta come nuova apertura
    await prendi(PHP_URL, p.b, `/academy-file.php?m=${mat(mariadb, 'file')}`, { Range: 'bytes=10-' });
    await prendi(NODE_URL, n.b, `/academy-file.php?m=${mat(postgres, 'file')}`, { Range: 'bytes=10-' });
    const [la, lb] = [await prendi(PHP_URL, p.b, `/academy-file.php?m=${mat(mariadb, 'link')}`), await prendi(NODE_URL, n.b, `/academy-file.php?m=${mat(postgres, 'link')}`)];
    expect(lb.status).toBe(302);
    expect(lb.location).toBe(la.location);
    expect(lb.referrer).toBe('no-referrer');
    expect(stato(postgres, 'Bellandi')).toEqual(stato(mariadb, 'Bellandi'));
    const visti = (fn: typeof mariadb) => fn(`SELECT material_id, open_count FROM academy_material_views ORDER BY assignment_id, material_id`);
    expect(visti(postgres)).toEqual(visti(mariadb));
    expect(eventi(postgres)).toEqual(eventi(mariadb));
  });

  it('completamento: dichiarazione obbligatoria, poi completato e staff avvisato', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const a = await phpPost(p, { action: 'complete', course_id: String(corso(mariadb)) });
    const b = (await n.post(`/dipendente/academy/${corso(postgres)}/completa`, {})).json;
    expect(new URLSearchParams(a).get('err')).toBe('Per completare devi spuntare la dichiarazione');
    expect(b).toEqual({ ok: false, errore: 'dichiarazione' });
    await phpPost(p, { action: 'complete', course_id: String(corso(mariadb)), declaration: '1' });
    expect((await n.post(`/dipendente/academy/${corso(postgres)}/completa`, { declaration: true })).json).toEqual({ ok: true });
    expect(stato(postgres, 'Bellandi')).toEqual(stato(mariadb, 'Bellandi'));
    expect(eventi(postgres)).toEqual(eventi(mariadb));
    const notifiche = (fn: typeof mariadb) => fn(`SELECT recipient_type, recipient_id, type, title, message, link, company_id FROM notifications WHERE type = 'academy_completed' ORDER BY recipient_type, recipient_id`);
    expect(notifiche(postgres)).toEqual(notifiche(mariadb));
  });

  it('materiale di un corso non assegnato: rifiutato', async () => {
    const [p, n] = await Promise.all([phpEntra('sara.montanari'), nodeEntra('sara.montanari')]);
    expect((await prendi(NODE_URL, n.b, `/academy-file.php?m=${mat(postgres, 'file')}`)).status).toBe((await prendi(PHP_URL, p.b, `/academy-file.php?m=${mat(mariadb, 'file')}`)).status);
    expect((await n.get(`/dipendente/academy?course=${corso(postgres)}`)).status).toBe(404);
  });
});

describe('attestato', () => {
  it('scaricato dal titolare, segnato e registrato', async () => {
    const [p, n] = await Promise.all([phpEntra('elena.gualtieri'), nodeEntra('elena.gualtieri')]);
    const id = (fn: typeof mariadb) => fn(`SELECT id FROM academy_certificates`)[0].id;
    const [a, b] = [await prendi(PHP_URL, p.b, `/academy-file.php?cert=${id(mariadb)}`), await prendi(NODE_URL, n.b, `/academy-file.php?cert=${id(postgres)}`)];
    expect(b.status).toBe(200);
    expect(b.byte.equals(a.byte)).toBe(true);
    const scaricato = (fn: typeof mariadb) => fn(`SELECT employee_downloaded_at IS NOT NULL AS s FROM academy_certificates`)[0].s;
    expect(['1', 't']).toContain(scaricato(postgres));
    expect(['1', 't']).toContain(scaricato(mariadb));
    expect(eventi(postgres)).toEqual(eventi(mariadb));
  });

  it('di un altro dipendente: rifiutato', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const id = (fn: typeof mariadb) => fn(`SELECT id FROM academy_certificates`)[0].id;
    expect((await prendi(NODE_URL, n.b, `/academy-file.php?cert=${id(postgres)}`)).status).toBe((await prendi(PHP_URL, p.b, `/academy-file.php?cert=${id(mariadb)}`)).status);
  });
});

describe('registro e promemoria', () => {
  it('promemoria di scadenza all\'apertura della pagina', async () => {
    mariadb(`UPDATE academy_courses SET due_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)`);
    postgres(`UPDATE academy_courses SET due_date = CURRENT_DATE + 2`);
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    await p.b.chiama('/employee/academy.php');
    await n.get('/dipendente/academy');
    const promemoria = (fn: typeof mariadb) => fn(`SELECT recipient_id, type, title, message, link FROM notifications WHERE type = 'academy' ORDER BY recipient_id`);
    expect(promemoria(postgres).length).toBeGreaterThan(0);
    expect(promemoria(postgres)).toEqual(promemoria(mariadb));
    expect(eventi(postgres)).toEqual(eventi(mariadb));
  });

  it('catena di hash valida da tutte e due le parti, stesso calcolo', () => {
    expect(php<any>(`echo json_encode(Academy::verifyChain(1));`).ok).toBe(true);
    // Le righe scritte dal PHP si verificano con il calcolo del nuovo backend.
    const righe = mariadb(`SELECT prev_hash, company_id, course_id, assignment_id, employee_id, material_id, event, actor_type, actor_id, details, ip_address, user_agent,
      DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, hash FROM academy_events WHERE company_id = 1 ORDER BY id`);
    expect(righe.length).toBeGreaterThan(3);
    for (const r of righe) expect(AcademyService.hash(r)).toBe(r.hash);
    const nodo = postgres(`SELECT prev_hash, company_id, course_id, assignment_id, employee_id, material_id, event, actor_type, actor_id, details, ip_address, user_agent,
      to_char(created_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS') AS created_at, hash FROM academy_events WHERE company_id = 1 ORDER BY id`);
    let prev: string | null = null;
    for (const r of nodo) {
      expect(r.prev_hash).toBe(prev);
      expect(AcademyService.hash(r)).toBe(r.hash);
      prev = r.hash;
    }
  });
});
