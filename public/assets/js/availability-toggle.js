/**
 * Availability toggle (employee topbar)
 * Cambia stato Operativo / In chiamata / In riunione
 */
(function () {
    const root = document.querySelector('[data-availability-toggle]');
    if (!root) return;

    const button = root.querySelector('.availability-pill');
    const menu = root.querySelector('.availability-menu');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const baseUrl = (window.PAM && window.PAM.baseUrl) ? window.PAM.baseUrl : '';

    if (!button || !menu) return;

    function close() {
        menu.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    }
    function open() {
        menu.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
    }

    button.addEventListener('click', (e) => {
        e.stopPropagation();
        if (menu.classList.contains('open')) close(); else open();
    });

    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) close();
    });

    menu.addEventListener('click', async (e) => {
        const item = e.target.closest('[data-status]');
        if (!item) return;
        e.preventDefault();
        const status = item.getAttribute('data-status');

        button.classList.add('is-saving');
        try {
            const res = await fetch(baseUrl + '/api/availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ status }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok || data.error) {
                throw new Error(data.message || 'Errore');
            }
            // Aggiorna UI
            root.setAttribute('data-current', status);
            const labelEl = button.querySelector('.availability-label');
            if (labelEl) labelEl.textContent = item.textContent.trim();
            close();
        } catch (err) {
            alert('Impossibile aggiornare lo stato: ' + err.message);
        } finally {
            button.classList.remove('is-saving');
        }
    });
})();
