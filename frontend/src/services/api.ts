import axios from 'axios';

/**
 * Client delle API. La sessione viaggia in cookie httpOnly (non leggibili da
 * JavaScript): qui non si salva nessun token. Le richieste che cambiano dati
 * portano l'header X-CSRF-Token letto dal cookie non httpOnly "cora_csrf".
 */
export const api = axios.create({ baseURL: '/api/v1', withCredentials: true });

const leggiCsrf = () => document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1];

// Se il cookie CSRF non c'e' ancora (prima visita), lo si chiede una volta sola
// prima di mandare la richiesta: senza, il backend la rifiuterebbe.
let preparazione: Promise<unknown> | null = null;

api.interceptors.request.use(async (config) => {
  const metodo = (config.method || 'get').toLowerCase();
  if (!['get', 'head', 'options'].includes(metodo)) {
    if (!leggiCsrf()) {
      preparazione ??= axios.get('/api/v1/auth/sessione', { withCredentials: true }).finally(() => { preparazione = null; });
      await preparazione;
    }
    const token = leggiCsrf();
    if (token) config.headers.set('X-CSRF-Token', decodeURIComponent(token));
  }
  return config;
});

/** Pagine che richiedono l'accesso (il superadmin ha il suo login e lo gestisce da se'). */
const AREE_RISERVATE = /^\/(admin|employee(?!\/unsubscribe)|consulente-lavoro|formatore|accountant)(\/|$)/;

// Password temporanea da cambiare: il backend rifiuta tutto il resto, la pagina
// porta al cambio password (come il redirect di header-employee.php).
api.interceptors.response.use(undefined, (err) => {
  const d = err?.response?.data;
  if (err?.response?.status === 403 && d?.must_change_password && location.pathname.startsWith('/employee/')
    && location.pathname !== '/employee/change-password') {
    location.replace('/employee/change-password');
  }
  // Sessione scaduta (es. computer ripreso dopo ore) su una pagina riservata:
  // al login e poi di nuovo qui, come il redirect del PHP. Senza, la pagina
  // restava bianca in attesa di dati che non arrivano.
  const url = String(err?.config?.url ?? '');
  if (err?.response?.status === 401 && !url.startsWith('/auth/') && AREE_RISERVATE.test(location.pathname)) {
    const qui = location.pathname + location.search;
    location.replace('/auth/login?return=' + encodeURIComponent(qui));
  }
  return Promise.reject(err);
});
