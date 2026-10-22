/**
 * Cora — chat del dipendente, identica alla chat interna del CRM Connecteed
 * (frontend/src/pages/ChatPage.tsx, design "largo" al computer e "telefono"
 * sotto gli 820px). Cambia solo il colore: il lime diventa l'arancio di Cora.
 *
 * Tempo reale come nel CRM (services/realtime.ts): Socket.IO porta solo
 * avvisi (`evento` con la busta { v, type, at, source, data }); i dati restano
 * quelli delle azioni PHP. Polling di riserva: veloce senza socket (2s e 5s),
 * lento con il socket (30s e 60s). Quando la connessione torna, o la scheda
 * torna in primo piano, si rilegge quello che ci si e' persi.
 */
(function () {
    'use strict';

    var cfg = window.CORA_CHAT;
    var root = document.getElementById('coraChat');
    if (!cfg || !root) return;

    var A = cfg.assets;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var me = cfg.me;
    var mqTelefono = window.matchMedia('(max-width: 820px)');
    var telefono = mqTelefono.matches;
    var LIMITE_MESSAGGIO = 20000;
    var LIMITE_ALLEGATO = 20 * 1024 * 1024;
    var PAGINA = 50;
    var MINUTI_MODIFICA = cfg.editMinutes || 15;
    var REAZIONI = ['👍', '❤️', '😂', '😮', '😎', '😍', '😇'];
    var EMOJI_CATEGORIES = [
        { label: 'Faccine', emojis: ['😀','😁','😂','🤣','😊','😍','🥰','😘','😎','🤩','🥳','😅','😉','😋','😜','🤪','🤔','🤨','😐','😴','🥱','😮','😱','😭','😢','😤','😡','🤯','🥶','🥵','🤒','🤕','🤢','🤮','🤧','😇','🤠','🤡','👻','💀','👽','🤖'] },
        { label: 'Gesti', emojis: ['👍','👎','👌','🤌','✌️','🤞','🤟','🤘','🖐️','✋','👋','🤚','💪','🙏','👏','🙌','🤝','☝️','👆','👇','👈','👉','✊','👊','🫶'] },
        { label: 'Cuori', emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝'] },
        { label: 'Natura', emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🦄','🐝','🦋','🌸','🌹','🌻','🌲','🌴','🌈','☀️','🌙','⭐','🔥','💧','⚡','❄️'] },
        { label: 'Cibo', emojis: ['🍏','🍎','🍕','🍔','🍟','🌭','🍿','🥐','🍩','🍪','🎂','🍰','🍫','🍬','☕','🍺','🍷','🥂','🍾'] },
        { label: 'Oggetti', emojis: ['⚽','🏀','🎮','🎲','🎧','🎸','🚗','✈️','🚀','💻','📱','⌚','📷','💡','🔧','🔑','💰','💳','📅','📌','📎','✏️','📚','✅','❌','⚠️','❓','❗','💯','🎉','🎊','🎁','🏆','🥇'] }
    ];
    var NOME_STATO = { online: 'Disponibile', occupato: 'Occupato', in_chiamata: 'In chiamata', non_disturbare: 'Non disturbare', assente: 'Assente', offline: 'Offline' };
    var AV_COLORS = ['#3b82f6', '#f97316', '#10b981', '#ec4899', '#8b5cf6', '#14b8a6', '#ef4444', '#6366f1'];

    /* Icone lucide usate dal CRM */
    function L(path, w) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 2) + '" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
    }
    var I = {
        x: L('<path d="M18 6 6 18M6 6l12 12"/>'),
        check: L('<path d="M20 6 9 17l-5-5"/>'),
        check3: L('<path d="M20 6 9 17l-5-5"/>', 3),
        checkCheck: L('<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>'),
        chevDown: L('<path d="m6 9 6 6 6-6"/>'),
        chevLeft: L('<path d="m15 18-6-6 6-6"/>'),
        chevRight: L('<path d="m9 18 6-6-6-6"/>'),
        more: L('<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>'),
        pin: L('<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>'),
        pinOff: L('<path d="M12 17v5"/><path d="M15 9.34V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H7.89"/><path d="m2 2 20 20"/><path d="M9 9v1.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h11"/>'),
        trash: L('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>'),
        users: L('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
        search: L('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 1.5),
        fileText: L('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>'),
        fileText15: L('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>', 1.5),
        download: L('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>'),
        image: L('<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>', 1.5),
        camera: L('<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>', 1.5),
        camera2: L('<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>'),
        mic: L('<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>'),
        send: L('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>'),
        play: L('<polygon points="6 3 20 12 6 21 6 3"/>'),
        pause: L('<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>'),
        reply: L('<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>'),
        pencil: L('<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>', 1.75),
        plus: L('<path d="M5 12h14"/><path d="M12 5v14"/>'),
        bell: L('<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'),
        msg: L('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>')
    };

    /* ---------------- Utilita' ---------------- */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(sel) { return root.querySelector(sel); }
    function colorFor(key) {
        var h = 0;
        key = String(key || '');
        for (var i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) >>> 0;
        return AV_COLORS[h % AV_COLORS.length];
    }
    function coloreCitazione(nome) { return nome === 'Tu' ? '#d42a66' : colorFor(nome); }
    function initials(name) {
        var p = String(name || '').trim().split(/\s+/).filter(Boolean);
        return (((p[0] || '')[0] || '') + ((p[1] || '')[0] || '')).toUpperCase() || '?';
    }
    function fmtMsgTime(d) {
        try { return new Date(d).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; }
    }
    function fmtOraLista(d) {
        if (!d) return '';
        var date = new Date(d), now = new Date();
        if (date.toDateString() === now.toDateString()) return fmtMsgTime(d);
        var ieri = new Date(now); ieri.setDate(now.getDate() - 1);
        if (date.toDateString() === ieri.toDateString()) return 'Ieri';
        if (now.getTime() - date.getTime() < 6 * 86400000) {
            var g = date.toLocaleDateString('it-IT', { weekday: 'long' });
            return g.charAt(0).toUpperCase() + g.slice(1);
        }
        return date.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit' });
    }
    function etichettaGiorno(d) {
        var date = new Date(d), now = new Date(), yest = new Date(now);
        yest.setDate(now.getDate() - 1);
        if (date.toDateString() === now.toDateString()) return 'Oggi';
        if (date.toDateString() === yest.toDateString()) return 'Ieri';
        return date.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short' });
    }
    function testoUltimoAccesso(q) {
        if (!q) return 'Offline';
        var d = new Date(q), ora = d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        var oggi = new Date(), ieri = new Date(); ieri.setDate(oggi.getDate() - 1);
        if (d.toDateString() === oggi.toDateString()) return 'Ultimo accesso oggi alle ' + ora;
        if (d.toDateString() === ieri.toDateString()) return 'Ultimo accesso ieri alle ' + ora;
        return 'Ultimo accesso il ' + d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit' }) + ' alle ' + ora;
    }
    function fmtRecTime(sec) { return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); }
    function fmtSize(b) {
        if (!b) return '';
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }
    function nuovoIdClient() {
        try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID(); } catch (e) {}
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
    function isTmp(m) { return String(m.id).indexOf('tmp-') === 0; }

    function post(action, data, files) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', csrf);
        Object.keys(data || {}).forEach(function (k) { if (data[k] != null) fd.append(k, data[k]); });
        Object.keys(files || {}).forEach(function (k) { if (files[k]) fd.append(k, files[k], files[k].name); });
        return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            if (r.status === 401) { location.reload(); throw new Error('Sessione scaduta'); }
            return r.json().then(function (j) {
                if (!r.ok) { var e = new Error((j && j.error) || 'Errore'); e.response = true; throw e; }
                return j;
            });
        });
    }

    /* Avatar e pallino di stato (stile Teams) */
    function avatarHtml(name, url, size, id) {
        var st = 'width:' + size + 'px;height:' + size + 'px;font-size:' + (size * 0.38) + 'px;';
        if (url) return '<span class="cc-av" style="' + st + '" title="' + esc(name) + '"><img src="' + esc(url) + '" alt="' + esc(name) + '"></span>';
        return '<span class="cc-av" style="' + st + 'background-color:' + colorFor(id || name) + '" title="' + esc(name) + '">' + esc(initials(name)) + '</span>';
    }
    function avatarConv(c, size) {
        var box = 'width:' + size + 'px;height:' + size + 'px;';
        var ic = Math.round(size * 0.45);
        if (c.type === 'group') {
            return c.avatarUrl
                ? '<img src="' + esc(c.avatarUrl) + '" alt="" class="cc-av-img" style="' + box + '">'
                : '<span class="cc-av-group" style="' + box + '"><span style="width:' + ic + 'px;height:' + ic + 'px">' + I.users + '</span></span>';
        }
        return avatarHtml(c.title, c.avatarUrl, size, c.members[0] && c.members[0].id);
    }
    function pallino(stato, size, cls) {
        var a = 'width="' + size + '" height="' + size + '" viewBox="0 0 16 16" class="cc-pallino ' + (cls || '') + '" role="img" aria-label="' + esc(NOME_STATO[stato]) + '"';
        if (stato === 'online') return '<svg ' + a + '><circle cx="8" cy="8" r="8" fill="#6bb700"/><path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        if (stato === 'occupato') return '<svg ' + a + '><circle cx="8" cy="8" r="8" fill="#c4314b"/></svg>';
        if (stato === 'in_chiamata') return '<svg ' + a + '><circle cx="8" cy="8" r="8" fill="#c4314b"/><path d="M5.6 4.2c.3-.2.7-.1.9.2l.8 1.3c.2.3.1.7-.1.9l-.6.5c.4.9 1.1 1.6 2 2l.5-.6c.2-.3.6-.3.9-.1l1.3.8c.3.2.4.6.2.9l-.5.8c-.3.4-.8.6-1.3.5-2.2-.5-3.9-2.2-4.4-4.4-.1-.5.1-1 .5-1.3l.8-.5z" fill="#fff"/></svg>';
        if (stato === 'non_disturbare') return '<svg ' + a + '><circle cx="8" cy="8" r="8" fill="#c4314b"/><rect x="4" y="7" width="8" height="2" rx="1" fill="#fff"/></svg>';
        if (stato === 'assente') return '<svg ' + a + '><circle cx="8" cy="8" r="8" fill="#ffaa44"/><path d="M8 4.5V8l2.4 1.6" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        return '<svg ' + a + '><circle cx="8" cy="8" r="6.6" fill="#fff" stroke="#8a8886" stroke-width="2.2"/></svg>';
    }
    function img(file, cls) { return '<img src="' + A + file + '" alt="" class="' + (cls || '') + '">'; }
    function maschera(file, cls) {
        var u = 'url(&quot;' + A + file + '&quot;)';
        return '<span aria-hidden="true" class="cc-mask ' + (cls || '') + '" style="mask-image:' + u + ';-webkit-mask-image:' + u + '"></span>';
    }
    function linkify(text) {
        return esc(text).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '<a href="$1" target="_blank" rel="noopener noreferrer" class="cc-link">$1</a>');
    }
    function testoConMenzioni(text, nomi, mio) {
        var validi = Array.from(new Set(nomi.filter(Boolean))).sort(function (a, b) { return b.length - a.length; });
        if (!validi.length) return linkify(text);
        var re = new RegExp('(@(?:' + validi.map(function (n) { return n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + '))', 'g');
        return text.split(re).map(function (pz) {
            if (pz.charAt(0) !== '@' || validi.indexOf(pz.slice(1)) === -1) return linkify(pz);
            var sonoIo = !!mio && pz.slice(1) === mio;
            return '<span class="cc-menzione' + (sonoIo ? ' is-me' : '') + '">' + esc(pz) + '</span>';
        }).join('');
    }

    /* ---------------- Stato ---------------- */
    var S = {
        conversations: cfg.conversations || [],
        contacts: cfg.contacts || [],
        presenze: cfg.presence || {},
        activeId: null,
        messages: [],
        loadingMessages: false,
        haPrecedenti: false,
        caricandoPrecedenti: false,
        errorePrecedenti: false,
        showListMobile: true,
        pendingFile: null,
        anteprimaRotta: false,
        replyTo: null,
        editing: null,
        menzione: null,
        indiceMenzione: 0,
        menzionati: [],
        emojiOpen: false,
        emojiCat: 0,
        attachOpen: false,
        gifOpen: false,
        foglioAllegati: false,
        avvisoInvio: null,
        nonLetti: null,
        inFondo: true,
        recording: false,
        recSeconds: 0,
        menuStato: false,
        mioManuale: null,
        convMenu: null
    };
    var cacheMessaggi = new Map();
    var bozzePerChat = new Map();
    var lastMsgId = null;
    var inFondoRef = true;
    var scorrimentoMio = 0;
    var inInvio = new Map();
    var consegnati = new Set();
    var caricandoPrecedentiRef = false;

    function conv(id) { for (var i = 0; i < S.conversations.length; i++) if (S.conversations[i].id === id) return S.conversations[i]; return null; }
    function active() { return conv(S.activeId); }
    function presenzaDi(c) {
        if (!c || c.type !== 'direct' || !c.members[0]) return null;
        return S.presenze[c.members[0].id] || { stato: 'offline', ultimoAccesso: null };
    }
    function sottotitolo(c) {
        if (c.type === 'group') return c.memberCount + ' membri';
        var ruolo = (c.members[0] && c.members[0].role) || '';
        var pr = presenzaDi(c);
        if (!pr) return ruolo;
        return [ruolo, pr.stato === 'offline' ? testoUltimoAccesso(pr.ultimoAccesso) : NOME_STATO[pr.stato]].filter(Boolean).join(' · ');
    }
    function mioStato() { return (S.presenze[me.id] && S.presenze[me.id].stato) || 'online'; }
    function statoMostrato() { return S.mioManuale === 'auto' ? 'online' : (S.mioManuale || mioStato()); }
    function modificabile(m) {
        return !!m.mine && !m.pending && !isTmp(m) && (m.kind || 'testo') === 'testo' && !m.attachment && !!m.body
            && Date.now() - new Date(m.createdAt).getTime() < MINUTI_MODIFICA * 60000;
    }
    function nomiTaggati(m) {
        var a = active();
        if (!m.mentions || !m.mentions.length || !a) return [];
        return m.mentions.map(function (uid) {
            if (uid === me.id) return me.name;
            var mb = a.members.filter(function (x) { return x.id === uid; })[0];
            return mb ? mb.name : '';
        }).filter(Boolean);
    }

    /* ---------------- Tempo reale ---------------- */
    var RT = { socket: null, connesso: false, giaConnesso: false, ascoltatori: {}, chatAperta: null, ultimoGesto: Date.now(), spento: !cfg.realtime, token: cfg.realtime && cfg.realtime.token };
    function suEvento(tipo, f) { (RT.ascoltatori[tipo] = RT.ascoltatori[tipo] || []).push(f); }
    function cambiaStato(stato) {
        if (RT.connesso === stato) return;
        RT.connesso = stato;
        riavviaPolling();
        // Connessione tornata: gli eventi persi non arrivano piu', si rilegge
        if (stato && RT.giaConnesso) riallinea();
        if (stato) RT.giaConnesso = true;
    }
    function tokenFresco() {
        return post('cora_rt_token', {}).then(function (r) { RT.token = r.token; return r.token; }).catch(function () { return RT.token; });
    }
    function avviaRealtime() {
        if (RT.spento || RT.socket || typeof window.io !== 'function') return;
        var primo = true;
        RT.socket = window.io({
            path: cfg.realtime.path,
            auth: function (cb) {
                // Il token si legge a ogni tentativo: dopo la scadenza si entra con uno nuovo
                if (primo && RT.token) { primo = false; cb({ token: RT.token, cliente: 'web' }); return; }
                tokenFresco().then(function (t) { cb({ token: t, cliente: 'web' }); });
            },
            reconnectionDelay: 1000,
            reconnectionDelayMax: 30000,
            randomizationFactor: 0.5
        });
        RT.socket.on('connect', function () {
            cambiaStato(true);
            if (RT.chatAperta) RT.socket.emit('chat.viewing', { conversationId: RT.chatAperta });
            segnalaAttivita();
        });
        RT.socket.on('disconnect', function (motivo) {
            cambiaStato(false);
            // Chiusa dal server (token scaduto): Socket.IO non riprova da solo
            if (motivo === 'io server disconnect') setTimeout(function () { if (RT.socket) RT.socket.connect(); }, 1000 + Math.random() * 1000);
        });
        RT.socket.on('connect_error', function (err) {
            cambiaStato(false);
            var m = err && err.message;
            if (m === 'realtime_off' || m === 'too_many_connections') { fermaRealtime(); return; }
            if (RT.socket && !RT.socket.active) setTimeout(function () { if (RT.socket) RT.socket.connect(); }, 3000);
        });
        RT.socket.on('evento', function (busta) {
            if (!busta || typeof busta.type !== 'string') return;
            (RT.ascoltatori[busta.type] || []).forEach(function (f) { try { f(busta.data, busta); } catch (e) { console.error(e); } });
        });
    }
    function fermaRealtime() {
        RT.spento = true;
        if (RT.socket) { RT.socket.removeAllListeners(); RT.socket.disconnect(); RT.socket = null; }
        cambiaStato(false);
    }
    function segnalaAttivita() {
        if (!RT.socket || !RT.socket.connected) return;
        RT.socket.emit('presence.activity', { attivo: document.visibilityState === 'visible' && Date.now() - RT.ultimoGesto < 120000 });
    }
    function segnalaChatAperta(id) {
        RT.chatAperta = id;
        if (RT.socket && RT.socket.connected) RT.socket.emit('chat.viewing', { conversationId: id });
    }
    function aggiornaGuardo() {
        var guardo = !!S.activeId && document.visibilityState === 'visible' && !(telefono && S.showListMobile);
        segnalaChatAperta(guardo ? S.activeId : null);
    }
    ['pointerdown', 'keydown', 'touchstart', 'wheel', 'mousemove'].forEach(function (e) {
        window.addEventListener(e, function () { RT.ultimoGesto = Date.now(); }, { passive: true });
    });
    setInterval(segnalaAttivita, 30000);

    /* Segnale di presenza ogni 45 secondi (stile Teams) */
    function inviaPresenza() {
        var attivo = document.visibilityState === 'visible' && Date.now() - RT.ultimoGesto < 5 * 60000;
        post('cora_presence_ping', { attivo: attivo ? 1 : '' }).then(function (r) {
            if (r && r.stato) { S.presenze[me.id] = { stato: r.stato, ultimoAccesso: new Date().toISOString() }; renderStato(); }
        }).catch(function () {});
    }
    function caricaPresenze() {
        post('cora_presence_all', {}).then(function (d) {
            if (d && typeof d === 'object' && !Array.isArray(d)) { S.presenze = d; renderList(); renderHeader(); renderStato(); }
        }).catch(function () {});
    }
    suEvento('presence.changed', function (d) {
        if (!d || !d.userId) return;
        S.presenze[d.userId] = { stato: d.stato, ultimoAccesso: d.ultimoAccesso };
        renderList(); renderHeader(); renderStato();
    });

    /* ---------------- Struttura della pagina ---------------- */
    root.innerHTML =
        '<div class="cc-box">' +
            '<aside class="cc-aside" data-aside>' +
                '<div data-pushbar></div>' +
                '<div class="cc-stato" data-stato></div>' +
                '<div data-unreadbar></div>' +
                '<div class="cc-lista" data-lista></div>' +
            '</aside>' +
            '<section class="cc-section" data-section></section>' +
        '</div>' +
        '<button type="button" class="cc-fab" data-fab aria-label="Nuova chat" hidden>' + img('nuovo-piu.svg', 'cc-s6') + '</button>';
    var asideEl = $('[data-aside]'), sectionEl = $('[data-section]'), listaEl = $('[data-lista]');

    /* ---------------- Barra notifiche push ---------------- */
    function renderPushBar() {
        var el = $('[data-pushbar]');
        var nascosta = false;
        try { nascosta = localStorage.getItem('push_bar_dismissed') === '1'; } catch (e) {}
        var off = window.PAMPush && 'Notification' in window && Notification.permission !== 'denied' && Notification.permission !== 'granted';
        if (nascosta || !off) { el.innerHTML = ''; return; }
        el.innerHTML = '<div class="cc-pushbar"><span class="cc-s4 cc-pushbar-ic">' + I.bell + '</span><span class="cc-pushbar-t">Attiva le notifiche per non perdere i messaggi.</span>' +
            '<button type="button" class="cc-pushbar-on" data-push-on>Attiva</button><button type="button" class="cc-pushbar-x" data-push-x title="Non mostrare più"><span class="cc-s35">' + I.x + '</span></button></div>';
        el.querySelector('[data-push-on]').onclick = function () {
            this.textContent = '...';
            Promise.resolve(window.PAMPush.subscribe && window.PAMPush.subscribe()).catch(function () {}).then(renderPushBar);
        };
        el.querySelector('[data-push-x]').onclick = function () {
            try { localStorage.setItem('push_bar_dismissed', '1'); } catch (e) {}
            renderPushBar();
        };
    }

    /* ---------------- Il tuo stato ---------------- */
    function renderStato() {
        var el = $('[data-stato]');
        var st = statoMostrato();
        var voci = [[null, 'online', 'Automatico', 'Disponibile quando usi l\'app'], ['occupato', 'occupato', 'Occupato', null], ['non_disturbare', 'non_disturbare', 'Non disturbare', null], ['assente', 'assente', 'Assente', null]];
        el.innerHTML = '<button type="button" class="cc-stato-btn" data-stato-btn aria-haspopup="menu" aria-expanded="' + S.menuStato + '">' +
            pallino(st, 12) + '<span class="cc-stato-l">Il tuo stato:</span><span class="cc-stato-v">' + esc(NOME_STATO[st]) + '</span><span class="cc-s4 cc-ink60">' + I.chevDown + '</span></button>' +
            (S.menuStato ? '<div class="cc-velo cc-z20" data-stato-velo></div><div role="menu" class="cc-stato-menu">' + voci.map(function (v) {
                var sel = (v[0] === null && (S.mioManuale === 'auto' || (!S.mioManuale && mioStato() === 'online'))) || (v[0] !== null && st === v[0]);
                return '<button type="button" role="menuitem" class="cc-stato-voce" data-stato-v="' + (v[0] || '') + '">' + pallino(v[1], 14, 'cc-mt05') +
                    '<span class="cc-stato-voce-t"><span class="cc-b">' + esc(v[2]) + '</span>' + (v[3] ? '<span class="cc-stato-nota">' + esc(v[3]) + '</span>' : '') + '</span>' +
                    (sel ? '<span class="cc-s4 cc-mt05">' + I.check + '</span>' : '') + '</button>';
            }).join('') + '</div>' : '');
    }
    asideEl.addEventListener('click', function (e) {
        if (e.target.closest('[data-stato-btn]')) { S.menuStato = !S.menuStato; renderStato(); return; }
        if (e.target.closest('[data-stato-velo]')) { S.menuStato = false; renderStato(); return; }
        var v = e.target.closest('[data-stato-v]');
        if (v) {
            var stato = v.getAttribute('data-stato-v') || null;
            S.menuStato = false;
            S.mioManuale = stato || 'auto';
            renderStato();
            post('cora_presence_status', { stato: stato || '' }).then(function (r) {
                if (r && r.stato) S.presenze[me.id] = { stato: r.stato, ultimoAccesso: new Date().toISOString() };
            }).catch(function () { S.mioManuale = null; renderStato(); });
            return;
        }
        if (e.target.closest('[data-read-all]')) segnaTutteLette();
    });

    /* ---------------- Elenco conversazioni ---------------- */
    function renderUnreadBar() {
        var el = $('[data-unreadbar]');
        var n = S.conversations.filter(function (c) { return c.unread > 0; }).length;
        el.innerHTML = n ? '<div class="cc-unreadbar"><span class="cc-unreadbar-t">' + (n === 1 ? '1 chat da leggere' : n + ' chat da leggere') + '</span>' +
            '<button type="button" data-read-all><span class="cc-s35">' + I.checkCheck + '</span> Segna tutte come lette</button></div>' : '';
    }
    function renderList() {
        renderUnreadBar();
        aggiornaBadgeDock();
        if (!S.conversations.length) {
            listaEl.innerHTML = '<div class="cc-vuota">' + maschera('icona-chat.svg', 'cc-s10') + '<p>Seleziona una conversazione oppure inizia una nuova chat</p></div>';
            return;
        }
        var largo = !telefono;
        var scroll = listaEl.scrollTop;
        listaEl.innerHTML = S.conversations.map(function (c) {
            var anteprima = c.lastMessagePreview || (c.type === 'group' ? c.memberCount + ' membri' : ((c.members[0] && c.members[0].role) || ''));
            var daLeggere = c.unread > 0;
            var pr = presenzaDi(c);
            return '<div class="cc-riga" data-conv="' + c.id + '">' +
                '<div class="cc-swipe" data-swipe="' + (daLeggere && !largo ? 1 : 0) + '">' +
                '<div class="cc-swipe-azione" aria-hidden="true"><span class="cc-s4">' + I.checkCheck + '</span><span data-swipe-l></span></div>' +
                '<button type="button" class="cc-riga-btn' + (largo && S.activeId === c.id ? ' is-attiva' : '') + '" data-apri="' + c.id + '">' +
                    '<span class="cc-riga-av">' + avatarConv(c, 56) + (pr ? pallino(pr.stato, 16, 'cc-riga-dot') : '') + '</span>' +
                    '<span class="cc-riga-corpo">' +
                        '<span class="cc-riga-testi"><span class="cc-riga-titolo">' + esc(c.title) + '</span><span class="cc-riga-prev">' + esc(anteprima) + '</span></span>' +
                        '<span class="cc-riga-meta"><span class="cc-riga-ora' + (daLeggere ? ' is-da-leggere' : '') + '">' + esc(fmtOraLista(c.lastMessageAt)) + '</span>' +
                        '<span class="cc-riga-flag">' + (c.pinned ? img('fissata.svg', 'cc-s4') : '') +
                        (daLeggere && c.mentioned ? '<span class="cc-chiocciola" title="Sei stato menzionato">@</span>' : '') +
                        (daLeggere ? '<span class="cc-nonletti">' + c.unread + '</span>' : '') + '</span></span>' +
                    '</span>' +
                '</button></div>' +
                (largo ? '<button type="button" class="cc-riga-piu' + (S.convMenu === c.id ? ' is-on' : '') + '" data-conv-menu="' + c.id + '" aria-label="Azioni"><span class="cc-s4">' + I.more + '</span></button>' +
                    (S.convMenu === c.id ? '<div class="cc-velo cc-z10" data-conv-velo></div><div class="cc-conv-menu">' +
                        (daLeggere ? '<button type="button" data-letta="' + c.id + '"><span class="cc-s4">' + I.checkCheck + '</span> Segna come letta</button>' : '') +
                        '<button type="button" data-fissa="' + c.id + '"><span class="cc-s4">' + (c.pinned ? I.pinOff : I.pin) + '</span> ' + (c.pinned ? 'Rimuovi in alto' : 'Fissa in alto') + '</button>' +
                        '<button type="button" class="is-rosso" data-elimina-conv="' + c.id + '"><span class="cc-s4">' + I.trash + '</span> Elimina</button>' +
                    '</div>' : '') : '') +
            '</div>';
        }).join('');
        listaEl.scrollTop = scroll;
        attivaSwipe();
    }
    listaEl.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-conv-menu]'))) { e.stopPropagation(); var id = +t.getAttribute('data-conv-menu'); S.convMenu = S.convMenu === id ? null : id; renderList(); return; }
        if (e.target.closest('[data-conv-velo]')) { S.convMenu = null; renderList(); return; }
        if ((t = e.target.closest('[data-letta]'))) { segnaLetta(+t.getAttribute('data-letta')); return; }
        if ((t = e.target.closest('[data-fissa]'))) { togglePin(+t.getAttribute('data-fissa')); return; }
        if ((t = e.target.closest('[data-elimina-conv]'))) { deleteConv(+t.getAttribute('data-elimina-conv')); return; }
        if ((t = e.target.closest('[data-apri]'))) apriDaElenco(+t.getAttribute('data-apri'));
    });

    /* Riga che si trascina a sinistra col dito: segna come letta (RigaSwipe) */
    function attivaSwipe() {
        listaEl.querySelectorAll('[data-swipe="1"]').forEach(function (w) {
            var btn = w.querySelector('.cc-riga-btn'), az = w.querySelector('.cc-swipe-azione');
            var inizio = null, dx = 0, trascinato = false;
            function set(v) {
                dx = v;
                btn.style.transform = 'translateX(' + v + 'px)';
                btn.style.transition = v === 0 ? 'transform .2s ease' : 'none';
                az.style.width = (Math.max(0, -v) + 24) + 'px';
                az.style.opacity = v < 0 ? 1 : 0;
                az.querySelector('[data-swipe-l]').textContent = -v >= 80 ? 'Letta' : '';
            }
            w.addEventListener('touchstart', function (e) { var t = e.touches[0]; inizio = { x: t.clientX, y: t.clientY, oriz: null }; trascinato = false; }, { passive: true });
            w.addEventListener('touchmove', function (e) {
                if (!inizio) return;
                var t = e.touches[0], ddx = t.clientX - inizio.x, ddy = t.clientY - inizio.y;
                if (inizio.oriz === null && (Math.abs(ddx) > 8 || Math.abs(ddy) > 8)) inizio.oriz = Math.abs(ddx) > Math.abs(ddy);
                if (inizio.oriz && ddx < 0) { trascinato = true; set(Math.max(-120, ddx)); }
            }, { passive: true });
            w.addEventListener('touchend', function () { if (dx <= -80) segnaLetta(+btn.getAttribute('data-apri')); set(0); inizio = null; });
            w.addEventListener('touchcancel', function () { set(0); inizio = null; });
            w.addEventListener('click', function (e) { if (trascinato) { e.stopPropagation(); e.preventDefault(); trascinato = false; } }, true);
        });
    }

    function aggiornaBadgeDock() {
        var n = S.conversations.reduce(function (a, c) { return a + (c.unread || 0); }, 0);
        // voce Chat del dock e del foglio "Altro" del telefono
        document.querySelectorAll('.cora-dock-item[href$="/employee/chat.php"], .cora-more-item[href$="/employee/chat.php"]').forEach(function (item) {
            var b = item.querySelector('.cora-dock-badge');
            if (!n) { if (b) b.remove(); return; }
            if (!b) { b = document.createElement('span'); b.className = 'cora-dock-badge'; item.insertBefore(b, item.querySelector('.cora-more-chev')); }
            b.textContent = n > 9 ? '9+' : n;
        });
    }

    function loadConversations() {
        return post('cora_list', {}).then(function (d) {
            if (!Array.isArray(d)) return;
            S.conversations = d;
            // La chat aperta non conta come da leggere
            if (S.activeId && document.visibilityState === 'visible' && !(telefono && S.showListMobile)) {
                S.conversations.forEach(function (c) { if (c.id === S.activeId) c.unread = 0; });
            }
            renderList(); renderHeader();
        }).catch(function () {});
    }
    function loadContacts() {
        return post('cora_contacts', {}).then(function (d) { if (Array.isArray(d)) S.contacts = d; }).catch(function () {});
    }
    var attesaElenco;
    function ricaricaElenco() { clearTimeout(attesaElenco); attesaElenco = setTimeout(loadConversations, 300); }

    function segnaLetta(convId) {
        S.convMenu = null;
        S.conversations.forEach(function (c) { if (c.id === convId) c.unread = 0; });
        renderList();
        post('cora_read', { conversation_id: convId }).catch(function () {});
    }
    function segnaTutteLette() {
        S.conversations.forEach(function (c) { c.unread = 0; });
        renderList();
        post('cora_read_all', {}).catch(function () {});
    }
    function togglePin(convId) {
        S.convMenu = null;
        renderList();
        post('cora_pin', { conversation_id: convId }).then(loadConversations).catch(function () {});
    }
    function deleteConv(convId) {
        S.convMenu = null;
        post('cora_hide', { conversation_id: convId }).then(function () {
            if (S.activeId === convId) { S.activeId = null; S.showListMobile = true; renderThread(); aggiornaLayout(); history.replaceState(null, '', location.pathname); }
            loadConversations();
        }).catch(function () {});
    }

    /* ---------------- Apertura di una conversazione ---------------- */
    function salvaBozza() {
        var prima = S.activeId;
        if (!prima) return;
        var ta = $('[data-composer-ta]');
        var testo = S.editing ? S.editing.bozza : (ta ? ta.value : '');
        if (testo || S.replyTo || S.pendingFile) bozzePerChat.set(prima, { draft: testo, replyTo: S.replyTo, pendingFile: S.pendingFile });
        else bozzePerChat.delete(prima);
    }

    function apriDaElenco(id) {
        if (telefono) history.pushState({ chatAperta: true }, '', location.pathname + '?conv=' + id);
        else history.replaceState(null, '', location.pathname + '?conv=' + id);
        openConversation(id);
    }
    function tornaAllElenco() {
        if (history.state && history.state.chatAperta) { history.back(); return; }
        S.showListMobile = true;
        aggiornaLayout(); aggiornaGuardo();
        history.replaceState(null, '', location.pathname);
    }
    window.addEventListener('popstate', function () {
        var m = /[?&]conv=(\d+)/.exec(location.search);
        if (m) openConversation(+m[1]);
        else { S.showListMobile = true; aggiornaLayout(); aggiornaGuardo(); }
    });

    function openConversation(id) {
        inFondoRef = true; S.inFondo = true;
        var c = conv(id);
        var daLeggere = c ? c.unread : 0;
        if (!S.nonLetti || S.nonLetti.convId !== id) S.nonLetti = null;
        if (S.activeId !== id) {
            salvaBozza();
            S.activeId = id;
            var salvata = bozzePerChat.get(id);
            S.replyTo = salvata ? salvata.replyTo : null;
            S.pendingFile = salvata ? salvata.pendingFile : null;
            S.editing = null; S.avvisoInvio = null; S.menzione = null; S.menzionati = [];
            S.emojiOpen = false; S.attachOpen = false; S.gifOpen = false; S.foglioAllegati = false;
            S.showListMobile = false;
            renderThread(salvata ? salvata.draft : '');
        }
        S.showListMobile = false;
        S.haPrecedenti = false; S.errorePrecedenti = false; S.caricandoPrecedenti = false;
        var gia = (cacheMessaggi.get(id) || []).slice(-PAGINA);
        if (gia.length) {
            S.messages = gia; S.loadingMessages = false;
            lastMsgId = gia[gia.length - 1].id;
            renderMessages();
            if (!daLeggere) scrollToBottom();
        } else {
            S.messages = []; S.loadingMessages = true; lastMsgId = null;
            renderMessages();
        }
        renderList(); aggiornaLayout(); aggiornaGuardo(); adattaAltezza();
        post('cora_messages', { conversation_id: id, limit: PAGINA }).then(function (msgs) {
            msgs = Array.isArray(msgs) ? msgs : [];
            cacheMessaggi.set(id, msgs);
            if (S.activeId !== id) return;
            var altrui = msgs.filter(function (m) { return !m.mine; });
            var primo = daLeggere > 0 && altrui.length ? altrui[Math.max(0, altrui.length - daLeggere)] : null;
            if (primo) S.nonLetti = { convId: id, id: primo.id, n: daLeggere };
            S.messages = msgs;
            S.haPrecedenti = msgs.length >= PAGINA;
            lastMsgId = msgs.length ? msgs[msgs.length - 1].id : null;
            S.loadingMessages = false;
            renderMessages();
            if (primo) vaiANonLetti(primo.id); else scrollToBottom();
            return post('cora_read', { conversation_id: id }).then(function () {
                S.conversations.forEach(function (x) { if (x.id === id) x.unread = 0; });
                renderList(); renderHeader();
            });
        }).catch(function () {}).then(function () {
            if (S.activeId === id && S.loadingMessages) { S.loadingMessages = false; renderMessages(); }
        });
    }

    function aggiornaLayout() {
        root.classList.toggle('is-lista', S.showListMobile);
        root.classList.toggle('is-conversazione', !S.showListMobile);
        document.body.classList.toggle('cc-in-conversazione', telefono && !S.showListMobile);
        root.querySelector('[data-fab]').hidden = !(telefono && S.showListMobile);
        adattaAltezza();
    }

    /* ---------------- Conversazione ---------------- */
    function renderThread(draft) {
        var a = active();
        if (!a) {
            sectionEl.innerHTML = '<div class="cc-nessuna"><div><span class="cc-nessuna-ic">' + I.msg + '</span><p class="cc-nessuna-t">Seleziona una conversazione</p><p class="cc-nessuna-s">oppure inizia una nuova chat</p></div></div>';
            return;
        }
        sectionEl.innerHTML =
            '<div class="cc-trascina" data-trascina hidden><p>Rilascia qui per allegare a ' + esc(a.title) + '</p></div>' +
            '<div data-header></div>' +
            '<div class="cc-corpo">' +
                '<div class="cc-sfondo" aria-hidden="true" style="background-image:url(&quot;' + A + 'sfondo-chat.jpg&quot;)"></div>' +
                '<div class="cc-scroll" data-scroll><div class="cc-contenuto" data-contenuto></div></div>' +
                '<button type="button" class="cc-giu" data-giu aria-label="Vai all\'ultimo messaggio" hidden><span class="cc-s5">' + I.chevDown + '</span></button>' +
            '</div>' +
            '<div class="cc-composer" data-composer>' +
                '<div data-avviso></div><div data-menzioni></div><div data-emoji></div><div data-gif></div>' +
                '<div data-banner></div><div data-pending></div>' +
                '<div data-riga></div>' +
            '</div>' +
            '<input type="file" hidden data-file="foto" accept="image/*,video/*">' +
            '<input type="file" hidden data-file="camera" accept="image/*" capture="environment">' +
            '<input type="file" hidden data-file="doc">';
        renderHeader();
        renderRiga(draft || '');
        renderComposerExtra();
        var sc = $('[data-scroll]');
        sc.addEventListener('scroll', suScrollMessaggi, { passive: true });
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function () { if (inFondoRef) vaiInFondo(); });
            ro.observe(sc); ro.observe($('[data-contenuto]'));
        }
    }

    function renderHeader() {
        var el = $('[data-header]'), a = active();
        if (!el || !a) return;
        var pr = presenzaDi(a);
        var altre = S.conversations.filter(function (c) { return c.id !== S.activeId && c.unread > 0; }).length;
        if (telefono) {
            el.innerHTML = '<div class="cc-th-tel">' +
                '<div class="cc-th-tel-sx">' +
                    '<button type="button" data-indietro aria-label="Torna alle chat" class="cc-th-indietro">' + img('indietro.svg', 'cc-s8') +
                    (altre > 0 ? '<span class="cc-th-altre">' + altre + '</span>' : '') + '</button>' +
                    '<button type="button" data-info class="cc-th-chi cc-th-chi-tel">' +
                        '<span class="cc-rel">' + avatarConv(a, 36) + (pr ? pallino(pr.stato, 12, 'cc-th-dot') : '') + '</span>' +
                        '<span class="cc-th-testi"><span class="cc-th-tel-t">' + esc(a.title) + '</span><span class="cc-th-tel-s">' + esc(sottotitolo(a)) + '</span></span>' +
                    '</button>' +
                '</div>' +
                (a.type === 'group' ? '<button type="button" data-info aria-label="Dettagli del gruppo" class="cc-th-piu"><span class="cc-s5">' + I.more + '</span></button>' : '') +
            '</div>';
        } else {
            el.innerHTML = '<div class="cc-th">' +
                '<button type="button" data-info class="cc-th-chi">' +
                    (a.type === 'group'
                        ? (a.avatarUrl ? '<img src="' + esc(a.avatarUrl) + '" alt="" class="cc-th-gruppo-img">' : '<span class="cc-th-gruppo"><span class="cc-s4">' + I.users + '</span></span>')
                        : '<span class="cc-rel">' + avatarHtml(a.title, a.avatarUrl, 32, a.members[0] && a.members[0].id) + (pr ? pallino(pr.stato, 12, 'cc-th-dot') : '') + '</span>') +
                    '<span class="cc-th-testi cc-th-desk"><span class="cc-th-t">' + esc(a.title) + '</span><span class="cc-th-s">' + esc(sottotitolo(a)) + '</span></span>' +
                '</button>' +
                (a.type === 'group' ? '<button type="button" data-info aria-label="Dettagli del gruppo" class="cc-th-piu-desk"><span class="cc-s4">' + I.more + '</span></button>' : '') +
            '</div>';
        }
    }

    /* Aggancio in fondo, come WhatsApp */
    function vaiInFondo(smooth) {
        var el = $('[data-scroll]'); if (!el) return;
        scorrimentoMio = Date.now();
        if (smooth) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' }); else el.scrollTop = el.scrollHeight;
    }
    function scrollToBottom(smooth) {
        inFondoRef = true; S.inFondo = true; aggiornaGiu();
        vaiInFondo(smooth);
        requestAnimationFrame(function () { requestAnimationFrame(function () { if (inFondoRef) vaiInFondo(smooth); }); });
    }
    function vaiANonLetti(id) {
        requestAnimationFrame(function () { requestAnimationFrame(function () {
            var el = $('[data-scroll]'), nodo = el && el.querySelector('[data-msg="' + id + '"]');
            if (!el || !nodo) { scrollToBottom(); return; }
            scorrimentoMio = Date.now();
            el.scrollTop = Math.max(0, nodo.offsetTop - 8);
            var vicino = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
            inFondoRef = vicino; S.inFondo = vicino; aggiornaGiu();
        }); });
    }
    function aggiornaGiu() {
        var b = $('[data-giu]');
        if (b) b.hidden = S.inFondo || !S.messages.length;
    }
    function suScrollMessaggi() {
        var el = $('[data-scroll]'); if (!el) return;
        if (el.scrollTop < 300 && S.haPrecedenti && !S.errorePrecedenti) caricaPrecedenti();
        if (Date.now() - scorrimentoMio < 700) return;
        var vicino = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        inFondoRef = vicino;
        if (vicino !== S.inFondo) { S.inFondo = vicino; aggiornaGiu(); }
    }

    function caricaPrecedenti() {
        var id = S.activeId;
        var piuVecchio = S.messages.filter(function (m) { return !m.pending && !isTmp(m); })[0];
        if (!id || !piuVecchio || caricandoPrecedentiRef) return;
        caricandoPrecedentiRef = true;
        S.caricandoPrecedenti = true; S.errorePrecedenti = false;
        renderMessages(true);
        post('cora_messages', { conversation_id: id, before: piuVecchio.id, limit: PAGINA }).then(function (vecchi) {
            vecchi = Array.isArray(vecchi) ? vecchi : [];
            if (S.activeId !== id) return;
            var conosciuti = new Set(S.messages.map(function (m) { return m.id; }));
            var nuovi = vecchi.filter(function (m) { return !conosciuti.has(m.id); });
            if (nuovi.length) S.messages = nuovi.concat(S.messages);
            S.haPrecedenti = vecchi.length >= PAGINA && nuovi.length > 0;
            S.caricandoPrecedenti = false;
            renderMessages(true);
        }).catch(function () {
            if (S.activeId === id) { S.errorePrecedenti = true; S.caricandoPrecedenti = false; renderMessages(true); }
        }).then(function () { caricandoPrecedentiRef = false; });
    }

    /* Disegna i messaggi. tieniPosto: aggiunte in cima, la vista non salta. */
    function renderMessages(tieniPosto) {
        var cont = $('[data-contenuto]'), a = active(), el = $('[data-scroll]');
        if (!cont || !a) return;
        var distanza = tieniPosto && el ? el.scrollHeight - el.scrollTop : null;
        var html = '';
        var msgs = S.messages;
        if (!S.loadingMessages && (S.haPrecedenti || S.errorePrecedenti || msgs.length > PAGINA)) {
            html += '<div class="cc-precedenti">' + (S.caricandoPrecedenti
                ? '<span class="cc-pillola"><span class="cc-spin"></span>Carico i messaggi precedenti…</span>'
                : S.errorePrecedenti
                    ? '<button type="button" class="cc-pillola" data-riprova-prec>Messaggi precedenti non caricati · <span class="cc-riprova">Riprova</span></button>'
                    : !S.haPrecedenti ? '<span class="cc-pillola">Inizio della conversazione</span>' : '') + '</div>';
        }
        msgs.forEach(function (m, i) {
            var prev = msgs[i - 1], next = msgs[i + 1];
            var showDate = !prev || new Date(prev.createdAt).toDateString() !== new Date(m.createdAt).toDateString();
            var lastOfRun = !next || next.senderId !== m.senderId || next.mine !== m.mine;
            var firstOfRun = !prev || prev.senderId !== m.senderId || prev.mine !== m.mine || showDate;
            var tipo = (m.attachment && m.attachment.type) || '';
            var isImage = tipo.indexOf('image/') === 0;
            var isAudio = tipo.indexOf('audio/') === 0;
            var letto = !!m.mine && !!m.seenBy && m.seenBy.length > 0;
            var conBox = !!(m.replyTo || m.attachment);
            var soloImmagine = !!m.attachment && isImage && !m.body;
            var nomeMittente = a.type === 'group' && !m.mine && firstOfRun ? m.senderName : null;
            var spazioOra = 44 + (m.mine ? 19 : 0) + (m.editedAt ? 70 : 0);

            html += '<div data-msg="' + esc(m.id) + '" class="cc-msg">';
            if (S.nonLetti && S.nonLetti.convId === S.activeId && S.nonLetti.id === m.id) {
                html += '<div class="cc-nonletti-fascia"><span>' + (S.nonLetti.n === 1 ? '1 messaggio non letto' : S.nonLetti.n + ' messaggi non letti') + '</span></div>';
            }
            if (showDate) html += '<div class="cc-giorno"><span>' + esc(etichettaGiorno(m.createdAt)) + '</span></div>';
            html += '<div class="cc-rigabolla ' + (m.mine ? 'is-mio' : 'is-altro') + (lastOfRun ? ' is-ultimo' : '') + '">';
            html += '<div class="cc-scorri" data-scorri><span class="cc-scorri-ic" aria-hidden="true"><span class="cc-s4">' + I.reply + '</span></span><div class="cc-scorri-dentro">';
            html += '<div role="button" tabindex="0" data-bolla class="cc-bolla' + (m.mine ? ' is-mio' : '') + (conBox ? ' con-box' : '') + (soloImmagine ? ' solo-foto' : '') + (m.pending ? ' in-attesa' : '') + (m.internal ? ' is-interno' : '') + '">';
            if (lastOfRun) html += '<img src="' + A + (m.mine ? 'coda-mia.svg' : 'coda-altro.svg') + '" alt="" aria-hidden="true" class="cc-coda">';
            if (nomeMittente) html += '<p class="cc-mittente' + (conBox ? ' cc-pbox' : '') + '" style="color:' + colorFor(m.senderId) + '">' + esc(nomeMittente) + '</p>';
            if (m.forwarded) html += '<p class="cc-inoltrato' + (conBox ? ' cc-pbox' : '') + '">' + img('az-inoltra.svg', 'cc-s3 cc-o60') + ' Inoltrato</p>';
            if (m.replyTo) {
                var nome = m.replyTo.senderId && m.replyTo.senderId === me.id ? 'Tu' : m.replyTo.senderName;
                html += '<div class="cc-citazione" data-salta="' + esc(m.replyTo.id) + '"><span class="cc-citazione-barra" style="background:' + coloreCitazione(nome) + '"></span>' +
                    '<span class="cc-citazione-t"><span class="cc-citazione-n" style="color:' + coloreCitazione(nome) + '">' + esc(nome) + '</span><span class="cc-citazione-b">' + esc(m.replyTo.body) + '</span></span></div>';
            }
            if (m.attachment && isImage) {
                var u = m.attachment.data || m.attachment.url || '';
                var src = u.indexOf('?download_attachment=') === 0 ? u + '&inline=1' : u;
                html += '<span class="cc-foto" data-foto data-nome="' + esc(m.attachment.name) + '">' +
                    (src ? '<img src="' + esc(src) + '" alt="' + esc(m.attachment.name) + '" loading="lazy" data-img-retry>' : '<span class="cc-foto-attesa"></span>') + '</span>';
            }
            if (m.attachment && isAudio) html += '<span class="cc-voce-box">' + voiceHtml(m) + '</span>';
            if (m.attachment && !isImage && !isAudio) {
                html += '<button type="button" class="cc-file" data-scarica="' + esc(m.attachment.url || '') + '"><span class="cc-file-ic"><span class="cc-s5">' + I.fileText + '</span></span>' +
                    '<span class="cc-file-t"><span class="cc-file-n">' + esc(m.attachment.name) + '</span>' + (m.attachment.size ? '<span class="cc-file-d">' + esc(fmtSize(m.attachment.size)) + '</span>' : '') + '</span>' +
                    '<span class="cc-s4 cc-ink50">' + I.download + '</span></button>';
            }
            if (m.body) {
                html += '<p class="cc-testo' + (conBox ? ' cc-pbox3' : '') + '">' + testoConMenzioni(m.body, nomiTaggati(m), me.name) +
                    '<span aria-hidden="true" class="cc-spazio-ora" style="width:' + spazioOra + 'px"></span></p>';
            }
            if (!m.body && !soloImmagine) html += '<span class="cc-h4"></span>';
            html += '<span class="cc-ora' + (soloImmagine ? ' su-foto' : '') + '">' + esc(m.pending ? 'invio…' : (m.editedAt ? 'modificato · ' : '') + fmtMsgTime(m.createdAt)) +
                (m.mine && !m.pending ? '<img src="' + A + 'letto.svg" alt="' + (letto ? 'Letto' : 'Inviato') + '" class="cc-spunte' + (letto ? '' : ' is-grigio') + '">' : '') + '</span>';
            html += '</div></div></div>';
            if (m.reactions && m.reactions.length) {
                html += '<div class="cc-reazioni">' + m.reactions.map(function (r) {
                    return '<button type="button" class="cc-reazione' + (r.mine ? ' is-mia' : '') + '" data-reazione="' + esc(r.emoji) + '" title="' + esc((r.users || []).join(', ')) + '">' +
                        '<span class="cc-reazione-e">' + esc(r.emoji) + '</span>' + (r.count > 1 ? '<span class="cc-reazione-n">' + r.count + '</span>' : '') + '</button>';
                }).join('') + '</div>';
            }
            html += '</div></div>';
        });
        if (S.loadingMessages) {
            html += '<div class="cc-skel"><div><i class="w52"></i></div><div class="dx"><i class="w40"></i></div><div><i class="w52"></i></div></div>';
        } else if (!msgs.length) {
            html += '<div class="cc-primo">' + maschera('icona-chat.svg', 'cc-s10') + '<p>Scrivi il primo messaggio</p></div>';
        }
        cont.innerHTML = html;
        if (distanza !== null) el.scrollTop = el.scrollHeight - distanza;
        idrataVoci();
        attivaScorriRispondi();
        cont.querySelectorAll('[data-img-retry]').forEach(function (im) {
            var n = 0;
            im.addEventListener('error', function () {
                if (im.src.indexOf('blob:') === 0) return;
                if (n >= 3) {
                    im.parentNode.innerHTML = '<span class="cc-foto-err">Immagine non disponibile <button type="button" data-foto-riprova>Riprova</button></span>';
                    return;
                }
                var attesa = [1500, 4000, 8000][n++];
                setTimeout(function () { im.src = im.src.replace(/[&?]t=\d+$/, '') + (im.src.indexOf('?') === -1 ? '?' : '&') + 't=' + n; }, attesa);
            });
        });
        // Senza abbastanza messaggi per scorrere, i precedenti si chiedono subito
        if (el && S.haPrecedenti && !S.errorePrecedenti && !S.loadingMessages && !S.caricandoPrecedenti && el.scrollHeight <= el.clientHeight) setTimeout(caricaPrecedenti, 0);
        aggiornaGiu();
    }

    /* Messaggio vocale: play, barra, durata scritta nel nome (VoiceBubble) */
    function voiceHtml(m) {
        var d = /\((\d+):(\d{2})\)/.exec(m.attachment.name || '');
        var tot = d ? Number(d[1]) * 60 + Number(d[2]) : 0;
        var u = m.attachment.url || m.attachment.data || '';
        if (u.indexOf('?download_attachment=') === 0) u += '&inline=1';
        return '<span class="cc-voce" data-voce="' + esc(u) + '" data-tot="' + tot + '">' +
            '<button type="button" class="cc-voce-btn"' + (u ? '' : ' disabled') + '>' + (u ? '<span class="cc-s4">' + I.play + '</span>' : '<span class="cc-spin cc-spin4"></span>') + '</button>' +
            '<span class="cc-voce-dx"><span class="cc-voce-barra"><span class="cc-voce-pieno"></span></span><span class="cc-voce-t">' + fmtRecTime(tot) + '</span></span></span>';
    }
    var audioInCorso = null;
    function idrataVoci() {
        $('[data-contenuto]').querySelectorAll('[data-voce]').forEach(function (v) {
            var a = null, total = +v.getAttribute('data-tot') || 0, btn = v.querySelector('button'), pieno = v.querySelector('.cc-voce-pieno'), t = v.querySelector('.cc-voce-t');
            var misura = false;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!a) {
                    a = new Audio(v.getAttribute('data-voce'));
                    a.preload = 'metadata';
                    a.addEventListener('play', function () { btn.innerHTML = '<span class="cc-s4">' + I.pause + '</span>'; });
                    a.addEventListener('pause', function () { btn.innerHTML = '<span class="cc-s4">' + I.play + '</span>'; });
                    a.addEventListener('ended', function () { pieno.style.width = '0'; t.textContent = fmtRecTime(Math.round(total)); });
                    a.addEventListener('loadedmetadata', function () {
                        // Chrome scrive i webm senza durata: si salta in fondo per fargliela calcolare
                        if (isFinite(a.duration)) { total = a.duration; return; }
                        misura = true; a.currentTime = 1e101;
                    });
                    a.addEventListener('durationchange', function () {
                        if (misura && isFinite(a.duration)) { total = a.duration; misura = false; a.currentTime = 0; }
                    });
                    a.addEventListener('timeupdate', function () {
                        if (misura) return;
                        if (total > 0) pieno.style.width = Math.min(100, a.currentTime / total * 100) + '%';
                        t.textContent = fmtRecTime(Math.round(a.currentTime));
                    });
                    a.addEventListener('error', function () { t.textContent = 'Non disponibile · tocca per riprovare'; a = null; });
                }
                if (!a.paused) { a.pause(); return; }
                if (misura) { misura = false; a.currentTime = 0; }
                if (audioInCorso && audioInCorso !== a) audioInCorso.pause();
                audioInCorso = a;
                a.play().catch(function () {});
            });
        });
    }

    /* Telefono: bolla trascinata a destra per rispondere (ScorriRispondi) */
    function attivaScorriRispondi() {
        if (!telefono) return;
        $('[data-contenuto]').querySelectorAll('[data-scorri]').forEach(function (w) {
            var dentro = w.querySelector('.cc-scorri-dentro'), ic = w.querySelector('.cc-scorri-ic');
            var inizio = null, dx = 0, appena = false, SOGLIA = 56;
            var id = w.closest('[data-msg]').getAttribute('data-msg');
            function set(v) {
                dx = v;
                dentro.style.transform = 'translateX(' + v + 'px)';
                dentro.style.transition = v ? 'none' : 'transform 0.2s ease-out';
                ic.style.opacity = Math.min(1, v / SOGLIA);
                ic.style.transform = 'translateY(-50%) scale(' + (v >= SOGLIA ? 1.1 : 0.8 + 0.2 * Math.min(1, v / SOGLIA)) + ')';
            }
            w.addEventListener('touchstart', function (e) { var t = e.touches[0]; inizio = t.clientX < 28 ? null : { x: t.clientX, y: t.clientY, verso: null }; }, { passive: true });
            w.addEventListener('touchmove', function (e) {
                if (!inizio) return;
                var t = e.touches[0], ddx = t.clientX - inizio.x, ddy = t.clientY - inizio.y;
                if (!inizio.verso) {
                    if (Math.abs(ddx) < 8 && Math.abs(ddy) < 8) return;
                    inizio.verso = Math.abs(ddx) > Math.abs(ddy) && ddx > 0 ? 'h' : 'v';
                }
                if (inizio.verso === 'h') set(Math.min(80, Math.max(0, ddx)));
            }, { passive: true });
            w.addEventListener('touchend', function () {
                if (dx >= SOGLIA) {
                    var m = trovaMsg(id);
                    if (m && !m.pending) { S.editing = null; S.replyTo = m; renderComposerExtra(); focusTa(); }
                    try { if (navigator.vibrate) navigator.vibrate(10); } catch (e) {}
                    appena = true; setTimeout(function () { appena = false; }, 350);
                }
                inizio = null; set(0);
            });
            w.addEventListener('click', function (e) { if (appena || dx > 4) { e.stopPropagation(); e.preventDefault(); } }, true);
        });
    }

    function trovaMsg(id) {
        for (var i = 0; i < S.messages.length; i++) if (String(S.messages[i].id) === String(id)) return S.messages[i];
        return null;
    }

    /* Clic nella conversazione */
    sectionEl.addEventListener('click', function (e) {
        var t;
        if (e.target.closest('[data-indietro]')) { tornaAllElenco(); return; }
        if (e.target.closest('[data-info]')) { openInfo(); return; }
        if (e.target.closest('[data-giu]')) { scrollToBottom(true); return; }
        if (e.target.closest('[data-composer]')) { clicComposer(e); return; }
        if (e.target.closest('[data-riprova-prec]')) { caricaPrecedenti(); return; }
        if ((t = e.target.closest('[data-reazione]'))) { toggleReaction(t.closest('[data-msg]').getAttribute('data-msg'), t.getAttribute('data-reazione')); return; }
        if ((t = e.target.closest('[data-foto]'))) {
            e.stopPropagation();
            if (e.target.closest('[data-foto-riprova]')) { renderMessages(); return; }
            var im = t.querySelector('img');
            if (im) setViewer(im.src, t.getAttribute('data-nome'));
            return;
        }
        if ((t = e.target.closest('[data-scarica]'))) {
            e.stopPropagation();
            var u = t.getAttribute('data-scarica');
            if (u && u !== '#') window.open(u, '_blank');
            return;
        }
        if (e.target.closest('.cc-voce')) return;
        if ((t = e.target.closest('[data-bolla]'))) {
            var m = trovaMsg(t.closest('[data-msg]').getAttribute('data-msg'));
            if (!m || (m.pending && !isTmp(m))) return;
            if (window.getSelection && String(window.getSelection()).length) return;
            apriMenuAzioni(m, t.getBoundingClientRect());
        }
    });
    sectionEl.addEventListener('keydown', function (e) {
        var b = e.target.closest && e.target.closest('[data-bolla]');
        if (b && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); b.click(); }
    });

    /* ---------------- Menu del messaggio (MenuAzioniMessaggio) ---------------- */
    function azioniDi(m) {
        var v = [];
        if (!m.pending) {
            v.push({ label: 'Rispondi', icona: 'az-rispondi.svg', f: function () { S.editing = null; S.replyTo = m; renderComposerExtra(); focusTa(); } });
            v.push({ label: 'Inoltra', icona: 'az-inoltra.svg', f: function () { apriInoltra(m); } });
        }
        if (m.body) v.push({ label: 'Copia', icona: 'az-copia.svg', f: function () { try { navigator.clipboard.writeText(m.body); } catch (e) {} } });
        if (m.mine && !m.pending) v.push({ label: 'Info', icona: 'az-info.svg', f: function () { apriVisto(m.seenBy || []); } });
        if (modificabile(m)) v.push({ label: 'Modifica', icona: 'modifica', f: function () { iniziaModifica(m); } });
        if (m.mine && (!m.seenBy || m.seenBy.length === 0)) v.push({ label: 'Elimina', icona: 'az-elimina.svg', pericolo: true, f: function () { deleteMessage(m); } });
        return v;
    }
    function apriMenuAzioni(m, rect) {
        var LARGHEZZA = 230, MARGINE = 8;
        var vw = innerWidth, vh = innerHeight;
        var sinistra = Math.min(Math.max(MARGINE, m.mine ? rect.right - LARGHEZZA : rect.left), vw - LARGHEZZA - MARGINE);
        var azioni = azioniDi(m);
        var ov = document.createElement('div');
        ov.className = 'cc-menu-az';
        var tutte = false;
        function barraHtml() {
            if (m.pending) return '';
            return '<div class="cc-menu-barra" data-barra style="left:' + sinistra + 'px;width:' + LARGHEZZA + 'px">' + (tutte
                ? '<div class="cc-menu-tutte">' + EMOJI_CATEGORIES[0].emojis.map(function (e) { return '<button type="button" data-r="' + e + '">' + e + '</button>'; }).join('') + '</div>'
                : '<div class="cc-menu-rapide">' + REAZIONI.map(function (e) { return '<button type="button" data-r="' + e + '">' + e + '</button>'; }).join('') +
                    '<button type="button" class="cc-menu-piu" data-tutte aria-label="Altre reazioni">' + img('reazione-piu.svg', 'cc-s5') + '</button></div>') + '</div>';
        }
        function disegna() {
            ov.innerHTML = '<div class="cc-menu-velo"></div>' + barraHtml() +
                '<div class="cc-menu-lista" data-lista-az style="left:' + sinistra + 'px;width:' + LARGHEZZA + 'px">' + azioni.map(function (a, i) {
                    return '<button type="button" data-az="' + i + '">' + (a.icona === 'modifica' ? '<span class="cc-s4">' + I.pencil + '</span>' : img(a.icona, 'cc-s4')) +
                        '<span class="' + (a.pericolo ? 'cc-pericolo' : '') + '">' + esc(a.label) + '</span></button>';
                }).join('') + '</div>';
            var barra = ov.querySelector('[data-barra]'), menu = ov.querySelector('[data-lista-az]');
            var hBarra = barra ? barra.offsetHeight : 0, hMenu = menu.offsetHeight;
            var top = rect.top - hBarra - MARGINE, mtop = rect.bottom + MARGINE;
            if (mtop + hMenu > vh - MARGINE) { mtop = Math.max(MARGINE + hBarra + MARGINE, rect.top - hMenu - MARGINE); top = mtop - hBarra - MARGINE; }
            if (top < MARGINE) top = MARGINE;
            if (barra) barra.style.top = top + 'px';
            menu.style.top = Math.min(mtop, vh - hMenu - MARGINE) + 'px';
        }
        document.body.appendChild(ov);
        disegna();
        function chiudi() { ov.remove(); document.removeEventListener('keydown', tasto, true); }
        function tasto(e) { if (e.key === 'Escape') { e.stopPropagation(); chiudi(); } }
        document.addEventListener('keydown', tasto, true);
        ov.addEventListener('click', function (e) {
            var t;
            if (e.target.closest('[data-tutte]')) { tutte = true; disegna(); return; }
            if ((t = e.target.closest('[data-r]'))) { chiudi(); toggleReaction(m.id, t.getAttribute('data-r')); return; }
            if ((t = e.target.closest('[data-az]'))) { chiudi(); azioni[+t.getAttribute('data-az')].f(); return; }
            if (!e.target.closest('[data-barra]') && !e.target.closest('[data-lista-az]')) chiudi();
        });
    }

    function toggleReaction(messageId, emoji) {
        post('cora_react', { message_id: messageId, emoji: emoji }).then(function (r) {
            if (!r || !r.success) return;
            S.messages.forEach(function (m) { if (String(m.id) === String(messageId)) m.reactions = r.reactions || []; });
            renderMessages();
        }).catch(function () {});
    }
    function deleteMessage(m) {
        if (isTmp(m)) { S.messages = S.messages.filter(function (x) { return x.id !== m.id; }); renderMessages(); return; }
        if (!confirm('Eliminare questo messaggio? Sparira per tutti.')) return;
        var prev = S.messages.slice();
        S.messages = S.messages.filter(function (x) { return x.id !== m.id; });
        renderMessages();
        post('cora_delete', { message_id: m.id }).then(function (r) {
            if (!r.success) throw new Error(r.error);
            loadConversations();
        }).catch(function (err) {
            S.messages = prev; renderMessages();
            alert(err.message || 'Impossibile eliminare il messaggio.');
        });
    }
    function iniziaModifica(m) {
        var ta = $('[data-composer-ta]');
        S.editing = { msg: m, bozza: S.editing ? S.editing.bozza : (ta ? ta.value : '') };
        S.replyTo = null;
        S.avvisoInvio = null;
        if (ta) { ta.value = m.body; adattaTa(); }
        renderComposerExtra();
        requestAnimationFrame(function () { if (ta) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } });
    }
    function annullaModifica() {
        if (!S.editing) return;
        var ta = $('[data-composer-ta]');
        if (ta) { ta.value = S.editing.bozza; adattaTa(); }
        S.editing = null;
        renderComposerExtra();
    }
    function salvaModifica() {
        var ed = S.editing, ta = $('[data-composer-ta]');
        if (!ed || !ta) return;
        var text = ta.value.trim();
        if (!text) { S.avvisoInvio = 'Il messaggio non può restare vuoto: per toglierlo usa Elimina.'; renderComposerExtra(); return; }
        if (text.length > LIMITE_MESSAGGIO) { S.avvisoInvio = 'Il messaggio è di ' + text.length.toLocaleString('it-IT') + ' caratteri, il massimo è ' + LIMITE_MESSAGGIO.toLocaleString('it-IT') + '.'; renderComposerExtra(); return; }
        S.editing = null; ta.value = ed.bozza; adattaTa(); S.avvisoInvio = null; renderComposerExtra();
        if (text === ed.msg.body) return;
        var prima = { body: ed.msg.body, editedAt: ed.msg.editedAt || null };
        S.messages.forEach(function (m) { if (m.id === ed.msg.id) { m.body = text; m.editedAt = new Date().toISOString(); } });
        renderMessages();
        post('cora_edit', { message_id: ed.msg.id, message: text }).then(function (r) {
            if (!r.success) throw new Error(r.error);
            S.messages.forEach(function (m) { if (m.id === ed.msg.id && r.message) { m.body = r.message.body; m.editedAt = r.message.editedAt; } });
            renderMessages(); loadConversations();
        }).catch(function (err) {
            S.messages.forEach(function (m) { if (m.id === ed.msg.id) { m.body = prima.body; m.editedAt = prima.editedAt; } });
            renderMessages();
            S.editing = { msg: ed.msg, bozza: ed.bozza };
            ta.value = text; adattaTa();
            S.avvisoInvio = err.message || 'Impossibile modificare il messaggio.';
            renderComposerExtra();
        });
    }

    /* ---------------- Composizione ---------------- */
    function focusTa() { var ta = $('[data-composer-ta]'); if (ta) ta.focus(); }
    function valoreTa() { var ta = $('[data-composer-ta]'); return ta ? ta.value : ''; }
    function adattaTa() {
        var el = $('[data-composer-ta]'); if (!el) return;
        if (!el.value) { el.style.height = ''; aggiornaInvio(); return; }
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + el.offsetHeight - el.clientHeight) + 'px';
        aggiornaInvio();
    }
    function haTesto() { return !!(valoreTa().trim() || S.pendingFile); }

    /* La riga di scrittura (ridisegnata solo quando cambia forma) */
    function renderRiga(draft) {
        var el = $('[data-riga]'); if (!el) return;
        if (S.recording) {
            el.innerHTML = '<div class="cc-rec">' +
                '<button type="button" class="cc-rec-x" data-rec-x title="Annulla registrazione"><span class="cc-s5">' + I.trash + '</span></button>' +
                '<div class="cc-rec-c"><span class="cc-rec-dot"></span><span class="cc-rec-t" data-rec-t>' + fmtRecTime(S.recSeconds) + '</span><span class="cc-rec-l">Registrazione in corso...</span></div>' +
                '<button type="button" class="cc-rec-invia" data-rec-invia title="Invia messaggio vocale"><span class="cc-s4">' + I.send + '</span></button></div>';
            return;
        }
        if (telefono) {
            el.innerHTML = '<div class="cc-tel">' +
                '<div class="cc-tel-riga">' +
                    '<button type="button" class="cc-tel-piu" data-foglio aria-label="' + (S.foglioAllegati ? 'Tastiera' : 'Allega') + '">' + img(S.foglioAllegati ? 'tastiera.svg' : 'piu.svg', 'cc-s8') + '</button>' +
                    '<div class="cc-tel-campo-w"><div class="cc-tel-campo">' +
                        '<textarea data-composer-ta rows="1" placeholder="" aria-label="Scrivi un messaggio" autocomplete="off" autocorrect="on" autocapitalize="sentences" spellcheck="true" enterkeyhint="send"></textarea>' +
                        '<button type="button" data-gif-btn aria-label="GIF e sticker">' + img('sticker.svg', 'cc-s6') + '</button>' +
                    '</div></div>' +
                    '<span data-invio class="cc-invio-w"></span>' +
                '</div>' +
                (S.foglioAllegati ? '<div class="cc-foglio">' + [['Foto', 'foto-40.svg', 'foto'], ['Fotocamera', 'camera-40.svg', 'camera'], ['Documento', 'documento-40.svg', 'doc']].map(function (t) {
                    return '<button type="button" class="cc-foglio-t" data-pick="' + t[2] + '"><span class="cc-foglio-ic">' + img(t[1], 'cc-s10') + '</span><span class="cc-foglio-l">' + t[0] + '</span></button>';
                }).join('') + '</div>' : '') +
            '</div>';
        } else {
            el.innerHTML = '<div class="cc-desk-riga">' +
                '<button type="button" class="cc-tool' + (S.emojiOpen ? ' is-on' : '') + '" data-emoji-btn aria-label="Emoji">' + img('desk-emoji.svg', 'cc-s5') + '</button>' +
                '<div class="cc-rel cc-shrink0"><button type="button" class="cc-tool' + (S.attachOpen ? ' is-on' : '') + '" data-attach-btn aria-label="Allega">' + img('desk-allegato.svg', 'cc-s5') + '</button>' +
                (S.attachOpen ? '<div class="cc-velo cc-z10" data-attach-velo></div><div class="cc-attach-menu">' +
                    '<button type="button" data-pick="foto"><span class="cc-s5">' + I.image + '</span> Foto e video</button>' +
                    '<button type="button" data-pick="camera"><span class="cc-s5">' + I.camera + '</span> Fotocamera</button>' +
                    '<button type="button" data-pick="doc"><span class="cc-s5">' + I.fileText15 + '</span> Documento</button>' +
                    '<button type="button" data-gif-btn><span class="cc-gif-l">GIF</span> GIF e sticker</button></div>' : '') + '</div>' +
                '<textarea data-composer-ta rows="1" placeholder="Scrivi un messaggio..." autocomplete="off" autocorrect="on" autocapitalize="sentences" spellcheck="true" enterkeyhint="send"></textarea>' +
                '<span data-invio class="cc-invio-w"></span>' +
            '</div>';
        }
        var ta = $('[data-composer-ta]');
        ta.value = draft || '';
        ta.addEventListener('input', suScrivi);
        ta.addEventListener('keydown', onKeyDown);
        ta.addEventListener('focus', function () {
            if (!(S.emojiOpen || S.attachOpen || S.foglioAllegati)) return;
            var v = ta.value, p = ta.selectionStart;
            S.emojiOpen = false; S.attachOpen = false; S.foglioAllegati = false;
            renderRiga(v); renderComposerExtra();
            var n = $('[data-composer-ta]'); n.focus(); n.setSelectionRange(p, p);
        });
        ta.addEventListener('paste', function (e) {
            var f = e.clipboardData && e.clipboardData.files && e.clipboardData.files[0];
            if (f) { e.preventDefault(); allegaFile(f); }
        });
        adattaTa();
    }
    function aggiornaInvio() {
        var el = $('[data-invio]'); if (!el) return;
        var pieno = haTesto();
        var html;
        if (telefono) {
            html = pieno
                ? '<button type="button" class="cc-tel-invia" data-invia aria-label="Invia">' + img('invia.svg', 'cc-s6') + '</button>'
                : '<span class="cc-tel-dx"><button type="button" data-pick="camera" aria-label="Scatta una foto">' + img('fotocamera.svg', 'cc-s8') + '</button>' +
                  '<button type="button" data-rec aria-label="Registra un messaggio vocale">' + img('microfono.svg', 'cc-s8') + '</button></span>';
        } else {
            html = pieno
                ? '<button type="button" class="cc-invia" data-invia aria-label="Invia">' + img('desk-invia.svg', 'cc-s4') + '</button>'
                : '<button type="button" class="cc-invia" data-rec aria-label="Registra un messaggio vocale"><span class="cc-s4">' + I.mic + '</span></button>';
        }
        if (el.innerHTML !== html) el.innerHTML = html;
    }

    /* Avviso, menzioni, emoji, modifica, risposta, allegato in attesa */
    function renderComposerExtra() {
        if (!$('[data-composer]')) return;
        $('[data-avviso]').innerHTML = S.avvisoInvio ? '<div class="cc-avviso"><p>' + esc(S.avvisoInvio) + '</p><button type="button" data-avviso-x title="Chiudi"><span class="cc-s35">' + I.x + '</span></button></div>' : '';
        var cand = candidatiMenzione();
        $('[data-menzioni]').innerHTML = S.menzione && cand.length ? '<div role="listbox" aria-label="Persone da taggare" class="cc-menzioni">' + cand.map(function (mb, i) {
            return '<button type="button" role="option" aria-selected="' + (i === S.indiceMenzione) + '" class="' + (i === S.indiceMenzione ? 'is-sel' : '') + '" data-menz="' + i + '">' + avatarHtml(mb.name, mb.avatarUrl, 28, mb.id) +
                '<span class="cc-menz-t"><span class="cc-menz-n">' + esc(mb.name) + '</span>' + (mb.role ? '<span class="cc-menz-r">' + esc(mb.role) + '</span>' : '') + '</span></button>';
        }).join('') + '</div>' : '';
        $('[data-emoji]').innerHTML = S.emojiOpen ? '<div class="cc-emoji">' +
            '<div class="cc-emoji-tabs">' + EMOJI_CATEGORIES.map(function (c, i) { return '<button type="button" class="' + (S.emojiCat === i ? 'is-on' : '') + '" data-emoji-cat="' + i + '">' + c.label + '</button>'; }).join('') + '</div>' +
            '<div class="cc-emoji-grid">' + EMOJI_CATEGORIES[S.emojiCat].emojis.map(function (em) { return '<button type="button" data-em="' + em + '">' + em + '</button>'; }).join('') + '</div></div>' : '';
        var b = '';
        if (S.editing) {
            b += '<div class="cc-banner"><span class="cc-banner-barra" style="background:#e0a800"></span><div class="cc-banner-c"><div class="cc-banner-t">' +
                '<p class="cc-banner-n" style="color:#b07f00">Modifica messaggio</p><p class="cc-banner-b">' + esc(S.editing.msg.body) + '</p></div>' +
                '<button type="button" data-annulla-mod aria-label="Annulla modifica">' + img('chiudi-cerchio.svg', 'cc-s6') + '</button></div></div>';
        }
        if (S.replyTo) {
            var col = S.replyTo.mine ? '#d42a66' : '#2a96d4';
            var corpo = S.replyTo.body || (S.replyTo.attachment ? '📎 ' + S.replyTo.attachment.name : '');
            b += '<div class="cc-banner"><span class="cc-banner-barra" style="background:' + col + '"></span><div class="cc-banner-c"><div class="cc-banner-t">' +
                '<p class="cc-banner-n" style="color:' + col + '">' + esc(S.replyTo.mine ? 'Tu' : S.replyTo.senderName) + '</p><p class="cc-banner-b">' + esc(corpo) + '</p></div>' +
                '<button type="button" data-annulla-risp aria-label="Annulla risposta">' + img('chiudi-cerchio.svg', 'cc-s6') + '</button></div></div>';
        }
        $('[data-banner]').innerHTML = b;
        var pf = S.pendingFile;
        $('[data-pending]').innerHTML = pf ? '<div class="cc-pending">' + (pf.type.indexOf('image/') === 0 && pf.preview && !S.anteprimaRotta
            ? '<img src="' + esc(pf.preview) + '" alt="' + esc(pf.name) + '" data-anteprima>'
            : '<span class="cc-pending-f"><span class="cc-s4 cc-grigio">' + I.fileText + '</span> ' + esc(pf.name) + ' <span class="cc-pending-s">' + esc(fmtSize(pf.size)) + '</span></span>') +
            '<button type="button" data-pending-x title="Rimuovi allegato"><span class="cc-s4">' + I.x + '</span></button></div>' : '';
        var ant = $('[data-anteprima]');
        if (ant) ant.onerror = function () { S.anteprimaRotta = true; renderComposerExtra(); };
        aggiornaInvio();
    }

    function clicComposer(e) {
        var t;
        if (e.target.closest('[data-avviso-x]')) { S.avvisoInvio = null; renderComposerExtra(); return; }
        if ((t = e.target.closest('[data-menz]'))) { scegliMenzione(candidatiMenzione()[+t.getAttribute('data-menz')]); return; }
        if ((t = e.target.closest('[data-emoji-cat]'))) { S.emojiCat = +t.getAttribute('data-emoji-cat'); renderComposerExtra(); return; }
        if ((t = e.target.closest('[data-em]'))) { var ta = $('[data-composer-ta]'); ta.value += t.getAttribute('data-em'); adattaTa(); return; }
        if (e.target.closest('[data-annulla-mod]')) { annullaModifica(); return; }
        if (e.target.closest('[data-annulla-risp]')) { S.replyTo = null; renderComposerExtra(); return; }
        if (e.target.closest('[data-pending-x]')) { S.pendingFile = null; renderComposerExtra(); return; }
        if (e.target.closest('[data-emoji-btn]')) { var v1 = valoreTa(); S.emojiOpen = !S.emojiOpen; S.attachOpen = false; renderRiga(v1); renderComposerExtra(); return; }
        if (e.target.closest('[data-attach-btn]')) { var v2 = valoreTa(); S.attachOpen = !S.attachOpen; S.emojiOpen = false; renderRiga(v2); renderComposerExtra(); return; }
        if (e.target.closest('[data-attach-velo]')) { var v3 = valoreTa(); S.attachOpen = false; renderRiga(v3); renderComposerExtra(); return; }
        if (e.target.closest('[data-foglio]')) {
            var v4 = valoreTa(); S.foglioAllegati = !S.foglioAllegati; S.emojiOpen = false; renderRiga(v4); renderComposerExtra();
            if (!S.foglioAllegati) focusTa();
            return;
        }
        if (e.target.closest('[data-gif-btn]')) {
            var v5 = valoreTa(); S.gifOpen = !S.gifOpen; S.attachOpen = false; S.foglioAllegati = false; renderRiga(v5); renderComposerExtra(); renderGif(); return;
        }
        if ((t = e.target.closest('[data-pick]'))) {
            var tipo = t.getAttribute('data-pick');
            if (S.attachOpen || S.foglioAllegati) { var v6 = valoreTa(); S.attachOpen = false; S.foglioAllegati = false; renderRiga(v6); renderComposerExtra(); }
            sectionEl.querySelector('[data-file="' + tipo + '"]').click();
            return;
        }
        if (e.target.closest('[data-invia]')) { send(); return; }
        if (e.target.closest('[data-rec]')) { startRecording(); return; }
        if (e.target.closest('[data-rec-x]')) { stopRecording(true); return; }
        if (e.target.closest('[data-rec-invia]')) { stopRecording(false); return; }
    }
    sectionEl.addEventListener('mousedown', function (e) {
        // I pulsanti del campo non tolgono il fuoco alla scrittura (come nel CRM)
        if (e.target.closest('[data-emoji-btn], [data-attach-btn], [data-foglio], [data-menz]')) e.preventDefault();
    });
    sectionEl.addEventListener('change', function (e) {
        var inp = e.target.closest('input[type=file][data-file]');
        if (!inp) return;
        var f = inp.files && inp.files[0];
        inp.value = '';
        if (f) allegaFile(f);
    });

    /* Menzioni con @ nei gruppi */
    function candidatiMenzione() {
        var a = active();
        if (!S.menzione || !a || a.type !== 'group') return [];
        var q = S.menzione.query.toLowerCase();
        return a.members.filter(function (mb) { return mb.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 6);
    }
    function suScrivi() {
        var ta = $('[data-composer-ta]'), a = active();
        adattaTa();
        if (!a || a.type !== 'group') { if (S.menzione) { S.menzione = null; renderComposerExtra(); } return; }
        var prima = ta.value.slice(0, ta.selectionStart == null ? ta.value.length : ta.selectionStart);
        var tr = /(^|\s)@([^\s@]{0,30})$/.exec(prima);
        if (tr) { S.menzione = { query: tr[2], inizio: prima.length - tr[2].length - 1 }; S.indiceMenzione = 0; renderComposerExtra(); }
        else if (S.menzione) { S.menzione = null; renderComposerExtra(); }
    }
    function scegliMenzione(mb) {
        if (!S.menzione || !mb) return;
        var ta = $('[data-composer-ta]');
        var fine = S.menzione.inizio + 1 + S.menzione.query.length;
        var ins = '@' + mb.name + ' ';
        ta.value = ta.value.slice(0, S.menzione.inizio) + ins + ta.value.slice(fine);
        if (!S.menzionati.some(function (x) { return x.id === mb.id; })) S.menzionati.push(mb);
        var pos = S.menzione.inizio + ins.length;
        S.menzione = null;
        renderComposerExtra(); adattaTa();
        requestAnimationFrame(function () { ta.focus(); ta.setSelectionRange(pos, pos); });
    }
    function onKeyDown(e) {
        var cand = candidatiMenzione();
        if (S.menzione && cand.length) {
            if (e.key === 'ArrowDown') { e.preventDefault(); S.indiceMenzione = (S.indiceMenzione + 1) % cand.length; renderComposerExtra(); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); S.indiceMenzione = (S.indiceMenzione - 1 + cand.length) % cand.length; renderComposerExtra(); return; }
            if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); scegliMenzione(cand[Math.min(S.indiceMenzione, cand.length - 1)]); return; }
            if (e.key === 'Escape') { e.preventDefault(); S.menzione = null; renderComposerExtra(); return; }
        }
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
        if (e.key === 'Escape' && S.editing) { e.preventDefault(); annullaModifica(); }
    }

    /* Allegati: foto ridotte a 1280px (JPEG 0,82), GIF intatte */
    function allegaFile(file) {
        if (file.size > LIMITE_ALLEGATO) { alert('File troppo grande (max ' + (LIMITE_ALLEGATO / 1048576) + 'MB)'); return; }
        var fine = function (f, preview) {
            S.pendingFile = { name: f.name, type: f.type || 'application/octet-stream', size: f.size, file: f, preview: preview };
            S.anteprimaRotta = false;
            renderComposerExtra();
        };
        if (file.type.indexOf('image/') === 0 && file.type !== 'image/gif') {
            var url = URL.createObjectURL(file), im = new Image();
            im.onerror = function () { fine(file, url); };
            im.onload = function () {
                var MAX = 1280, w = im.width, h = im.height;
                if (w <= MAX && h <= MAX) { fine(file, url); return; }
                var k = Math.min(MAX / w, MAX / h);
                var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
                cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
                cv.toBlob(function (bl) {
                    if (!bl) { fine(file, url); return; }
                    var nf = new File([bl], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
                    fine(nf, URL.createObjectURL(nf));
                }, 'image/jpeg', 0.82);
            };
            im.src = url;
        } else {
            fine(file, file.type.indexOf('image/') === 0 ? URL.createObjectURL(file) : null);
        }
    }

    /* Trascinamento di foto, video e file sulla conversazione aperta */
    function conFile(e) { return Array.prototype.indexOf.call((e.dataTransfer && e.dataTransfer.types) || [], 'Files') !== -1; }
    function suTrascina(e) {
        if (!S.activeId || !conFile(e)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        var t = $('[data-trascina]'); if (t) t.hidden = false;
    }
    sectionEl.addEventListener('dragover', suTrascina);
    sectionEl.addEventListener('dragenter', suTrascina);
    sectionEl.addEventListener('dragleave', function (e) {
        if (!sectionEl.contains(e.relatedTarget)) { var t = $('[data-trascina]'); if (t) t.hidden = true; }
    });
    sectionEl.addEventListener('drop', function (e) {
        if (!conFile(e)) return;
        e.preventDefault(); e.stopPropagation();
        var t = $('[data-trascina]'); if (t) t.hidden = true;
        var f = e.dataTransfer.files && e.dataTransfer.files[0];
        if (f && S.activeId) allegaFile(f);
    });

    /* ---------------- Invio ---------------- */
    function inserisciInOrdine(lista, msg) {
        if (lista.some(function (m) { return m.id === msg.id; })) return lista;
        var t = new Date(msg.createdAt).getTime(), i = lista.length;
        while (i > 0) {
            var p = lista[i - 1];
            if (p.pending) { i--; continue; }
            var tp = new Date(p.createdAt).getTime();
            if (tp < t || (tp === t && p.id < msg.id)) break;
            i--;
        }
        return lista.slice(0, i).concat([msg], lista.slice(i));
    }

    function send(override) {
        if (S.editing && !override) { salvaModifica(); return; }
        var ta = $('[data-composer-ta]');
        var text = override ? '' : (ta ? ta.value.trim() : '');
        var id = S.activeId, file = override || S.pendingFile, reply = S.replyTo;
        if ((!text && !file) || !id) return;
        if (text.length > LIMITE_MESSAGGIO) {
            S.avvisoInvio = 'Il messaggio è di ' + text.length.toLocaleString('it-IT') + ' caratteri, il massimo è ' + LIMITE_MESSAGGIO.toLocaleString('it-IT') + '. Dividilo in due, oppure allega il testo come file.';
            renderComposerExtra();
            return;
        }
        S.avvisoInvio = null;
        // Su telefono, inviato il messaggio, la tastiera si chiude
        if (telefono && ta) ta.blur();
        if (ta && !override) { ta.value = ''; adattaTa(); }
        if (!override) S.pendingFile = null;
        S.replyTo = null; S.emojiOpen = false;
        if (S.attachOpen) { S.attachOpen = false; renderRiga(valoreTa()); }
        var clientId = nuovoIdClient(), tempId = 'tmp-' + clientId;
        inInvio.set(clientId, tempId);
        var optimistic = {
            id: tempId, body: text, senderId: me.id, senderName: 'Tu', senderAvatarUrl: null, mine: true,
            createdAt: new Date().toISOString(), pending: true, reactions: [], seenBy: [], mentions: [],
            replyTo: reply ? { id: reply.id, senderId: reply.senderId, senderName: reply.mine ? 'Tu' : reply.senderName, body: (reply.body || (reply.attachment ? '📎 ' + reply.attachment.name : '')).slice(0, 120) } : null,
            attachment: file ? { name: file.name, type: file.type, size: file.size, data: file.type.indexOf('image/') === 0 ? file.preview : null, url: file.type.indexOf('audio/') === 0 ? file.preview : null } : null
        };
        S.messages.push(optimistic);
        renderMessages(); renderComposerExtra();
        scrollToBottom(true);
        var mentionIds = S.menzionati.filter(function (mb) { return text.indexOf('@' + mb.name) !== -1; }).map(function (mb) { return mb.id; });
        S.menzionati = []; S.menzione = null;
        var dati = { conversation_id: id, message: text, reply_to_id: reply ? reply.id : null, mention_ids: mentionIds.join(','), client_id: clientId, cora: 1 };
        var invia = function () {
            return file && file.gif
                ? post('cora_send_gif', { conversation_id: id, url: file.gif, description: file.name, client_id: clientId })
                : post('send', dati, file ? { attachment: file.file } : null);
        };
        // Nessuna risposta (rete caduta a meta'): si riprova una volta con lo stesso clientId
        invia().catch(function (err) {
            if (err && err.response) throw err;
            return new Promise(function (r) { setTimeout(r, 1500); }).then(invia);
        }).then(function (r) {
            if (!r || !r.success) { var er = new Error((r && r.error) || 'invio fallito'); er.response = true; throw er; }
            inInvio.delete(clientId);
            var real = r.message;
            if (S.activeId === id) {
                var senza = S.messages.filter(function (m) { return m.id !== tempId; });
                S.messages = real && !senza.some(function (m) { return m.id === real.id; }) ? senza.concat([real]) : senza;
                if (real) lastMsgId = real.id;
                renderMessages();
            }
            loadConversations();
        }).catch(function (err) {
            inInvio.delete(clientId);
            // Il messaggio e' arrivato lo stesso (lo ha portato il socket): e' inviato
            if (consegnati.has(clientId)) { loadConversations(); return; }
            var reason = (err && err.message) || 'invio fallito';
            if (S.activeId !== id) {
                var bz = bozzePerChat.get(id);
                bozzePerChat.set(id, {
                    draft: text ? (bz && bz.draft ? text + '\n' + bz.draft : text) : (bz ? bz.draft : ''),
                    replyTo: reply || (bz && bz.replyTo) || null,
                    pendingFile: (file && !file.gif) ? file : (bz && bz.pendingFile) || null
                });
                return;
            }
            S.messages = S.messages.filter(function (m) { return m.id !== tempId; });
            renderMessages();
            var t2 = $('[data-composer-ta]');
            if (text && t2) { t2.value = t2.value ? text + '\n' + t2.value : text; adattaTa(); }
            if (file && !file.gif) S.pendingFile = file;
            if (reply) S.replyTo = reply;
            S.avvisoInvio = reason;
            renderComposerExtra();
        });
    }

    /* ---------------- Messaggio vocale ---------------- */
    var rec = null;
    function startRecording() {
        if (S.recording) return;
        if (!navigator.mediaDevices || !window.MediaRecorder) { alert('Microfono non disponibile. Controlla i permessi del browser.'); return; }
        var bozza = valoreTa();
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            // Prima mp4/AAC, che si ascolta ovunque (anche su iPhone); poi webm
            var mime = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'].filter(function (t) {
                return typeof MediaRecorder.isTypeSupported === 'function' && MediaRecorder.isTypeSupported(t);
            })[0] || '';
            var r = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            var chunks = [], inizio = Date.now();
            rec = { r: r, cancel: false, timer: null };
            r.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
            r.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                clearInterval(rec.timer);
                var cancel = rec.cancel;
                rec = null;
                S.recording = false; S.recSeconds = 0;
                renderRiga(bozza); renderComposerExtra();
                if (cancel) return;
                var tipo = (r.mimeType || mime || 'audio/webm').split(';')[0];
                var blob = new Blob(chunks, { type: tipo });
                if (blob.size < 1200) return; // tocco involontario
                if (blob.size > 8 * 1048576) { alert('Messaggio vocale troppo lungo (max 8MB)'); return; }
                var ext = (r.mimeType || '').indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                var durata = Math.max(1, Math.round((Date.now() - inizio) / 1000));
                var f = new File([blob], 'Messaggio vocale (' + fmtRecTime(durata) + ').' + ext, { type: blob.type || 'audio/webm' });
                send({ name: f.name, type: f.type, size: f.size, file: f, preview: URL.createObjectURL(f) });
            };
            r.start();
            S.recording = true; S.recSeconds = 0;
            renderRiga('');
            rec.timer = setInterval(function () {
                S.recSeconds++;
                var t = $('[data-rec-t]'); if (t) t.textContent = fmtRecTime(S.recSeconds);
            }, 1000);
        }).catch(function () { alert('Microfono non disponibile. Controlla i permessi del browser.'); });
    }
    function stopRecording(cancel) { if (!rec) return; rec.cancel = cancel; rec.r.stop(); }

    /* ---------------- GIF e sticker (Giphy) ---------------- */
    function renderGif() {
        var el = $('[data-gif]');
        if (!el) return;
        if (!S.gifOpen) { el.innerHTML = ''; return; }
        var kind = 'gif', query = '', timer = null;
        el.innerHTML = '<div class="cc-velo cc-z10" data-gif-velo></div><div class="cc-gif">' +
            '<div class="cc-gif-h"><button type="button" class="is-on" data-gif-k="gif">GIF</button><button type="button" data-gif-k="sticker">Sticker</button>' +
            '<input data-gif-q placeholder="Cerca..."><button type="button" class="cc-gif-x" data-gif-x title="Chiudi"><span class="cc-s4">' + I.x + '</span></button></div>' +
            '<div data-gif-res></div></div>';
        var res = el.querySelector('[data-gif-res]');
        function carica() {
            res.innerHTML = '<div class="cc-gif-grid">' + '<div class="cc-gif-skel"></div>'.repeat(9) + '</div>';
            post('cora_gifs', { q: query, kind: kind }).then(function (d) {
                if (d.enabled === false) { res.innerHTML = '<p class="cc-gif-p">GIF e sticker non ancora configurati: manca la chiave Giphy sul server.</p>'; return; }
                var items = d.results || [];
                if (!items.length) { res.innerHTML = '<p class="cc-gif-p">Nessun risultato.</p>'; return; }
                res.innerHTML = '<div class="cc-gif-grid cc-gif-scroll">' + items.map(function (g, i) {
                    return '<button type="button" data-gif-i="' + i + '" title="' + esc(g.description) + '"><img src="' + esc(g.preview) + '" alt="' + esc(g.description) + '" loading="lazy"></button>';
                }).join('') + '</div>';
                res.querySelectorAll('[data-gif-i]').forEach(function (bt) {
                    bt.onclick = function () {
                        var g = items[+bt.getAttribute('data-gif-i')];
                        S.gifOpen = false; renderGif();
                        send({ name: g.description || 'GIF', type: 'image/gif', size: 0, gif: g.url, preview: g.url });
                    };
                });
            }).catch(function () { res.innerHTML = '<p class="cc-gif-p">Nessun risultato.</p>'; });
        }
        el.querySelectorAll('[data-gif-k]').forEach(function (bt) {
            bt.onclick = function () {
                kind = bt.getAttribute('data-gif-k');
                el.querySelectorAll('[data-gif-k]').forEach(function (x) { x.classList.toggle('is-on', x === bt); });
                carica();
            };
        });
        el.querySelector('[data-gif-q]').oninput = function () { query = this.value; clearTimeout(timer); timer = setTimeout(carica, query ? 350 : 0); };
        el.querySelector('[data-gif-x]').onclick = el.querySelector('[data-gif-velo]').onclick = function () { S.gifOpen = false; renderGif(); };
        carica();
    }

    /* ---------------- Finestre (Modal del CRM) ---------------- */
    function modale(titolo, corpoHtml, azioniHtml, size) {
        var m = document.createElement('div');
        m.className = 'cc-modale';
        m.innerHTML = '<div class="cc-modale-box cc-modale-' + (size || 'md') + '" role="dialog" aria-modal="true">' +
            '<div class="cc-modale-maniglia"><div></div></div>' +
            '<div class="cc-modale-h"><h2>' + esc(titolo) + '</h2><button type="button" data-chiudi aria-label="Chiudi"><span class="cc-s4">' + I.x + '</span></button></div>' +
            '<div class="cc-modale-c" data-corpo>' + corpoHtml + '</div>' +
            (azioniHtml ? '<div class="cc-modale-a" data-azioni>' + azioniHtml + '</div>' : '') + '</div>';
        document.body.appendChild(m);
        document.body.style.overflow = 'hidden';
        function chiudi() { m.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', tasto); }
        function tasto(e) { if (e.key === 'Escape') chiudi(); }
        document.addEventListener('keydown', tasto);
        m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-chiudi]')) chiudi(); });
        m.chiudi = chiudi;
        return m;
    }

    function apriVisto(lista) {
        modale('Visto da', '<div class="cc-visto">' + (lista.length ? lista.map(function (s) {
            return '<div class="cc-visto-r">' + avatarHtml(s.name, s.avatarUrl, 34, s.id) + '<div class="cc-visto-t"><p class="cc-visto-n">' + esc(s.name) + '</p>' +
                (s.seenAt ? '<p class="cc-visto-d">' + esc(new Date(s.seenAt).toLocaleString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })) + '</p>' : '') +
                '</div><span class="cc-s4 cc-blu">' + I.check + '</span></div>';
        }).join('') : '<p class="cc-visto-vuoto">Nessuno ha ancora letto.</p>') + '</div>',
        '<button type="button" class="cc-btn-primario" data-chiudi>Chiudi</button>', 'sm');
    }

    function apriInoltra(msg) {
        var scelte = new Set();
        var m = modale('Inoltra a…', '<div class="cc-inoltra"><p class="cc-inoltra-msg">' + esc(msg.body || (msg.attachment ? '📎 ' + msg.attachment.name : '')) + '</p>' +
            '<div class="cc-inoltra-l">' + S.conversations.map(function (c) {
                return '<button type="button" class="cc-inoltra-r" data-sc="' + c.id + '">' + avatarConv(c, 36) + '<span class="cc-inoltra-n">' + esc(c.title) + '</span>' +
                    '<span class="cc-inoltra-c" data-cerchio></span></button>';
            }).join('') + '</div></div>',
            '<button type="button" class="cc-btn-annulla" data-chiudi>Annulla</button><button type="button" class="cc-btn-lime" data-conferma disabled>Inoltra</button>', 'sm');
        var conf = m.querySelector('[data-conferma]');
        m.querySelectorAll('[data-sc]').forEach(function (bt) {
            bt.onclick = function () {
                var id = +bt.getAttribute('data-sc');
                if (scelte.has(id)) scelte.delete(id); else scelte.add(id);
                var c = bt.querySelector('[data-cerchio]');
                c.classList.toggle('is-on', scelte.has(id));
                c.innerHTML = scelte.has(id) ? '<span class="cc-s35">' + I.check + '</span>' : '';
                conf.disabled = !scelte.size;
                conf.textContent = scelte.size > 1 ? 'Inoltra a ' + scelte.size + ' chat' : 'Inoltra';
            };
        });
        conf.onclick = function () {
            conf.disabled = true; conf.textContent = 'Invio…';
            post('cora_forward', { message_id: msg.id, conversation_ids: Array.from(scelte).join(',') }).then(function (r) {
                if (!r.success) throw new Error(r.error);
                m.chiudi(); loadConversations();
                if (scelte.has(S.activeId)) prendiNuovi();
            }).catch(function (err) {
                alert(err.message || 'Impossibile inoltrare il messaggio.');
                conf.disabled = false; conf.textContent = 'Inoltra';
            });
        };
    }

    function openInfo() {
        var id = S.activeId; if (!id) return;
        if (!S.contacts.length) loadContacts();
        var stato = { aggiungi: false, scelti: new Set(), busy: false, err: '' };
        var m = modale('Dettagli conversazione', '<div class="cc-info-car">Caricamento...</div>', '<button type="button" class="cc-btn-primario" data-chiudi>Chiudi</button>', 'sm');
        var info = null;
        function carica() {
            return post('cora_info', { conversation_id: id }).then(function (d) { info = d; disegna(); });
        }
        function disegna() {
            if (!info || !info.members) return;
            m.querySelector('.cc-modale-h h2').textContent = info.type === 'group' ? 'Dettagli gruppo' : 'Dettagli conversazione';
            var a = active();
            var h = '<div class="cc-info">';
            if (info.type === 'group') {
                h += '<div class="cc-info-gruppo"><div class="cc-rel">' + (info.avatarUrl ? '<img src="' + esc(info.avatarUrl) + '" alt="" class="cc-info-foto">'
                    : '<div class="cc-info-foto cc-info-foto-vuota"><span class="cc-s9">' + I.users + '</span></div>') +
                    (info.canEdit ? '<button type="button" class="cc-info-cam" data-cambia-foto title="Cambia foto"><span class="cc-s35">' + I.camera2 + '</span></button>' : '') +
                    '<input type="file" accept="image/*" hidden data-foto-gruppo></div>' +
                    '<p class="cc-info-nome">' + esc(info.name || (a && a.title) || 'Gruppo') + '</p>' +
                    (info.canEdit ? '<p class="cc-info-nota">Tocca l\'icona per cambiare la foto</p>' : '') + '</div>';
            }
            h += '<div><p class="cc-info-l">' + info.members.length + ' ' + (info.members.length === 1 ? 'partecipante' : 'partecipanti') + '</p><div class="cc-info-membri">';
            info.members.forEach(function (mem) {
                h += '<div class="cc-info-m">' + avatarHtml(mem.name, mem.avatarUrl, 36, mem.id) + '<div class="cc-info-mt"><p class="cc-info-mn">' + esc(mem.name) + (mem.id === me.id ? ' (tu)' : '') + '</p>' +
                    '<p class="cc-info-mr">' + esc(mem.role || '') + '</p></div>' +
                    (mem.isCreator ? '<span class="cc-creatore">Creatore</span>' : '') +
                    (info.realGroup && info.canEdit && mem.id !== me.id && !mem.isCreator ? '<button type="button" class="cc-togli" data-togli="' + esc(mem.id) + '" aria-label="Togli ' + esc(mem.name) + ' dal gruppo" title="Togli dal gruppo"' + (stato.busy ? ' disabled' : '') + '><span class="cc-s4">' + I.x + '</span></button>' : '') +
                    '</div>';
            });
            h += '</div>' + (stato.err ? '<p class="cc-info-err">' + esc(stato.err) + '</p>' : '');
            if (info.realGroup) {
                if (stato.aggiungi) {
                    var liberi = S.contacts.filter(function (c) { return !info.members.some(function (x) { return x.id === c.id; }); });
                    h += '<div class="cc-aggiungi"><p class="cc-aggiungi-l">Scegli chi aggiungere</p><div class="cc-aggiungi-lista">' +
                        (liberi.length ? liberi.map(function (c) {
                            return '<label class="cc-aggiungi-r"><input type="checkbox" data-agg="' + esc(c.id) + '"' + (stato.scelti.has(c.id) ? ' checked' : '') + '>' + avatarHtml(c.name, c.avatarUrl, 28, c.id) +
                                '<span class="cc-aggiungi-t"><span class="cc-aggiungi-n">' + esc(c.name) + '</span><span class="cc-aggiungi-ru">' + esc(c.role) + '</span></span></label>';
                        }).join('') : '<p class="cc-aggiungi-vuoto">Ci sono già tutti.</p>') + '</div>' +
                        '<div class="cc-aggiungi-a"><button type="button" class="cc-btn-bianco" data-agg-annulla>Annulla</button>' +
                        '<button type="button" class="cc-btn-accent" data-agg-ok' + (!stato.scelti.size || stato.busy ? ' disabled' : '') + '>' + (stato.busy ? 'Aggiungo...' : 'Aggiungi' + (stato.scelti.size ? ' (' + stato.scelti.size + ')' : '')) + '</button></div></div>';
                } else {
                    h += '<div class="cc-gruppo-az"><button type="button" class="cc-btn-accent" data-agg-apri><span class="cc-s4">' + I.plus + '</span> Aggiungi persone</button>' +
                        '<button type="button" class="cc-esci" data-esci>Esci dal gruppo</button></div>';
                }
            }
            h += '</div></div>';
            m.querySelector('[data-corpo]').innerHTML = h;
        }
        m.addEventListener('click', function (e) {
            var t;
            if (e.target.closest('[data-cambia-foto]')) { m.querySelector('[data-foto-gruppo]').click(); return; }
            if (e.target.closest('[data-agg-apri]')) { stato.aggiungi = true; disegna(); return; }
            if (e.target.closest('[data-agg-annulla]')) { stato.aggiungi = false; stato.scelti = new Set(); disegna(); return; }
            if (e.target.closest('[data-agg-ok]')) {
                stato.busy = true; stato.err = ''; disegna();
                post('cora_group_add', { conversation_id: id, members: Array.from(stato.scelti).join(',') }).then(function (r) {
                    if (!r.success) throw new Error(r.error);
                    stato.aggiungi = false; stato.scelti = new Set();
                    return carica().then(loadConversations);
                }).catch(function (err) { stato.err = err.message || 'Non sono riuscito ad aggiungere le persone.'; }).then(function () { stato.busy = false; disegna(); });
                return;
            }
            if ((t = e.target.closest('[data-togli]'))) {
                var mem = info.members.filter(function (x) { return x.id === t.getAttribute('data-togli'); })[0];
                if (!mem || !confirm('Togliere ' + mem.name + ' dal gruppo?')) return;
                stato.busy = true; stato.err = ''; disegna();
                post('cora_group_remove', { conversation_id: id, member: mem.id }).then(function (r) {
                    if (!r.success) throw new Error(r.error);
                    return carica().then(loadConversations);
                }).catch(function (err) { stato.err = err.message || 'Non sono riuscito a togliere la persona.'; }).then(function () { stato.busy = false; disegna(); });
                return;
            }
            if (e.target.closest('[data-esci]')) {
                if (!confirm('Uscire dal gruppo? Non riceverai più i suoi messaggi.')) return;
                post('cora_group_leave', { conversation_id: id }).then(function (r) {
                    if (!r.success) throw new Error(r.error);
                    m.chiudi(); S.activeId = null; S.showListMobile = true; renderThread(); aggiornaLayout();
                    history.replaceState(null, '', location.pathname);
                    loadConversations();
                }).catch(function (err) { stato.err = err.message || 'Non sono riuscito a uscire dal gruppo.'; disegna(); });
            }
        });
        m.addEventListener('change', function (e) {
            var t;
            if ((t = e.target.closest('[data-agg]'))) {
                var k = t.getAttribute('data-agg');
                if (t.checked) stato.scelti.add(k); else stato.scelti.delete(k);
                disegna();
                return;
            }
            if ((t = e.target.closest('[data-foto-gruppo]'))) {
                var f = t.files && t.files[0]; t.value = '';
                if (!f) return;
                if (f.type.indexOf('image/') !== 0) { alert('Seleziona un\'immagine.'); return; }
                if (f.size > 8 * 1048576) { alert('Immagine troppo grande (max 8MB).'); return; }
                post('cora_group_avatar', { conversation_id: id }, { avatar: f }).then(function (r) {
                    if (!r.success) throw new Error(r.error);
                    return carica().then(loadConversations);
                }).catch(function () { alert('Impossibile aggiornare la foto.'); });
            }
        });
        carica().catch(function () {});
    }

    /* Nuova chat: computer (NewChatModal) e telefono (NuovaChatMobile) */
    function nuovaChat() {
        var apri = function () { if (telefono) nuovaChatTelefono(); else nuovaChatComputer(); };
        if (!S.contacts.length) loadContacts().then(apri); else apri();
    }
    function startDirect(userId, m) {
        if (m) m.chiudi();
        post('cora_direct', { user: userId }).then(function (r) {
            if (!r.success) throw new Error(r.error);
            return loadConversations().then(function () { apriDaElenco(+r.id); });
        }).catch(function () {});
    }
    function createGroup(nome, ids, m) {
        if (m) m.chiudi();
        post('cora_group_create', { name: nome, members: ids.join(',') }).then(function (r) {
            if (!r.success) throw new Error(r.error);
            return loadConversations().then(function () { apriDaElenco(+r.id); });
        }).catch(function (err) { alert(err.message || 'Gruppo non creato'); });
    }
    function nuovaChatComputer() {
        var st = { mode: 'direct', search: '', nome: '', selected: new Set() };
        var m = modale('Nuova chat', '', '<span></span>', 'md');
        function disegna() {
            var filtrati = S.contacts.filter(function (c) { return c.name.toLowerCase().indexOf(st.search.toLowerCase()) !== -1; });
            m.querySelector('[data-corpo]').innerHTML = '<div class="cc-nuova">' +
                '<div class="cc-nuova-modi"><button type="button" class="' + (st.mode === 'direct' ? 'is-on' : '') + '" data-modo="direct">Persona</button>' +
                '<button type="button" class="' + (st.mode === 'group' ? 'is-on' : '') + '" data-modo="group"><span class="cc-s4">' + I.users + '</span> Gruppo</button></div>' +
                (st.mode === 'group' ? '<input type="text" class="cc-campo" data-nome-gruppo placeholder="Nome del gruppo" value="' + esc(st.nome) + '">' : '') +
                '<div class="cc-rel"><span class="cc-nuova-lente cc-s4">' + I.search + '</span><input type="text" class="cc-campo cc-campo-lente" data-cerca placeholder="Cerca persone..." value="' + esc(st.search) + '"></div>' +
                (st.mode === 'group' && st.selected.size ? '<p class="cc-nuova-sel">' + st.selected.size + ' selezionat' + (st.selected.size === 1 ? 'o' : 'i') + '</p>' : '') +
                '<div class="cc-nuova-lista">' + (filtrati.length ? filtrati.map(function (c) {
                    var sel = st.selected.has(c.id);
                    return '<button type="button" class="cc-nuova-r" data-contatto="' + esc(c.id) + '">' + avatarHtml(c.name, c.avatarUrl, 36, c.id) +
                        '<div class="cc-nuova-t"><p class="cc-nuova-n">' + esc(c.name) + '</p><p class="cc-nuova-ru">' + esc(c.role) + '</p></div>' +
                        (st.mode === 'group' ? '<span class="cc-nuova-check' + (sel ? ' is-on' : '') + '">' + (sel ? '<span class="cc-s35">' + I.check + '</span>' : '') + '</span>' : '') + '</button>';
                }).join('') : '<p class="cc-nuova-vuota">Nessun contatto</p>') + '</div></div>';
            m.querySelector('[data-azioni]').innerHTML = st.mode === 'group'
                ? '<button type="button" class="cc-btn-annulla" data-chiudi>Annulla</button><button type="button" class="cc-btn-primario" data-crea' + (!st.nome.trim() || !st.selected.size ? ' disabled' : '') + '>Crea gruppo</button>'
                : '<button type="button" class="cc-btn-annulla" data-chiudi>Chiudi</button>';
        }
        disegna();
        m.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-modo]'))) { st.mode = t.getAttribute('data-modo'); disegna(); return; }
            if ((t = e.target.closest('[data-contatto]'))) {
                var id = t.getAttribute('data-contatto');
                if (st.mode === 'direct') { startDirect(id, m); return; }
                if (st.selected.has(id)) st.selected.delete(id); else st.selected.add(id);
                disegna();
                return;
            }
            if (e.target.closest('[data-crea]')) createGroup(st.nome, Array.from(st.selected), m);
        });
        m.addEventListener('input', function (e) {
            var t = e.target, pos = t.selectionStart, sel;
            if (t.matches('[data-cerca]')) { st.search = t.value; sel = '[data-cerca]'; }
            else if (t.matches('[data-nome-gruppo]')) { st.nome = t.value; sel = '[data-nome-gruppo]'; }
            else return;
            disegna();
            var n = m.querySelector(sel); n.focus(); n.setSelectionRange(pos, pos);
        });
    }
    function nuovaChatTelefono() {
        var st = { passo: 'persone', cerca: '', scelti: [], nome: '' };
        var ov = document.createElement('div');
        ov.className = 'cc-ncm';
        document.body.appendChild(ov);
        function chiudi() { ov.remove(); }
        function faccia(c, size) {
            size = size || 32;
            return c.avatarUrl ? '<img src="' + esc(c.avatarUrl) + '" alt="" class="cc-ncm-f" style="width:' + size + 'px;height:' + size + 'px">'
                : '<span class="cc-ncm-f cc-ncm-ini" style="width:' + size + 'px;height:' + size + 'px">' + esc(c.name.split(/\s+/).slice(0, 2).map(function (x) { return x[0] || ''; }).join('').toUpperCase()) + '</span>';
        }
        function sceltiHtml() {
            return '<div class="cc-ncm-scelti">' + st.scelti.map(function (id) {
                var c = S.contacts.filter(function (x) { return x.id === id; })[0]; if (!c) return '';
                return '<div class="cc-ncm-s">' + faccia(c, 40) + '<span>' + esc(c.name.split(/\s+/)[0]) + '</span><button type="button" data-togli-s="' + esc(c.id) + '" aria-label="Togli ' + esc(c.name) + '"><span class="cc-s3">' + I.x + '</span></button></div>';
            }).join('') + '</div>';
        }
        function disegna() {
            var q = st.cerca.trim().toLowerCase();
            var lista = S.contacts.filter(function (c) { return !q || c.name.toLowerCase().indexOf(q) !== -1 || (c.role || '').toLowerCase().indexOf(q) !== -1; })
                .slice().sort(function (a, b) { return a.name.localeCompare(b.name, 'it', { sensitivity: 'base' }); });
            var gruppi = [];
            lista.forEach(function (c) {
                var l = (c.name[0] || '#').toUpperCase(), g = gruppi.filter(function (x) { return x.lettera === l; })[0];
                if (g) g.persone.push(c); else gruppi.push({ lettera: l, persone: [c] });
            });
            var testa = st.passo === 'persone'
                ? '<div class="cc-ncm-h"><p>Nuova chat</p><button type="button" data-ncm-x aria-label="Chiudi"><span class="cc-s4">' + I.x + '</span></button></div>'
                : st.passo === 'membri'
                    ? '<div class="cc-ncm-h"><button type="button" data-ncm-indietro="persone" aria-label="Indietro"><span class="cc-s5">' + I.chevLeft + '</span></button><p>Aggiungi membri</p>' +
                      '<button type="button" class="cc-ncm-avanti" data-ncm-avanti' + (!st.scelti.length ? ' disabled' : '') + '>Avanti <span class="cc-s4">' + I.chevRight + '</span></button></div>'
                    : '<div class="cc-ncm-h"><button type="button" data-ncm-indietro="membri" aria-label="Indietro"><span class="cc-s5">' + I.chevLeft + '</span></button><p>Aggiungi titolo</p>' +
                      '<button type="button" class="cc-btn-accent" data-ncm-crea' + (!st.nome.trim() || !st.scelti.length ? ' disabled' : '') + '>' + maschera('spunta.svg', 'cc-s4') + ' Crea gruppo</button></div>';
            var corpo;
            if (st.passo === 'titolo') {
                corpo = '<input class="cc-ncm-nome" data-ncm-nome placeholder="Nome del gruppo" value="' + esc(st.nome) + '">' +
                    '<div class="cc-ncm-box"><p>Membri del gruppo selezionati (' + st.scelti.length + ')</p>' + sceltiHtml() + '</div>';
            } else {
                corpo = '<label class="cc-ncm-cerca"><span class="cc-s4">' + I.search + '</span><input data-ncm-cerca placeholder="Cerca..." value="' + esc(st.cerca) + '"></label>' +
                    (st.passo === 'persone' ? '<button type="button" class="cc-ncm-gruppo" data-ncm-gruppo>' + maschera('gruppo.svg', 'cc-s4') + ' Nuovo gruppo</button>' : '') +
                    (st.passo === 'membri' && st.scelti.length ? '<div class="cc-ncm-box">' + sceltiHtml() + '</div>' : '') +
                    '<div class="cc-ncm-gruppi">' + (gruppi.length ? '' : '<p class="cc-ncm-vuoto">Nessun collega trovato.</p>') + gruppi.map(function (g) {
                        return '<div class="cc-ncm-g"><p class="cc-ncm-l">' + esc(g.lettera) + '</p><div class="cc-ncm-blocco">' + g.persone.map(function (c, i) {
                            var sel = st.scelti.indexOf(c.id) !== -1;
                            return '<button type="button" class="cc-ncm-p' + (i > 0 ? ' con-riga' : '') + '" data-ncm-p="' + esc(c.id) + '">' + faccia(c) +
                                '<span class="cc-ncm-pt"><span class="cc-ncm-pn">' + esc(c.name) + '</span><span class="cc-ncm-pr">' + esc(c.role) + '</span></span>' +
                                (st.passo === 'membri' ? '<span class="cc-ncm-check' + (sel ? ' is-on' : '') + '">' + (sel ? '<span class="cc-s35">' + I.check3 + '</span>' : '') + '</span>' : '') + '</button>';
                        }).join('') + '</div></div>';
                    }).join('') + '</div>';
            }
            ov.innerHTML = '<div class="cc-ncm-box-out" role="dialog" aria-modal="true" aria-label="Nuova chat">' + testa + corpo + '</div>';
        }
        disegna();
        ov.addEventListener('click', function (e) {
            var t;
            if (e.target === ov || e.target.closest('[data-ncm-x]')) { chiudi(); return; }
            if ((t = e.target.closest('[data-ncm-indietro]'))) { st.passo = t.getAttribute('data-ncm-indietro'); disegna(); return; }
            if (e.target.closest('[data-ncm-gruppo]')) { st.passo = 'membri'; disegna(); return; }
            if (e.target.closest('[data-ncm-avanti]')) { st.passo = 'titolo'; disegna(); var n = ov.querySelector('[data-ncm-nome]'); if (n) n.focus(); return; }
            if (e.target.closest('[data-ncm-crea]')) { chiudi(); createGroup(st.nome.trim(), st.scelti); return; }
            if ((t = e.target.closest('[data-togli-s]'))) { var k = t.getAttribute('data-togli-s'); st.scelti = st.scelti.filter(function (x) { return x !== k; }); disegna(); return; }
            if ((t = e.target.closest('[data-ncm-p]'))) {
                var id = t.getAttribute('data-ncm-p');
                if (st.passo === 'persone') { chiudi(); startDirect(id); return; }
                st.scelti = st.scelti.indexOf(id) !== -1 ? st.scelti.filter(function (x) { return x !== id; }) : st.scelti.concat([id]);
                disegna();
            }
        });
        ov.addEventListener('input', function (e) {
            var t = e.target, pos = t.selectionStart, sel;
            if (t.matches('[data-ncm-cerca]')) { st.cerca = t.value; sel = '[data-ncm-cerca]'; }
            else if (t.matches('[data-ncm-nome]')) { st.nome = t.value; sel = '[data-ncm-nome]'; }
            else return;
            disegna();
            var n = ov.querySelector(sel); n.focus(); n.setSelectionRange(pos, pos);
        });
    }
    document.addEventListener('click', function (e) { if (e.target.closest('[data-cc-new]') || e.target.closest('[data-fab]')) nuovaChat(); });

    /* Visualizzatore immagini a schermo intero */
    function setViewer(src, name) {
        var v = document.createElement('div');
        v.className = 'cc-viewer';
        v.innerHTML = '<button type="button" aria-label="Chiudi"><span class="cc-s6">' + I.x + '</span></button><img src="' + esc(src) + '" alt="' + esc(name || '') + '">';
        document.body.appendChild(v);
        function chiudi() { v.remove(); document.removeEventListener('keydown', tasto); }
        function tasto(e) { if (e.key === 'Escape') chiudi(); }
        document.addEventListener('keydown', tasto);
        v.addEventListener('click', function (e) { if (e.target.tagName !== 'IMG') chiudi(); });
    }

    /* ---------------- Eventi in tempo reale ---------------- */
    var attesaLetto;
    function segnaLettaPresto(id) {
        clearTimeout(attesaLetto);
        attesaLetto = setTimeout(function () {
            if (S.activeId === id && document.visibilityState === 'visible') post('cora_read', { conversation_id: id }).catch(function () {});
        }, 400);
    }
    suEvento('chat.message.created', function (d) {
        if (!d || !d.message || !d.message.id) return;
        ricaricaElenco();
        if (d.conversationId !== S.activeId) {
            var mem = cacheMessaggi.get(d.conversationId);
            if (mem) cacheMessaggi.set(d.conversationId, inserisciInOrdine(mem, d.message));
            return;
        }
        var tempId = d.clientId ? inInvio.get(d.clientId) : undefined;
        if (d.clientId) consegnati.add(d.clientId);
        var el = $('[data-scroll]');
        var wasNearBottom = !el || inFondoRef || (el.scrollHeight - el.scrollTop - el.clientHeight < 140);
        if (tempId && S.messages.some(function (m) { return m.id === tempId; })) {
            if (S.messages.some(function (m) { return m.id === d.message.id; })) S.messages = S.messages.filter(function (m) { return m.id !== tempId; });
            else S.messages = S.messages.map(function (m) { return m.id === tempId ? Object.assign({}, d.message, { attachment: d.message.attachment || m.attachment }) : m; });
        } else {
            S.messages = inserisciInOrdine(S.messages, d.message);
        }
        var ultimo = S.messages[S.messages.length - 1];
        if (!ultimo || new Date(d.message.createdAt).getTime() >= new Date(ultimo.createdAt).getTime()) lastMsgId = d.message.id;
        renderMessages();
        if (wasNearBottom) scrollToBottom(true);
        if (!d.message.mine) segnaLettaPresto(d.conversationId);
    });
    suEvento('chat.message.updated', function (d) {
        if (!d || !d.message || !d.message.id) return;
        var agg = function (lista) {
            return lista.map(function (m) { return m.id === d.message.id ? Object.assign({}, m, { body: d.message.body, editedAt: d.message.editedAt || null, reactions: d.message.reactions || [] }) : m; });
        };
        var mem = cacheMessaggi.get(d.conversationId);
        if (mem) cacheMessaggi.set(d.conversationId, agg(mem));
        if (d.conversationId === S.activeId) { S.messages = agg(S.messages); renderMessages(); }
        ricaricaElenco();
    });
    suEvento('chat.message.deleted', function (d) {
        if (!d || !d.messageId) return;
        var mem = cacheMessaggi.get(d.conversationId);
        if (mem) cacheMessaggi.set(d.conversationId, mem.filter(function (m) { return m.id !== d.messageId; }));
        if (d.conversationId === S.activeId) {
            S.messages = S.messages.filter(function (m) { return m.id !== d.messageId; });
            if (lastMsgId === d.messageId) {
                var resto = S.messages.filter(function (m) { return !m.pending; });
                lastMsgId = resto.length ? resto[resto.length - 1].id : null;
            }
            renderMessages();
        }
        ricaricaElenco();
    });
    suEvento('chat.conversation.read', function (d) {
        if (!d || !d.conversationId) return;
        ricaricaElenco();
        // Un collega ha letto la chat aperta: le mie bolle fino a quel momento prendono la spunta
        if (d.userId === me.id || d.conversationId !== S.activeId) return;
        var c = conv(d.conversationId);
        var membro = c ? c.members.filter(function (mb) { return mb.id === d.userId; })[0] : null;
        var letto = new Date(d.readAt).getTime();
        var cambiato = false;
        S.messages = S.messages.map(function (m) {
            if (!m.mine || m.pending || new Date(m.createdAt).getTime() > letto) return m;
            if (m.seenBy && m.seenBy.some(function (x) { return x.id === d.userId; })) return m;
            cambiato = true;
            return Object.assign({}, m, { seenBy: (m.seenBy || []).concat([{ id: d.userId, name: membro ? membro.name : 'Utente', avatarUrl: membro ? membro.avatarUrl : null, seenAt: d.readAt }]) });
        });
        if (cambiato) renderMessages();
    });
    suEvento('chat.conversation.changed', function () { ricaricaElenco(); });

    /* ---------------- Polling di riserva e riallineamento ---------------- */
    function prendiNuovi() {
        var id = S.activeId;
        if (!id) return Promise.resolve();
        var params = lastMsgId && !isTmp({ id: lastMsgId }) ? { conversation_id: id, after: lastMsgId } : { conversation_id: id, limit: 50 };
        return post('cora_messages', params).then(function (incoming) {
            incoming = Array.isArray(incoming) ? incoming : [];
            if (S.activeId !== id) return;
            var known = new Map(S.messages.map(function (m) { return [m.id, m]; }));
            var fresh = incoming.filter(function (m) { return !known.has(m.id); });
            var corretti = incoming.filter(function (m) { var k = known.get(m.id); return k && (k.editedAt || null) !== (m.editedAt || null); });
            if (corretti.length) {
                var per = new Map(corretti.map(function (m) { return [m.id, m]; }));
                S.messages = S.messages.map(function (m) { var c = per.get(m.id); return c ? Object.assign({}, m, { body: c.body, editedAt: c.editedAt }) : m; });
            }
            if (fresh.length) {
                var el = $('[data-scroll]');
                var wasNearBottom = !el || inFondoRef || (el.scrollHeight - el.scrollTop - el.clientHeight < 140);
                var ids = new Set(S.messages.map(function (m) { return m.id; }));
                S.messages = S.messages.concat(fresh.filter(function (m) { return !ids.has(m.id); }));
                lastMsgId = fresh[fresh.length - 1].id;
                renderMessages();
                if (wasNearBottom) scrollToBottom(true);
                if (document.visibilityState === 'visible') post('cora_read', { conversation_id: id }).catch(function () {});
                return;
            }
            if (corretti.length) renderMessages();
        }).catch(function () {});
    }
    function riallinea() {
        loadConversations();
        caricaPresenze();
        var id = S.activeId;
        if (!id) return;
        post('cora_messages', { conversation_id: id, limit: PAGINA }).then(function (pagina) {
            if (S.activeId !== id || !Array.isArray(pagina) || !pagina.length) return;
            var inizio = new Date(pagina[0].createdAt).getTime();
            var vecchi = S.messages.filter(function (m) { return !m.pending && new Date(m.createdAt).getTime() < inizio && !pagina.some(function (x) { return x.id === m.id; }); });
            var inAttesa = S.messages.filter(function (m) { return m.pending; });
            S.messages = vecchi.concat(pagina, inAttesa);
            lastMsgId = pagina[pagina.length - 1].id;
            renderMessages();
        }).catch(function () { prendiNuovi(); });
    }
    var timers = [];
    function riavviaPolling() {
        timers.forEach(clearInterval);
        timers = [
            setInterval(prendiNuovi, RT.connesso ? 30000 : 2000),
            setInterval(loadConversations, RT.connesso ? 60000 : 5000),
            setInterval(caricaPresenze, RT.connesso ? 60000 : 20000)
        ];
    }
    document.addEventListener('visibilitychange', function () {
        aggiornaGuardo();
        segnalaAttivita();
        if (document.visibilityState === 'visible') { RT.ultimoGesto = Date.now(); riallinea(); }
        inviaPresenza();
    });

    /* Telefono e computer si scambiano senza ricaricare la pagina */
    mqTelefono.addEventListener('change', function () {
        telefono = mqTelefono.matches;
        var v = valoreTa();
        renderList();
        if (S.activeId) { renderHeader(); renderRiga(v); renderComposerExtra(); renderMessages(); }
        aggiornaLayout();
    });

    /* Telefono: la chat occupa lo spazio fino al dock (o fino in fondo, dentro una conversazione) */
    function adattaAltezza() {
        if (!telefono) { root.style.height = ''; return; }
        var dock = document.querySelector('.cora-tabbar');
        var fondo = dock && S.showListMobile ? dock.getBoundingClientRect().top - 12 : innerHeight;
        root.style.height = Math.max(380, fondo - root.getBoundingClientRect().top) + 'px';
    }
    window.addEventListener('resize', adattaAltezza);

    /* ---------------- Avvio ---------------- */
    renderPushBar();
    renderStato();
    renderList();
    renderThread();
    aggiornaLayout();
    riavviaPolling();
    inviaPresenza();
    setInterval(inviaPresenza, 45000);
    if (document.readyState === 'complete') avviaRealtime(); else window.addEventListener('load', avviaRealtime);
    if (cfg.open && conv(cfg.open)) openConversation(cfg.open);
    window.addEventListener('pagehide', function () { segnalaChatAperta(null); });
})();
