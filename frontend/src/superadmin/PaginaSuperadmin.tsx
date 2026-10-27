import { useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useStile } from '../componenti/stile';
import { esegui } from '../php/runtime';
import paginaClic from '../php/pagine/superadmin/clic';
import paginaIndice from '../php/pagine/superadmin/index';
import paginaLogin from '../php/pagine/superadmin/login';
import stile from '../php/pagine/superadmin/stile';
import paginaTotp from '../php/pagine/superadmin/totp';
import { api } from '../services/api';
import { Contenuto } from '../staff/PaginaStaff';
import '../staff/funzioni-cora';

type Quale = 'login' | 'totp' | 'index' | 'clic' | 'logout';
const TITOLI: Record<Quale, string> = { login: 'Superadmin Login', totp: 'Superadmin — verifica 2FA', index: 'Superadmin — Tenant', clic: 'Superadmin — Utilizzo HR', logout: 'Superadmin' };
const RENDER = { login: paginaLogin, totp: paginaTotp, index: paginaIndice, clic: paginaClic } as const;

/** Campi di un modulo come oggetto (i campi name[] diventano array). */
function campi(f: HTMLFormElement): Record<string, any> {
  const o: Record<string, any> = {};
  for (const [k, v] of new FormData(f).entries()) {
    if (k.endsWith('[]')) (o[k] ??= []).push(String(v));
    else o[k] = String(v);
  }
  return o;
}

/**
 * Pagine del superadmin (public/superadmin/): stesso markup e stile del PHP,
 * senza la cornice dello staff. Login in due passaggi, poi tenant e report.
 */
export function PaginaSuperadmin({ quale }: { quale: Quale }) {
  const vai = useNavigate();
  useStile(stile);
  useLayoutEffect(() => {
    document.title = TITOLI[quale];
    const auth = quale === 'login' || quale === 'totp';
    if (auth) document.body.classList.add('auth');
    return () => document.body.classList.remove('auth');
  }, [quale]);
  const [V, setV] = useState<any>(null);
  const [errore, setErrore] = useState('');

  useEffect(() => {
    let vivo = true;
    (async () => {
      if (quale === 'logout') {
        await api.post('/superadmin/esci').catch(() => undefined);
        vai('/superadmin/login', { replace: true });
        return;
      }
      const s = (await api.get('/superadmin/stato')).data;
      if (!s.configurato) { if (vivo) setErrore('Superadmin non configurato.'); return; }
      if (quale === 'login' || quale === 'totp') {
        if (s.fase === 'completa') { vai('/superadmin/', { replace: true }); return; }
        if (quale === 'totp' && s.fase !== 'password') { vai('/superadmin/login', { replace: true }); return; }
        if (vivo) setV({ error: '' });
        return;
      }
      if (s.fase !== 'completa') { vai('/superadmin/login', { replace: true }); return; }
      const r = await api.get(quale === 'index' ? '/superadmin/tenant' : `/superadmin/clic${location.search}`);
      if (vivo) setV(r.data.V);
    })().catch(() => vai('/superadmin/login', { replace: true }));
    return () => { vivo = false; };
  }, [quale, vai]);

  // Moduli della pagina: al backend, poi la pagina si ridisegna (o si passa al passo dopo)
  useEffect(() => {
    const invio = async (e: SubmitEvent) => {
      const f = e.target as HTMLFormElement;
      if ((f.getAttribute('method') ?? 'get').toLowerCase() !== 'post') return;
      e.preventDefault();
      const dati = campi(f);
      delete dati.csrf_token;
      if (quale === 'login' || quale === 'totp') {
        const r = (await api.post(`/superadmin/${quale}`, dati)).data;
        if (r.ok) vai(r.fase === 'completa' ? '/superadmin/' : '/superadmin/totp');
        else if (r.fase === 'nessuna') vai('/superadmin/login');
        else setV({ error: r.error ?? '' });
        return;
      }
      const r = await api.post('/superadmin/tenant', dati);
      setV(r.data.V);
      window.scrollTo(0, 0);
    };
    // Esportazioni del report: file dal backend
    const clic = (e: MouseEvent) => {
      const a = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (!a) return;
      const u = new URL(a.href, location.href);
      if (u.pathname.endsWith('/superadmin/clic') && u.searchParams.has('export')) {
        e.preventDefault();
        location.assign(`/api/v1/superadmin/clic/export${u.search}`);
      }
    };
    document.addEventListener('submit', invio);
    document.addEventListener('click', clic);
    return () => { document.removeEventListener('submit', invio); document.removeEventListener('click', clic); };
  }, [quale, vai]);

  const html = useMemo(() => (V && quale !== 'logout' ? esegui(RENDER[quale], V) : ''), [V, quale]);
  if (errore) return <>{errore}</>;
  return <Contenuto html={html} />;
}
