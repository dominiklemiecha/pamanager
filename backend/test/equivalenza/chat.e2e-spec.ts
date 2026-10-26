import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Chat del dipendente (vista Cora): configurazione della pagina, elenco,
 * messaggi, invio, reazioni, modifica, eliminazione, gruppi, allegati e
 * presenza, sul PHP (azioni POST alla pagina) e sul nuovo backend.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

/** window.CORA_CHAT della pagina PHP. */
async function phpConfig(p: Php, query = '') {
  const html = (await p.b.chiama('/employee/chat.php' + query)).testo;
  const json = /window\.CORA_CHAT = (\{[\s\S]*?\});<\/script>/.exec(html)?.[1];
  return { cfg: json ? JSON.parse(json) : null, csrf: /name="csrf-token" content="([^"]+)"/.exec(html)?.[1] ?? '' };
}

async function phpAzione(p: Php, campi: Record<string, string>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const { csrf } = await phpConfig(p);
  const fd = new FormData();
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await p.b.chiama('/employee/chat.php', { metodo: 'POST', corpo: fd as any });
  return JSON.parse(r.testo);
}
async function nodeAzione(n: Node, campi: Record<string, string>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await n.b.chiama('/api/v1/chat/azione', { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  return JSON.parse(r.testo);
}

async function scarica(base: string, b: Php['b'] | Node['b'], percorso: string) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, { redirect: 'manual', headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; ') } });
  return { status: r.status, byte: Buffer.from(await r.arrayBuffer()) };
}

/** Toglie quello che cambia per forza tra le due parti (orari di invio, indirizzi dei file). */
function pulisci(v: any): any {
  if (Array.isArray(v)) return v.map(pulisci);
  if (v && typeof v === 'object') {
    const o: any = {};
    for (const [k, x] of Object.entries(v)) {
      if (['createdAt', 'editedAt', 'seenAt', 'lastMessageAt', 'ultimoAccesso'].includes(k)) o[k] = x ? 'ORA' : x;
      else if (k === 'url' && typeof x === 'string' && x.includes('download_attachment=')) o[k] = 'ALLEGATO';
      else o[k] = pulisci(x);
    }
    return o;
  }
  return v;
}

const idDip = (fn: typeof mariadb, cognome: string) => Number(fn(`SELECT id FROM employees WHERE last_name = '${cognome}'`)[0].id);
const idConv = (fn: typeof mariadb) => Number(fn(`SELECT id FROM chat_conversations ORDER BY id LIMIT 1`)[0].id);
const ultimoMsg = (fn: typeof mariadb) => Number(fn(`SELECT MAX(id) AS id FROM chat_messages`)[0].id);

beforeAll(() => ripristina());

describe('pagina', () => {
  it('stessa configurazione: me, chat, contatti (le date con il fuso di Roma)', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = (await phpConfig(p)).cfg;
    const b = (await n.get('/chat')).json;
    expect(a).toBeTruthy();
    for (const k of ['me', 'open', 'editMinutes']) expect(b[k]).toEqual(a[k]);
    // Correzione: il PHP imposta MySQL sull'offset di oggi (Database.php), quindi
    // dopo un cambio d'ora sbaglia di un'ora i messaggi scritti prima del cambio
    const senzaOra = (cs: any[]) => cs.map((c) => ({ ...c, lastMessageAt: undefined }));
    expect(senzaOra(b.conversations)).toEqual(senzaOra(a.conversations));
    b.conversations.forEach((c: any, i: number) => {
      const scarto = Math.abs(Date.parse(c.lastMessageAt) - Date.parse(a.conversations[i].lastMessageAt));
      expect([0, 3600000]).toContain(Number.isNaN(scarto) ? 0 : scarto);
    });
    expect(b.contacts).toEqual(a.contacts);
  });

  it('aprendo una chat si legge: stesso conteggio dopo', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = (await phpConfig(p, `?conv=${idConv(mariadb)}`)).cfg;
    const b = (await n.get(`/chat?conv=${idConv(postgres)}`)).json;
    expect(b.open).toBe(a.open);
    expect(pulisci(b.conversations)).toEqual(pulisci(a.conversations));
  });

  it('chat di altri: non si apre', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const a = (await phpConfig(p, `?conv=${idConv(mariadb)}`)).cfg;
    const b = (await n.get(`/chat?conv=${idConv(postgres)}`)).json;
    expect(a.open).toBe(0);
    expect(b.open).toBe(0);
    expect(await nodeAzione(n, { action: 'cora_messages', conversation_id: String(idConv(postgres)) })).toEqual({ success: false, error: 'Accesso non autorizzato' });
  });
});

describe('messaggi', () => {
  it('il dipendente non vede le note interne', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = await phpAzione(p, { action: 'cora_messages', conversation_id: String(idConv(mariadb)) });
    const b = await nodeAzione(n, { action: 'cora_messages', conversation_id: String(idConv(postgres)) });
    expect(a.length).toBe(2);
    expect(pulisci(b)).toEqual(pulisci(a));
  });

  it('invio, reazione, modifica', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const invio = (fn: typeof mariadb) => ({ action: 'send', cora: '1', conversation_id: String(idConv(fn)), message: '  Grazie mille!  ', client_id: 'prova-cliente-0001', is_internal: '1' });
    const [a, b] = [await phpAzione(p, invio(mariadb)), await nodeAzione(n, invio(postgres))];
    expect(pulisci(b)).toEqual(pulisci(a));
    // Stesso invio ripetuto: lo stesso messaggio, non un doppione.
    const [a2, b2] = [await phpAzione(p, invio(mariadb)), await nodeAzione(n, invio(postgres))];
    expect(pulisci(b2)).toEqual(pulisci(a2));

    const reaz = (fn: typeof mariadb) => ({ action: 'cora_react', message_id: String(ultimoMsg(fn)), emoji: '👍' });
    expect(pulisci(await nodeAzione(n, reaz(postgres)))).toEqual(pulisci(await phpAzione(p, reaz(mariadb))));
    const mod = (fn: typeof mariadb) => ({ action: 'cora_edit', message_id: String(ultimoMsg(fn)), message: 'Grazie davvero!' });
    expect(pulisci(await nodeAzione(n, mod(postgres)))).toEqual(pulisci(await phpAzione(p, mod(mariadb))));
    const vuoto = (fn: typeof mariadb) => ({ action: 'cora_edit', message_id: String(ultimoMsg(fn)), message: '   ' });
    expect(await nodeAzione(n, vuoto(postgres))).toEqual(await phpAzione(p, vuoto(mariadb)));
  });

  it('eliminazione: solo i propri, solo se non letti', async () => {
    const [ph, nh] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const el = (fn: typeof mariadb) => ({ action: 'cora_delete', message_id: String(ultimoMsg(fn)) });
    expect(await nodeAzione(nh, el(postgres))).toEqual(await phpAzione(ph, el(mariadb)));
    const altrui = (fn: typeof mariadb) => ({ action: 'cora_delete', message_id: String(fn(`SELECT MIN(id) AS id FROM chat_messages WHERE sender_type = 'admin'`)[0].id) });
    expect(await nodeAzione(nh, altrui(postgres))).toEqual(await phpAzione(ph, altrui(mariadb)));
    const q = (fn: typeof mariadb) => fn(`SELECT sender_type, message, is_internal FROM chat_messages ORDER BY id`).map((r) => ({ ...r, is_internal: ['1', 't'].includes(r.is_internal) }));
    expect(q(postgres)).toEqual(q(mariadb));
  });

  it('allegato: si scarica solo partecipando', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const pdf = Buffer.from('%PDF-1.4\n%%EOF\n');
    const campi = (fn: typeof mariadb) => ({ action: 'send', cora: '1', conversation_id: String(idConv(fn)), message: '' });
    const file = { campo: 'attachment', nome: 'cud.pdf', dati: pdf, tipo: 'application/pdf' };
    const [a, b] = [await phpAzione(p, campi(mariadb), file), await nodeAzione(n, campi(postgres), file)];
    expect(pulisci(b)).toEqual(pulisci(a));
    const [fa, fb] = [await scarica(PHP_URL, p.b, `/employee/chat.php?download_attachment=${a.id}`), await scarica(NODE_URL, n.b, b.message.attachment.url)];
    expect(fb.status).toBe(200);
    expect(fb.byte.equals(fa.byte)).toBe(true);
    const estraneo = await nodeEntra('marco.bellandi');
    expect((await scarica(NODE_URL, estraneo.b, b.message.attachment.url)).status).toBe(403);
    // Estensione vietata o contenuto falso
    const html = { campo: 'attachment', nome: 'pagina.html', dati: Buffer.from('<script>x</script>'), tipo: 'text/html' };
    expect(await nodeAzione(n, campi(postgres), html)).toEqual(await phpAzione(p, campi(mariadb), html));
    const finto = { campo: 'attachment', nome: 'finto.pdf', dati: Buffer.from('<script>x</script>'), tipo: 'application/pdf' };
    expect(await nodeAzione(n, campi(postgres), finto)).toEqual({ success: false, error: 'Il contenuto del file non corrisponde al tipo' });
  });
});

describe('nuove chat e gruppi', () => {
  it('chat diretta con un collega', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = await phpAzione(p, { action: 'cora_direct', user: `employee:${idDip(mariadb, 'Bellandi')}` });
    const b = await nodeAzione(n, { action: 'cora_direct', user: `employee:${idDip(postgres, 'Bellandi')}` });
    expect(b).toEqual(a);
  });

  it('correzione: niente chat con dipendenti di altre aziende', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = await phpAzione(p, { action: 'cora_direct', user: `employee:${idDip(mariadb, 'Castellari')}` });
    const b = await nodeAzione(n, { action: 'cora_direct', user: `employee:${idDip(postgres, 'Castellari')}` });
    expect(a.success).toBe(true); // falla del PHP
    expect(b).toEqual({ success: false, error: 'Non puoi contattare questo utente' });
  });

  it('gruppo: correzione, solo persone dell\'azienda', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const membri = (fn: typeof mariadb) => [`employee:${idDip(fn, 'Bellandi')}`, `employee:${idDip(fn, 'Gualtieri')}`, `employee:${idDip(fn, 'Castellari')}`].join(',');
    const a = await phpAzione(p, { action: 'cora_group_create', name: 'Pranzo', members: membri(mariadb) });
    const b = await nodeAzione(n, { action: 'cora_group_create', name: 'Pranzo', members: membri(postgres) });
    expect(a.success).toBe(true);
    expect(b.success).toBe(true);
    const membriDi = (fn: typeof mariadb, id: number) => fn(`SELECT participant_type, participant_id FROM chat_extra_participants WHERE conversation_id = ${id} ORDER BY id`).length;
    expect(membriDi(mariadb, a.id)).toBe(4);
    expect(membriDi(postgres, b.id)).toBe(3);
    const ia = await phpAzione(p, { action: 'cora_info', conversation_id: String(a.id) });
    const ib = await nodeAzione(n, { action: 'cora_info', conversation_id: String(b.id) });
    expect({ ...ib, members: ib.members.length }).toEqual({ ...ia, id: b.id, members: ia.members.length - 1 });
    for (const [x, y] of [['', ''], ['Nome', '']]) {
      expect(await nodeAzione(n, { action: 'cora_group_create', name: x, members: y })).toEqual(await phpAzione(p, { action: 'cora_group_create', name: x, members: y }));
    }
    const esci = (id: number) => ({ action: 'cora_group_leave', conversation_id: String(id) });
    const bel = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    expect(await nodeAzione(bel[1], esci(b.id))).toEqual(await phpAzione(bel[0], esci(a.id)));
  });

  it('fissa e nascondi', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    for (const action of ['cora_pin', 'cora_pin', 'cora_hide']) {
      expect(await nodeAzione(n, { action, conversation_id: String(idConv(postgres)) })).toEqual(await phpAzione(p, { action, conversation_id: String(idConv(mariadb)) }));
    }
    const a = await phpAzione(p, { action: 'cora_list' });
    const b = await nodeAzione(n, { action: 'cora_list' });
    // La chat con Castellari (altra azienda) c'e' solo nel PHP: vedi la correzione sopra.
    expect(pulisci(b).map((c: any) => c.title)).toEqual(pulisci(a).map((c: any) => c.title).filter((t: string) => t !== 'Anna Castellari'));
  });
});

describe('presenza', () => {
  it('segnale e stato scelto', async () => {
    const [p, n] = await Promise.all([phpEntra('elena.gualtieri'), nodeEntra('elena.gualtieri')]);
    expect(await nodeAzione(n, { action: 'cora_presence_ping', attivo: '1' })).toEqual(await phpAzione(p, { action: 'cora_presence_ping', attivo: '1' }));
    for (const stato of ['occupato', 'boh', '']) {
      expect(await nodeAzione(n, { action: 'cora_presence_status', stato })).toEqual(await phpAzione(p, { action: 'cora_presence_status', stato }));
    }
    const a = await phpAzione(p, { action: 'cora_presence_all' });
    const b = await nodeAzione(n, { action: 'cora_presence_all' });
    expect(pulisci(b)).toEqual(pulisci(a));
  });

  it('azione sconosciuta', async () => {
    const [p, n] = await Promise.all([phpEntra('elena.gualtieri'), nodeEntra('elena.gualtieri')]);
    expect(await nodeAzione(n, { action: 'boh' })).toEqual(await phpAzione(p, { action: 'boh' }));
  });
});
