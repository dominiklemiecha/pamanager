import { useState } from 'react';
import { nome } from '../../cora/formato';
import { api } from '../../services/api';
import { Modale, toast } from './comune';

/* ===================== Import anagrafica TeamSystem (tracciato ANDIP) ===================== */

interface Modifica { campo: string; etichetta: string; prima: string | null; dopo: string | null }
interface Voce {
  riga: number; codiceFiscale: string; cognome: string; nome: string; matricola: string; filiale: number | null; descrFiliale: string;
  qualifica: string; partTime: number | null; dataCessazione: string | null; email: string | null;
  stato: 'aggiorna' | 'invariato' | 'nuovo' | 'cessato' | 'errore'; dipendenteId: number | null; attivo: boolean | null; disattiva: boolean;
  modifiche: Modifica[]; errori: string[];
}
interface Anteprima {
  azienda: string | null; voci: Voce[]; reparti: Array<{ id: number; nome: string }>;
  assenti: Array<{ id: number; nome: string; cognome: string; codiceFiscale: string }>;
}
interface Esito { aggiornati: number; disattivati: number; creati: number; emailInviate: number; errori: Array<{ codiceFiscale: string; errore: string }> }

const STATI: Record<Voce['stato'], [string, string]> = {
  aggiorna: ['Da aggiornare', 'attesa'], invariato: ['Già allineato', 'ok'], nuovo: ['Da creare', 'attesa'],
  cessato: ['Cessato, non creato', 'cessato'], errore: ['Errore nel file', 'errore'],
};
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const dataIt = (v: string | null) => (v && /^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v.slice(8, 10)}/${v.slice(5, 7)}/${v.slice(0, 4)}` : v ?? '—');
const valore = (m: Modifica, v: string | null) => (m.campo.endsWith('_date') ? dataIt(v) : m.campo === 'part_time_percent' && v !== null ? `${Number(v)}`.replace('.', ',') : v ?? '—');
const errore = (e: any) => e?.response?.data?.message ?? 'Operazione non riuscita';
const plurale = (n: number, uno: string, tanti: string) => `${n} ${n === 1 ? uno : tanti}`;

/**
 * Carica il file ANDIP del consulente e mostra, per ogni dipendente
 * (riconosciuto dal codice fiscale), cosa cambia o se va creato. Per i
 * nuovi l'HR indica email (per le credenziali) e reparto. Si applicano
 * solo le righe scelte.
 */
export function ImportAndip({ aperta, chiudi, fatto }: { aperta: boolean; chiudi: () => void; fatto: () => Promise<void> }) {
  const [contenuto, setContenuto] = useState('');
  const [file, setFile] = useState('');
  const [a, setA] = useState<Anteprima | null>(null);
  const [scelti, setScelti] = useState<Set<string>>(new Set());
  const [email, setEmail] = useState<Record<string, string>>({});
  const [reparto, setReparto] = useState<Record<string, string>>({});
  const [repartoBase, setRepartoBase] = useState('');
  const [err, setErr] = useState('');
  const [lavoro, setLavoro] = useState(false);

  const azzera = () => { setContenuto(''); setFile(''); setA(null); setScelti(new Set()); setEmail({}); setReparto({}); setRepartoBase(''); setErr(''); };
  const esci = () => { azzera(); chiudi(); };

  const leggi = async (f: File | undefined) => {
    if (!f) return;
    setErr(''); setA(null); setLavoro(true);
    try {
      const testo = await f.text();
      const r = await api.post('/hr/andip/anteprima', { contenuto: testo });
      const dati = r.data as Anteprima;
      setContenuto(testo); setFile(f.name); setA(dati);
      setScelti(new Set(dati.voci.filter((v) => v.stato === 'aggiorna' || v.stato === 'nuovo').map((v) => v.codiceFiscale)));
      setEmail(Object.fromEntries(dati.voci.filter((v) => v.stato === 'nuovo').map((v) => [v.codiceFiscale, v.email ?? ''])));
      setReparto({});
      setRepartoBase(dati.reparti.length === 1 ? String(dati.reparti[0].id) : '');
    } catch (e) { setErr(errore(e)); }
    setLavoro(false);
  };

  const daAggiornare = (a?.voci ?? []).filter((v) => v.stato === 'aggiorna' && scelti.has(v.codiceFiscale));
  const daCreare = (a?.voci ?? []).filter((v) => v.stato === 'nuovo' && scelti.has(v.codiceFiscale));
  const ciSonoNuovi = (a?.voci ?? []).some((v) => v.stato === 'nuovo');
  const ciSonoAzioni = (a?.voci ?? []).some((v) => v.stato === 'aggiorna' || v.stato === 'nuovo');
  const repartoDi = (cf: string) => reparto[cf] || repartoBase;

  const applica = async () => {
    // Nuovi: email e reparto obbligatori (servono per le credenziali e per l'anagrafica)
    const senzaEmail = daCreare.filter((v) => !EMAIL.test((email[v.codiceFiscale] ?? '').trim()));
    if (senzaEmail.length) { setErr(`Email mancante o non valida per: ${senzaEmail.map((v) => `${nome(v.cognome)} ${nome(v.nome)}`).join(', ')}`); return; }
    const senzaReparto = daCreare.filter((v) => !repartoDi(v.codiceFiscale));
    if (senzaReparto.length) { setErr(`Scegli il reparto per: ${senzaReparto.map((v) => `${nome(v.cognome)} ${nome(v.nome)}`).join(', ')}`); return; }
    setLavoro(true); setErr('');
    try {
      const r = await api.post('/hr/andip/applica', {
        contenuto, scelti: daAggiornare.map((v) => v.codiceFiscale),
        nuovi: daCreare.map((v) => ({ codiceFiscale: v.codiceFiscale, email: email[v.codiceFiscale].trim(), repartoId: Number(repartoDi(v.codiceFiscale)) })),
      });
      const x = r.data as Esito;
      const parti = [
        x.creati ? `${plurale(x.creati, 'dipendente creato', 'dipendenti creati')} (${plurale(x.emailInviate, 'email inviata', 'email inviate')})` : '',
        x.aggiornati ? plurale(x.aggiornati, 'aggiornato', 'aggiornati') : '',
        x.disattivati ? plurale(x.disattivati, 'disattivato', 'disattivati') : '',
      ].filter(Boolean);
      toast('Anagrafica importata', parti.join(', ') || 'Nessuna modifica');
      await fatto();
      if (x.errori.length) {
        // Resta aperta con gli errori: l'anteprima riletta mostra cosa manca ancora
        setErr(x.errori.map((e) => `${e.codiceFiscale}: ${e.errore}`).join(' · '));
        const n = await api.post('/hr/andip/anteprima', { contenuto });
        const dati = n.data as Anteprima;
        setA(dati);
        setScelti(new Set(dati.voci.filter((v) => (v.stato === 'aggiorna' || v.stato === 'nuovo') && x.errori.some((e) => e.codiceFiscale === v.codiceFiscale)).map((v) => v.codiceFiscale)));
      } else { azzera(); chiudi(); }
    } catch (e) { setErr(errore(e)); }
    setLavoro(false);
  };

  const cambia = (cf: string) => setScelti((s) => { const n = new Set(s); if (n.has(cf)) n.delete(cf); else n.add(cf); return n; });
  const etichettaPulsante = [
    daCreare.length ? `crea ${daCreare.length}` : '',
    daAggiornare.length ? `aggiorna ${daAggiornare.length}` : '',
  ].filter(Boolean).join(' e ');

  return (
    <Modale aperta={aperta} chiudi={esci} larga titolo="Importa anagrafica da TeamSystem"
      sottotitolo={a ? `${file}${a.azienda ? ` · azienda TeamSystem ${a.azienda}` : ''}` : 'File ANDIP (CSV) del consulente paghe. I dipendenti sono riconosciuti dal codice fiscale.'}>
      <div className="hr-form">
        {!a && (
          <label className="cora-btn cora-btn-secondary hr-andip-file">
            <input type="file" hidden accept=".csv,text/csv" disabled={lavoro} onChange={(e) => { leggi(e.target.files?.[0]); e.target.value = ''; }} />
            {lavoro ? 'Lettura in corso…' : 'Scegli il file CSV'}
          </label>
        )}
        {a && (
          <>
            <p className="hr-andip-nota">I dati del file sostituiscono quelli di Cora (tutte le colonne restano consultabili nella scheda del dipendente); un campo vuoto nel file non cancella il dato presente.
              Chi ha una data di cessazione già passata viene disattivato. I nuovi dipendenti ricevono le credenziali all’email indicata;
              chi è già cessato non viene creato.</p>
            {ciSonoNuovi && (a.reparti.length ? (
              <label className="cora-field hr-andip-reparto"><span className="cora-label">Reparto dei nuovi dipendenti</span>
                <select className="cora-input" value={repartoBase} onChange={(e) => setRepartoBase(e.target.value)}>
                  <option value="">Scegli…</option>
                  {a.reparti.map((r) => <option key={r.id} value={r.id}>{r.nome}</option>)}
                </select></label>
            ) : <p className="hr-errore">Per creare i nuovi dipendenti serve almeno un reparto attivo: crealo in Dipendenti → Reparti.</p>)}
            <div className="hr-andip-elenco">
              {a.voci.map((v) => {
                const [etichetta, tono] = STATI[v.stato];
                const sceglibile = v.stato === 'aggiorna' || (v.stato === 'nuovo' && a.reparti.length > 0);
                return (
                  <div key={v.riga} className="hr-andip-voce">
                    <div className="hr-andip-testa">
                      {sceglibile
                        ? <input type="checkbox" checked={scelti.has(v.codiceFiscale)} onChange={() => cambia(v.codiceFiscale)} aria-label={`Importa ${v.cognome} ${v.nome}`} />
                        : <span className="hr-andip-spazio" />}
                      <span className="hr-andip-chi">
                        <strong>{nome(v.cognome)} {nome(v.nome)}</strong>
                        <small>{v.codiceFiscale || '—'} · matricola {v.matricola || '—'} · filiale {v.filiale ?? '—'}{v.descrFiliale ? ` (${v.descrFiliale})` : ''}{v.qualifica ? ` · ${v.qualifica}` : ''}</small>
                      </span>
                      <span className={`hr-badge is-${tono}`}><i />{etichetta}</span>
                    </div>
                    {v.stato === 'nuovo' && sceglibile && scelti.has(v.codiceFiscale) && (
                      <div className="hr-andip-nuovo">
                        <input className="cora-input" type="email" placeholder="Email per le credenziali" aria-label={`Email di ${v.cognome} ${v.nome}`}
                          value={email[v.codiceFiscale] ?? ''} onChange={(e) => setEmail((m) => ({ ...m, [v.codiceFiscale]: e.target.value }))} />
                        <select className="cora-input" aria-label={`Reparto di ${v.cognome} ${v.nome}`} value={repartoDi(v.codiceFiscale)}
                          onChange={(e) => setReparto((m) => ({ ...m, [v.codiceFiscale]: e.target.value }))}>
                          <option value="">Reparto…</option>
                          {a.reparti.map((r) => <option key={r.id} value={r.id}>{r.nome}</option>)}
                        </select>
                      </div>
                    )}
                    {v.stato === 'cessato' && <p className="hr-andip-dett">Cessato il {dataIt(v.dataCessazione)}</p>}
                    {v.disattiva && <p className="hr-andip-dett is-pericolo">Cessato il {dataIt(v.dataCessazione)}: verrà disattivato</p>}
                    {v.modifiche.map((m) => (
                      m.campo === 'teamsystem_data'
                        ? <p key={m.campo} className="hr-andip-dett">{m.etichetta}: {m.dopo}</p>
                        : <p key={m.campo} className="hr-andip-dett">{m.etichetta}: <s>{valore(m, m.prima)}</s> → <b>{valore(m, m.dopo)}</b></p>
                    ))}
                    {v.errori.map((x) => <p key={x} className="hr-andip-dett is-pericolo">Riga {v.riga}: {x}</p>)}
                  </div>
                );
              })}
            </div>
            {!ciSonoAzioni && <p className="hr-andip-nota">Nessun dipendente da creare o aggiornare: i dati del file sono già allineati con Cora.</p>}
            {a.assenti.length > 0 && (
              <p className="hr-andip-nota">Attivi in Cora ma non presenti nel file (non vengono toccati): {a.assenti.map((x) => `${nome(x.cognome)} ${nome(x.nome)}`).join(', ')}.</p>
            )}
          </>
        )}
        {err && <p className="hr-errore">{err}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={a ? azzera : esci} disabled={lavoro}>{a ? 'Cambia file' : 'Annulla'}</button>
          {a && ciSonoAzioni && (
            <button type="button" className="cora-btn cora-btn-primary" onClick={applica} disabled={lavoro || !etichettaPulsante}>
              {lavoro ? 'Importazione…' : etichettaPulsante ? `${etichettaPulsante[0].toUpperCase()}${etichettaPulsante.slice(1)} dipendent${daCreare.length + daAggiornare.length === 1 ? 'e' : 'i'}` : 'Nessuna riga scelta'}
            </button>
          )}
        </div>
      </div>
    </Modale>
  );
}
