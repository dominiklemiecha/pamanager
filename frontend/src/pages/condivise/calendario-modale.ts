/** Finestra dell'evento, copiata da public/includes/_calendar.inc.php (senza il campo CSRF: il token viaggia nell'header). */
export const htmlModale = (contatti: string) => `<div class="cal-date-backdrop" id="calDateBackdrop" onclick="closePops()"></div>
<div class="cal-modal-overlay" id="calModal" onclick="if(event.target === this) calCloseModal()">
    <div class="cal-modal" data-tour="cal-modal">
        <div class="cal-modal-h">
            <h3 id="calModalTitle">Nuovo evento</h3>
            <button type="button" class="cal-modal-close" onclick="calCloseModal()">&times;</button>
        </div>
        <form id="calForm" onsubmit="return calSubmit(event)">
            <input type="hidden" name="action" value="create_event">
            <input type="hidden" name="participants" id="calPartsJson" value="[]">
            <input type="hidden" name="start_at" id="calStart">
            <input type="hidden" name="end_at"   id="calEnd">
            <div class="cal-modal-body">
                <div class="cal-fg">
                    <label for="calTitle">Titolo evento</label>
                    <input type="text" id="calTitle" name="title" required placeholder="Es. Riunione team marketing" autocomplete="off">
                </div>

                <div class="cal-fg">
                    <label>Quando</label>
                    <div class="cal-date-presets" id="calDatePresets">
                        <button type="button" class="cal-preset" data-day="0">Oggi</button>
                        <button type="button" class="cal-preset" data-day="1">Domani</button>
                        <button type="button" class="cal-preset" data-day="next-mon">Lun prossimo</button>
                    </div>

                    <!-- Date pill -->
                    <div class="cal-pick-wrap cal-date-wrap">
                        <button type="button" class="cal-pill cal-pill-date" id="calDateBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span id="calDateLabel">Seleziona data</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="margin-left:auto;color:#94a3b8;"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="cal-pop cal-pop-date" id="calDatePop" hidden>
                            <div class="cal-mini-h">
                                <button type="button" class="cal-mini-nav" id="calMiniPrev" aria-label="Mese precedente">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                                <span class="cal-mini-label" id="calMiniLabel"></span>
                                <button type="button" class="cal-mini-nav" id="calMiniNext" aria-label="Mese successivo">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                            </div>
                            <div class="cal-mini-dows">
                                <span>L</span><span>M</span><span>M</span><span>G</span><span>V</span><span>S</span><span>D</span>
                            </div>
                            <div class="cal-mini-grid" id="calMiniGrid"></div>
                        </div>
                    </div>

                    <!-- Time pills -->
                    <div class="cal-pick-row">
                        <div class="cal-pick-wrap" style="flex:1;">
                            <button type="button" class="cal-pill" id="calT1Btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span id="calT1Label">--:--</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="margin-left:auto;color:#94a3b8;"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="cal-pop cal-pop-time" id="calT1Pop" hidden></div>
                        </div>
                        <span class="cal-pick-sep">→</span>
                        <div class="cal-pick-wrap" style="flex:1;">
                            <button type="button" class="cal-pill" id="calT2Btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span id="calT2Label">--:--</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="margin-left:auto;color:#94a3b8;"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="cal-pop cal-pop-time" id="calT2Pop" hidden></div>
                        </div>
                    </div>

                    <div class="cal-duration-chips">
                        <button type="button" class="cal-chip" data-mins="30">30 min</button>
                        <button type="button" class="cal-chip" data-mins="60">1 ora</button>
                        <button type="button" class="cal-chip" data-mins="90">1h 30</button>
                        <button type="button" class="cal-chip" data-mins="120">2 ore</button>
                    </div>
                </div>

                <div class="cal-fg">
                    <label for="calLocation">Luogo</label>
                    <div class="cal-when-field">
                        <span class="cal-when-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </span>
                        <input type="text" id="calLocation" name="location" placeholder="Aula riunioni · Online · ...">
                    </div>
                </div>

                <div class="cal-fg">
                    <label>Partecipanti</label>
                    <div class="cal-participants" id="calPartsList">
                        <button type="button" class="cal-part-add-btn" onclick="calToggleContacts()" aria-label="Aggiungi partecipante">+</button>
                    </div>
                    <div class="cal-contact-picker" id="calContactPicker">
                        <div class="cal-contact-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="search" id="calContactSearch" placeholder="Cerca persone...">
                        </div>
                        ${contatti}
                    </div>
                </div>
                <div class="cal-conflicts" id="calConflicts"></div>
            </div>
            <div class="cal-modal-footer">
                <button type="button" class="cal-btn cal-btn-danger" id="calDeclineBtn" style="display:none;" onclick="calRespond('declined')">Rifiuta invito</button>
                <button type="button" class="cal-btn cal-btn-accept" id="calAcceptBtn" style="display:none;" onclick="calRespond('accepted')">Accetta</button>
                <button type="button" class="cal-btn cal-btn-danger" id="calDeleteBtn" style="display:none;" onclick="calDeleteEvent()">Elimina</button>
                <button type="button" class="cal-btn cal-btn-ghost" onclick="calCloseModal()">Annulla</button>
                <button type="submit" class="cal-btn cal-btn-primary" id="calSubmitBtn">
                    <svg id="calSubmitIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span id="calSubmitLabel">Crea evento</span>
                </button>
            </div>
        </form>
    </div>
</div>`;
