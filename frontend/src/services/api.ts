import axios from 'axios';

/**
 * Client delle API. La sessione viaggia in cookie httpOnly (non leggibili da
 * JavaScript): qui non si salva nessun token. Le richieste che cambiano dati
 * portano l'header X-CSRF-Token letto dal cookie non httpOnly "cora_csrf".
 */
export const api = axios.create({ baseURL: '/api/v1', withCredentials: true });

api.interceptors.request.use((config) => {
  const metodo = (config.method || 'get').toLowerCase();
  if (!['get', 'head', 'options'].includes(metodo)) {
    const m = document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/);
    if (m) config.headers.set('X-CSRF-Token', decodeURIComponent(m[1]));
  }
  return config;
});
