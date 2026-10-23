import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Chat dello staff (_chat-page.inc.php): azioni dello script come HR e consulente. */
const PW = 'Prova!Cora2026';
async function phpAzione(p: Php, pagina: string, campi: Record<string, string>) {
  const html = (await p.b.chiama(pagina)).testo;
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '';
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, ...campi }).toString(), tipo: 'application/x-www-form-urlencoded' });
  return JSON.parse(r.testo);
}
async function nodeAzione(n: Node, pagina: string, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama(pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  return JSON.parse(r.testo);
}
const messaggi = (fn: typeof mariadb) => fn(`SELECT conversation_id, sender_type, message, is_internal FROM chat_messages ORDER BY id`)
  .map((r) => ({ ...r, is_internal: ['1', 't'].includes(r.is_internal) }));
const extra = (fn: typeof mariadb) => fn(`SELECT conversation_id, participant_type FROM chat_extra_participants ORDER BY id`);
const uid = (fn: typeof mariadb, u: string) => fn(`SELECT id FROM users WHERE username = '${u}'`)[0].id;

beforeAll(() => ripristina());

it('HR: elenco, messaggi, nota interna, consulente invitato e rimosso', async () => {
  const p = new Php(); await p.login('hr.alfa', PW);
  const n = new Node(); await n.login('hr.alfa', PW);
  const html = (await p.b.chiama('/admin/chat.php?conv=1')).testo;
  const V = (await n.get('/pagine/admin/chat?conv=1')).json.V;
  expect(V.conversations.map((c: any) => c.other_name)).toEqual([...html.matchAll(/class="contact-item[^"]*"[\s\S]*?<div class="n">([^<]*)</g)].map((m) => m[1]).slice(0, V.conversations.length));
  const a = await phpAzione(p, '/admin/chat.php?conv=1', { action: 'get_messages', conversation_id: '1' });
  const b = await nodeAzione(n, '/admin/chat.php?conv=1', { action: 'get_messages', conversation_id: '1' });
  expect(b.messages.map((m: any) => [m.message, m.sender_name, Number(m.is_internal)])).toEqual(a.messages.map((m: any) => [m.message, m.sender_name, Number(m.is_internal)]));
  const invio = { action: 'send', conversation_id: '1', message: 'Nota per il consulente', is_internal: '1' };
  expect((await nodeAzione(n, '/admin/chat.php?conv=1', invio)).success).toBe((await phpAzione(p, '/admin/chat.php?conv=1', invio)).success);
  expect(messaggi(postgres)).toEqual(messaggi(mariadb));
  const add = (fn: typeof mariadb) => ({ action: 'add_participant', conversation_id: '1', new_type: 'consulente_lavoro', new_id: uid(fn, 'studio.consulente') });
  expect(await nodeAzione(n, '/admin/chat.php?conv=1', add(postgres))).toEqual(await phpAzione(p, '/admin/chat.php?conv=1', add(mariadb)));
  expect(extra(postgres)).toEqual(extra(mariadb));
  expect(await nodeAzione(n, '/admin/chat.php?conv=1', add(postgres))).toEqual(await phpAzione(p, '/admin/chat.php?conv=1', add(mariadb)));
  const rem = (fn: typeof mariadb) => ({ action: 'remove_participant', conversation_id: '1', rem_type: 'consulente_lavoro', rem_id: uid(fn, 'studio.consulente') });
  expect(await nodeAzione(n, '/admin/chat.php?conv=1', rem(postgres))).toEqual(await phpAzione(p, '/admin/chat.php?conv=1', rem(mariadb)));
  expect(extra(postgres)).toEqual(extra(mariadb));
});

it('correzione: non si fa intervenire un admin di un\'altra azienda', async () => {
  const n = new Node(); await n.login('hr.alfa', PW);
  const r = await nodeAzione(n, '/admin/chat.php?conv=1', { action: 'add_participant', conversation_id: '1', new_type: 'admin', new_id: uid(postgres, 'hr.beta') });
  expect(r).toEqual({ success: false, error: 'Utente non valido' });
});
