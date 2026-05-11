/**
 * Heatmap presenze: ricerca dipendente + filtro stato + compressione dinamica avatar
 * Filtri persistenti via localStorage (sopravvivono al cambio settimana).
 */
(function () {
    const STORAGE_KEY = 'pamHeatmapFilters_v1';
    const AVATAR_W = 38;       // px, deve combaciare con CSS .heatmap-stack-photo
    const MIN_VISIBLE = 6;     // px minimi visibili tra un avatar e il successivo
    const MAX_VISIBLE = 28;    // px massimi (default spacing quando ci sta)

    document.querySelectorAll('.heatmap-card').forEach(initHeatmap);

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
            updateRowEmptyState();
            updateCounts();
            fitAllStacks();
        }

        function updateRowEmptyState() {
            card.querySelectorAll('.heatmap-day-row').forEach((row) => {
                const stack = row.querySelector('.heatmap-stack');
                if (!stack) return;
                const visible = stack.querySelectorAll('.heatmap-stack-avatar:not(.is-hidden)').length;
                row.classList.toggle('is-empty', visible === 0);
            });
        }

        function updateCounts() {
            card.querySelectorAll('.heatmap-day-row').forEach((row) => {
                const counters = row.querySelectorAll('.hm-count');
                if (!counters.length) return;
                const visible = { present: 0, busy: 0, pending: 0, absent: 0 };
                row.querySelectorAll('.heatmap-stack-avatar:not(.is-hidden)').forEach((av) => {
                    const s = av.dataset.state;
                    if (s in visible) visible[s]++;
                });
                counters.forEach((c) => {
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

        // Comprime gli avatar di una riga finche non escono dal contenitore
        function fitStack(stack) {
            const list = stack.querySelectorAll('.heatmap-stack-avatar:not(.is-hidden)');
            const n = list.length;
            if (n === 0) return;
            if (n === 1) {
                list[0].style.marginLeft = '0';
                stack.style.setProperty('--avatar-shift', '0px');
                return;
            }
            const w = stack.clientWidth;
            const photo = list[0].querySelector('.heatmap-stack-photo');
            const avatarW = photo ? photo.offsetWidth : AVATAR_W;
            let visiblePx = (w - avatarW) / (n - 1);
            if (visiblePx > MAX_VISIBLE) visiblePx = MAX_VISIBLE;
            if (visiblePx < MIN_VISIBLE) visiblePx = MIN_VISIBLE;
            const margin = visiblePx - avatarW;
            list.forEach((a, i) => {
                a.style.marginLeft = i === 0 ? '0' : margin + 'px';
            });
            // Calcola lo shift massimo per il fan-out su hover senza uscire dalla card.
            // Larghezza occupata a riposo = avatarW + (n-1) * visiblePx
            const restWidth = avatarW + (n - 1) * visiblePx;
            const slack = Math.max(0, w - restWidth - 4); // 4px di safety
            const maxShiftPerAvatar = (n - 1) > 0 ? slack / (n - 1) : 0;
            const shift = Math.min(3, maxShiftPerAvatar); // ideale 3px, ridotto se non c'e spazio
            stack.style.setProperty('--avatar-shift', shift.toFixed(2) + 'px');
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

        // Apply iniziale
        applyFilters();
    }
})();
