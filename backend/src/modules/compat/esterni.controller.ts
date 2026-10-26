import { Body, Controller, Get, HttpCode, Logger, Post, Query, Req, Res, UseInterceptors } from '@nestjs/common';
import { AnyFilesInterceptor } from '@nestjs/platform-express';
import { timingSafeEqual } from 'crypto';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { AttestatiService } from '../academy/attestati.service';
import { Pubblico, SenzaCsrf, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { DocumentiService } from '../documenti/documenti.service';
import { TimbraturaService } from '../presenze/timbratura.service';

const esc = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const dmy = (d: string | null | undefined) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '-');

/**
 * Indirizzi del PHP usati fuori dalle pagine: download dei documenti
 * (api/download.php), clic dell'HR (api/ui-click.php), log del service
 * worker (api/push-log.php), porta NFC (api/door.php), timbratura da carta
 * NFC (punch.php) e verifica pubblica degli attestati (verifica-attestato.php).
 */
@Controller()
export class EsterniController {
  private readonly logger = new Logger('Esterni');
  constructor(private readonly ds: DataSource, private readonly documenti: DocumentiService, private readonly attestati: AttestatiService,
    private readonly limiti: LimitiDbService, private readonly timbratura: TimbraturaService) {}

  private ctx(req: RichiestaAutenticata, u: UtenteCorrente) {
    return { ip: req.ip ?? null, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId };
  }

  /** Download di busta paga / CU / documento (Document::download, con filigrana sui PDF). */
  @Get('api/download.php')
  async scarica(@Query('id') id: string, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const n = Number.parseInt(String(id ?? ''), 10) || 0;
    if (!n) { res.status(400).json({ error: 'ID documento non specificato' }); return; }
    const r = await this.documenti.scaricaBusta(n, u, this.ctx(req, u));
    if (!r.ok) {
      res.status(r.error === 'Accesso non autorizzato' ? 403 : /Limite/.test(r.error) ? 429 : 404).json({ error: r.error });
      return;
    }
    res.set({ 'Content-Type': r.mime, 'Content-Disposition': `attachment; filename="${r.nome}"`, 'Content-Length': String(r.contenuto.length), 'Cache-Control': 'private, no-store' }).send(r.contenuto);
  }

  /**
   * Clic dell'utenza HR (navigator.sendBeacon con FormData): il token CSRF
   * arriva nel corpo, non in un header. Sempre 204, al massimo 30 al minuto.
   */
  @Post('api/ui-click.php')
  @HttpCode(204)
  @SenzaCsrf()
  @UseInterceptors(AnyFilesInterceptor({ limits: { files: 0, fields: 10, fieldSize: 2048 } }))
  async clic(@Body() b: Record<string, string>, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    if (!u || u.tipo !== 'user' || u.ruolo !== 'admin') return;
    const cookie = String(req.cookies?.cora_csrf ?? '');
    const token = String(b?.csrf_token ?? '');
    if (!cookie || cookie.length !== token.length || !timingSafeEqual(Buffer.from(cookie), Buffer.from(token))) return;
    if (!(await this.limiti.consenti(`admin_${u.id}`, 'ui_click', 30, 60))) return;
    const pulisci = (k: string, max: number) => [...String(b?.[k] ?? '').replace(/\s+/gu, ' ').trim()].slice(0, max).join('');
    const etichetta = pulisci('label', 100);
    const pagina = pulisci('page', 120);
    if (etichetta === '' || pagina === '') return;
    await senzaIsolamento(() => this.ds.query(
      'INSERT INTO ui_click_logs (company_id, user_id, page, area, kind, label, target) VALUES ($1, $2, $3, $4, $5, $6, $7)',
      [u.companyId || null, u.id, pagina, pulisci('area', 40) || 'Pagina', pulisci('kind', 20) || 'button', etichetta, pulisci('target', 160) || null])).catch((e) => this.logger.warn(`ui-click: ${e?.message ?? e}`));
    if (Math.random() < 1 / 500) await senzaIsolamento(() => this.ds.query(`DELETE FROM ui_click_logs WHERE created_at < CURRENT_TIMESTAMP - interval '12 months'`)).catch(() => undefined);
  }

  /** Diagnostica del service worker: solo con una sessione, testo troncato, nei log del server. */
  @Get('api/push-log.php')
  @HttpCode(204)
  pushLog(@Query('event') evento: string, @Utente() u: UtenteCorrente) {
    this.logger.log(`sw ${u.tipo}#${u.id}: ${String(evento ?? '').replace(/[^\w.:-]/g, '').slice(0, 60)}`);
  }

  /**
   * Apertura della porta con badge NFC, chiamata dall'ESP32 (api/door.php).
   * Correzione: al massimo 60 tentativi al minuto per indirizzo (prima
   * nessun limite contro chi prova chiavi o badge a raffica).
   */
  @Post('api/door.php')
  @Pubblico()
  @SenzaCsrf()
  async porta(@Query('c') slug: string, @Body() b: { uid?: unknown }, @Req() req: RichiestaAutenticata, @Res() res: Response) {
    res.set('Cache-Control', 'no-store');
    const rispondi = (s: number, x: Record<string, unknown>) => { res.status(s).json(x); };
    if (!(await this.limiti.consenti(String(req.ip ?? ''), 'door', 60, 60))) { rispondi(429, { open: false, reason: 'rate_limited' }); return; }
    const s = String(slug ?? '').trim().toLowerCase();
    if (s === '') { rispondi(400, { open: false, reason: 'missing_company' }); return; }
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT id, door_enabled, door_api_key, door_open_duration_ms FROM companies WHERE lower(slug) = $1', [s]));
    if (!az) { rispondi(404, { open: false, reason: 'company_not_found' }); return; }
    if (!az.door_enabled) { rispondi(503, { open: false, reason: 'module_disabled' }); return; }
    const chiave = String(req.headers['x-door-key'] ?? '');
    const attesa = String(az.door_api_key ?? '');
    if (!attesa || attesa.length !== chiave.length || !timingSafeEqual(Buffer.from(attesa), Buffer.from(chiave))) { rispondi(401, { open: false, reason: 'invalid_key' }); return; }
    const uid = String(b?.uid ?? '').trim().toUpperCase().replace(/[^0-9A-F]/g, '');
    if (uid === '' || uid.length < 8 || uid.length > 20) { rispondi(400, { open: false, reason: 'invalid_uid' }); return; }
    const registra = (eid: number | null, ok: boolean, motivo: string) => senzaIsolamento(() => this.ds.query(
      'INSERT INTO door_access_log (company_id, employee_id, nfc_uid, granted, reason, ip_address, user_agent) VALUES ($1, $2, $3, $4, $5, $6, $7)',
      [az.id, eid, uid, ok, motivo, req.ip ?? null, String(req.headers['user-agent'] ?? '').slice(0, 255)]));
    const [e] = await senzaIsolamento(() => this.ds.query('SELECT id, first_name, last_name, is_active FROM employees WHERE company_id = $1 AND nfc_uid = $2 LIMIT 1', [az.id, uid]));
    if (!e) { await registra(null, false, 'unknown_uid'); rispondi(200, { open: false, reason: 'unknown_uid' }); return; }
    if (!e.is_active) { await registra(e.id, false, 'employee_inactive'); rispondi(200, { open: false, reason: 'employee_inactive' }); return; }
    await registra(e.id, true, 'ok');
    rispondi(200, { open: true, employee: `${e.first_name} ${e.last_name}`.trim(), duration_ms: Math.max(500, Math.min(10000, Number(az.door_open_duration_ms) || 3000)) });
  }

  /** Timbratura da carta NFC (punch.php?c=SLUG): pagina minima di conferma. */
  @Get('punch.php')
  @Pubblico()
  async timbra(@Query('c') slug: string, @Req() req: RichiestaAutenticata, @Res() res: Response) {
    const u = req.utente;
    const s = String(slug ?? '').trim();
    if (!u || u.tipo !== 'employee') {
      const ritorno = `/punch.php${s ? `?c=${encodeURIComponent(s)}` : ''}`;
      res.redirect(302, `/auth/login.php?return=${encodeURIComponent(ritorno)}`);
      return;
    }
    res.set({ 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    // Correzione: senza carta il PHP timbrava comunque (nessun controllo dell'azienda).
    if (s === '') { res.send(this.paginaTimbratura({ success: false, error: 'Carta non valida per la tua azienda.' })); return; }
    // Correzione: un link da un altro sito timbrava per conto del dipendente
    // collegato. La carta NFC apre l'indirizzo direttamente (Sec-Fetch-Site
    // "none"); da altri siti serve una conferma con un tocco.
    const sito = String(req.headers['sec-fetch-site'] ?? '');
    if (sito !== '' && sito !== 'none' && sito !== 'same-origin') { res.send(this.paginaConferma(s)); return; }
    const r = await this.timbratura.daCarta(u.id, s, req.ip ?? null, String(req.headers['user-agent'] ?? ''));
    res.send(this.paginaTimbratura(r));
  }

  /** Richiesta arrivata da un altro sito: la timbratura parte solo col tocco del dipendente. */
  private paginaConferma(slug: string): string {
    const href = `/punch.php?c=${encodeURIComponent(slug)}`;
    return `<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Timbratura</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; min-height: 100dvh;
  display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; }
.box { max-width: 320px; width: 100%; }
h1 { font-size: 20px; margin-bottom: 10px; }
p { font-size: 14px; color: #475569; margin-bottom: 22px; }
a.btn { display: inline-block; background: #0b3aa4; color: #fff; text-decoration: none; font-weight: 600; padding: 14px 28px; border-radius: 12px; }
a.annulla { display: block; margin-top: 16px; font-size: 13px; color: #64748b; }
</style>
</head>
<body>
    <div class="box">
        <h1>Confermi la timbratura?</h1>
        <p>La richiesta arriva da un altro sito. Timbra solo se hai appena avvicinato il telefono alla carta.</p>
        <a class="btn" href="${esc(href)}">Timbra adesso</a>
        <a class="annulla" href="/employee/">Annulla</a>
    </div>
</body>
</html>`;
  }

  private paginaTimbratura(r: Awaited<ReturnType<TimbraturaService['daCarta']>>): string {
    const ok = r.success;
    const tipo = r.kind ?? null;
    const etichetta = tipo === 'in' ? 'Entrata' : tipo === 'out' ? 'Uscita' : '';
    const ora = r.ora ?? '';
    const fondo = ok && tipo === 'in' ? '#dcfce7' : ok && tipo === 'out' ? '#fef3c7' : '#fee2e2';
    const colore = ok && tipo === 'in' ? 'var(--green)' : ok && tipo === 'out' ? 'var(--orange)' : 'var(--red)';
    return `<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${ok ? `Timbrato ${esc(ora)}` : 'Timbratura'}</title>
<style>
:root { --green: #16a34a; --orange: #d97706; --red: #b91c1c; --navy: #0b3aa4; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: ${fondo}; color: #0f172a; min-height: 100vh; min-height: 100dvh;
  display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; }
.box { max-width: 320px; width: 100%; }
.ic { width: 96px; height: 96px; border-radius: 50%; background: white; display: inline-flex; align-items: center; justify-content: center; margin: 0 auto 18px;
  box-shadow: 0 8px 24px rgba(15,23,42,0.08); color: ${colore}; animation: pop .4s cubic-bezier(0.34, 1.56, 0.64, 1); }
.ic svg { width: 48px; height: 48px; }
@keyframes pop { 0% { transform: scale(0.5); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
.kind { font-size: 18px; font-weight: 700; color: ${colore}; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px; }
.time { font-size: 64px; font-weight: 700; color: #0f172a; letter-spacing: -0.03em; line-height: 1; margin: 6px 0 12px; font-variant-numeric: tabular-nums; }
.who { font-size: 14px; color: #475569; }
.err-msg { font-size: 14px; color: var(--red); margin-top: 10px; }
.close-hint { margin-top: 28px; font-size: 12.5px; color: #6e7191; opacity: 0.7; }
</style>
</head>
<body>
    <div class="box">
        ${ok ? `<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div class="kind">${etichetta}</div>
            <div class="time">${esc(ora)}</div>
            <div class="who">${esc(r.employee ?? '')}</div>`
    : `<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
            <div class="kind">Timbratura</div>
            <div class="err-msg">${esc(r.error ?? '')}</div>`}
        <div class="close-hint">Ti riporto alla home…</div>
    </div>
<script>
if (navigator.vibrate) { try { navigator.vibrate([80]); } catch (_) {} }
setTimeout(function(){ window.location.replace("/employee/"); }, 1500);
</script>
</body>
</html>`;
  }

  /** Verifica pubblica di un attestato (link del QR), al massimo 30 verifiche ogni 5 minuti per indirizzo. */
  @Get('verifica-attestato.php')
  @Pubblico()
  async verifica(@Query('t') t: string, @Req() req: RichiestaAutenticata, @Res() res: Response) {
    const token = String(t ?? '');
    let d: Awaited<ReturnType<AttestatiService['verifica']>> = null;
    if (/^[a-f0-9]{32}$/.test(token) && (await this.limiti.consenti(String(req.ip ?? ''), 'verify_certificate', 30, 300))) d = await this.attestati.verifica(token);
    const cf = (x: string | null) => (x && x.length === 16 ? `${x.slice(0, 6)}******${x.slice(-4)}` : '');
    const corpo = !d ? `
        <span class="badge ko"><span class="dot"></span>Attestato non trovato</span>
        <h1>Verifica non riuscita</h1>
        <p class="note">Il codice non corrisponde a nessun attestato emesso. Controlla di aver inquadrato il QR code corretto.</p>` : `
        ${d.file_intact ? '<span class="badge ok"><span class="dot"></span>Attestato valido</span>' : '<span class="badge warn"><span class="dot"></span>Attestato emesso, file originale non verificabile</span>'}
        <h1>${esc(d.course_title)}</h1>
        <dl>
            <dt>Partecipante</dt><dd>${esc(`${d.first_name} ${d.last_name}`.trim())}</dd>
            <dt>Codice fiscale</dt><dd>${esc(cf(d.fiscal_code))}</dd>
            <dt>Azienda</dt><dd>${esc(d.company_name ?? '-')}</dd>
            <dt>Completato il</dt><dd>${dmy(d.completed_at)}</dd>
            <dt>Attestato n.</dt><dd>${esc(d.number)}</dd>
            <dt>Emesso il</dt><dd>${dmy(d.issued_at)}</dd>
        </dl>
        <h2>Registro di frequenza</h2>
        <p class="sub">
            ${d.chain.ok ? '<span class="badge ok" style="font-size:0.75rem; padding:3px 9px;"><span class="dot"></span>Registro integro</span>' : '<span class="badge ko" style="font-size:0.75rem; padding:3px 9px;"><span class="dot"></span>Registro alterato</span>'}
            &nbsp;Ora del server, indirizzo IP parzialmente oscurato.
        </p>
        <ul class="log">${d.events.map((ev: { at: string; label: string; event: string; detail: string; actor: string; ip: string }) => {
    const cls = ['started', 'certificate_generated'].includes(ev.event) ? 'key' : ev.event === 'completed' ? 'done' : '';
    return `
                <li class="${cls}">
                    <div class="when">${dmy(ev.at)} ${ev.at.slice(11, 19)}</div>
                    <div class="what">${esc(ev.label)}${ev.detail !== '' ? `: <span style="font-weight:400;">${esc(ev.detail)}</span>` : ''}</div>
                    <div class="meta">${esc(ev.actor)}${ev.ip !== '' ? ` · IP ${esc(ev.ip)}` : ''}</div>${ev.event === 'completed' && d.declaration_text ? `
                        <div class="meta" style="font-style:italic;">“${esc(d.declaration_text)}”</div>` : ''}
                </li>`;
  }).join('')}
        </ul>
        <p class="note">
            Ogni riga del registro contiene l'impronta (hash SHA-256) della precedente: una modifica successiva
            ai dati verrebbe segnalata qui sopra. L'impronta del PDF emesso è conservata per verificarne l'integrità.
        </p>`;
    res.set({ 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex' }).send(`<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verifica attestato</title>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>
        :root { --accent: #0b3aa4; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #f4f6fb; color: #1f2937; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(11,58,164,0.10); max-width: 760px; width: 100%; padding: 28px; }
        h2 { font-size: 1rem; margin: 28px 0 4px; color: #0f172a; }
        .sub { font-size: 0.8rem; color: #6b7280; margin: 0 0 12px; }
        .log { list-style: none; margin: 0; padding: 0; border-left: 2px solid #dbe3f4; }
        .log li { position: relative; padding: 0 0 14px 18px; font-size: 0.88rem; }
        .log li::before { content: ''; position: absolute; left: -6px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; }
        .log li.key::before { background: var(--accent); }
        .log li.done::before { background: #16a34a; }
        .log .when { color: #6b7280; font-size: 0.78rem; font-variant-numeric: tabular-nums; }
        .log .what { font-weight: 600; }
        .log .meta { color: #6b7280; font-size: 0.78rem; }
        .badge { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.9rem; padding: 6px 12px; border-radius: 999px; }
        .badge .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .ok { background: #dcfce7; color: #166534; }
        .warn { background: #fef3c7; color: #92400e; }
        .ko { background: #fee2e2; color: #991b1b; }
        h1 { font-size: 1.35rem; margin: 16px 0 4px; color: var(--accent); }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; margin: 20px 0 0; font-size: 0.92rem; }
        dt { color: #6b7280; }
        dd { margin: 0; font-weight: 500; }
        p.note { font-size: 0.8rem; color: #6b7280; margin: 20px 0 0; }
        @media (max-width: 480px) { dl { grid-template-columns: 1fr; gap: 2px; } dd { margin-bottom: 8px; } }
    </style>
</head>
<body>
<div class="card">${corpo}
</div>
</body>
</html>`);
  }
}
