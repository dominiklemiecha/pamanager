import { useEffect, useState } from 'react';
import { api } from '../services/api';
import { TIPI } from '../pages/condivise/calendario-feste';

interface Giornata { id: number; day: string; kind: string; label: string }
interface Avviso {
  id?: number; title: string; message: string; resume_note: string; signature: string;
  start_date: string; end_date: string; theme: string;
}
const VUOTO: Avviso = { title: '', message: '', resume_note: '', signature: '', start_date: '', end_date: '', theme: 'natale' };

const S = {
  barra: { display: 'flex', justifyContent: 'flex-end', marginBottom: 10 } as const,
  bottone: { border: '1px solid #e2eae9', background: '#fff', borderRadius: 999, padding: '8px 16px', fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' } as const,
  primario: { border: 0, background: '#080808', color: '#fff', borderRadius: 999, padding: '9px 18px', fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' } as const,
  fondo: { position: 'fixed', inset: 0, background: 'rgba(8,8,8,.45)', zIndex: 10000, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '40px 16px', overflowY: 'auto' } as const,
  box: { background: '#fff', borderRadius: 20, width: '100%', maxWidth: 760, padding: 24, display: 'flex', flexDirection: 'column', gap: 18, color: '#080808' } as const,
  sezione: { border: '1px solid #e2eae9', borderRadius: 16, padding: 16, display: 'flex', flexDirection: 'column', gap: 10 } as const,
  griglia: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 } as const,
  campo: { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, fontWeight: 600, color: '#4f5c59' } as const,
  input: { border: '1px solid #e2eae9', borderRadius: 10, padding: '9px 10px', fontSize: 14, fontFamily: 'inherit', color: '#080808' } as const,
  riga: { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0', borderTop: '1px solid #edf2f1', fontSize: 14 } as const,
  errore: { background: '#ffedec', color: '#c9123b', borderRadius: 10, padding: '8px 12px', fontSize: 13 } as const,
};
const fmt = (d: string) => new Date(`${d}T12:00:00Z`).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

/**
 * Gestione delle giornate aziendali e dell'avviso del periodo (solo HR):
 * pulsante sopra il calendario e finestra con i due elenchi.
 */
export default function GestioneGiornate() {
  const [aperta, setAperta] = useState(false);
  const [giornate, setGiornate] = useState<Giornata[]>([]);
  const [avviso, setAvviso] = useState<Avviso>(VUOTO);
  const [nuova, setNuova] = useState({ day: '', kind: 'chiusura', label: '' });
  const [errore, setErrore] = useState('');
  const [occupato, setOccupato] = useState(false);
  const [cambiato, setCambiato] = useState(false);

  const carica = () => api.get('/calendario/giornate').then((r) => {
    setGiornate(r.data.giornate ?? []);
    setAvviso(r.data.avvisi?.[0] ?? VUOTO);
  }).catch(() => setErrore('Impossibile caricare le giornate'));
  useEffect(() => { if (aperta) carica(); }, [aperta]);

  async function invia(dati: Record<string, unknown>) {
    if (occupato) return false;
    setOccupato(true); setErrore('');
    try {
      const r = await api.post('/calendario/giornate', dati);
      if (!r.data?.success) { setErrore(r.data?.error ?? 'Operazione non riuscita'); return false; }
      setCambiato(true);
      await carica();
      return true;
    } catch {
      setErrore('Operazione non riuscita');
      return false;
    } finally {
      setOccupato(false);
    }
  }

  function chiudi() {
    setAperta(false);
    // Il calendario e' disegnato dai dati del server: si ricarica per mostrare le modifiche.
    if (cambiato) location.reload();
  }

  const campo = (k: keyof Avviso) => ({
    value: String(avviso[k] ?? ''),
    onChange: (ev: { target: { value: string } }) => setAvviso({ ...avviso, [k]: ev.target.value }),
  });

  return (
    <>
      <div style={S.barra}>
        <button type="button" style={S.bottone} onClick={() => setAperta(true)}>Giornate aziendali</button>
      </div>
      {aperta && (
        <div style={S.fondo} onClick={(ev) => { if (ev.target === ev.currentTarget) chiudi(); }}>
          <div style={S.box} role="dialog" aria-label="Giornate aziendali">
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <h2 style={{ margin: 0, fontSize: 20 }}>Giornate aziendali</h2>
              <button type="button" style={S.bottone} onClick={chiudi}>Chiudi</button>
            </div>
            {errore && <div style={S.errore}>{errore}</div>}

            <div style={S.sezione}>
              <b>Avviso nel calendario</b>
              <span style={{ fontSize: 13, color: '#4f5c59' }}>Banner in alto nel calendario di tutti, fino alla data di fine. Elenca le giornate del periodo.</span>
              <div style={S.griglia}>
                <label style={S.campo}>Titolo<input style={S.input} maxLength={150} {...campo('title')} /></label>
                <label style={S.campo}>Dal<input type="date" style={S.input} {...campo('start_date')} /></label>
                <label style={S.campo}>Al<input type="date" style={S.input} {...campo('end_date')} /></label>
                <label style={S.campo}>Tema
                  <select style={S.input} {...campo('theme')}>
                    <option value="natale">Natale (Babbo Natale, renne, neve)</option>
                    <option value="semplice">Semplice</option>
                  </select>
                </label>
              </div>
              <label style={S.campo}>Messaggio<textarea style={{ ...S.input, minHeight: 70 }} maxLength={4000} {...campo('message')} /></label>
              <label style={S.campo}>Ripresa / nota finale<textarea style={{ ...S.input, minHeight: 50 }} maxLength={1000} {...campo('resume_note')} /></label>
              <label style={S.campo}>Firma<input style={S.input} maxLength={100} {...campo('signature')} /></label>
              <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                {avviso.id && (
                  <button type="button" style={S.bottone} disabled={occupato}
                    onClick={() => { if (confirm('Eliminare l\'avviso? Le giornate restano.')) invia({ action: 'delete_notice', id: avviso.id }); }}>
                    Elimina avviso
                  </button>
                )}
                <button type="button" style={{ ...S.primario, opacity: occupato ? 0.6 : 1 }} disabled={occupato}
                  onClick={() => invia({ action: 'save_notice', ...avviso })}>
                  {occupato ? 'Salvataggio…' : 'Salva avviso'}
                </button>
              </div>
            </div>

            <div style={S.sezione}>
              <b>Giornate</b>
              <span style={{ fontSize: 13, color: '#4f5c59' }}>Chiusure, mezze giornate, giorni in smart working o in presenza. Le festività nazionali sono già nel calendario.</span>
              <div style={S.griglia}>
                <label style={S.campo}>Data<input type="date" style={S.input} value={nuova.day} onChange={(ev) => setNuova({ ...nuova, day: ev.target.value })} /></label>
                <label style={S.campo}>Tipo
                  <select style={S.input} value={nuova.kind} onChange={(ev) => setNuova({ ...nuova, kind: ev.target.value })}>
                    {Object.entries(TIPI).map(([k, t]) => <option key={k} value={k}>{t.nome}</option>)}
                  </select>
                </label>
                <label style={S.campo}>Descrizione<input style={S.input} maxLength={80} placeholder="es. Vigilia di Natale" value={nuova.label} onChange={(ev) => setNuova({ ...nuova, label: ev.target.value })} /></label>
              </div>
              <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                <button type="button" style={{ ...S.primario, opacity: occupato ? 0.6 : 1 }} disabled={occupato || !nuova.day}
                  onClick={async () => { if (await invia({ action: 'save_day', ...nuova })) setNuova({ day: '', kind: nuova.kind, label: '' }); }}>
                  Aggiungi giornata
                </button>
              </div>
              {giornate.length === 0 && <span style={{ fontSize: 13, color: '#8a9794' }}>Nessuna giornata inserita.</span>}
              {giornate.map((g) => (
                <div key={g.id} style={S.riga}>
                  <span style={{ minWidth: 150, fontWeight: 600 }}>{fmt(g.day)}</span>
                  <span className={`fe-chip ${TIPI[g.kind]?.cls ?? ''}`}>{TIPI[g.kind]?.nome ?? g.kind}</span>
                  <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{g.label}</span>
                  <button type="button" style={S.bottone} disabled={occupato}
                    onClick={() => { if (confirm('Eliminare questa giornata?')) invia({ action: 'delete_day', id: g.id }); }}>
                    Elimina
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
