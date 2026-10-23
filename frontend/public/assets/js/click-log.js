/**
 * Log dei clic dell'utenza HR: per ogni pulsante o link premuto invia
 * pagina, zona, tipo, etichetta e destinazione a /api/ui-click.php.
 * Caricato solo per il ruolo admin (footer-admin.php).
 * Per escludere un elemento: data-no-track. Per un nome esplicito: data-track="...".
 */
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var base = document.currentScript && document.currentScript.dataset.endpoint;
    if (!meta || !base || !navigator.sendBeacon) return;
    var token = meta.getAttribute('content');

    function text(el) {
        return (el.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function areaOf(el) {
        if (el.closest('#appSidebar')) return 'Menu laterale';
        if (el.closest('.bottom-nav, .sheet')) return 'Menu mobile';
        if (el.closest('dialog, [role="dialog"], .modal, .modal-overlay')) return 'Finestra';
        if (el.closest('.cd-tabs')) return 'Schede';
        if (el.closest('table')) return 'Tabella';
        if (el.closest('header, .app-topbar, .topbar, #userMenu')) return 'Barra in alto';
        if (el.closest('form')) return 'Modulo';
        return 'Pagina';
    }

    function pathOf(href) {
        try {
            var u = new URL(href, location.href);
            if (u.origin !== location.origin) return u.host;
            return u.pathname;
        } catch (e) { return ''; }
    }

    function labelOf(el, area) {
        if (el.dataset.track) return el.dataset.track;
        // Righe di tabella: il testo e' spesso un nome di persona, si registra solo dove porta
        if (area === 'Tabella' && el.closest('tbody')) {
            var t = el.getAttribute('aria-label') || el.getAttribute('title');
            if (t) return t;
            if (el.tagName === 'A') return 'Link in riga → ' + pathOf(el.href);
            return 'Pulsante in riga';
        }
        // Voci di menu: solo il titolo, senza sottotitoli e badge che cambiano
        var title = el.querySelector('.nav-title, .bn-label');
        var l = el.getAttribute('data-tooltip') || (title ? text(title) : '') || el.getAttribute('aria-label') || '';
        if (!l && el.tagName === 'INPUT') l = el.value || '';
        if (!l) l = text(el);
        // Contatori (badge, "3 in attesa") spezzerebbero i conteggi dello stesso pulsante
        l = l.replace(/\(\s*\d+\s*\)/g, '').replace(/(^|\s)\d+(?=\s|$)/g, ' ').replace(/\s+/g, ' ').trim();
        if (!l) l = el.getAttribute('title') || '';
        if (!l && el.tagName === 'A') l = pathOf(el.href);
        if (!l) l = el.id ? '#' + el.id : '';
        return l.slice(0, 100);
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('button, a[href], [role="button"], input[type="submit"], input[type="button"], summary');
        if (!el || el.closest('[data-no-track]')) return;
        var area = areaOf(el);
        var label = labelOf(el, area);
        if (!label) return;
        var fd = new FormData();
        fd.append('csrf_token', token);
        fd.append('page', location.pathname);
        fd.append('area', area);
        fd.append('kind', el.tagName === 'A' ? 'link' : 'button');
        fd.append('label', label);
        fd.append('target', el.tagName === 'A' ? pathOf(el.href) : (el.form && el.form.getAttribute('action') ? pathOf(el.form.action) : ''));
        try { navigator.sendBeacon(base, fd); } catch (err) { /* il log non deve mai bloccare il clic */ }
    }, true);
})();
