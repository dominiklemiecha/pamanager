/*
 * Script del calendario condiviso, copiato da public/includes/_calendar.inc.php.
 * Uniche differenze: token CSRF dal cookie, chiamante da window.CORA_CAL e
 * azioni inviate a /api/v1/calendario/azione invece che alla pagina PHP.
 */
(function() {
    // Nuovo stack: token CSRF dal cookie, chi chiama e indirizzo delle azioni dalla pagina React.
    const CSRF_TOKEN = decodeURIComponent((document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/) || [])[1] || '');
    const CALLER_TYPE = window.CORA_CAL.tipo;
    const CALLER_ID   = window.CORA_CAL.id;
    const CAL_URL     = '/api/v1/calendario/azione';

    // Avvisi: toast nella vista Cora, finestra del browser altrove
    function calAlert(msg) {
        if (window.Cora && Cora.toast) Cora.toast('Calendario', msg, 'error');
        else window.alert(msg);
    }
    const modal = document.getElementById('calModal');
    const form = document.getElementById('calForm');
    const partsList = document.getElementById('calPartsList');
    const picker = document.getElementById('calContactPicker');
    const titleEl = document.getElementById('calModalTitle');
    const deleteBtn = document.getElementById('calDeleteBtn');
    const submitLabel = document.getElementById('calSubmitLabel');
    const partsJson = document.getElementById('calPartsJson');
    const conflictsBox = document.getElementById('calConflicts');

    let selectedParts = []; // {user_type, user_id, name, photo}
    let editingEventId = null;

    const startHidden = document.getElementById('calStart');
    const endHidden   = document.getElementById('calEnd');

    function pad(n) { return String(n).padStart(2, '0'); }
    function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
    function fmtTime(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

    const MONTH_IT = ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'];
    const DOW_IT   = ['dom','lun','mar','mer','gio','ven','sab'];

    // Stato custom picker
    const state = {
        date: null,    // 'YYYY-MM-DD'
        t1:   null,    // 'HH:MM'
        t2:   null,    // 'HH:MM'
        miniMonth: null, // Date dell'1 del mese visualizzato nel mini-cal
    };

    function renderDateLabel() {
        const lbl = document.getElementById('calDateLabel');
        if (!state.date) { lbl.textContent = 'Seleziona data'; return; }
        const d = new Date(state.date + 'T00:00:00');
        lbl.textContent = DOW_IT[d.getDay()] + ' ' + d.getDate() + ' ' + MONTH_IT[d.getMonth()];
    }
    function renderTimeLabel(which) {
        const lbl = document.getElementById(which === 1 ? 'calT1Label' : 'calT2Label');
        const val = which === 1 ? state.t1 : state.t2;
        lbl.textContent = val || '--:--';
    }
    function syncHidden() {
        if (!state.date || !state.t1 || !state.t2) return;
        startHidden.value = state.date + ' ' + state.t1 + ':00';
        endHidden.value   = state.date + ' ' + state.t2 + ':00';
    }

    // ===== MINI CALENDARIO =====
    function renderMini() {
        const grid = document.getElementById('calMiniGrid');
        const label = document.getElementById('calMiniLabel');
        const m = state.miniMonth;
        label.textContent = MONTH_IT[m.getMonth()] + ' ' + m.getFullYear();
        grid.innerHTML = '';

        const firstDow = (m.getDay() || 7) - 1; // 0=lun..6=dom
        const daysInMonth = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
        const todayStr = fmtDate(new Date());

        // Empty cells prima del giorno 1
        for (let i = 0; i < firstDow; i++) {
            const cell = document.createElement('div');
            cell.className = 'cal-mini-cell is-empty';
            grid.appendChild(cell);
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const dStr = m.getFullYear() + '-' + pad(m.getMonth()+1) + '-' + pad(day);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cal-mini-cell';
            if (dStr === todayStr) btn.classList.add('is-today');
            if (dStr === state.date) btn.classList.add('is-selected');
            btn.textContent = day;
            btn.addEventListener('click', () => {
                state.date = dStr;
                renderDateLabel();
                renderMini();
                syncHidden();
                closePops();
            });
            grid.appendChild(btn);
        }
    }
    document.getElementById('calMiniPrev').addEventListener('click', () => {
        state.miniMonth = new Date(state.miniMonth.getFullYear(), state.miniMonth.getMonth() - 1, 1);
        renderMini();
    });
    document.getElementById('calMiniNext').addEventListener('click', () => {
        state.miniMonth = new Date(state.miniMonth.getFullYear(), state.miniMonth.getMonth() + 1, 1);
        renderMini();
    });

    // ===== TIME PICKER =====
    function renderTimeList(which) {
        const pop = document.getElementById(which === 1 ? 'calT1Pop' : 'calT2Pop');
        pop.innerHTML = '';
        const current = which === 1 ? state.t1 : state.t2;
        for (let h = 7; h < 22; h++) {
            for (let m = 0; m < 60; m += 15) {
                const v = pad(h) + ':' + pad(m);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cal-time-opt';
                if (v === current) btn.classList.add('is-selected');
                btn.textContent = v;
                btn.addEventListener('click', () => {
                    if (which === 1) {
                        state.t1 = v;
                        // Se c'e' una durata chip attiva: applicala. Altrimenti mantieni delta esistente.
                        const activeChip = document.querySelector('.cal-chip.active');
                        let mins = null;
                        if (activeChip) mins = parseInt(activeChip.dataset.mins, 10);
                        else if (state.t2 && state.t1) {
                            const [h1,m1] = state.t1.split(':').map(Number);
                            const [h2,m2] = state.t2.split(':').map(Number);
                            mins = (h2*60+m2) - (h1*60+m1);
                            if (mins <= 0) mins = 60;
                        } else {
                            mins = 60;
                        }
                        const [hh, mm] = v.split(':').map(Number);
                        const end = new Date(); end.setHours(hh, mm + mins, 0, 0);
                        state.t2 = fmtTime(end);
                        renderTimeLabel(1);
                        renderTimeLabel(2);
                        renderTimeList(2);
                    } else {
                        state.t2 = v;
                        renderTimeLabel(2);
                    }
                    renderTimeList(which);
                    syncHidden();
                    closePops();
                });
                pop.appendChild(btn);
            }
        }
        // Scroll to selected
        if (current) {
            setTimeout(() => {
                const sel = pop.querySelector('.is-selected');
                if (sel) sel.scrollIntoView({ block: 'center' });
            }, 0);
        }
    }

    function closePops() {
        document.querySelectorAll('.cal-pop').forEach(p => p.hidden = true);
        document.querySelectorAll('.cal-pill').forEach(p => p.classList.remove('is-open'));
        const bd = document.getElementById('calDateBackdrop');
        if (bd) bd.classList.remove('show');
    }
    window.closePops = closePops;
    function openPop(btnId, popId, before) {
        const pop = document.getElementById(popId);
        const isOpen = !pop.hidden;
        closePops();
        if (isOpen) return;
        if (before) before();

        // Sposta il date popup al body così esce dal stacking context del modal
        if (popId === 'calDatePop' && pop.parentElement !== document.body) {
            document.body.appendChild(pop);
        }

        pop.hidden = false;
        document.getElementById(btnId).classList.add('is-open');

        if (popId === 'calDatePop') {
            const bd = document.getElementById('calDateBackdrop');
            if (bd) bd.classList.add('show');
        }
    }

    document.getElementById('calDateBtn').addEventListener('click', () => {
        openPop('calDateBtn', 'calDatePop', () => {
            if (!state.miniMonth) {
                const base = state.date ? new Date(state.date + 'T00:00:00') : new Date();
                state.miniMonth = new Date(base.getFullYear(), base.getMonth(), 1);
            }
            renderMini();
        });
    });
    document.getElementById('calT1Btn').addEventListener('click', () => {
        openPop('calT1Btn', 'calT1Pop', () => renderTimeList(1));
    });
    document.getElementById('calT2Btn').addEventListener('click', () => {
        openPop('calT2Btn', 'calT2Pop', () => renderTimeList(2));
    });
    document.addEventListener('click', (e) => {
        // Il date popup è stato spostato al body (fuori da .cal-pick-wrap), quindi accetta anche .cal-pop
        if (!e.target.closest('.cal-pick-wrap, .cal-pop')) closePops();
    });

    window.calOpenModal = function(eventId) {
        editingEventId = null;
        form.reset();
        selectedParts = [];
        renderParts();
        picker.classList.remove('show');
        conflictsBox.classList.remove('show');
        titleEl.textContent = 'Nuovo evento';
        submitLabel.textContent = 'Crea evento';
        deleteBtn.style.display = 'none';
        document.getElementById('calTitle').disabled = false;
        document.getElementById('calLocation').disabled = false;
        const icon = document.getElementById('calSubmitIcon');
        if (icon) icon.style.display = '';

        // Default: oggi, prossima mezz'ora, durata 1h
        const now = new Date();
        const round = new Date(Math.ceil(now.getTime() / (15*60*1000)) * (15*60*1000));
        const end = new Date(round.getTime() + 60*60*1000);
        state.date = fmtDate(round);
        state.t1   = fmtTime(round);
        state.t2   = fmtTime(end);
        state.miniMonth = new Date(round.getFullYear(), round.getMonth(), 1);
        renderDateLabel();
        renderTimeLabel(1);
        renderTimeLabel(2);
        syncHidden();
        closePops();

        // Pulisci stati chip/preset
        document.querySelectorAll('.cal-preset, .cal-chip').forEach(b => b.classList.remove('active'));
        document.querySelector('.cal-chip[data-mins="60"]')?.classList.add('active');

        modal.classList.add('show');
    };

    // Preset data (Oggi / Domani / Lun prossimo)
    document.querySelectorAll('.cal-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.cal-preset').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const d = new Date();
            if (btn.dataset.day === '0') {
                // oggi
            } else if (btn.dataset.day === '1') {
                d.setDate(d.getDate() + 1);
            } else if (btn.dataset.day === 'next-mon') {
                const dow = d.getDay() || 7;
                d.setDate(d.getDate() + (8 - dow));
            }
            state.date = fmtDate(d);
            state.miniMonth = new Date(d.getFullYear(), d.getMonth(), 1);
            renderDateLabel();
            syncHidden();
        });
    });

    // Duration chips
    document.querySelectorAll('.cal-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.cal-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const mins = parseInt(btn.dataset.mins, 10);
            if (!state.t1) return;
            const [hh, mm] = state.t1.split(':').map(Number);
            const start = new Date(); start.setHours(hh, mm, 0, 0);
            const end = new Date(start.getTime() + mins * 60000);
            state.t2 = fmtTime(end);
            renderTimeLabel(2);
            syncHidden();
        });
    });

    // Search contatti
    const contactSearch = document.getElementById('calContactSearch');
    contactSearch?.addEventListener('input', () => {
        const q = contactSearch.value.trim().toLowerCase();
        document.querySelectorAll('.cal-contact-item').forEach(item => {
            const hay = item.getAttribute('data-search') || '';
            item.style.display = (!q || hay.includes(q)) ? '' : 'none';
        });
    });

    window.calCloseModal = function() {
        modal.classList.remove('show');
    };

    window.calToggleContacts = function() {
        picker.classList.toggle('show');
    };

    window.calToggleContactPick = function(el) {
        const type = el.dataset.type;
        const id   = parseInt(el.dataset.id, 10);
        const name = el.dataset.name;
        const photo = el.dataset.photo;

        const i = selectedParts.findIndex(p => p.user_type === type && p.user_id === id);
        if (i >= 0) {
            selectedParts.splice(i, 1);
            el.classList.remove('selected');
        } else {
            selectedParts.push({ user_type: type, user_id: id, name, photo });
            el.classList.add('selected');
        }
        renderParts();
    };

    function renderParts() {
        // Pulisci chip (ma mantieni il + btn)
        [...partsList.querySelectorAll('.av')].forEach(a => a.remove());
        const addBtn = partsList.querySelector('.cal-part-add-btn');
        selectedParts.forEach(p => {
            const chip = document.createElement('span');
            chip.className = 'av';
            chip.title = p.name;

            // Foto/iniziale
            const photo = document.createElement('span');
            photo.className = 'av-photo';
            if (p.photo) {
                const img = document.createElement('img');
                img.src = (window.PAM?.baseUrl || '') + '/' + String(p.photo).replace(/^\//, '');
                img.alt = p.name;
                photo.appendChild(img);
            } else {
                photo.textContent = (p.name || '?').charAt(0).toUpperCase();
            }
            chip.appendChild(photo);

            // Nome
            const name = document.createElement('span');
            name.className = 'av-name';
            name.textContent = p.name || '';
            chip.appendChild(name);

            // X (bottone reale)
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'x';
            x.setAttribute('aria-label', 'Rimuovi ' + (p.name || ''));
            x.innerHTML = '&times;';
            x.addEventListener('click', (ev) => {
                ev.stopPropagation();
                selectedParts = selectedParts.filter(sp => !(sp.user_type === p.user_type && sp.user_id === p.user_id));
                document.querySelector('.cal-contact-item[data-type="' + p.user_type + '"][data-id="' + p.user_id + '"]')?.classList.remove('selected');
                renderParts();
            });
            chip.appendChild(x);

            partsList.insertBefore(chip, addBtn);
        });
        partsJson.value = JSON.stringify(selectedParts.map(p => ({ user_type: p.user_type, user_id: p.user_id })));
    }

    window.calSubmit = function(e) {
        e.preventDefault();
        // Se il bottone dice "Chiudi" (dettaglio sola lettura) chiudi senza submit
        if (submitLabel.textContent === 'Chiudi') {
            calCloseModal();
            return false;
        }

        // Sicurezza: forza sync dei hidden start_at/end_at prima dell'invio
        syncHidden();
        if (!startHidden.value || !endHidden.value) {
            calAlert('Seleziona data e orari');
            return false;
        }

        const fd = new FormData(form);
        if (editingEventId) {
            fd.set('action', 'update_event');
            fd.append('event_id', editingEventId);
        } else {
            fd.set('action', 'create_event');
        }

        fetch(CAL_URL, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(async r => {
                const txt = await r.text();
                try { return JSON.parse(txt); }
                catch (e) {
                    console.error('Non-JSON response:', txt);
                    throw new Error('Risposta server non valida: ' + txt.slice(0, 200));
                }
            })
            .then(data => {
                if (data.success) {
                    if (data.conflicts && data.conflicts.length > 0) {
                        const names = data.conflicts.map(c => c.name).join(', ');
                        calAlert('Attenzione: conflitti rilevati per ' + names + '.');
                        // il toast non blocca come alert(): lascialo leggere prima di ricaricare
                        if (window.Cora) { setTimeout(() => window.location.reload(), 2500); return; }
                    }
                    window.location.reload();
                } else {
                    calAlert(data.error || 'Errore');
                }
            })
            .catch(err => { console.error(err); calAlert(err.message || 'Errore di connessione'); });
        return false;
    };

    window.calOpenDetail = function(eventId) {
        editingEventId = eventId;
        // Carica dati evento dal server
        const fd = new FormData();
        fd.append('action', 'get_event');
        fd.append('event_id', eventId);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch(CAL_URL, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(async r => {
                const txt = await r.text();
                try { return JSON.parse(txt); }
                catch (e) { console.error('Non-JSON:', txt); throw new Error('Risposta server invalida'); }
            })
            .then(data => {
                if (!data.success) { calAlert(data.error || 'Errore'); return; }
                const ev = data.event;
                const canEdit = !!data.can_edit;
                titleEl.textContent = canEdit ? 'Modifica evento' : 'Dettaglio evento';
                submitLabel.textContent = canEdit ? 'Salva modifiche' : 'Chiudi';
                deleteBtn.style.display = canEdit ? 'inline-flex' : 'none';
                const icon = document.getElementById('calSubmitIcon');
                if (icon) icon.style.display = 'none'; // niente "+" in modalita' modifica/dettaglio

                // Bottoni accetta/rifiuta visibili solo se invitato (non owner) E status=pending
                const me = (data.participants || []).find(p =>
                    String(p.user_type).toLowerCase() === String(CALLER_TYPE).toLowerCase() &&
                    parseInt(p.user_id, 10) === CALLER_ID);
                const isInvitedPending = me && me.status === 'pending';
                document.getElementById('calAcceptBtn').style.display = isInvitedPending ? 'inline-flex' : 'none';
                document.getElementById('calDeclineBtn').style.display = isInvitedPending ? 'inline-flex' : 'none';

                document.getElementById('calTitle').value = ev.title || '';
                document.getElementById('calLocation').value = ev.location || '';
                document.getElementById('calTitle').disabled  = !canEdit;
                document.getElementById('calLocation').disabled = !canEdit;

                // Popola data/orari nello stato
                const dt1 = new Date(ev.start_at.replace(' ', 'T'));
                const dt2 = new Date(ev.end_at.replace(' ', 'T'));
                state.date = fmtDate(dt1);
                state.t1 = fmtTime(dt1);
                state.t2 = fmtTime(dt2);
                state.miniMonth = new Date(dt1.getFullYear(), dt1.getMonth(), 1);
                renderDateLabel();
                renderTimeLabel(1);
                renderTimeLabel(2);
                syncHidden();

                // Popola partecipanti
                selectedParts = (data.participants || []).map(p => ({
                    user_type: p.user_type, user_id: parseInt(p.user_id, 10),
                    name: p.name, photo: p.photo_path,
                }));
                // Marca i contatti gia' selezionati nel picker
                document.querySelectorAll('.cal-contact-item').forEach(it => {
                    const sel = selectedParts.some(sp =>
                        sp.user_type === it.dataset.type &&
                        sp.user_id === parseInt(it.dataset.id, 10));
                    it.classList.toggle('selected', sel);
                });
                renderParts();

                modal.classList.add('show');
            })
            .catch(err => { console.error(err); calAlert('Errore di connessione'); });
    };

    window.calRespond = function(status) {
        if (!editingEventId) return;
        const fd = new FormData();
        fd.append('action', 'respond_event');
        fd.append('event_id', editingEventId);
        fd.append('status', status);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch(CAL_URL, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(async r => {
                const txt = await r.text();
                try { return JSON.parse(txt); } catch (_) { throw new Error(txt.slice(0,200)); }
            })
            .then(data => {
                if (data.success) window.location.reload();
                else calAlert(data.error || 'Errore');
            })
            .catch(err => { console.error(err); calAlert('Errore di connessione'); });
    };

    window.calDeleteEvent = function() {
        if (!editingEventId) return;
        if (!confirm('Eliminare questo evento?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_event');
        fd.append('event_id', editingEventId);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch(CAL_URL, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(r => r.json())
            .then(data => {
                if (data.success) window.location.reload();
                else calAlert(data.error || 'Errore');
            });
    };

    // Su mobile (≤720px) forza view=day: la settimanale non è leggibile
    (function() {
        if (window.innerWidth <= 720) {
            const u = new URL(window.location.href);
            if ((u.searchParams.get('view') ?? 'week') === 'week') {
                u.searchParams.set('view', 'day');
                window.location.replace(u.toString());
            }
        }
    })();

    // Auto-scroll al load: un'ora prima di adesso se oggi e' visibile, altrimenti alle 8:00
    const grid = document.getElementById('calGrid');
    const nowLine = document.querySelector('.cal-now-line');
    if (grid) grid.scrollTop = nowLine ? Math.max(0, parseInt(nowLine.style.top, 10) - 60) : 60;

    // ========================= DRAG & DROP =========================
    // 1 minuto = 1px (--cal-hour-h = 60), griglia inizia alle 7:00.
    // Snap a 15 minuti.
    const SNAP_MIN = 15;
    let dragData = null;

    document.querySelectorAll('.cal-evt.is-draggable').forEach(card => {
        card.addEventListener('dragstart', (e) => {
            // offsetY = distanza dal top della card al cursor al momento del grab.
            // La useremo per posizionare il top dell'evento dove era visivamente, non dove sta il cursore.
            const rect = card.getBoundingClientRect();
            const grabOffsetY = e.clientY - rect.top;
            dragData = {
                id: card.dataset.eventId,
                duration: parseInt(card.dataset.duration, 10) || 60,
                grabOffsetY: grabOffsetY,
            };
            card.classList.add('is-dragging');
            document.body.classList.add('cal-dragging');
            try { e.dataTransfer.setData('text/plain', card.dataset.eventId); } catch (_) {}
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('is-dragging');
            document.body.classList.remove('cal-dragging');
            document.querySelectorAll('.cal-day-col').forEach(c => c.classList.remove('is-drop-target'));
        });
    });

    // CLICK su uno slot vuoto -> apre modal "Nuovo evento" pre-riempito con quel giorno/ora
    document.querySelectorAll('.cal-day-col').forEach(col => {
        col.addEventListener('click', (e) => {
            // Ignora clic su eventi esistenti (gestiti dal loro onclick) o linea ora
            if (e.target.closest('.cal-evt') || e.target.closest('.cal-now-line')) return;

            const rect = col.getBoundingClientRect();
            const offsetPx = e.clientY - rect.top;
            // 1 minuto = 1px, partendo dalle 7:00. Snap a 15 min.
            const mins = Math.max(0, Math.round(offsetPx / SNAP_MIN) * SNAP_MIN);
            const totalStartMin = 7 * 60 + mins;
            if (totalStartMin >= 22 * 60) return;
            const startH = Math.floor(totalStartMin / 60);
            const startM = totalStartMin % 60;
            const endTotalMin = totalStartMin + 60; // default 1h
            const endH = Math.floor(endTotalMin / 60);
            const endM = endTotalMin % 60;
            const day = col.dataset.day;

            // Apri il modal in modalità "nuovo evento" e poi sovrascrivi date/orari
            if (typeof window.calOpenModal === 'function') {
                window.calOpenModal();
                state.date = day;
                state.t1 = pad(startH) + ':' + pad(startM);
                state.t2 = pad(endH) + ':' + pad(endM);
                const [y, m] = day.split('-').map(Number);
                state.miniMonth = new Date(y, m - 1, 1);
                renderDateLabel();
                renderTimeLabel(1);
                renderTimeLabel(2);
                syncHidden();
                // Focus al titolo
                setTimeout(() => document.getElementById('calTitle')?.focus(), 50);
            }
        });

        col.addEventListener('dragover', (e) => {
            if (!dragData) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            col.classList.add('is-drop-target');
        });
        col.addEventListener('dragleave', () => col.classList.remove('is-drop-target'));
        col.addEventListener('drop', (e) => {
            if (!dragData) return;
            e.preventDefault();
            col.classList.remove('is-drop-target');

            // Posiziona il TOP della card dove era visivamente: clientY - grabOffsetY.
            // Poi rispetto al top della colonna.
            const rect = col.getBoundingClientRect();
            const cardTopY = e.clientY - (dragData.grabOffsetY || 0);
            let offsetPx = cardTopY - rect.top;
            // 1 minuto = 1px, partendo dalle 7:00. Snap a 15 min.
            let mins = Math.max(0, Math.round(offsetPx / SNAP_MIN) * SNAP_MIN);
            const startMinutesFromBase = mins; // dalle 07:00
            const totalStartMin = 7 * 60 + startMinutesFromBase;
            const startH = Math.floor(totalStartMin / 60);
            const startM = totalStartMin % 60;
            if (startH >= 22) return; // fuori range

            const day = col.dataset.day; // YYYY-MM-DD
            const dur = dragData.duration;
            const endTotalMin = totalStartMin + dur;
            const endH = Math.floor(endTotalMin / 60);
            const endM = endTotalMin % 60;
            const pad = n => String(n).padStart(2, '0');
            const newStart = `${day} ${pad(startH)}:${pad(startM)}:00`;
            const newEnd   = `${day} ${pad(endH)}:${pad(endM)}:00`;

            // POST move_event (solo orari, no rischio di sovrascrivere title/location)
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', 'move_event');
            fd.append('event_id', dragData.id);
            fd.append('start_at', newStart);
            fd.append('end_at', newEnd);

            fetch(CAL_URL, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
                .then(async r => {
                    const txt = await r.text();
                    try { return JSON.parse(txt); } catch (_) { throw new Error(txt.slice(0,200)); }
                })
                .then(data => {
                    if (data.success) window.location.reload();
                    else calAlert(data.error || 'Errore aggiornamento');
                })
                .catch(err => { console.error(err); calAlert('Errore di connessione'); });

            dragData = null;
        });
    });
})();
