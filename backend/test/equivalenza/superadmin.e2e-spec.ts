import { createHmac } from 'crypto';
import { NODE_URL, postgres, ripristina } from './ambiente';

/** Superadmin (public/superadmin/): accesso in due passaggi e gestione dei tenant. */
const SEGRETO = 'JBSWY3DPEHPK3PXP';
function totp(segreto: string) {
  const alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bit = '';
  for (const c of segreto) bit += alfabeto.indexOf(c).toString(2).padStart(5, '0');
  const chiave = Buffer.from(bit.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const contatore = Buffer.alloc(8);
  contatore.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = createHmac('sha1', chiave).update(contatore).digest();
  const o = h[h.length - 1] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

/** Client con cookie e CSRF per le rotte /api/v1/superadmin. */
class Sa {
  cookie = new Map<string, string>();
  ip = `203.0.113.${Math.floor(Math.random() * 200) + 20}`;
  async chiama(metodo: string, percorso: string, corpo?: unknown) {
    if (!this.cookie.has('cora_csrf') && percorso !== '/api/v1/auth/sessione') await this.chiama('GET', '/api/v1/auth/sessione');
    const r = await fetch(NODE_URL + percorso, {
      method: metodo, redirect: 'manual', body: corpo === undefined ? undefined : JSON.stringify(corpo),
      headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': this.ip, 'X-CSRF-Token': decodeURIComponent(this.cookie.get('cora_csrf') ?? ''),
        Cookie: [...this.cookie].map(([k, v]) => `${k}=${v}`).join('; ') },
    });
    for (const c of r.headers.getSetCookie()) { const [kv] = c.split(';'); const i = kv.indexOf('='); this.cookie.set(kv.slice(0, i), kv.slice(i + 1)); }
    let j: any = null;
    try { j = await r.json(); } catch { j = null; }
    return { status: r.status, j };
  }
  async entra() {
    await this.chiama('POST', '/api/v1/superadmin/login', { username: 'sa.prova', password: 'Prova!Cora2026' });
    return this.chiama('POST', '/api/v1/superadmin/totp', { code: totp(SEGRETO) });
  }
}

beforeAll(() => ripristina());

describe('accesso', () => {
  it('senza codice niente pannello; password errata respinta', async () => {
    const s = new Sa();
    expect((await s.chiama('POST', '/api/v1/superadmin/login', { username: 'sa.prova', password: 'sbagliata' })).j).toEqual({ ok: false, error: 'Credenziali non valide' });
    expect((await s.chiama('POST', '/api/v1/superadmin/login', { username: 'sa.prova', password: 'Prova!Cora2026' })).j).toEqual({ ok: true, fase: 'password' });
    expect((await s.chiama('GET', '/api/v1/superadmin/tenant')).status).toBe(401);
    expect((await s.chiama('POST', '/api/v1/superadmin/totp', { code: '000000' })).j.ok).toBe(false);
    expect((await s.chiama('POST', '/api/v1/superadmin/totp', { code: totp(SEGRETO) })).j).toEqual({ ok: true, fase: 'completa' });
    expect((await s.chiama('GET', '/api/v1/superadmin/tenant')).status).toBe(200);
    await s.chiama('POST', '/api/v1/superadmin/esci');
    expect((await s.chiama('GET', '/api/v1/superadmin/tenant')).status).toBe(401);
  });

  it('un utente normale non entra nel pannello', async () => {
    const s = new Sa();
    expect((await s.chiama('GET', '/api/v1/superadmin/clic')).status).toBe(401);
  });
});

describe('tenant', () => {
  it('creazione con token di primo accesso salvato come impronta', async () => {
    const s = new Sa();
    await s.entra();
    const r = await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'create', company_name: 'Gamma Srl', admin_name: 'Anna Gialli', admin_email: 'anna.gialli@esempio.test', admin_username: 'anna.gialli' });
    expect(r.j.V.error).toBe('');
    expect(r.j.V.message).toMatch(/^Tenant creato\./);
    const [u] = postgres(`SELECT u.id, u.role, c.name FROM users u JOIN companies c ON c.id = u.company_id WHERE u.username = 'anna.gialli'`);
    expect(u.role).toBe('admin');
    expect(u.name).toBe('Gamma Srl');
    const [t] = postgres(`SELECT token FROM password_reset_tokens WHERE user_type = 'admin' AND user_id = ${u.id}`);
    expect(t.token).toMatch(/^[a-f0-9]{64}$/);
    const doppio = await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'create', company_name: 'X', admin_name: 'Y', admin_email: 'altro@esempio.test', admin_username: 'anna.gialli' });
    expect(doppio.j.V.error).toBe('Username gia in uso');
  });

  it('disattiva, riattiva, aziende assegnate', async () => {
    const s = new Sa();
    await s.entra();
    const id = postgres(`SELECT id FROM users WHERE username = 'anna.gialli'`)[0].id;
    expect((await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'deactivate', user_id: id })).j.V.message).toBe('Tenant disattivato');
    expect(postgres(`SELECT u.is_active AS u, c.is_active AS c FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ${id}`)[0]).toEqual({ u: 'f', c: 'f' });
    expect((await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'activate', user_id: id })).j.V.message).toBe('Tenant riattivato');
    expect((await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'set_companies', user_id: id, 'company_ids[]': ['2'] })).j.V.message).toBe('Aziende assegnate aggiornate');
    expect(postgres(`SELECT company_id FROM user_companies WHERE user_id = ${id}`)).toEqual([{ company_id: '2' }]);
    await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'set_companies', user_id: id, 'company_ids[]': [] });
  });

  it('eliminazione: conferma obbligatoria; correzione: l\'admin globale non porta via tutte le aziende', async () => {
    const s = new Sa();
    await s.entra();
    const id = postgres(`SELECT id FROM users WHERE username = 'anna.gialli'`)[0].id;
    expect((await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'delete', user_id: id, confirm: 'si' })).j.V.error).toBe('Conferma mancante o errata');
    const globale = postgres(`SELECT id FROM users WHERE username = 'connecteed'`)[0].id;
    const r = await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'delete', user_id: globale, confirm: 'ELIMINA' });
    expect(r.j.V.error).toMatch(/^Admin globale/);
    expect(Number(postgres('SELECT COUNT(*) AS n FROM companies')[0].n)).toBeGreaterThanOrEqual(3);
    expect((await s.chiama('POST', '/api/v1/superadmin/tenant', { action: 'delete', user_id: id, confirm: 'ELIMINA' })).j.V.message).toBe('Tenant eliminato definitivamente');
    expect(postgres(`SELECT COUNT(*) AS n FROM companies WHERE name = 'Gamma Srl'`)[0].n).toBe('0');
  });
});
