/**
 * Guida interattiva del dipendente (Cora).
 *
 * - Proposta in automatico sulla Home finche' tour_status e' NULL (migration 063).
 * - Riapribile dal Profilo ([data-tour-restart]): rivederla non scrive nulla sul DB.
 * - Avanzamento tra pagine in sessionStorage, legato a dipendente + sessione di login.
 * - Non invia form e non clicca azioni reali: blocca i click fuori dalla guida finche' e' aperta,
 *   ma lascia libero lo scroll (l'oscuramento ha pointer-events:none).
 * - Gli elementi si agganciano tramite attributi data-tour, mai per posizione nel DOM.
 */
(function () {
    'use strict';

    var CFG = window.PAM_TOUR;
    if (!CFG || !CFG.employeeId) return;
    if (CFG.page === 'change-password' || CFG.page === 'contract-sign') return;

    var STORE_KEY = 'pamTour';
    var MAX_AGE_MS = 30 * 60 * 1000;
    var WAIT_TARGET_MS = 1500;
    var PAGE_URLS = {
        'index': '',
        'leave-requests': 'leave-requests.php',
        'documents': 'documents.php',
        'communications': 'communications.php',
        'calendar': 'calendar.php',
        'chat': 'chat.php',
        'profile': 'profile.php'
    };

    function isMobileLayout() { return window.matchMedia('(max-width: 820px)').matches; }
    // Scheda ancorata in alto/basso su schermi stretti o bassi (smartphone anche in orizzontale)
    function isSheetLayout() { return window.matchMedia('(max-width: 639px), (max-height: 520px)').matches; }

    var STEPS = [
        {
            id: 'welcome', page: '*', chapter: 'Benvenuto',
            title: 'Benvenuto in Cora',
            body: 'In pochi passi ti mostriamo dove trovare buste paga e documenti, come chiedere ferie e permessi e come restare in contatto con l\'ufficio HR. La guida non invia e non modifica nulla: puoi chiuderla quando vuoi e rivederla dal tuo Profilo.',
            next: 'Inizia', showSkip: true
        },
        {
            id: 'nav', page: 'index', chapter: 'Home',
            target: function () { return isMobileLayout() ? '[data-tour="nav-mobile"]' : '[data-tour="nav-desktop"]'; },
            title: 'Il menu',
            body: function () {
                return isMobileLayout()
                    ? 'Dalla barra in basso raggiungi Home, Documenti, Ferie, Calendario e Chat. Le comunicazioni dell\'azienda le trovi qui nella Home.'
                    : 'Da qui raggiungi tutte le sezioni: Documenti, Ferie e permessi, Comunicazioni, Academy, Calendario e Chat. Il pallino rosso ti avvisa quando c\'è qualcosa di nuovo.';
            }
        },
        {
            id: 'availability', page: 'index', chapter: 'Home',
            target: '[data-tour="availability"]',
            title: 'Il tuo stato',
            body: 'Fai sapere ai colleghi se sei Operativo, In chiamata o In riunione. Quando ti serve, tocca qui e scegli lo stato: i colleghi lo vedono nelle presenze.'
        },
        {
            id: 'punch', page: 'index', chapter: 'Home', mobileOnly: true,
            target: '[data-tour="punch"]',
            title: 'Timbra entrata e uscita',
            body: 'Tocca il pulsante e avvicina il telefono al badge NFC aziendale: la timbratura parte da sola al contatto.'
        },
        {
            id: 'quick-actions', page: 'index', chapter: 'Home',
            target: '[data-tour="quick-actions"]',
            title: 'Le scorciatoie',
            body: 'Con un tocco chiedi ferie o un permesso, apri le tue buste paga e scrivi all\'HR.'
        },
        {
            id: 'balance', page: 'index', chapter: 'Home',
            target: '[data-tour="balance"]',
            title: 'Il tuo saldo',
            body: 'Quante ferie e quanti permessi ti restano quest\'anno, quanti ne hai già usati e il totale. Se vedi "Saldo non configurato", l\'HR non l\'ha ancora impostato.'
        },
        {
            id: 'heatmap', page: 'index', chapter: 'Home',
            target: '[data-tour="heatmap"]',
            title: 'Presenze della settimana',
            body: 'Chi è al lavoro e chi è assente giorno per giorno. Con le frecce passi alla settimana precedente o successiva.'
        },
        {
            id: 'leave-form', page: 'leave-requests', chapter: 'Ferie e permessi',
            target: ['[data-tour="leave-types"]', '[data-tour="leave-form"]'],
            title: 'Nuova richiesta',
            body: 'Scegli il tipo (ferie, permesso, malattia, L.104, smart working o altro), il periodo e se vale per la giornata intera o a ore. Poi premi "Invia richiesta" in fondo al modulo: la richiesta arriva all\'HR. Per la malattia puoi aggiungere protocollo INPS e certificato.'
        },
        {
            id: 'leave-list', page: 'leave-requests', chapter: 'Ferie e permessi',
            target: '[data-tour="leave-list"]',
            title: 'Le mie richieste',
            body: 'Qui segui lo stato di ogni richiesta: in attesa, approvata o rifiutata. Puoi filtrare per anno e annullare una richiesta finché è in attesa.'
        },
        {
            id: 'docs-filters', page: 'documents', chapter: 'Documenti',
            target: '[data-tour="docs-filters"]',
            title: 'I tuoi documenti',
            body: 'Buste paga, CU e altri documenti, divisi per mese. Filtra per tipo o per anno. Tocca un documento per scaricarlo: da quel momento non risulta più "Nuovo".'
        },
        {
            id: 'docs-info', page: 'documents', chapter: 'Documenti',
            target: '[data-tour="docs-info"]',
            title: 'Cosa contiene ogni documento',
            body: 'Questa finestra spiega i tipi di documento. La apri quando vuoi dal pulsante informazioni accanto ai filtri.',
            onEnter: function () { if (typeof window.openInfoModal === 'function') window.openInfoModal(); },
            onExit: function () { if (typeof window.closeInfoModal === 'function') window.closeInfoModal(); }
        },
        {
            id: 'comms', page: 'communications', chapter: 'Comunicazioni',
            target: '[data-tour="comms-filters"]',
            title: 'Comunicazioni',
            body: 'Avvisi e novità dell\'azienda. Filtra per priorità: quelle da leggere sono evidenziate e, quando ne apri una, risulta letta.'
        },
        {
            id: 'cal-toolbar', page: 'calendar', chapter: 'Calendario',
            target: '[data-tour="cal-toolbar"]',
            title: 'Il calendario',
            body: function () {
                return 'Eventi e riunioni a cui partecipi. Usa le frecce per spostarti e "Oggi" per tornare alla data corrente'
                    + (window.matchMedia('(max-width: 720px)').matches ? '.' : ', oppure scegli la vista Giorno o Settimana.');
            }
        },
        {
            id: 'cal-modal', page: 'calendar', chapter: 'Calendario',
            target: '[data-tour="cal-modal"]',
            title: 'Nuovo evento',
            body: 'Con "Nuovo evento" indichi titolo, giorno, orario, luogo e i colleghi da invitare. Gli invitati possono accettare o rifiutare. Finché non premi "Crea evento" non viene salvato nulla.',
            onEnter: function () { if (typeof window.calOpenModal === 'function') window.calOpenModal(); },
            onExit: function () { if (typeof window.calCloseModal === 'function') window.calCloseModal(); }
        },
        {
            id: 'chat', page: 'chat', chapter: 'Chat',
            target: ['[data-tour="chat-pinned"]', '[data-tour="chat-search"]'],
            title: 'Chat',
            body: function (el) {
                var pinned = el && el.getAttribute('data-tour') === 'chat-pinned';
                return (pinned ? 'In "In evidenza" trovi sempre l\'Amministrazione e il Consulente del lavoro. ' : '')
                    + 'Cerca un collega per nome e tocca il contatto per iniziare a scrivere. Puoi anche allegare file.';
            }
        },
        {
            id: 'profile', page: 'profile', chapter: 'Profilo',
            target: '[data-tour="profile-notify"]',
            title: 'Il tuo profilo',
            body: 'Qui aggiorni foto e nome e scegli se ricevere le notifiche via email o push. Il profilo lo apri sempre toccando la tua foto in alto a destra.'
        },
        {
            id: 'done', page: 'profile', chapter: 'Profilo',
            target: '[data-tour="profile-guide"]',
            title: 'Hai finito!',
            body: 'Puoi rivedere questa guida quando vuoi da qui. Buon lavoro con Cora.',
            next: 'Fine'
        }
    ];

    // ---------- Stato in sessionStorage ----------
    function readStore() {
        try {
            var s = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || 'null');
            if (!s || s.emp !== CFG.employeeId || s.sess !== CFG.session || Date.now() - s.ts > MAX_AGE_MS) {
                clearStore();
                return null;
            }
            return s;
        } catch (e) { return null; }
    }
    function writeStore(stepId) {
        try {
            window.sessionStorage.setItem(STORE_KEY, JSON.stringify({
                emp: CFG.employeeId, sess: CFG.session, step: stepId, replay: replay, ts: Date.now()
            }));
        } catch (e) {}
    }
    function clearStore() {
        try { window.sessionStorage.removeItem(STORE_KEY); } catch (e) {}
    }

    function indexOfStep(id) {
        for (var i = 0; i < STEPS.length; i++) if (STEPS[i].id === id) return i;
        return -1;
    }
    function applies(step) {
        if (step.mobileOnly && !isMobileLayout()) return false;
        if (step.page === 'index' && CFG.figmaHome) return false;
        return true;
    }
    function applicableSteps() { return STEPS.filter(applies); }
    function resolve(val, arg) { return typeof val === 'function' ? val(arg) : val; }

    // ---------- DOM della guida ----------
    var root, spot, tip, tipChapter, tipTitle, tipBody, tipBar, tipCount, btnPrev, btnNext, btnSkip, pill;
    var active = false, replay = false, cur = -1, curEl = null, renderToken = 0;
    var sheetSide = null, trackTimer = null, rafId = 0, resizeTimer = null, ro = null;
    var lastWidth = window.innerWidth;

    var ICON_X = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';

    function build() {
        if (root) return;
        root = document.createElement('div');
        root.className = 'pt-root';
        root.innerHTML =
            '<div class="pt-spot is-none"></div>' +
            '<div class="pt-tip" role="dialog" aria-modal="true" aria-labelledby="ptTitle" aria-describedby="ptBody">' +
                '<div class="pt-tip-head"><span class="pt-chapter"></span>' +
                '<button type="button" class="pt-x" aria-label="Chiudi la guida">' + ICON_X + '</button></div>' +
                '<h3 class="pt-title" id="ptTitle"></h3>' +
                '<p class="pt-body" id="ptBody"></p>' +
                '<div class="pt-foot">' +
                    '<div class="pt-progress"><div class="pt-bar"><span></span></div><span class="pt-count"></span></div>' +
                    '<div class="pt-actions">' +
                        '<button type="button" class="pt-btn pt-btn-link pt-skip">Salta la guida</button>' +
                        '<button type="button" class="pt-btn pt-btn-ghost pt-prev">Indietro</button>' +
                        '<button type="button" class="pt-btn pt-btn-primary pt-next">Avanti</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(root);
        spot = root.querySelector('.pt-spot');
        tip = root.querySelector('.pt-tip');
        tipChapter = root.querySelector('.pt-chapter');
        tipTitle = root.querySelector('.pt-title');
        tipBody = root.querySelector('.pt-body');
        tipBar = root.querySelector('.pt-bar span');
        tipCount = root.querySelector('.pt-count');
        btnPrev = root.querySelector('.pt-prev');
        btnNext = root.querySelector('.pt-next');
        btnSkip = root.querySelector('.pt-skip');
        root.querySelector('.pt-x').addEventListener('click', closeTour);
        btnSkip.addEventListener('click', function () { finish('skipped'); });
        btnPrev.addEventListener('click', function () { go(cur - 1, -1); });
        btnNext.addEventListener('click', function () {
            if (STEPS[cur] && STEPS[cur].id === 'done') finish('completed');
            else go(cur + 1, 1);
        });
    }

    // ---------- Avvio / navigazione ----------
    function start(isReplay) {
        hidePill();
        replay = !!isReplay;
        go(0, 1);
    }

    function go(idx, dir) {
        while (idx >= 0 && idx < STEPS.length && !applies(STEPS[idx])) idx += dir;
        if (idx >= STEPS.length) { finish('completed'); return; }
        if (idx < 0) return;
        var step = STEPS[idx];
        if (step.page !== '*' && step.page !== CFG.page) {
            exitCurrent();
            writeStore(step.id);
            window.location.href = CFG.baseUrl + PAGE_URLS[step.page];
            return;
        }
        render(idx, dir || 1);
    }

    function exitCurrent() {
        if (cur >= 0 && STEPS[cur] && STEPS[cur].onExit) {
            try { STEPS[cur].onExit(); } catch (e) {}
        }
    }

    function render(idx, dir) {
        build();
        var token = ++renderToken;
        if (idx !== cur) exitCurrent();
        cur = idx;
        var step = STEPS[idx];
        writeStore(step.id);
        activate();
        if (step.onEnter) { try { step.onEnter(); } catch (e) {} }

        var selectors = step.target ? [].concat(resolve(step.target)) : null;
        if (!selectors) {
            curEl = null;
            fillTip(step, null);
            placeCentered();
            return;
        }
        root.classList.add('is-moving');
        waitForTarget(selectors, WAIT_TARGET_MS).then(function (el) {
            if (token !== renderToken || !active) return;
            if (!el) {
                // Elemento non disponibile (funzione disattivata o dati assenti): passo saltato
                if (step.onExit) { try { step.onExit(); } catch (e) {} }
                cur = -1;
                go(idx + dir, dir);
                return;
            }
            curEl = el;
            observeTarget(el);
            fillTip(step, el);
            placeOnTarget(el, true, token);
        });
    }

    function fillTip(step, el) {
        var list = applicableSteps();
        var pos = list.indexOf(step) + 1;
        tipChapter.textContent = step.chapter;
        tipTitle.textContent = step.title;
        tipBody.textContent = resolve(step.body, el);
        tipCount.textContent = pos + ' di ' + list.length;
        tipBar.style.width = Math.round(pos / list.length * 100) + '%';
        btnNext.textContent = step.next || 'Avanti';
        btnSkip.hidden = !step.showSkip;
        btnPrev.hidden = pos <= 1;
    }

    function activate() {
        if (active) return;
        active = true;
        document.documentElement.classList.add('pt-active');
        document.addEventListener('click', blockOutside, true);
        document.addEventListener('submit', blockOutside, true);
        document.addEventListener('dragstart', blockOutside, true);
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onResize);
        window.addEventListener('orientationchange', onResize);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', onResize);
            window.visualViewport.addEventListener('scroll', onScroll);
        }
        root.classList.add('is-open');
    }

    function deactivate() {
        active = false;
        renderToken++;
        document.documentElement.classList.remove('pt-active');
        document.removeEventListener('click', blockOutside, true);
        document.removeEventListener('submit', blockOutside, true);
        document.removeEventListener('dragstart', blockOutside, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('scroll', onScroll, true);
        window.removeEventListener('resize', onResize);
        window.removeEventListener('orientationchange', onResize);
        if (window.visualViewport) {
            window.visualViewport.removeEventListener('resize', onResize);
            window.visualViewport.removeEventListener('scroll', onScroll);
        }
        if (ro) { ro.disconnect(); ro = null; }
        if (root) root.classList.remove('is-open', 'is-moving');
    }

    function finish(status) {
        exitCurrent();
        cur = -1;
        curEl = null;
        clearStore();
        deactivate();
        hidePill();
        if (!replay && CFG.status === null) {
            CFG.status = status;
            saveStatus(status);
        }
        replay = false;
    }

    // Chiudere sull'ultimo passo equivale ad averla completata
    function closeTour() {
        finish(STEPS[cur] && STEPS[cur].id === 'done' ? 'completed' : 'skipped');
    }

    function saveStatus(status) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        try {
            fetch(CFG.api, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ status: status, csrf_token: token })
            }).catch(function () {});
        } catch (e) {}
    }

    // ---------- Guardie di interazione ----------
    function blockOutside(e) {
        if (!active || (root && root.contains(e.target))) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        tip.classList.remove('pt-nudge');
        void tip.offsetWidth;
        tip.classList.add('pt-nudge');
    }

    function onKey(e) {
        if (!active) return;
        if (e.key === 'Escape') {
            e.preventDefault(); e.stopPropagation();
            closeTour();
        } else if (e.key === 'ArrowRight' && !isTyping(e)) {
            e.preventDefault(); btnNext.click();
        } else if (e.key === 'ArrowLeft' && !isTyping(e)) {
            e.preventDefault(); if (!btnPrev.hidden) btnPrev.click();
        } else if (e.key === 'Tab') {
            // Focus confinato nella guida
            var f = [].slice.call(tip.querySelectorAll('button:not([hidden])'));
            if (!f.length) return;
            var i = f.indexOf(document.activeElement);
            e.preventDefault();
            var n = e.shiftKey ? (i <= 0 ? f.length - 1 : i - 1) : (i + 1) % f.length;
            f[n].focus();
        }
    }
    function isTyping(e) {
        var t = e.target;
        return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
    }

    // ---------- Ricerca elemento ----------
    function isVisible(el) {
        if (!el || !el.isConnected) return false;
        var r = el.getBoundingClientRect();
        if (r.width < 1 || r.height < 1) return false;
        var cs = window.getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none';
    }
    function findTarget(selectors) {
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (isVisible(el)) return el;
        }
        return null;
    }
    function waitForTarget(selectors, timeout) {
        return new Promise(function (resolveP) {
            var el = findTarget(selectors);
            if (el) { resolveP(el); return; }
            var done = false;
            var mo = new MutationObserver(check);
            mo.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
            var timer = setTimeout(function () { finishWait(null); }, timeout);
            var poll = setInterval(check, 120);
            function check() { var found = findTarget(selectors); if (found) finishWait(found); }
            function finishWait(res) {
                if (done) return;
                done = true;
                mo.disconnect(); clearTimeout(timer); clearInterval(poll);
                resolveP(res);
            }
        });
    }

    function observeTarget(el) {
        if (ro) ro.disconnect();
        if (!('ResizeObserver' in window)) return;
        ro = new ResizeObserver(function () { scheduleTrack(); });
        ro.observe(el);
        ro.observe(document.body);
    }

    // ---------- Geometria ----------
    function viewport() {
        var vv = window.visualViewport;
        return { w: vv ? vv.width : window.innerWidth, h: vv ? vv.height : window.innerHeight };
    }

    // Spazio occupato da header e bottom-nav fissi, che coprono il contenuto scrollabile
    function insets() {
        var top = 0, bottom = 0;
        var hdr = document.querySelector('.app-header');
        if (hdr) {
            var hs = window.getComputedStyle(hdr);
            if ((hs.position === 'sticky' || hs.position === 'fixed') && hs.display !== 'none') {
                var hr = hdr.getBoundingClientRect();
                if (hr.top <= 1 && hr.bottom > 0) top = hr.bottom;
            }
        }
        var bn = document.querySelector('.emp-bottom-nav');
        if (bn && window.getComputedStyle(bn).display !== 'none') {
            var br = bn.getBoundingClientRect();
            if (br.height > 0) bottom = Math.max(0, window.innerHeight - br.top);
        }
        return { top: top, bottom: bottom };
    }

    // Elemento ancorato allo schermo (fixed o dentro header/barre sticky): niente scroll, niente ritaglio
    function isFixed(el) {
        for (var n = el; n && n !== document.body; n = n.parentElement) {
            var pos = window.getComputedStyle(n).position;
            if (pos === 'fixed' || pos === 'sticky') return true;
        }
        return false;
    }

    function scrollParent(el) {
        for (var n = el.parentElement; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
            var cs = window.getComputedStyle(n);
            if (/(auto|scroll|overlay)/.test(cs.overflowY) && n.scrollHeight > n.clientHeight + 1) return n;
        }
        return null;
    }

    // Delta di scroll per portare r dentro [top, bottom]: centrato se ci sta, altrimenti allineato in alto
    function scrollDelta(r, top, bottom) {
        var h = bottom - top;
        if (r.height <= h) {
            if (r.top >= top && r.bottom <= bottom) return 0;
            return (r.top + r.height / 2) - (top + h / 2);
        }
        return r.top - top;
    }

    // Prima i contenitori scrollabili interni, poi la pagina
    function scrollIntoZone(el, zTop, zBot) {
        var p = scrollParent(el);
        while (p) {
            var pr = p.getBoundingClientRect();
            var d = scrollDelta(el.getBoundingClientRect(), Math.max(pr.top, zTop), Math.min(pr.bottom, zBot));
            if (Math.abs(d) > 1) p.scrollTop += d;
            p = scrollParent(p);
        }
        var dw = scrollDelta(el.getBoundingClientRect(), zTop, zBot);
        if (Math.abs(dw) > 1) window.scrollBy({ top: dw, behavior: 'smooth' });
    }

    // Attende che la posizione dell'elemento resti ferma per qualche frame
    function waitStable(el, maxMs) {
        return new Promise(function (resolveP) {
            var last = null, same = 0, t0 = performance.now();
            function frame() {
                var r = el.getBoundingClientRect();
                var key = Math.round(r.top) + ',' + Math.round(r.left) + ',' + Math.round(r.width) + ',' + Math.round(r.height);
                same = key === last ? same + 1 : 0;
                last = key;
                if (same >= 4 || performance.now() - t0 > maxMs) resolveP();
                else requestAnimationFrame(frame);
            }
            requestAnimationFrame(frame);
        });
    }

    function measureTip() {
        return { w: tip.offsetWidth, h: tip.offsetHeight };
    }

    // Area libera per l'elemento, dato il lato in cui sta la scheda su mobile
    function zoneFor(side, fixed, vp, ins, tipH) {
        var pad = 10, gap = 14;
        var top = fixed ? pad : ins.top + pad;
        var bottom = fixed ? vp.h - pad : vp.h - ins.bottom - pad;
        if (side === 'bottom') bottom = Math.min(bottom, vp.h - tipH - gap - pad);
        if (side === 'top') top = Math.max(top, tipH + gap + pad);
        return { top: top, bottom: bottom };
    }

    function chooseSheetSide(el, fixed, vp, ins, tipH) {
        var r = el.getBoundingClientRect();
        var zb = zoneFor('bottom', fixed, vp, ins, tipH);
        var zt = zoneFor('top', fixed, vp, ins, tipH);
        if (fixed) {
            var ob = Math.max(0, r.bottom - zb.bottom);
            var ot = Math.max(0, zt.top - r.top);
            return ob <= ot ? 'bottom' : 'top';
        }
        var fitsB = r.height <= zb.bottom - zb.top;
        var fitsT = r.height <= zt.bottom - zt.top;
        if (fitsB !== fitsT) return fitsB ? 'bottom' : 'top';
        return Math.abs(scrollDelta(r, zb.top, zb.bottom)) <= Math.abs(scrollDelta(r, zt.top, zt.bottom)) ? 'bottom' : 'top';
    }

    function placeCentered() {
        sheetSide = null;
        root.classList.remove('is-moving');
        tip.className = 'pt-tip is-center is-ready';
        tip.style.left = tip.style.top = '';
        spot.className = 'pt-spot is-none';
        spot.style.cssText = '';
        focusNext();
    }

    function placeOnTarget(el, withScroll, token) {
        var vp = viewport(), ins = insets(), fixed = isFixed(el);
        var sheet = isSheetLayout();
        tip.className = 'pt-tip ' + (sheet ? 'is-sheet' : 'is-pop');
        var th = measureTip().h;
        var zone;
        if (sheet) {
            sheetSide = chooseSheetSide(el, fixed, vp, ins, th);
            zone = zoneFor(sheetSide, fixed, vp, ins, th);
        } else {
            sheetSide = null;
            zone = zoneFor(null, fixed, vp, ins, th);
            // Riserva lo spazio della scheda sotto l'elemento: se e' piu' alto, resta visibile la parte superiore
            if (!fixed) zone = { top: zone.top, bottom: zone.bottom - th - 14 };
        }
        var attempts = 0;
        function settle() {
            if (withScroll && !fixed) scrollIntoZone(el, zone.top, zone.bottom);
            waitStable(el, 900).then(function () {
                if (token !== renderToken || !active) return;
                // Il browser puo' annullare lo scroll (es. ripristino posizione dopo un refresh): si riprova
                var r = el.getBoundingClientRect();
                if (withScroll && !fixed && attempts++ < 2 && Math.abs(scrollDelta(r, zone.top, zone.bottom)) > 4) {
                    settle();
                    return;
                }
                root.classList.remove('is-moving');
                track(true);
                focusNext();
            });
        }
        settle();
        root.classList.add('is-moving');
        track(false);
    }

    function focusNext() {
        try { btnNext.focus({ preventScroll: true }); } catch (e) { btnNext.focus(); }
    }

    // Allinea riquadro e scheda alla posizione attuale dell'elemento (nessuno scroll automatico)
    function track(showTip) {
        if (!active || !curEl) return;
        if (!curEl.isConnected) { render(cur, 1); return; }
        var vp = viewport(), ins = insets(), fixed = isFixed(curEl);
        var r = curEl.getBoundingClientRect();
        var pad = 6;
        var clipTop = fixed ? 0 : ins.top, clipBot = fixed ? vp.h : vp.h - ins.bottom;
        var top = Math.max(r.top - pad, clipTop), bottom = Math.min(r.bottom + pad, clipBot);
        var left = Math.max(r.left - pad, 4), right = Math.min(r.right + pad, vp.w - 4);
        var radius = Math.min(20, (parseFloat(window.getComputedStyle(curEl).borderTopLeftRadius) || 8) + 4);

        var tw = tip.offsetWidth, th = tip.offsetHeight, gap = 14, m = 12;
        var tr;
        if (sheetSide) {
            tip.classList.toggle('is-top', sheetSide === 'top');
            tip.classList.toggle('is-bottom', sheetSide === 'bottom');
            tip.style.left = tip.style.top = '';
            var b = tip.getBoundingClientRect();
            tr = { top: b.top, bottom: b.bottom, left: b.left, right: b.right };
        } else {
            var pos = popPosition({ top: top, bottom: bottom, left: left, right: right }, tw, th, vp, gap, m);
            tip.style.left = pos.left + 'px';
            tip.style.top = pos.top + 'px';
            tr = { top: pos.top, bottom: pos.top + th, left: pos.left, right: pos.left + tw };
        }
        // L'evidenziazione non passa mai sotto la scheda: mostra solo la parte libera dell'elemento
        if (tr.left < right && tr.right > left && tr.top < bottom && tr.bottom > top) {
            if (tr.top >= (top + bottom) / 2 || tr.bottom >= bottom) bottom = Math.min(bottom, tr.top - 10);
            else top = Math.max(top, tr.bottom + 10);
        }

        if (bottom - top < 4 || right - left < 4) {
            spot.className = 'pt-spot is-hidden';
            spot.style.cssText = '';
        } else {
            spot.className = 'pt-spot';
            spot.style.cssText = 'top:' + top + 'px;left:' + left + 'px;width:' + (right - left) + 'px;height:' + (bottom - top) + 'px;border-radius:' + radius + 'px;';
        }
        if (showTip) tip.classList.add('is-ready');
    }

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

    function popPosition(r, tw, th, vp, gap, m) {
        var cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2;
        var cands = [
            { top: r.bottom + gap, left: cx - tw / 2, ok: r.bottom + gap + th <= vp.h - m },
            { top: r.top - gap - th, left: cx - tw / 2, ok: r.top - gap - th >= m },
            { top: cy - th / 2, left: r.right + gap, ok: r.right + gap + tw <= vp.w - m },
            { top: cy - th / 2, left: r.left - gap - tw, ok: r.left - gap - tw >= m }
        ];
        for (var i = 0; i < cands.length; i++) {
            if (cands[i].ok) {
                return { top: clamp(cands[i].top, m, vp.h - th - m), left: clamp(cands[i].left, m, vp.w - tw - m) };
            }
        }
        // Elemento molto grande: scheda in basso al centro, l'evidenziazione si ferma sopra
        return { top: vp.h - th - m, left: clamp(cx - tw / 2, m, vp.w - tw - m) };
    }

    function scheduleTrack() {
        if (rafId) return;
        rafId = requestAnimationFrame(function () { rafId = 0; track(false); });
    }

    function onScroll() {
        if (!active || !curEl) return;
        root.classList.add('is-tracking');
        clearTimeout(trackTimer);
        trackTimer = setTimeout(function () { root.classList.remove('is-tracking'); }, 160);
        scheduleTrack();
    }

    function onResize() {
        if (!active) return;
        scheduleTrack();
        // Solo altezza cambiata (barra indirizzi mobile, tastiera virtuale): basta riallineare,
        // senza riposizionare lo scroll che l'utente sta facendo
        if (Math.abs(window.innerWidth - lastWidth) < 2) return;
        lastWidth = window.innerWidth;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (!active || cur < 0) return;
            var step = STEPS[cur];
            if (!applies(step)) { go(cur + 1, 1); return; }
            render(cur, 1);
        }, 220);
    }

    // ---------- Guida in pausa (pagina cambiata a mano) ----------
    function showPill(idx) {
        if (pill) return;
        pill = document.createElement('div');
        pill.className = 'pt-pill';
        pill.setAttribute('role', 'status');
        pill.innerHTML = '<span class="pt-pill-dot"></span><span class="pt-pill-txt">Guida in pausa</span>' +
            '<button type="button" class="pt-pill-resume">Riprendi</button>' +
            '<button type="button" class="pt-pill-x" aria-label="Chiudi la guida">' + ICON_X + '</button>';
        document.body.appendChild(pill);
        var ins = insets();
        pill.style.bottom = 'calc(' + (ins.bottom + 14) + 'px + env(safe-area-inset-bottom, 0px))';
        pill.querySelector('.pt-pill-resume').addEventListener('click', function () { go(idx, 1); });
        pill.querySelector('.pt-pill-x').addEventListener('click', function () { finish('skipped'); });
    }
    function hidePill() {
        if (pill) { pill.remove(); pill = null; }
    }

    // ---------- Avvio ----------
    function init() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-tour-restart]');
            if (btn) { e.preventDefault(); start(true); return; }
            var out = e.target.closest && e.target.closest('a[href*="/auth/logout.php"]');
            if (out) clearStore();
        });

        var saved = readStore();
        if (saved) {
            var idx = indexOfStep(saved.step);
            if (idx < 0) { clearStore(); return; }
            replay = !!saved.replay;
            var step = STEPS[idx];
            if (step.page === '*' || step.page === CFG.page) render(idx, 1);
            else showPill(idx);
            return;
        }
        if (CFG.status === null && CFG.page === 'index' && !CFG.figmaHome) {
            start(false);
        }
    }

    // Avvio a pagina caricata: immagini e font cambiano le altezze, e il ripristino dello scroll avviene prima
    // (con un limite di attesa, se una risorsa esterna tarda)
    var started = false;
    function initOnce() { if (!started) { started = true; init(); } }
    if (document.readyState === 'complete') initOnce();
    else {
        window.addEventListener('load', function () { setTimeout(initOnce, 0); });
        setTimeout(initOnce, 2500);
    }

    window.PamTour = { start: function () { start(true); } };
})();
