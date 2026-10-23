import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Cornice, CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { dataBreve, fa, iniziali, nome, num, TIPI_ASSENZA } from '../../cora/formato';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

interface Saldo { total: number; used: number; residual: number; inAttesa: number }
interface Persona { id: number; nome: string; cognome: string; foto: string; assente?: boolean }
interface DatiHome {
  mioId: number;
  nome: string;
  saldi: { ferie: Saldo; permesso: Saldo };
  compleanni: Persona[];
  persone: Persona[];
  documenti: Array<{ titolo: string; data: string; url: string }>;
  comunicazioni: Array<{ id: number; titolo: string; data: string; letta: boolean }>;
  richieste: Array<{ id: number; leave_type: string; start_date: string; end_date: string; status: string; created_at: string }>;
}

const S = ({ n }: { n: string }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n) }} />;
const GIORNI = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const STATI: Record<string, string> = { pending: 'In attesa', approved: 'Approvata', rejected: 'Rifiutata', cancelled: 'Annullata' };
const R = 78;
const C = 2 * Math.PI * R;
const arrot2 = (n: number) => Math.round(n * 100) / 100;

/** "23 – 29 ott", "8 ott", "30 set – 1 ott" */
function intervallo(s: string, e: string): string {
  if (s === e) return dataBreve(s, false);
  if (s.slice(0, 7) === e.slice(0, 7)) return `${Number(s.slice(8, 10))} – ${dataBreve(e, false)}`;
  return `${dataBreve(s, false)} – ${dataBreve(e, false)}`;
}

function oraRoma() {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', hour: '2-digit', weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric', hourCycle: 'h23' })
    .formatToParts(new Date()).map((x) => [x.type, x.value]));
  const g = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(p.weekday);
  return { ora: Number(p.hour), giorno: g, d: Number(p.day), m: Number(p.month), a: Number(p.year) };
}

function Volto({ p }: { p: Persona }) {
  return p.foto ? <img src={p.foto} alt="" loading="lazy" /> : <span>{iniziali(p.nome, p.cognome)}</span>;
}

/** Home del dipendente (employee/index.php, interfaccia Cora). */
export default function Home() {
  const cornice = useCornice();
  const [d, setD] = useState<DatiHome | null>(null);
  const liste = useRef<HTMLElement>(null);
  useEffect(() => { api.get('/dipendente/home').then((r) => setD(r.data)).catch(() => undefined); }, []);

  // Liste della Home: le righe che non entrano per intero restano nascoste.
  useLayoutEffect(() => {
    if (!d || !cornice) return;
    const adatta = () => {
      liste.current?.querySelectorAll<HTMLElement>('.cora-rows').forEach((box) => {
        const righe = Array.from(box.children) as HTMLElement[];
        righe.forEach((r) => { r.hidden = false; });
        if (getComputedStyle(box).overflow !== 'hidden') return;
        const limite = box.getBoundingClientRect().bottom + 1;
        righe.forEach((r) => { if (r.getBoundingClientRect().bottom > limite) r.hidden = true; });
      });
    };
    adatta();
    const timer = setTimeout(adatta, 900);
    window.addEventListener('resize', adatta);
    return () => { clearTimeout(timer); window.removeEventListener('resize', adatta); };
  }, [d, cornice]);

  // Banner del compleanno: si chiude per oggi (stesso script della pagina PHP).
  useEffect(() => {
    if (!d || !cornice) return;
    const b = document.querySelector<HTMLElement>('[data-cora-bday]');
    if (!b) return;
    const chiave = 'cora-bday-' + new Date().toISOString().slice(0, 10);
    try { if (localStorage.getItem(chiave)) return; } catch { /* niente */ }
    b.hidden = false;
    b.querySelector('[data-cora-bday-close]')?.addEventListener('click', () => {
      b.hidden = true;
      try { localStorage.setItem(chiave, '1'); } catch { /* niente */ }
    });
  }, [d, cornice]);

  if (!cornice || !d) return null;

  const t = oraRoma();
  const saluto = t.ora < 13 ? 'Buongiorno' : t.ora < 18 ? 'Buon pomeriggio' : 'Buonasera';
  const oggi = `${GIORNI[t.giorno]} ${t.d} ${MESI[t.m]} ${t.a}`;

  return (
    <CoraShell pagina="index" titolo="Home" cornice={cornice}>
      <HomeContenuto d={d} cornice={cornice} saluto={saluto} oggi={oggi} liste={liste} />
      <PresenzeModale persone={d.persone} />
    </CoraShell>
  );
}

function contaPresenze(persone: Persona[]) {
  const assenti = persone.filter((p) => p.assente).length;
  return { assenti, presenti: persone.length - assenti };
}

function PresenzeModale({ persone }: { persone: Persona[] }) {
  const { assenti, presenti } = contaPresenze(persone);
  return (
    <div className="cora-modal" id="coraPresenceModal" hidden>
    <div className="cora-modal-box cora-modal-sm" role="dialog" aria-modal="true" aria-labelledby="coraPresenceTitle">
      <div className="cora-modal-h">
        <div><h2 id="coraPresenceTitle">Presenze di oggi</h2><p>{presenti} present{presenti === 1 ? 'e' : 'i'} · {assenti} assent{assenti === 1 ? 'e' : 'i'}</p></div>
        <button type="button" className="cora-modal-x" data-cora-close aria-label="Chiudi"><S n="ic-x-modal.svg" /></button>
      </div>
      <div className="cora-people">
        {persone.map((p) => (
          <div key={p.id} className="cora-person">
            <span className="cora-av">
              <Volto p={p} />
              <i className={p.assente ? 'is-absent' : 'is-present'} />
            </span>
            <span className="cora-person-name">{nome(p.nome)} {nome(p.cognome)}</span>
            <span className={'cora-person-state ' + (p.assente ? 'is-absent' : 'is-present')}>{p.assente ? 'Assente' : 'Presente'}</span>
          </div>
        ))}
      </div>
    </div>
    </div>
  );
}

function HomeContenuto(props: {
  d: DatiHome; cornice: Cornice; saluto: string; oggi: string; liste: React.RefObject<HTMLElement | null>;
}) {
  const { d, saluto, oggi } = props;
  const anno = new Date().getFullYear();
  const { assenti, presenti } = contaPresenze(d.persone);

  const festa = d.compleanni.length ? calcolaFesta(d) : null;

  const centro = festa ? (
    <div className="cora-bday" data-cora-bday hidden>
      <span className="cora-bday-confetti" aria-hidden="true" dangerouslySetInnerHTML={{ __html: svgCora('coriandoli.svg') }} />
      <span className="cora-bday-who">
        <span className="cora-bday-avatar">
          {festa.persona.foto ? <img src={festa.persona.foto} alt="" /> : iniziali(festa.persona.nome, festa.persona.cognome)}
        </span>
        <span className="cora-bday-text">{festa.testo}</span>
      </span>
      <span className="cora-bday-actions">
        {!festa.me && <a className="cora-bday-cta" href="/employee/chat.php">Fai gli auguri</a>}
        <button type="button" className="cora-bday-close" data-cora-bday-close aria-label="Chiudi" dangerouslySetInnerHTML={{ __html: svgCora('ic-x.svg') }} />
      </span>
    </div>
  ) : undefined;

  const saldi: Array<['ferie' | 'permesso', string, string, string]> = [
    ['ferie', `Ferie ${anno}`, 'gg', 'giorni residui'],
    ['permesso', `Permessi ${anno}`, 'h', 'ore residue'],
  ];

  return (
    <>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <Topbar titolo={`${saluto}, ${nome(d.nome)}`} sottotitolo={oggi} centro={centro} cornice={props.cornice} />
      </div>

      <section className="cora-home-row cora-home-saldi cora-reveal" style={{ '--i': 0 } as React.CSSProperties} data-tour="balance">
        {saldi.map(([tipo, titolo, unita, etichetta]) => {
          const s = d.saldi[tipo];
          const pct = s.total > 0 ? Math.max(0, Math.min(1, s.used / s.total)) : 0;
          const arco = pct * C;
          return (
            <div key={tipo} className="cora-card cora-saldo">
              <h2>{titolo}</h2>
              <div className="cora-saldo-body">
                <div className="cora-donut">
                  <svg viewBox="0 0 176 176" width="176" height="176" aria-hidden="true">
                    <circle cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent-soft)" strokeWidth="20" />
                    <circle className="cora-donut-arc" cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent)" strokeWidth="20"
                      strokeDasharray={`${arrot2(arco)} ${arrot2(C)}`} style={{ '--arc': arrot2(arco) } as React.CSSProperties} transform="rotate(-90 88 88)" />
                  </svg>
                  <div className="cora-donut-val">
                    <strong>{num(s.residual)}</strong>
                    <span>{etichetta}</span>
                  </div>
                  {s.inAttesa > 0 && (
                    <a className="cora-donut-pending" href="/employee/leave-requests.php?status=pending"
                      title={`${s.inAttesa} richiest${s.inAttesa === 1 ? 'a' : 'e'} in attesa`} dangerouslySetInnerHTML={{ __html: svgCora('ic-orologio.svg') }} />
                  )}
                </div>
                <div className="cora-saldo-rows">
                  <div className="cora-saldo-row"><i className="dot is-used" /><span>Utilizzati</span><b>{num(s.used)} {unita}</b></div>
                  <div className="cora-saldo-row"><i className="dot is-resid" /><span>Residui</span><b>{num(s.residual)} {unita}</b></div>
                  <div className="cora-saldo-row"><i className="dot is-total" /><span>Totale maturato</span><b>{num(s.total)} {unita}</b></div>
                </div>
              </div>
            </div>
          );
        })}
      </section>

      <section className="cora-home-row cora-home-actions cora-reveal" style={{ '--i': 1 } as React.CSSProperties} data-tour="quick-actions">
        <a className="cora-card cora-quick" href="/employee/leave-requests.php?new=ferie">
          <span className="cora-quick-txt"><strong>Richiedi ferie</strong><span>Crea una nuova richiesta</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
        <div className="cora-card cora-presence">
          <div className="cora-card-h">
            <h2>Presenze di oggi</h2>
            <a href="#" className="cora-see-all" data-cora-open="coraPresenceModal">Vedi tutti <S n="ic-arr-r.svg" /></a>
          </div>
          <div className="cora-presence-body">
            <div className="cora-avatars">
              {d.persone.slice(0, 7).map((p, i) => (
                <span key={p.id} className="cora-av" style={{ zIndex: 10 - i }} title={`${nome(p.nome)} ${nome(p.cognome)}`}>
                  <Volto p={p} />
                  <i className={p.assente ? 'is-absent' : 'is-present'} />
                </span>
              ))}
            </div>
            <div className="cora-presence-count">
              <span><i className="is-present" />{presenti} present{presenti === 1 ? 'e' : 'i'}</span>
              <span><i className="is-absent" />{assenti} assent{assenti === 1 ? 'e' : 'i'}</span>
            </div>
          </div>
        </div>
        <a className="cora-card cora-quick" href="/employee/leave-requests.php?new=permesso">
          <span className="cora-quick-txt"><strong>Richiedi permesso</strong><span>Ore o intera giornata</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
      </section>

      <section ref={props.liste} className="cora-home-row cora-home-lists cora-reveal" style={{ '--i': 2 } as React.CSSProperties}>
        <div className={'cora-card cora-list-card' + (d.comunicazioni.length ? '' : ' is-empty')}>
          <div className="cora-card-h">
            <h2>Avvisi</h2>
            {d.comunicazioni.length > 0 && <a href="/employee/communications.php" className="cora-see-all">Vedi tutti <S n="ic-arr-r.svg" /></a>}
          </div>
          {!d.comunicazioni.length ? (
            <div className="cora-list-empty">
              <span className="cora-list-empty-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-comunicazioni.svg') }} />
              <strong>Nessun avviso</strong>
              <span>Qui troverai i nuovi aggiornamenti aziendali</span>
            </div>
          ) : (
            <div className="cora-rows">
              {d.comunicazioni.map((c) => (
                <a key={c.id} className="cora-row" href={`/employee/communications.php?id=${c.id}`}>
                  <span className="cora-row-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-comunicazioni.svg') }} />
                  <span className="cora-row-txt">
                    <strong>{c.titolo}</strong>
                    <span>{dataBreve(c.data)}</span>
                  </span>
                  {!c.letta && <i className="cora-unread-dot" />}
                  <span className="cora-row-chev" dangerouslySetInnerHTML={{ __html: svgCora('ic-chev-r.svg') }} />
                </a>
              ))}
            </div>
          )}
        </div>

        <div className={'cora-card cora-list-card' + (d.documenti.length ? '' : ' is-empty')}>
          <div className="cora-card-h">
            <h2>Documenti recenti</h2>
            <a href="/employee/documents.php" className="cora-see-all">Vedi tutti <S n="ic-arr-r.svg" /></a>
          </div>
          {!d.documenti.length ? (
            <div className="cora-list-empty">
              <span className="cora-list-empty-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-documento.svg') }} />
              <strong>Nessun documento</strong>
              <span>Buste paga e documenti arriveranno qui</span>
            </div>
          ) : (
            <div className="cora-rows">
              {d.documenti.map((doc) => (
                <a key={doc.url} className="cora-row" href={doc.url}>
                  <span className="cora-row-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-documento.svg') }} />
                  <span className="cora-row-txt">
                    <strong>{doc.titolo}</strong>
                    <span>{dataBreve(doc.data)}</span>
                  </span>
                  <span className="cora-row-chev" dangerouslySetInnerHTML={{ __html: svgCora('ic-chev-r.svg') }} />
                </a>
              ))}
            </div>
          )}
        </div>

        <div className={'cora-card cora-list-card cora-list-narrow' + (d.richieste.length ? '' : ' is-empty')}>
          <div className="cora-card-h">
            <h2>Le tue ultime richieste</h2>
            <a href="/employee/leave-requests.php" className="cora-see-all">Vedi tutte <S n="ic-arr-r.svg" /></a>
          </div>
          {!d.richieste.length ? (
            <div className="cora-list-empty">
              <span className="cora-list-empty-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-ferie-permessi.svg') }} />
              <strong>Nessuna richiesta</strong>
              <span>Le tue richieste di ferie e permessi compariranno qui</span>
            </div>
          ) : (
            <div className="cora-rows">
              {d.richieste.map((l) => (
                <a key={l.id} className="cora-row" href="/employee/leave-requests.php">
                  <span className="cora-row-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-ferie-permessi.svg') }} />
                  <span className="cora-row-txt">
                    <strong>{TIPI_ASSENZA[l.leave_type] ?? l.leave_type.charAt(0).toUpperCase() + l.leave_type.slice(1)}</strong>
                    <span>{intervallo(l.start_date, l.end_date)} · {l.leave_type === 'malattia' ? 'comunicata' : 'creata'} {fa(l.created_at)}</span>
                  </span>
                  <span className={`cora-status is-${l.status}`}>{STATI[l.status] ?? l.status}</span>
                </a>
              ))}
            </div>
          )}
        </div>
      </section>
    </>
  );
}

/** Testo del banner dei compleanni, come in home.inc.php. */
function calcolaFesta(d: DatiHome): { me: boolean; testo: string; persona: Persona } | null {
  const mio = d.mioId;
  const [primo] = d.compleanni;
  if (!primo) return null;
  if (primo.id === mio) return { me: true, testo: `Buon compleanno, ${nome(primo.nome)}! Tanti auguri`, persona: primo };
  const altri = d.compleanni.filter((p) => p.id !== mio);
  if (!altri.length) return null;
  const nomi = altri.map((p) => `${nome(p.nome)} ${nome(p.cognome)}`);
  const ultimo = nomi.length > 1 ? ` e ${nomi.pop()}` : '';
  return { me: false, testo: `Oggi è il compleanno di ${nomi.join(', ')}${ultimo}`, persona: altri[0] };
}
