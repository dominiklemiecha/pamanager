import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Calendario condiviso dal lato del dipendente: vista settimana e azioni dello
 * script (crea, modifica, sposta, dettaglio, risposta, elimina, conflitti),
 * sul PHP (POST alla pagina) e sul nuovo backend. Le correzioni sono scritte nei test.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

async function phpAzione(p: Php, campi: Record<string, string>) {
  const pagina = await p.b.chiama('/employee/calendar.php');
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? /name="csrf-token" content="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
  const fd = new FormData();
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await p.b.chiama('/employee/calendar.php', { metodo: 'POST', corpo: fd as any });
  return JSON.parse(r.testo);
}
async function nodeAzione(n: Node, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1/calendario/azione', { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  return JSON.parse(r.testo);
}

const idDip = (fn: typeof mariadb, cognome: string) => Number(fn(`SELECT id FROM employees WHERE last_name = '${cognome}'`)[0].id);
const idUtente = (fn: typeof mariadb, username: string) => Number(fn(`SELECT id FROM users WHERE username = '${username}'`)[0].id);
const idEvento = (fn: typeof mariadb, titolo: string) => Number(fn(`SELECT id FROM calendar_events WHERE title = '${titolo}' ORDER BY id DESC LIMIT 1`)[0].id);
const invitati = (fn: typeof mariadb, titolo: string) => fn(
  `SELECT p.user_type, COALESCE(e.last_name, u.username) AS chi, p.status FROM calendar_event_participants p
     JOIN calendar_events ev ON ev.id = p.event_id
     LEFT JOIN employees e ON p.user_type = 'employee' AND e.id = p.user_id
     LEFT JOIN users u ON p.user_type <> 'employee' AND u.id = p.user_id
    WHERE ev.title = '${titolo}' ORDER BY p.user_type, chi`);
const evento = (fn: typeof mariadb, titolo: string) => fn(
  `SELECT title, location, owner_type, ${fn === mariadb ? "DATE_FORMAT(start_at, '%Y-%m-%d %H:%i:%s')" : "to_char(start_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')"} AS inizio,
          ${fn === mariadb ? "DATE_FORMAT(end_at, '%Y-%m-%d %H:%i:%s')" : "to_char(end_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')"} AS fine
     FROM calendar_events WHERE title = '${titolo}'`);

beforeAll(() => ripristina());

describe('vista', () => {
  it('stessi eventi nella settimana, stessa posizione e stesso stato dell\'invito', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const html = (await p.b.chiama('/employee/calendar.php?view=week&d=2026-10-28')).testo;
    const php = [...html.matchAll(/<a class="cal-evt ([^"]*)"\s+style="top: (\d+)px; height: (\d+)px;[\s\S]*?data-event-id="(\d+)"[\s\S]*?<div class="evt-title">([^<]+)</g)]
      .map((m) => ({ classi: m[1].replace(/\s+/g, ' ').trim(), top: +m[2], height: +m[3], id: +m[4], titolo: m[5] }));
    const d = (await n.get('/calendario?view=week&d=2026-10-28')).json;
    expect(d.giorni[0]).toBe('2026-10-26');
    expect(d.etichetta).toBe(/class="cal-nav-label">([^<]+)</.exec(html)?.[1]);
    expect(php.length).toBe(1);
    const ev = d.eventi[0];
    expect(ev.title).toBe(php[0].titolo);
    expect(ev.my_status === 'pending' ? 'is-pending' : '').toBe(php[0].classi);
    expect([ev.start_at, ev.end_at]).toEqual(['2026-10-28 10:00:00', '2026-10-28 12:00:00']);
  });
});

describe('azioni', () => {
  it('crea con invitati: correzione, niente persone di altre aziende', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const parti = (fn: typeof mariadb) => JSON.stringify([
      { user_type: 'employee', user_id: idDip(fn, 'Bellandi') },
      { user_type: 'employee', user_id: idDip(fn, 'Castellari') }, // altra azienda
      { user_type: 'admin', user_id: idUtente(fn, 'hr.alfa') },
      { user_type: 'employee', user_id: idDip(fn, 'Ferraresi') }, // se stessa
    ]);
    const campi = { action: 'create_event', title: 'Riunione di prova', location: 'Sala A', start_at: '2026-10-29 09:00:00', end_at: '2026-10-29 10:30:00' };
    const [a, b] = [await phpAzione(p, { ...campi, participants: parti(mariadb) }), await nodeAzione(n, { ...campi, participants: parti(postgres) })];
    expect(a.success).toBe(true);
    expect(b.success).toBe(true);
    expect(evento(postgres, 'Riunione di prova')).toEqual(evento(mariadb, 'Riunione di prova'));
    const [ip, inn] = [invitati(mariadb, 'Riunione di prova'), invitati(postgres, 'Riunione di prova')];
    expect(ip.map((x) => x.chi)).toContain('Castellari'); // falla del PHP
    expect(inn).toEqual(ip.filter((x) => x.chi !== 'Castellari'));
  });

  it('dati non validi: stessi errori', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    for (const campi of <Array<Record<string, string>>>[
      { action: 'create_event', title: '', start_at: '2026-10-29 09:00:00', end_at: '2026-10-29 10:00:00' },
      { action: 'create_event', title: 'X', start_at: '2026-10-29 11:00:00', end_at: '2026-10-29 10:00:00' },
      { action: 'nessuna' },
    ]) {
      expect(await nodeAzione(n, campi)).toEqual(await phpAzione(p, campi));
    }
  });

  it('dettaglio: invitato e proprietario lo vedono; correzione, un estraneo no', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const [a, b] = [await phpAzione(p, { action: 'get_event', event_id: String(idEvento(mariadb, 'Riunione di prova')) }),
      await nodeAzione(n, { action: 'get_event', event_id: String(idEvento(postgres, 'Riunione di prova')) })];
    expect(b.success).toBe(true);
    expect(b.can_edit).toBe(a.can_edit);
    expect(b.event.title).toBe(a.event.title);
    expect([b.event.start_at, b.event.end_at]).toEqual([a.event.start_at, a.event.end_at]);
    expect(b.owner.name).toBe(a.owner.name);
    expect(b.participants.map((x: any) => [x.user_type, x.name, x.status])).toEqual(
      a.participants.filter((x: any) => x.name !== 'Anna Castellari').map((x: any) => [x.user_type, x.name, x.status]));

    const [p2, n2] = await Promise.all([phpEntra('sara.montanari'), nodeEntra('sara.montanari')]);
    const a2 = await phpAzione(p2, { action: 'get_event', event_id: String(idEvento(mariadb, 'Riunione di prova')) });
    const b2 = await nodeAzione(n2, { action: 'get_event', event_id: String(idEvento(postgres, 'Riunione di prova')) });
    expect(a2.success).toBe(true); // falla del PHP
    expect(b2).toEqual({ success: false, error: 'Evento non trovato' });
  });

  it('risposta all\'invito', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    for (const status of ['accepted', 'boh']) {
      const [a, b] = [await phpAzione(p, { action: 'respond_event', event_id: String(idEvento(mariadb, 'Riunione di prova')), status }),
        await nodeAzione(n, { action: 'respond_event', event_id: String(idEvento(postgres, 'Riunione di prova')), status })];
      expect(b).toEqual(a);
    }
    expect(invitati(postgres, 'Riunione di prova')).toEqual(invitati(mariadb, 'Riunione di prova').filter((x) => x.chi !== 'Castellari'));
  });

  it('modifica e spostamento solo per chi lo gestisce', async () => {
    const [pb, nb] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const sposta = (fn: typeof mariadb) => ({ action: 'move_event', event_id: String(idEvento(fn, 'Riunione di prova')), start_at: '2026-10-29 14:00:00', end_at: '2026-10-29 15:30:00' });
    expect(await nodeAzione(nb, sposta(postgres))).toEqual(await phpAzione(pb, sposta(mariadb)));

    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    expect(await nodeAzione(n, sposta(postgres))).toEqual(await phpAzione(p, sposta(mariadb)));
    expect(evento(postgres, 'Riunione di prova')).toEqual(evento(mariadb, 'Riunione di prova'));

    const aggiorna = (fn: typeof mariadb) => ({
      action: 'update_event', event_id: String(idEvento(fn, 'Riunione di prova')), title: 'Riunione spostata', location: 'Online', description: '',
      start_at: '2026-10-30 09:00:00', end_at: '2026-10-30 10:00:00', participants: JSON.stringify([{ user_type: 'employee', user_id: idDip(fn, 'Gualtieri') }, { user_type: 'employee', user_id: idDip(fn, 'Bellandi') }]),
    });
    expect(await nodeAzione(n, aggiorna(postgres))).toEqual(await phpAzione(p, aggiorna(mariadb)));
    expect(evento(postgres, 'Riunione spostata')).toEqual(evento(mariadb, 'Riunione spostata'));
    expect(invitati(postgres, 'Riunione spostata')).toEqual(invitati(mariadb, 'Riunione spostata'));
  });

  it('correzione: spostamento oltre mezzanotte rifiutato senza errori del database', async () => {
    const n = await nodeEntra('giulia.ferraresi');
    const b = await nodeAzione(n, { action: 'move_event', event_id: String(idEvento(postgres, 'Riunione spostata')), start_at: '2026-10-30 23:00:00', end_at: '2026-10-30 25:00:00' });
    expect(b).toEqual({ success: false, error: 'Data o orario non validi' });
  });

  it('conflitti: correzione, niente dati di altre aziende', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const campi = (fn: typeof mariadb) => ({
      action: 'check_conflicts', start_at: '2026-08-05 09:00:00', end_at: '2026-08-05 10:00:00',
      participants: JSON.stringify([{ user_type: 'employee', user_id: idDip(fn, 'Castellari') }]),
    });
    const [a, b] = [await phpAzione(p, campi(mariadb)), await nodeAzione(n, campi(postgres))];
    expect(a.conflicts.length).toBe(1); // ferie di una dipendente di un'altra azienda
    expect(b).toEqual({ success: true, conflicts: [] });

    const stessi = (fn: typeof mariadb) => ({
      action: 'check_conflicts', start_at: '2026-10-30 09:30:00', end_at: '2026-10-30 11:00:00',
      participants: JSON.stringify([{ user_type: 'employee', user_id: idDip(fn, 'Bellandi') }, { user_type: 'employee', user_id: idDip(fn, 'Gualtieri') }]),
    });
    const [c, e] = [await phpAzione(p, stessi(mariadb)), await nodeAzione(n, stessi(postgres))];
    const norm = (x: any) => x.conflicts.map((k: any) => ({ name: k.name, eventi: k.calendar_events.map((v: any) => [v.title, v.start_at, v.end_at]), assenze: k.leaves.length }));
    expect(norm(e)).toEqual(norm(c));
  });

  it('eliminazione: solo il proprietario', async () => {
    const [pb, nb] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const elimina = (fn: typeof mariadb) => ({ action: 'delete_event', event_id: String(idEvento(fn, 'Riunione spostata')) });
    expect(await nodeAzione(nb, elimina(postgres))).toEqual(await phpAzione(pb, elimina(mariadb)));
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    expect(await nodeAzione(n, elimina(postgres))).toEqual(await phpAzione(p, elimina(mariadb)));
    expect(postgres(`SELECT COUNT(*) AS n FROM calendar_events WHERE title = 'Riunione spostata'`)[0].n).toBe('0');
    expect(mariadb(`SELECT COUNT(*) AS n FROM calendar_events WHERE title = 'Riunione spostata'`)[0].n).toBe('0');
  });
});
