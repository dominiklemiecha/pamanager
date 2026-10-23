/**
 * Cora — comportamenti comuni della vista dipendente.
 * Campanella, anello dei moduli, toast, ingresso a cascata, modali, filtri.
 */
(function () {
    'use strict';

    // Le modali escono dal contenitore scrollabile (che ha la maschera di
    // dissolvenza in basso) e coprono tutta la pagina, dock compreso.
    document.querySelectorAll('.cora-modal').forEach(function (m) { document.body.appendChild(m); });

    var baseUrl = (window.PAM && window.PAM.baseUrl) || '';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function closeAll(except) {
        document.querySelectorAll('.cora-bell.is-open, .cora-dock-cora.is-open').forEach(function (el) {
            if (el === except) return;
            el.classList.remove('is-open');
            var b = el.querySelector('[aria-expanded]');
            if (b) b.setAttribute('aria-expanded', 'false');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.cora-bell, .cora-dock-cora')) closeAll(null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
    });

    /* Campanella: all'apertura le notifiche diventano lette */
    document.querySelectorAll('[data-cora-bell]').forEach(function (bell) {
        var btn = bell.querySelector('button');
        var marked = false;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !bell.classList.contains('is-open');
            closeAll(bell);
            bell.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            var dot = bell.querySelector('[data-cora-bell-dot]');
            if (open && dot && !marked) {
                marked = true;
                fetch(baseUrl + '/api/notifications-read.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrf }
                }).then(function (r) { if (r.ok) dot.remove(); }).catch(function () {});
            }
        });
    });

    /* Anello dei moduli (voce Cora del dock) */
    document.querySelectorAll('[data-cora-modules]').forEach(function (wrap) {
        var btn = wrap.querySelector('button');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !wrap.classList.contains('is-open');
            closeAll(wrap);
            wrap.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    /* Telefono: "Altro" apre il foglio con le altre sezioni */
    var moreBtn = document.querySelector('[data-cora-more]');
    var moreSheet = document.querySelector('[data-cora-more-sheet]');
    if (moreBtn && moreSheet) {
        var setMore = function (open) {
            if (open) {
                moreSheet.hidden = false;
                requestAnimationFrame(function () { moreSheet.classList.add('is-open'); });
            } else {
                moreSheet.classList.remove('is-open');
                setTimeout(function () { if (!moreSheet.classList.contains('is-open')) moreSheet.hidden = true; }, 220);
            }
            moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        moreBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAll(null);
            setMore(moreSheet.hidden);
        });
        moreSheet.addEventListener('click', function (e) {
            if (!e.target.closest('.cora-more-box')) setMore(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !moreSheet.hidden) setMore(false);
        });
    }

    /* Dock scorrevole su mobile: voce attiva visibile, dissolvenza solo se c'e' altro a destra */
    var dockItems = document.querySelector('.cora-dock-items');
    if (dockItems) {
        var activeItem = dockItems.querySelector('.is-active');
        if (activeItem && dockItems.scrollWidth > dockItems.clientWidth) {
            dockItems.scrollLeft = Math.max(0, activeItem.offsetLeft - dockItems.clientWidth / 2 + activeItem.offsetWidth / 2);
        }
        var dockEnd = function () {
            dockItems.classList.toggle('is-end', dockItems.scrollLeft + dockItems.clientWidth >= dockItems.scrollWidth - 2);
        };
        dockItems.addEventListener('scroll', dockEnd, { passive: true });
        dockEnd();
    }

    /* Toast */
    var CHECK = '<svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><path d="M3.75 9.375l3.375 3.375 7.125-7.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var ALERT = '<svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><path d="M9 5.25v4.5M9 12.375h.008" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>';
    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(title, message, kind) {
        var host = document.getElementById('coraToasts');
        if (!host) return;
        var el = document.createElement('div');
        el.className = 'cora-toast' + (kind === 'error' ? ' is-error' : '');
        el.setAttribute('role', 'status');
        el.innerHTML = '<span class="cora-toast-ic">' + (kind === 'error' ? ALERT : CHECK) + '</span>' +
            '<div><div class="cora-toast-t">' + esc(title) + '</div>' +
            (message ? '<div class="cora-toast-m">' + esc(message) + '</div>' : '') + '</div>';
        host.appendChild(el);
        setTimeout(function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 260);
        }, kind === 'error' ? 7000 : 5000);
    }

    /* Ingresso a cascata: gli elementi .cora-reveal compaiono riga per riga */
    function reveal() {
        var items = document.querySelectorAll('.cora-reveal');
        items.forEach(function (el, i) {
            if (!el.style.getPropertyValue('--i')) el.style.setProperty('--i', String(i));
        });
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { document.body.classList.add('cora-revealed'); });
        });
    }

    /* Modali: [data-cora-open="id"] apre, [data-cora-close] chiude */
    function openModal(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.hidden = false;
        requestAnimationFrame(function () { m.classList.add('is-open'); });
        var f = m.querySelector('[data-cora-autofocus]');
        if (f) setTimeout(function () { f.focus(); }, 60);
        document.dispatchEvent(new CustomEvent('cora:modal-open', { detail: { id: id } }));
    }
    function closeModal(m) {
        m.classList.remove('is-open');
        setTimeout(function () { m.hidden = true; }, 180);
    }
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-cora-open]');
        if (opener) { e.preventDefault(); openModal(opener.getAttribute('data-cora-open')); return; }
        var closer = e.target.closest('[data-cora-close]');
        if (closer) { e.preventDefault(); closeModal(closer.closest('.cora-modal')); return; }
        if (e.target.classList && e.target.classList.contains('cora-modal')) closeModal(e.target);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.cora-modal.is-open').forEach(closeModal);
    });

    /*
     * Filtri lato client: [data-cora-filter-group] contiene i tab
     * ([data-filter="valore"]); gli elementi [data-cora-filterable] hanno
     * data-tags="a b c". Le sezioni [data-cora-section] spariscono se vuote.
     * Una ricerca [data-cora-search] filtra su data-search.
     */
    document.querySelectorAll('[data-cora-filter-group]').forEach(function (group) {
        var scope = document.querySelector(group.getAttribute('data-cora-filter-group')) || document;
        var search = scope.querySelector('[data-cora-search]');
        var activeTab = group.querySelector('.cora-tab.is-active');
        var current = activeTab ? activeTab.dataset.filter : 'all';

        function apply() {
            var q = search ? search.value.trim().toLowerCase() : '';
            scope.querySelectorAll('[data-cora-filterable]').forEach(function (el) {
                var tags = (' ' + (el.getAttribute('data-tags') || '') + ' ');
                var okTab = current === 'all' || tags.indexOf(' ' + current + ' ') !== -1;
                var okQ = !q || (el.getAttribute('data-search') || '').toLowerCase().indexOf(q) !== -1;
                el.hidden = !(okTab && okQ);
            });
            var anyVisible = false;
            scope.querySelectorAll('[data-cora-section]').forEach(function (sec) {
                var vis = sec.querySelectorAll('[data-cora-filterable]:not([hidden])').length;
                sec.hidden = vis === 0;
                var c = sec.querySelector('[data-cora-section-count]');
                if (c) c.textContent = String(vis);
                if (vis) anyVisible = true;
            });
            var empty = scope.querySelector('[data-cora-filter-empty]');
            if (empty) empty.hidden = anyVisible;
        }

        group.addEventListener('click', function (e) {
            var tab = e.target.closest('.cora-tab[data-filter]');
            if (!tab) return;
            group.querySelectorAll('.cora-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            current = tab.dataset.filter;
            apply();
        });
        if (search) search.addEventListener('input', apply);
        apply();
    });

    /*
     * Caricamento file: ogni input.cora-file diventa un'area "trascina qui o
     * sfoglia". Un file trascinato in qualsiasi punto della pagina finisce
     * nell'area sotto il cursore oppure nella prima visibile (dando
     * precedenza alla modale aperta); in mancanza, nell'input marcato
     * [data-cora-drop-target] (es. foto profilo, che si invia da sola).
     */
    var UP_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" aria-hidden="true"><path d="M12 15.5V4.5m0 0L8 8.5m4-4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 14.5v2.75A2.25 2.25 0 006.75 19.5h10.5a2.25 2.25 0 002.25-2.25V14.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    var FILE_ICON = '<svg viewBox="0 0 20 20" width="20" height="20" fill="none" aria-hidden="true"><path d="M11.667 1.667H5A1.667 1.667 0 003.333 3.333v13.334A1.667 1.667 0 005 18.333h10a1.667 1.667 0 001.667-1.666V6.667l-5-5z" stroke="currentColor" stroke-width="1.35" stroke-linejoin="round"/><path d="M11.667 1.667v5h5" stroke="currentColor" stroke-width="1.35" stroke-linejoin="round"/></svg>';
    var X_ICON = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" aria-hidden="true"><path d="M12 4L4 12M4 4l8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

    function fileSize(b) { return b >= 1048576 ? (Math.round(b / 104857.6) / 10).toString().replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function acceptLabel(accept) {
        var ext = (accept || '').split(',').map(function (a) { return a.trim(); })
            .filter(function (a) { return a.charAt(0) === '.'; })
            .map(function (a) { return a.slice(1).toUpperCase(); })
            .filter(function (a) { return a !== 'JPEG'; });
        if (!ext.length) return '';
        return ext.length === 1 ? ext[0] : ext.slice(0, -1).join(', ') + ' o ' + ext[ext.length - 1];
    }
    function accepts(input, file) {
        var acc = (input.getAttribute('accept') || '').split(',').map(function (a) { return a.trim().toLowerCase(); }).filter(Boolean);
        if (!acc.length) return true;
        var name = file.name.toLowerCase(), type = (file.type || '').toLowerCase();
        return acc.some(function (a) {
            if (a.charAt(0) === '.') return name.slice(-a.length) === a;
            if (a.slice(-2) === '/*') return type.indexOf(a.slice(0, -1)) === 0;
            return type === a;
        });
    }
    function setFile(input, file) {
        if (!accepts(input, file)) {
            var lbl = acceptLabel(input.getAttribute('accept'));
            toast('Formato non supportato', lbl ? 'Puoi caricare file ' + lbl + '.' : '', 'error');
            return false;
        }
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function enhanceFile(input) {
        if (input.dataset.coraDrop) return;
        input.dataset.coraDrop = '1';
        var zone = document.createElement('div');
        zone.className = 'cora-drop';
        var hint = acceptLabel(input.getAttribute('accept'));
        zone.innerHTML =
            '<div class="cora-drop-empty"><span class="cora-drop-ic">' + UP_ICON + '</span>' +
            '<span class="cora-drop-txt"><strong>Trascina qui il file o <u>sfoglia</u></strong>' +
            (hint ? '<span>' + hint + '</span>' : '') + '</span></div>' +
            '<div class="cora-drop-file"><span class="cora-drop-fic">' + FILE_ICON + '</span>' +
            '<span class="cora-drop-txt"><strong data-name></strong><span data-size></span></span>' +
            '<button type="button" class="cora-drop-x" aria-label="Rimuovi file">' + X_ICON + '</button></div>';
        input.parentNode.insertBefore(zone, input);
        zone.appendChild(input);
        input.classList.add('cora-drop-input');

        function refresh() {
            var f = input.files && input.files[0];
            zone.classList.toggle('has-file', !!f);
            if (f) {
                zone.querySelector('[data-name]').textContent = f.name;
                zone.querySelector('[data-size]').textContent = fileSize(f.size);
            }
        }
        input.addEventListener('change', refresh);
        zone.addEventListener('click', function (e) {
            if (e.target.closest('.cora-drop-x')) { e.preventDefault(); input.value = ''; refresh(); return; }
            if (e.target !== input) { e.preventDefault(); input.click(); }
        });
        zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('is-over'); });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('is-over');
            endDrag();
            var f = e.dataTransfer && e.dataTransfer.files[0];
            if (f) setFile(input, f);
        });
        refresh();
    }
    document.querySelectorAll('input[type="file"].cora-file').forEach(enhanceFile);

    function visible(el) { return !!(el && el.offsetParent !== null && el.getClientRects().length); }
    function dropTarget() {
        var modal = document.querySelector('.cora-modal.is-open');
        var scope = modal || document;
        var zones = [].filter.call(scope.querySelectorAll('.cora-drop'), visible);
        if (zones.length) return zones[0].querySelector('input[type="file"]');
        return modal ? null : document.querySelector('input[type="file"][data-cora-drop-target]');
    }
    var dragDepth = 0;
    var overlay = document.createElement('div');
    overlay.className = 'cora-drop-overlay';
    overlay.innerHTML = '<div><span class="cora-drop-ic">' + UP_ICON + '</span><strong>Rilascia per caricare</strong></div>';
    document.body.appendChild(overlay);
    function hasFiles(e) { return e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
    function endDrag() { dragDepth = 0; document.body.classList.remove('cora-dragging'); }
    window.addEventListener('dragenter', function (e) {
        if (!hasFiles(e) || !dropTarget()) return;
        dragDepth++;
        document.body.classList.add('cora-dragging');
    });
    window.addEventListener('dragleave', function (e) {
        if (!hasFiles(e)) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) endDrag();
    });
    window.addEventListener('dragover', function (e) { if (hasFiles(e)) e.preventDefault(); });
    window.addEventListener('drop', function (e) {
        if (!hasFiles(e)) return;
        e.preventDefault(); // mai aprire il file nel browser
        endDrag();
        var target = dropTarget();
        var f = e.dataTransfer.files[0];
        if (target && f) setFile(target, f);
    });

    window.Cora = {
        toast: toast,
        openModal: openModal,
        closeModal: function (id) { var m = document.getElementById(id); if (m && !m.hidden) closeModal(m); },
        // Foglio "Altro" del telefono (usato anche dalla guida)
        more: function (open) { if (setMore) setMore(!!open); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }

    /* Messaggio dal server (redirect dopo un'azione) */
    var flash = document.querySelector('[data-cora-flash]');
    if (flash) {
        toast(flash.getAttribute('data-title') || '', flash.getAttribute('data-message') || '', flash.getAttribute('data-kind') || '');
    }
    // Un ricaricamento non deve ripetere il toast ne' riaprire la modale
    if (window.history && history.replaceState && /[?&](message|new)=/.test(location.search)) {
        var u = new URL(location.href);
        u.searchParams.delete('message');
        u.searchParams.delete('new');
        history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
    }
})();
