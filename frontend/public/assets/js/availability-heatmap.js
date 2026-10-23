/**
 * Heatmap presenze: ricerca dipendente + filtro stato + compressione dinamica avatar
 * Filtri persistenti via localStorage (sopravvivono al cambio settimana).
 */
(function () {
    const STORAGE_KEY = 'pamHeatmapFilters_v1';
    const AVATAR_W = 38;       // px, deve combaciare con CSS .heatmap-stack-photo
    const MIN_VISIBLE = 6;     // px minimi visibili tra un avatar e il successivo
    const MAX_VISIBLE = 28;    // px massimi (default spacing quando ci sta)

    function initAll() {
        document.querySelectorAll('.heatmap-card').forEach(initHeatmap);
        initAjaxNav();
    }

    // Esposta per re-init dopo sostituzione DOM via AJAX
    window.PAMHeatmapInit = initAll;

    function initAjaxNav() {
        document.querySelectorAll('.heatmap-card').forEach((card) => {
            if (card.dataset.ajaxBound === '1') return;
            card.dataset.ajaxBound = '1';
            card.addEventListener('click', async (e) => {
                const a = e.target.closest('.heatmap-nav-btn');
                if (!a) return;
                const url = a.getAttribute('href');
                if (!url || url === '#') return;
                e.preventDefault();
                card.classList.add('is-loading');
                try {
                    const r = await fetch(url, { credentials: 'same-origin' });
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const html = await r.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const fresh = doc.querySelector('.heatmap-card');
                    if (!fresh) throw new Error('no heatmap-card in response');
                    card.replaceWith(fresh);
                    history.pushState({ heatmap: true }, '', url);
                    initAll();
                } catch (err) {
                    console.warn('Heatmap AJAX nav failed, fallback to full reload', err);
                    window.location.href = url;
                }
            });
        });
    }

    // Gestione tasto avanti/indietro browser
    window.addEventListener('popstate', () => {
        const card = document.querySelector('.heatmap-card');
        if (!card) return;
        fetch(window.location.href, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const fresh = doc.querySelector('.heatmap-card');
                if (fresh) {
                    card.replaceWith(fresh);
                    initAll();
                }
            })
            .catch(() => {});
    });

    initAll();

    function initHeatmap(card) {
        const searchInput = card.querySelector('.heatmap-search-input');
        const searchClear = card.querySelector('.heatmap-search-clear');
        const legendBtns  = card.querySelectorAll('.heatmap-legend-btn');
        const avatars     = card.querySelectorAll('.heatmap-stack-avatar');
        if (!avatars.length) return;

        let currentQuery = '';
        const activeStates = new Set();

        // Ripristina filtri + query da localStorage
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            if (Array.isArray(saved.states)) saved.states.forEach(s => activeStates.add(s));
            if (typeof saved.query === 'string' && saved.query) {
                currentQuery = saved.query.toLowerCase();
                if (searchInput) {
                    searchInput.value = saved.query;
                    if (searchClear) searchClear.hidden = false;
                }
            }
        } catch (e) {}

        function persist() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    states: Array.from(activeStates),
                    query: searchInput ? searchInput.value : '',
                }));
            } catch (e) {}
        }

        function applyFilters() {
            const hasFilter = activeStates.size > 0;
            const filtering = !!currentQuery || hasFilter;
            avatars.forEach((av) => {
                const state = av.dataset.state || '';
                const name  = av.dataset.name  || '';
                const matchState = !hasFilter || activeStates.has(state);
                const matchQuery = !currentQuery || name.indexOf(currentQuery) !== -1;
                const visible = matchState && matchQuery;
                av.classList.toggle('is-hidden', !visible);
            });
            legendBtns.forEach((b) => {
                b.classList.toggle('is-active', activeStates.has(b.dataset.filterState));
            });
            // Con un filtro attivo mostra TUTTI i match (no cap a 8); altrimenti cap + "+N"
            card.querySelectorAll('.heatmap-stack').forEach((s) => {
                if (s.classList.contains('heatmap-stack-off')) return;
                s.classList.toggle('is-capped', !filtering);
            });
            updateRowEmptyState();
            updateCounts();
            fitAllStacks();
        }

        function updateRowEmptyState() {
            card.querySelectorAll('.heatmap-day-row').forEach((row) => {
                const stack = row.querySelector('.heatmap-stack');
                // I giorni non lavorativi/festivi (.heatmap-stack-off) non hanno avatar:
                // non vanno marcati "is-empty" (mostrano gia' la propria etichetta).
                if (!stack || stack.classList.contains('heatmap-stack-off')) return;
                const visible = stack.querySelectorAll('.heatmap-stack-avatar:not(.is-hidden)').length;
                row.classList.toggle('is-empty', visible === 0);
            });
        }

        function updateCounts() {
            card.querySelectorAll('.heatmap-day-row').forEach((row) => {
                const counters = row.querySelectorAll('.hm-count');
                if (!counters.length) return;
                const visible = { present: 0, busy: 0, pending: 0, absent: 0, sw: 0 };
                const absentByLeave = {};
                row.querySelectorAll('.heatmap-stack-avatar:not(.is-hidden)').forEach((av) => {
                    const s = av.dataset.state;
                    if (s in visible) visible[s]++;
                    if (s === 'absent') {
                        const lv = av.dataset.leave || 'assenza';
                        absentByLeave[lv] = (absentByLeave[lv] || 0) + 1;
                    }
                });
                counters.forEach((c) => {
                    // Chip assenti per causale ("2 permesso"): conta per data-leave,
                    // NON per stato — altrimenti ogni chip mostrerebbe il totale assenti.
                    if (c.dataset.leaveLabel !== undefined) {
                        const lv = c.dataset.leaveLabel;
                        const n = absentByLeave[lv] || 0;
                        c.textContent = n + ' ' + (c.dataset.abbr || lv);
                        c.style.display = n === 0 ? 'none' : '';
                        return;
                    }
                    const klass = Array.from(c.classList).find(cl => cl.startsWith('hm-count-'));
                    if (!klass) return;
                    const stateKey = klass.replace('hm-count-', '');
                    if (stateKey in visible) {
                        c.textContent = visible[stateKey];
                        c.style.display = visible[stateKey] === 0 ? 'none' : '';
                    }
                });
            });
        }

        // Layout a card: gli avatar vanno a capo su 2 righe via CSS (flex-wrap).
        // Qui resettiamo eventuali margini inline e lasciamo fare al CSS.
        function fitStack(stack) {
            stack.querySelectorAll('.heatmap-stack-avatar').forEach((a) => {
                a.style.marginLeft = '';
            });
            stack.style.removeProperty('--avatar-shift');
        }

        function fitAllStacks() {
            card.querySelectorAll('.heatmap-stack').forEach(fitStack);
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                currentQuery = searchInput.value.trim().toLowerCase();
                if (searchClear) searchClear.hidden = !currentQuery;
                persist();
                applyFilters();
            });
        }
        if (searchClear) {
            searchClear.addEventListener('click', () => {
                searchInput.value = '';
                currentQuery = '';
                searchClear.hidden = true;
                searchInput.focus();
                persist();
                applyFilters();
            });
        }

        legendBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const state = btn.dataset.filterState;
                if (!state) return;
                if (activeStates.has(state)) activeStates.delete(state);
                else activeStates.add(state);
                persist();
                applyFilters();
            });
        });

        // Ricalcola al resize
        let resizeRaf = null;
        window.addEventListener('resize', () => {
            if (resizeRaf) cancelAnimationFrame(resizeRaf);
            resizeRaf = requestAnimationFrame(fitAllStacks);
        });

        // ===== Popup roster completo (click su "+N") =====
        const overlay = card.querySelector('.heatmap-roster-overlay');
        const rosterList = overlay ? overlay.querySelector('.heatmap-roster-list') : null;
        const rosterTitle = overlay ? overlay.querySelector('#heatmapRosterTitle') : null;

        function openRoster(stack) {
            if (!overlay || !rosterList) return;
            const label = stack.dataset.dayLabel || 'Presenze';
            if (rosterTitle) rosterTitle.textContent = 'Presenze · ' + label;
            rosterList.innerHTML = '';
            // Ordine popup: assenti/pending/occupati prima, poi disponibili
            const order = { absent: 0, pending: 1, busy: 2, sw: 3, present: 4 };
            const items = Array.from(stack.querySelectorAll('.heatmap-stack-avatar')).sort((a, b) => {
                return (order[a.dataset.state] ?? 9) - (order[b.dataset.state] ?? 9);
            });
            items.forEach((av) => {
                const photo = av.querySelector('.heatmap-stack-photo');
                const nameEl = av.querySelector('.hst-name');
                const statusEl = av.querySelector('.hst-status');
                const state = av.dataset.state || 'present';
                const row = document.createElement('div');
                row.className = 'hr-item';
                const avWrap = document.createElement('div');
                avWrap.className = 'hr-av';
                const img = photo ? photo.querySelector('img') : null;
                const ini = photo ? photo.querySelector('.heatmap-stack-initials') : null;
                if (img) {
                    const i = document.createElement('img'); i.src = img.src; i.alt = ''; avWrap.appendChild(i);
                } else if (ini) {
                    avWrap.style.background = ini.style.background;
                    avWrap.textContent = ini.textContent;
                }
                const info = document.createElement('div'); info.className = 'hr-info';
                const nm = document.createElement('div'); nm.className = 'hr-name';
                nm.textContent = nameEl ? nameEl.textContent : '';
                const st = document.createElement('div'); st.className = 'hr-status is-' + state;
                st.textContent = statusEl ? statusEl.textContent : '';
                info.appendChild(nm); info.appendChild(st);
                row.appendChild(avWrap); row.appendChild(info);
                rosterList.appendChild(row);
            });
            overlay.hidden = false;
        }
        function closeRoster() { if (overlay) overlay.hidden = true; }

        card.querySelectorAll('.heatmap-more-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const stack = btn.closest('.heatmap-stack');
                if (stack) openRoster(stack);
            });
        });
        if (overlay) {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay || e.target.closest('.heatmap-roster-close')) closeRoster();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeRoster(); });
        }

        // Apply iniziale
        applyFilters();
    }
})();
