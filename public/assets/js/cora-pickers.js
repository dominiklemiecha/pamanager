/**
 * Cora — selettori di data e orario nello stile della piattaforma
 * (al posto di quelli nativi del browser, che cambiano da sistema a sistema).
 *
 * Data:   [data-cora-date] con un input di testo (gg/mm/aaaa, scrivibile) e
 *         un input hidden con il valore aaaa-mm-gg che viene inviato.
 *         data-role="start|end": il calendario evidenzia l'intervallo e la
 *         fine non puo' precedere l'inizio.
 * Orario: [data-cora-time] con un input di testo scrivibile (9, 930, 9.30,
 *         14:15...) e l'elenco degli orari ogni 30 minuti.
 *
 * Quando un valore viene impostato da codice (es. periodi rapidi) si lancia
 * l'evento "cora:set" sull'input hidden per aggiornare il testo mostrato.
 * Una scelta dell'utente lancia "change" sull'input che viene inviato.
 */
(function () {
    'use strict';

    var MONTHS = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio',
                  'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    var DAYS = ['L', 'M', 'M', 'G', 'V', 'S', 'D'];
    var holidays = {};
    ((window.CORA_LEAVE && window.CORA_LEAVE.holidays) || []).forEach(function (d) { holidays[d] = true; });

    function pad(n) { return String(n).padStart(2, '0'); }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parseYmd(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function toIt(v) {
        var d = parseYmd(v);
        return d ? pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() : '';
    }
    /** "23/10/2026", "23-10-26", "23.10" (anno corrente) -> Date */
    function parseIt(text) {
        var m = /^\s*(\d{1,2})[\/.\-\s](\d{1,2})(?:[\/.\-\s](\d{2,4}))?\s*$/.exec(text || '');
        if (!m) return null;
        var y = m[3] ? +m[3] : new Date().getFullYear();
        if (y < 100) y += 2000;
        var d = new Date(y, +m[2] - 1, +m[1]);
        return (d.getMonth() === +m[2] - 1 && d.getDate() === +m[1]) ? d : null;
    }

    /* Un solo pannello aperto alla volta, posizionato sopra la pagina */
    var openPop = null;
    function closePop() {
        if (!openPop) return;
        openPop.el.remove();
        openPop.field.classList.remove('is-open');
        openPop = null;
    }
    function placePop(pop, field) {
        var r = field.getBoundingClientRect();
        if (pop.classList.contains('cora-times')) pop.style.width = r.width + 'px';
        var h = pop.offsetHeight, w = pop.offsetWidth;
        var below = window.innerHeight - r.bottom;
        var top = (below < h + 12 && r.top > h + 12) ? r.top - h - 6 : r.bottom + 6;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
    }
    document.addEventListener('mousedown', function (e) {
        if (openPop && !openPop.el.contains(e.target) && !openPop.field.contains(e.target)) closePop();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openPop) { e.stopPropagation(); closePop(); }
    }, true);
    window.addEventListener('resize', closePop);
    document.addEventListener('scroll', function (e) {
        if (openPop && !openPop.el.contains(e.target)) closePop();
    }, true);

    /* ---------------- Data ---------------- */
    function initDate(field) {
        var text = field.querySelector('input[type="text"]');
        var hidden = field.querySelector('input[type="hidden"]');
        var role = field.getAttribute('data-role');
        var form = field.closest('form') || document;
        var view = null; // primo giorno del mese mostrato

        function sync() { text.value = toIt(hidden.value); field.classList.remove('is-invalid'); }
        function pick(d) {
            hidden.value = d ? ymd(d) : '';
            sync();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function other(r) {
            var el = form.querySelector('[data-cora-date][data-role="' + r + '"] input[type="hidden"]');
            return el ? parseYmd(el.value) : null;
        }

        function render(pop) {
            var sel = parseYmd(hidden.value);
            var start = role === 'start' ? sel : other('start');
            var end = role === 'end' ? sel : other('end');
            var min = role === 'end' ? other('start') : null;
            var today = new Date(); today.setHours(0, 0, 0, 0);

            var html = '<div class="cora-cal-h">' +
                '<button type="button" class="cora-cal-nav" data-nav="-1" aria-label="Mese precedente"><svg viewBox="0 0 16 16" width="16" height="16" fill="none"><path d="M10 12.5L5.5 8 10 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
                '<strong>' + MONTHS[view.getMonth()] + ' ' + view.getFullYear() + '</strong>' +
                '<button type="button" class="cora-cal-nav" data-nav="1" aria-label="Mese successivo"><svg viewBox="0 0 16 16" width="16" height="16" fill="none"><path d="M6 3.5L10.5 8 6 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
                '</div><div class="cora-cal-grid">';
            DAYS.forEach(function (d) { html += '<span class="cora-cal-dow">' + d + '</span>'; });
            var first = new Date(view);
            var lead = (first.getDay() + 6) % 7; // lunedi = 0
            for (var i = 0; i < lead; i++) html += '<span></span>';
            var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            for (var n = 1; n <= days; n++) {
                var d = new Date(view.getFullYear(), view.getMonth(), n);
                var key = ymd(d);
                var cls = ['cora-cal-day'];
                var dow = d.getDay();
                if (dow === 0 || dow === 6 || holidays[key]) cls.push('is-off');
                if (+d === +today) cls.push('is-today');
                if (start && end && d > start && d < end) cls.push('in-range');
                if (start && +d === +start) cls.push('is-start');
                if (end && +d === +end) cls.push('is-end');
                if (sel && +d === +sel) cls.push('is-selected');
                var disabled = min && d < min;
                html += '<button type="button" class="' + cls.join(' ') + '" data-day="' + key + '"' +
                    (disabled ? ' disabled' : '') + (holidays[key] ? ' title="Festivo"' : '') + '>' + n + '</button>';
            }
            html += '</div><div class="cora-cal-f"><button type="button" class="cora-cal-link" data-today>Oggi</button></div>';
            pop.innerHTML = html;
        }

        function open() {
            if (openPop && openPop.field === field) return;
            closePop();
            var base = parseYmd(hidden.value) || (role === 'end' && other('start')) || new Date();
            view = new Date(base.getFullYear(), base.getMonth(), 1);
            var pop = document.createElement('div');
            pop.className = 'cora-pop cora-cal';
            pop.addEventListener('mousedown', function (e) { e.preventDefault(); }); // il focus resta sul campo
            pop.addEventListener('click', function (e) {
                var nav = e.target.closest('[data-nav]');
                if (nav) { view.setMonth(view.getMonth() + +nav.getAttribute('data-nav')); render(pop); return; }
                var day = e.target.closest('[data-day]');
                if (day && !day.disabled) { pick(parseYmd(day.getAttribute('data-day'))); closePop(); return; }
                if (e.target.closest('[data-today]')) { var t = new Date(); t.setHours(0, 0, 0, 0); pick(t); closePop(); }
            });
            render(pop);
            document.body.appendChild(pop);
            placePop(pop, field);
            field.classList.add('is-open');
            openPop = { el: pop, field: field };
        }

        text.addEventListener('focus', open);
        text.addEventListener('click', open);
        field.querySelector('.cora-field-ic').addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (openPop && openPop.field === field) closePop(); else { text.focus(); open(); }
        });
        text.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); text.blur(); }
            if (e.key === 'Tab') closePop();
        });
        text.addEventListener('blur', function () {
            if (text.value.trim() === '') { if (hidden.value) pick(null); return; }
            var d = parseIt(text.value);
            if (!d) { field.classList.add('is-invalid'); return; }
            if (ymd(d) !== hidden.value) pick(d); else sync();
            closePop();
        });
        hidden.addEventListener('cora:set', sync);
        sync();
    }

    /* ---------------- Orario ---------------- */
    var SLOTS = [];
    for (var h = 7; h <= 20; h++) { SLOTS.push(pad(h) + ':00'); if (h < 20) SLOTS.push(pad(h) + ':30'); }

    /** "9" -> 09:00, "930" -> 09:30, "9.30" / "9:30" / "9,30" -> 09:30 */
    function parseTime(t) {
        t = (t || '').trim().replace(/[.,h\s]/g, ':');
        var m = /^(\d{1,2})(?::?(\d{2}))?$/.exec(t);
        if (!m) return null;
        var hh = +m[1], mm = m[2] ? +m[2] : 0;
        if (hh > 23 || mm > 59) return null;
        return pad(hh) + ':' + pad(mm);
    }

    function initTime(field) {
        var input = field.querySelector('input');

        function set(v) {
            input.value = v;
            field.classList.remove('is-invalid');
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function open() {
            if (openPop && openPop.field === field) return;
            closePop();
            var pop = document.createElement('div');
            pop.className = 'cora-pop cora-times';
            pop.setAttribute('role', 'listbox');
            pop.innerHTML = SLOTS.map(function (s) {
                return '<button type="button" role="option" data-time="' + s + '"' +
                    (s === input.value ? ' class="is-selected" aria-selected="true"' : '') + '>' + s + '</button>';
            }).join('');
            pop.addEventListener('mousedown', function (e) { e.preventDefault(); });
            pop.addEventListener('click', function (e) {
                var b = e.target.closest('[data-time]');
                if (b) { set(b.getAttribute('data-time')); closePop(); }
            });
            document.body.appendChild(pop);
            placePop(pop, field);
            field.classList.add('is-open');
            openPop = { el: pop, field: field };
            var cur = pop.querySelector('.is-selected') || pop.querySelector('[data-time="09:00"]');
            if (cur) pop.scrollTop = cur.offsetTop - pop.clientHeight / 2 + cur.offsetHeight / 2;
        }

        input.addEventListener('focus', function () { input.select(); open(); });
        input.addEventListener('click', open);
        field.querySelector('.cora-field-ic').addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (openPop && openPop.field === field) closePop(); else { input.focus(); }
        });
        // Mentre si scrive l'elenco si posiziona sull'orario piu' vicino
        input.addEventListener('input', function () {
            if (!openPop || openPop.field !== field) return;
            var v = parseTime(input.value);
            if (!v) return;
            var best = null;
            openPop.el.querySelectorAll('[data-time]').forEach(function (b) {
                b.classList.toggle('is-selected', b.getAttribute('data-time') === v);
                if (!best && b.getAttribute('data-time') >= v) best = b;
            });
            if (best) openPop.el.scrollTop = best.offsetTop - openPop.el.clientHeight / 2;
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Tab') closePop();
        });
        input.addEventListener('blur', function () {
            var v = parseTime(input.value);
            if (!v) { field.classList.add('is-invalid'); return; }
            if (v !== input.value) set(v); else field.classList.remove('is-invalid');
            closePop();
        });
    }

    document.querySelectorAll('[data-cora-date]').forEach(initDate);
    document.querySelectorAll('[data-cora-time]').forEach(initTime);
})();
