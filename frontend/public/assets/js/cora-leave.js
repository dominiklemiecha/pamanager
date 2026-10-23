/**
 * Cora — modale "Nuova richiesta" di ferie e permessi.
 * Tipo, periodi rapidi, durata, riepilogo con giorni lavorativi e saldo residuo.
 */
(function () {
    'use strict';

    var cfg = window.CORA_LEAVE || { holidays: [], residual: {} };
    var form = document.getElementById('coraLeaveForm');
    if (!form) return;

    var typeInput = form.querySelector('[data-leave-type]');
    var types = form.querySelectorAll('[data-leave-types] .cora-chip');
    var presets = form.querySelectorAll('[data-leave-presets] .cora-chip');
    var start = form.querySelector('[data-start]');
    var end = form.querySelector('[data-end]');
    var times = form.querySelector('[data-leave-times]');
    var startTime = form.querySelector('[data-start-time]');
    var endTime = form.querySelector('[data-end-time]');
    var sick = form.querySelector('[data-leave-sick]');
    var info = form.querySelector('[data-leave-info]');
    var infoText = form.querySelector('[data-leave-info-text]');
    var holidays = {};
    (cfg.holidays || []).forEach(function (d) { holidays[d] = true; });

    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function parse(v) {
        var p = (v || '').split('-');
        return p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : null;
    }
    function isFull() {
        var r = form.querySelector('input[name="is_full_day"]:checked');
        return !r || r.value === '1';
    }
    function workingDays(s, e) {
        var n = 0, d = new Date(s);
        while (d <= e) {
            var dow = d.getDay();
            if (dow !== 0 && dow !== 6 && !holidays[ymd(d)]) n++;
            d.setDate(d.getDate() + 1);
        }
        return n;
    }
    function num(n) {
        return (Math.round(n * 10) / 10).toString().replace('.', ',');
    }

    function update() {
        var t = typeInput.value;
        if (sick) sick.hidden = t !== 'malattia';
        if (times) times.hidden = isFull();

        var s = parse(start.value), e = parse(end.value);
        if (!s || !e || e < s) { info.hidden = true; return; }
        var days = workingDays(s, e);
        var text;
        if (!isFull()) {
            var a = (startTime.value || '').split(':'), b = (endTime.value || '').split(':');
            var h = ((+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1])) / 60;
            if (!(h > 0)) { info.hidden = true; return; }
            var tot = h * Math.max(1, days);
            text = num(tot) + (tot === 1 ? ' ora' : ' ore');
            if (t === 'permesso' && typeof cfg.residual.permesso === 'number') {
                text += ' - dopo l’approvazione ti resteranno ' + num(cfg.residual.permesso - tot) + ' ore di permesso';
            }
        } else {
            text = days + (days === 1 ? ' giorno lavorativo' : ' giorni lavorativi');
            if (t === 'ferie' && typeof cfg.residual.ferie === 'number') {
                text += ' - dopo l’approvazione ti resteranno ' + num(cfg.residual.ferie - days) + ' giorni di ferie';
            }
        }
        infoText.textContent = text;
        info.hidden = false;
    }

    types.forEach(function (chip) {
        chip.addEventListener('click', function () {
            types.forEach(function (c) { c.classList.toggle('is-selected', c === chip); });
            typeInput.value = chip.dataset.type;
            // Permesso e L.104 di solito sono a ore, gli altri a giornata
            var radio = form.querySelector('input[name="is_full_day"][value="' + (chip.dataset.full === '0' ? '0' : '1') + '"]');
            if (radio) radio.checked = true;
            update();
        });
    });

    presets.forEach(function (chip) {
        chip.addEventListener('click', function () {
            presets.forEach(function (c) { c.classList.toggle('is-selected', c === chip); });
            var today = new Date(); today.setHours(0, 0, 0, 0);
            var s = new Date(today), e = new Date(today);
            var dow = today.getDay() || 7; // 1 = lunedi
            switch (chip.dataset.preset) {
                case 'tomorrow': s.setDate(s.getDate() + 1); e = new Date(s); break;
                case 'rest-of-week': e.setDate(e.getDate() + Math.max(0, 5 - dow)); break;
                case 'next-week': s.setDate(s.getDate() + (8 - dow)); e = new Date(s); e.setDate(e.getDate() + 4); break;
            }
            start.value = ymd(s);
            end.value = ymd(e);
            start.dispatchEvent(new Event('cora:set'));
            end.dispatchEvent(new Event('cora:set'));
            update();
        });
    });

    function clearPreset() { presets.forEach(function (c) { c.classList.remove('is-selected'); }); }
    start.addEventListener('change', function () {
        if (start.value && (!end.value || end.value < start.value)) {
            end.value = start.value;
            end.dispatchEvent(new Event('cora:set'));
        }
        clearPreset(); update();
    });
    end.addEventListener('change', function () { clearPreset(); update(); });
    form.querySelectorAll('input[name="is_full_day"], [data-start-time], [data-end-time]').forEach(function (el) {
        el.addEventListener('change', update);
    });

    form.addEventListener('submit', function (e) {
        var s = parse(start.value), en = parse(end.value);
        if (!s || !en) { e.preventDefault(); window.Cora && Cora.toast('Scegli le date', 'Indica inizio e fine della richiesta.', 'error'); return; }
        if (en < s) { e.preventDefault(); window.Cora && Cora.toast('Date non valide', 'La data di fine viene prima dell’inizio.', 'error'); return; }
        if (form.querySelector('.cora-picker.is-invalid')) {
            e.preventDefault();
            window.Cora && Cora.toast('Controlla date e orari', 'Scrivi la data come gg/mm/aaaa e l’orario come hh:mm.', 'error');
            return;
        }
        if (!isFull() && startTime.value >= endTime.value) {
            e.preventDefault();
            window.Cora && Cora.toast('Orari non validi', 'L’orario di fine deve venire dopo quello di inizio.', 'error');
            return;
        }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
    });

    update();

    document.addEventListener('DOMContentLoaded', function () {
        if (cfg.open && window.Cora) Cora.openModal('coraLeaveModal');
    });
})();
