/**
 * PAManager - Push Notifications
 * Gestione Service Worker e notifiche push
 */

(function() {
    'use strict';

    const PAMPush = {
        swRegistration: null,
        isSubscribed: false,
        applicationServerKey: null,

        /**
         * Inizializza il sistema di push notifications
         */
        async init() {
            const btn = document.getElementById('enableNotifications');

            // Mostra il bottone "Attiva notifiche" quando:
            //  - PWA installata (standalone)
            //  - O dev locale (localhost / 127.0.0.1) per testare comodamente
            const isStandalone =
                (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
                window.navigator.standalone === true ||
                document.referrer.startsWith('android-app://');
            const isLocalDev = location.hostname === 'localhost' ||
                               location.hostname === '127.0.0.1' ||
                               location.hostname.startsWith('192.168.');

            if (!isStandalone && !isLocalDev) {
                if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
                return false;
            }

            // Verifica HTTPS (richiesto per push notifications in produzione)
            // In sviluppo permetti anche rete locale
            const isLocalNetwork = location.hostname.startsWith('192.168.') ||
                                   location.hostname.startsWith('10.') ||
                                   location.hostname.startsWith('172.') ||
                                   location.hostname.endsWith('.local');
            const isSecure = location.protocol === 'https:' ||
                            location.hostname === 'localhost' ||
                            location.hostname === '127.0.0.1' ||
                            isLocalNetwork;

            if (!isSecure) {
                console.log('[Push] HTTPS richiesto per le notifiche push');
                if (btn) {
                    btn.style.display = 'inline-flex';
                    btn.innerHTML = '<span>HTTPS Richiesto</span>';
                    btn.disabled = true;
                    btn.title = 'Le notifiche push richiedono HTTPS';
                }
                return false;
            }

            // Verifica supporto browser
            if (!('serviceWorker' in navigator)) {
                console.log('[Push] Service Worker non supportato');
                if (btn) {
                    btn.style.display = 'inline-flex';
                    btn.innerHTML = '<span>Non Supportato</span>';
                    btn.disabled = true;
                    btn.title = 'Il browser non supporta i Service Worker';
                }
                return false;
            }

            if (!('PushManager' in window)) {
                console.log('[Push] Push API non supportata');
                if (btn) {
                    btn.style.display = 'inline-flex';
                    btn.innerHTML = '<span>Push Non Supportato</span>';
                    btn.disabled = true;
                    btn.title = 'Il browser non supporta le notifiche push';
                }
                return false;
            }

            if (!('Notification' in window)) {
                console.log('[Push] Notification API non supportata');
                if (btn) {
                    btn.style.display = 'inline-flex';
                    btn.innerHTML = '<span>Notifiche Non Supportate</span>';
                    btn.disabled = true;
                }
                return false;
            }

            console.log('[Push] Tutte le API sono supportate, inizializzazione...');

            try {
                // Registra Service Worker
                this.swRegistration = await navigator.serviceWorker.register(
                    (window.PAM?.baseUrl || '') + '/sw.js',
                    { scope: (window.PAM?.baseUrl || '') + '/' }
                );
                console.log('[Push] Service Worker registrato:', this.swRegistration.scope);

                // Attendi che il SW sia attivo
                if (this.swRegistration.installing) {
                    console.log('[Push] Service Worker in installazione...');
                    await new Promise(resolve => {
                        this.swRegistration.installing.addEventListener('statechange', function() {
                            if (this.state === 'activated') resolve();
                        });
                    });
                }

                // Ottieni chiave pubblica VAPID
                const keyObtained = await this.getApplicationServerKey();
                if (!keyObtained) {
                    console.error('[Push] Impossibile ottenere chiave VAPID');
                    if (btn) {
                        btn.style.display = 'inline-flex';
                        btn.innerHTML = '<span>Errore Config</span>';
                        btn.disabled = true;
                        btn.title = 'Errore nella configurazione del server';
                    }
                    return false;
                }

                // Verifica stato sottoscrizione
                await this.checkSubscription();

                // Configura pulsante se presente
                this.setupButton();

                console.log('[Push] Inizializzazione completata. Sottoscritto:', this.isSubscribed);
                return true;
            } catch (error) {
                console.error('[Push] Errore inizializzazione:', error);
                if (btn) {
                    btn.style.display = 'inline-flex';
                    btn.innerHTML = '<span>Errore</span>';
                    btn.disabled = true;
                    btn.title = 'Errore: ' + error.message;
                }
                return false;
            }
        },

        /**
         * Ottiene la chiave pubblica VAPID dal server
         */
        async getApplicationServerKey() {
            try {
                const response = await fetch((window.PAM?.baseUrl || '') + '/api/push.php?action=public_key');
                const data = await response.json();

                if (data.publicKey) {
                    this.applicationServerKey = this.urlBase64ToUint8Array(data.publicKey);
                    return true;
                }
            } catch (error) {
                console.error('[Push] Errore recupero chiave pubblica:', error);
            }
            return false;
        },

        /**
         * Verifica se l'utente è già sottoscritto
         */
        async checkSubscription() {
            try {
                const subscription = await this.swRegistration.pushManager.getSubscription();
                this.isSubscribed = subscription !== null;
                console.log('[Push] Stato sottoscrizione:', this.isSubscribed ? 'Attivo' : 'Non attivo');
                return this.isSubscribed;
            } catch (error) {
                console.error('[Push] Errore verifica sottoscrizione:', error);
                return false;
            }
        },

        /**
         * Configura il pulsante notifiche se presente
         */
        setupButton() {
            const btn = document.getElementById('enableNotifications');
            if (!btn) return;

            // Mostra pulsante
            btn.style.display = 'inline-flex';

            // Aggiorna stato visuale
            this.updateButton(btn);

            // Click handler
            btn.addEventListener('click', () => this.toggleSubscription(btn));
        },

        /**
         * Aggiorna lo stato del pulsante
         */
        updateButton(btn) {
            if (!btn) return;

            if (Notification.permission === 'denied') {
                btn.textContent = 'Notifiche Bloccate';
                btn.disabled = true;
                btn.classList.add('btn-disabled');
                return;
            }

            if (this.isSubscribed) {
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg> Notifiche Attive';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
            } else {
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg> Attiva Notifiche';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
            }
        },

        /**
         * Toggle sottoscrizione
         */
        async toggleSubscription(btn) {
            if (this.isSubscribed) {
                await this.unsubscribe(btn);
            } else {
                await this.subscribe(btn);
            }
        },

        /**
         * Sottoscrivi alle notifiche push
         */
        async subscribe(btn) {
            console.log('[Push] Inizio sottoscrizione...');

            // Verifica se siamo su iOS PWA
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const isStandalone = window.navigator.standalone === true ||
                                window.matchMedia('(display-mode: standalone)').matches;

            if (isIOS && !isStandalone) {
                this.showMessage('Su iOS devi prima aggiungere l\'app alla Home Screen, poi aprirla da li', 'warning');
                console.log('[Push] iOS rilevato ma non in modalità standalone. L\'app deve essere aggiunta alla Home.');
                return false;
            }

            if (!this.applicationServerKey) {
                console.log('[Push] Chiave VAPID mancante, riprovo...');
                await this.getApplicationServerKey();
            }

            if (!this.applicationServerKey) {
                this.showMessage('Errore: impossibile ottenere la chiave del server', 'error');
                console.error('[Push] Chiave VAPID non disponibile');
                return false;
            }

            // Verifica stato attuale permesso
            console.log('[Push] Stato permesso attuale:', Notification.permission);

            if (Notification.permission === 'denied') {
                this.showMessage('Notifiche bloccate. Vai nelle impostazioni del browser/dispositivo per sbloccarle.', 'error');
                this.updateButton(btn);
                return false;
            }

            try {
                // Richiedi permesso
                console.log('[Push] Richiedo permesso notifiche...');
                const permission = await Notification.requestPermission();
                console.log('[Push] Risposta permesso:', permission);

                if (permission === 'denied') {
                    this.showMessage('Hai rifiutato le notifiche. Per attivarle vai nelle impostazioni.', 'warning');
                    this.updateButton(btn);
                    return false;
                }

                if (permission !== 'granted') {
                    this.showMessage('Permesso notifiche non concesso: ' + permission, 'warning');
                    this.updateButton(btn);
                    return false;
                }

                console.log('[Push] Permesso concesso, creo sottoscrizione...');

                // Crea sottoscrizione
                const subscription = await this.swRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.applicationServerKey
                });

                console.log('[Push] Sottoscrizione creata:', JSON.stringify(subscription.toJSON()));

                // Invia al server
                console.log('[Push] Salvo sottoscrizione sul server...');
                const saved = await this.saveSubscription(subscription);

                if (saved === true) {
                    this.isSubscribed = true;
                    this.updateButton(btn);
                    this.showMessage('Notifiche push attivate!', 'success');
                    console.log('[Push] Sottoscrizione completata con successo');
                    return true;
                } else {
                    // saved può essere stringa con il messaggio errore lato server
                    const serverMsg = (typeof saved === 'string' && saved) ? saved : 'Impossibile salvare la sottoscrizione sul server';
                    await subscription.unsubscribe();
                    throw new Error(serverMsg);
                }
            } catch (error) {
                console.error('[Push] Errore sottoscrizione:', error);

                // Messaggi di errore specifici
                let errorMsg = 'Errore nell\'attivazione delle notifiche';
                if (error.name === 'NotAllowedError') {
                    errorMsg = 'Permesso negato. Verifica le impostazioni del browser.';
                } else if (error.name === 'AbortError') {
                    errorMsg = 'Operazione annullata. Riprova.';
                } else if (error.message) {
                    errorMsg += ': ' + error.message;
                }

                this.showMessage(errorMsg, 'error');
                return false;
            }
        },

        /**
         * Annulla sottoscrizione
         */
        async unsubscribe(btn) {
            try {
                const subscription = await this.swRegistration.pushManager.getSubscription();

                if (subscription) {
                    // Rimuovi dal server
                    await this.removeSubscription(subscription.endpoint);

                    // Rimuovi localmente
                    await subscription.unsubscribe();
                }

                this.isSubscribed = false;
                this.updateButton(btn);
                this.showMessage('Notifiche push disattivate', 'info');
                return true;
            } catch (error) {
                console.error('[Push] Errore cancellazione sottoscrizione:', error);
                this.showMessage('Errore nella disattivazione', 'error');
                return false;
            }
        },

        /**
         * Salva sottoscrizione sul server
         */
        async saveSubscription(subscription) {
            try {
                // Determina tipo utente dalla pagina
                const isEmployee = window.location.pathname.includes('/employee/');
                const action = isEmployee ? 'subscribe_employee' : 'subscribe';

                const response = await fetch((window.PAM?.baseUrl || '') + '/api/push.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.getCsrfToken()
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: action,
                        subscription: subscription.toJSON()
                    })
                });

                let data = null;
                try { data = await response.json(); } catch (_) { /* non-json */ }
                console.log('[Push] saveSubscription HTTP', response.status, data);

                if (data && data.success === true) {
                    return true;
                }

                // Restituisce il messaggio errore (stringa) per essere mostrato all'utente
                const msg = (data && (data.message || data.error)) || ('HTTP ' + response.status);
                return typeof msg === 'string' ? msg : false;
            } catch (error) {
                console.error('[Push] Errore salvataggio sottoscrizione:', error);
                return error.message || false;
            }
        },

        /**
         * Rimuove sottoscrizione dal server
         */
        async removeSubscription(endpoint) {
            try {
                const response = await fetch((window.PAM?.baseUrl || '') + '/api/push.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.getCsrfToken()
                    },
                    body: JSON.stringify({
                        action: 'unsubscribe',
                        endpoint: endpoint
                    })
                });

                const data = await response.json();
                return data.success === true;
            } catch (error) {
                console.error('[Push] Errore rimozione sottoscrizione:', error);
                return false;
            }
        },

        /**
         * Invia notifica di test
         */
        async sendTestNotification() {
            try {
                const response = await fetch((window.PAM?.baseUrl || '') + '/api/push.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.getCsrfToken()
                    },
                    body: JSON.stringify({ action: 'test' })
                });

                const data = await response.json();

                if (data.sent > 0) {
                    this.showMessage('Notifica di test inviata!', 'success');
                } else {
                    this.showMessage('Nessuna sottoscrizione attiva', 'warning');
                }

                return data;
            } catch (error) {
                console.error('[Push] Errore invio test:', error);
                this.showMessage('Errore nell\'invio della notifica', 'error');
                return null;
            }
        },

        /**
         * Converte stringa base64 in Uint8Array
         */
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding)
                .replace(/-/g, '+')
                .replace(/_/g, '/');

            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);

            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }

            return outputArray;
        },

        /**
         * Ottieni CSRF token
         */
        getCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.content : '';
        },

        /**
         * Mostra messaggio toast
         */
        showMessage(message, type = 'info') {
            console.log(`[Push/${type}] ${message}`);

            if (window.GestionalePA && window.GestionalePA.showToast) {
                window.GestionalePA.showToast(message, type);
            } else {
                // Fallback: crea toast semplice
                this.createSimpleToast(message, type);
            }
        },

        /**
         * Crea un toast semplice come fallback
         */
        createSimpleToast(message, type) {
            // Rimuovi toast esistente
            const existing = document.getElementById('pam-push-toast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.id = 'pam-push-toast';
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 24px;
                border-radius: 8px;
                color: white;
                font-size: 14px;
                z-index: 10000;
                max-width: 90%;
                text-align: center;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideUp 0.3s ease;
            `;

            // Colori per tipo
            const colors = {
                success: '#22c55e',
                error: '#ef4444',
                warning: '#f59e0b',
                info: '#3b82f6'
            };
            toast.style.background = colors[type] || colors.info;
            toast.textContent = message;

            // Aggiungi stile animazione
            if (!document.getElementById('pam-toast-style')) {
                const style = document.createElement('style');
                style.id = 'pam-toast-style';
                style.textContent = '@keyframes slideUp { from { opacity: 0; transform: translateX(-50%) translateY(20px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }';
                document.head.appendChild(style);
            }

            document.body.appendChild(toast);

            // Rimuovi dopo 4 secondi
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
    };

    // Inizializza quando DOM pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => PAMPush.init());
    } else {
        PAMPush.init();
    }

    // Esponi globalmente
    window.PAMPush = PAMPush;

})();
