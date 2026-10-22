/**
 * Tempo reale della chat (Socket.IO), stesso contratto del CRM Connecteed
 * (docs/realtime-eventi.md di quel progetto).
 *
 * Il socket porta solo avvisi dal server ai client: invio, modifica, letture
 * e presenza passano dalle azioni PHP di sempre. PHP, dopo aver salvato,
 * chiede a questo servizio di consegnare l'evento (POST /emit con il segreto
 * condiviso). Il client non sceglie le stanze: alla connessione entra nella
 * sua stanza personale e in quella della sua azienda.
 *
 * Login: token firmato da PHP (HS256 con REALTIME_SECRET) in auth.token.
 */
'use strict';

const http = require('http');
const crypto = require('crypto');
const { Server } = require('socket.io');

const PORT = Number(process.env.PORT || 3001);
const SECRET = process.env.REALTIME_SECRET || '';
const PERCORSO = '/realtime/socket.io';
const MAX_SOCKET_PER_UTENTE = 10;
const MAX_SEGNALI = 30;
const FINESTRA_SEGNALI_MS = 10000;
const attivo = () => !['off', 'false', '0', 'no'].includes(String(process.env.REALTIME_ENABLED || 'true').toLowerCase());

if (!SECRET) console.warn('[realtime] REALTIME_SECRET mancante: nessuno potra\' collegarsi');

const stanzaUtente = (cid, sub) => `c:${cid}:u:${sub}`;
const stanzaAzienda = (cid) => `c:${cid}:interni`;

function b64url(buf) { return Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); }
function verifica(token) {
  if (!SECRET || typeof token !== 'string') return null;
  const parti = token.split('.');
  if (parti.length !== 3) return null;
  const firma = b64url(crypto.createHmac('sha256', SECRET).update(parti[0] + '.' + parti[1]).digest());
  const a = Buffer.from(firma), b = Buffer.from(parti[2]);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    const p = JSON.parse(Buffer.from(parti[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString());
    if (!p.sub || !p.cid || !p.exp || p.exp * 1000 < Date.now()) return null;
    return p;
  } catch { return null; }
}

/** Socket → chat che sta guardando; socket → ultima attivita'. */
const guardando = new Map();
const attivita = new Map();

function busta(type, data) {
  return { v: 1, type, at: new Date().toISOString(), source: 'cora', data };
}

function leggiCorpo(req) {
  return new Promise((ok, ko) => {
    let s = '';
    req.on('data', (c) => { s += c; if (s.length > 2e6) { ko(new Error('troppo grande')); req.destroy(); } });
    req.on('end', () => { try { ok(JSON.parse(s || '{}')); } catch (e) { ko(e); } });
  });
}

const httpServer = http.createServer(async (req, res) => {
  const fine = (code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
  if (req.url === '/health') return fine(200, { ok: true });
  // Solo PHP, con il segreto condiviso (il servizio non e' esposto: passa dal proxy solo /realtime/socket.io)
  const chiave = req.headers['x-realtime-secret'];
  if (!SECRET || chiave !== SECRET) return fine(403, { ok: false });
  try {
    if (req.method === 'POST' && req.url === '/emit') {
      const corpo = await leggiCorpo(req);
      if (!attivo()) return fine(200, { ok: true, spento: true });
      // { cid, type, eventi: [{ sub, data }] }  oppure  { cid, type, azienda: true, data }
      const cid = corpo.cid;
      if (corpo.azienda) io.to(stanzaAzienda(cid)).emit('evento', busta(corpo.type, corpo.data));
      for (const e of corpo.eventi || []) io.to(stanzaUtente(cid, e.sub)).emit('evento', busta(corpo.type, e.data));
      return fine(200, { ok: true });
    }
    if (req.method === 'POST' && req.url === '/stato') {
      // Chi sta guardando una chat / chi e' attivo sul sito: PHP decide se mandare il push al telefono
      const corpo = await leggiCorpo(req);
      const out = {};
      for (const sub of corpo.subs || []) {
        let guarda = false, attivoSito = false;
        for (const g of guardando.values()) if (g.sub === sub && g.cid === corpo.cid && String(g.conv) === String(corpo.conv)) guarda = true;
        for (const a of attivita.values()) if (a.sub === sub && a.cid === corpo.cid && a.alle > Date.now() - 90000) attivoSito = true;
        out[sub] = { guarda, attivo: attivoSito };
      }
      return fine(200, { ok: true, stato: out });
    }
    if (req.method === 'POST' && req.url === '/disconnetti') {
      const corpo = await leggiCorpo(req);
      io.in(stanzaUtente(corpo.cid, corpo.sub)).disconnectSockets(true);
      return fine(200, { ok: true });
    }
  } catch (e) {
    return fine(400, { ok: false, errore: String(e && e.message || e) });
  }
  fine(404, { ok: false });
});

const io = new Server(httpServer, {
  path: PERCORSO,
  serveClient: true,
  maxHttpBufferSize: 16000,
  pingInterval: 25000,
  pingTimeout: 20000,
  cors: { origin: false },
});

io.use(async (socket, next) => {
  if (!attivo()) return next(new Error('realtime_off'));
  const p = verifica(socket.handshake.auth && socket.handshake.auth.token);
  if (!p) return next(new Error('unauthorized'));
  const gia = await io.in(stanzaUtente(p.cid, p.sub)).fetchSockets();
  if (gia.length >= MAX_SOCKET_PER_UTENTE) return next(new Error('too_many_connections'));
  socket.data = { p, segnali: [] };
  next();
});

function consentito(socket) {
  const d = socket.data, adesso = Date.now();
  d.segnali = d.segnali.filter((t) => adesso - t < FINESTRA_SEGNALI_MS);
  d.segnali.push(adesso);
  if (d.segnali.length > MAX_SEGNALI) { socket.disconnect(true); return false; }
  return true;
}

io.on('connection', (socket) => {
  const { p } = socket.data;
  socket.join(stanzaUtente(p.cid, p.sub));
  socket.join(stanzaAzienda(p.cid));
  // Alla scadenza del token la connessione si chiude: il client si ricollega con uno nuovo
  const scade = setTimeout(() => {
    socket.emit('sessione', { motivo: 'token_scaduto' });
    socket.disconnect(true);
  }, Math.max(0, p.exp * 1000 - Date.now()));

  socket.on('chat.viewing', (corpo) => {
    if (!consentito(socket)) return;
    const id = corpo && corpo.conversationId;
    // Serve solo a non mandare il push doppio a chi la chat la sta gia' leggendo:
    // dichiarare una chat altrui toglierebbe il push solo a se stessi.
    if (id && /^\d{1,12}$/.test(String(id))) {
      guardando.set(socket.id, { sub: p.sub, cid: p.cid, conv: String(id) });
    } else guardando.delete(socket.id);
  });
  socket.on('presence.activity', (corpo) => {
    if (!consentito(socket)) return;
    attivita.set(socket.id, { sub: p.sub, cid: p.cid, alle: corpo && corpo.attivo === true ? Date.now() : 0 });
  });
  socket.on('disconnect', () => {
    clearTimeout(scade);
    guardando.delete(socket.id);
    attivita.delete(socket.id);
  });
});

httpServer.listen(PORT, () => {
  console.log(`[realtime] ${attivo() ? 'attivo' : 'spento (REALTIME_ENABLED=false)'} su :${PORT}${PERCORSO}`);
});
