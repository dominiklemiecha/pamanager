import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import type { UtenteCorrente } from '../auth/auth.types';
import { ContattiService } from '../contatti/contatti.service';

export const TIPI = ['admin', 'employee', 'consulente_lavoro'];
type Esito = { success: boolean; error?: string; [k: string]: unknown };
interface Partecipante { user_type: string; user_id: number }

/** Data e ora come le scriveva MariaDB ("2026-10-28 10:00:00"), ora di Roma. */
const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;
const COLONNE = `e.id, e.company_id, e.owner_type, e.owner_id, e.title, e.description, e.location,
  ${ORA('e.start_at')} AS start_at, ${ORA('e.end_at')} AS end_at, e.color, e.all_day::int AS all_day,
  ${ORA('e.created_at')} AS created_at, ${ORA('e.updated_at')} AS updated_at`;

/** "AAAA-MM-GG HH:MM[:SS]" valida (anche nel calendario: niente 25:30 da un trascinamento oltre mezzanotte). */
function dataOra(v: unknown): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(v ?? '').trim());
  if (!m) return null;
  const [a, me, g, h, mi, s] = [+m[1], +m[2], +m[3], +m[4], +m[5], +(m[6] ?? 0)];
  const d = new Date(Date.UTC(a, me - 1, g));
  if (d.getUTCFullYear() !== a || d.getUTCMonth() !== me - 1 || d.getUTCDate() !== g || h > 23 || mi > 59 || s > 59) return null;
  return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${String(s).padStart(2, '0')}`;
}

/** Partecipanti dal modulo (JSON), solo tipi validi e niente doppioni. */
export function leggiPartecipanti(grezzo: unknown): Partecipante[] {
  let lista: unknown = grezzo;
  if (typeof grezzo === 'string') { try { lista = JSON.parse(grezzo); } catch { lista = []; } }
  if (!Array.isArray(lista)) return [];
  const visti = new Set<string>();
  const out: Partecipante[] = [];
  for (const p of lista) {
    const t = String(p?.user_type ?? '');
    const id = Number.parseInt(String(p?.user_id ?? ''), 10) || 0;
    if (!TIPI.includes(t) || !id || visti.has(`${t}:${id}`)) continue;
    visti.add(`${t}:${id}`);
    out.push({ user_type: t, user_id: id });
  }
  return out;
}

/**
 * Eventi del calendario condivisi (CalendarEvent.php e le azioni di
 * _calendar.inc.php). Risposte nello stesso formato JSON del PHP, usate dallo
 * script della pagina.
 *
 * Correzioni rispetto al PHP:
 *  - il dettaglio di un evento lo vede solo chi lo possiede, e' invitato o
 *    lo gestisce (prima bastava l'id, anche di un'altra azienda);
 *  - si invitano e si controllano i conflitti solo delle persone che si
 *    possono contattare (prima qualunque id, anche di altre aziende, con titoli
 *    dei loro eventi e le loro assenze nella risposta);
 *  - date non valide rifiutate con un messaggio, senza errori del database.
 */
@Injectable()
export class CalendarioService {
  constructor(private readonly ds: DataSource, private readonly contatti: ContattiService) {}

  private tipo(u: UtenteCorrente) { return u.tipo === 'employee' ? 'employee' : u.ruolo; }

  async evento(id: number): Promise<any | null> {
    const [e] = await this.ds.query(`SELECT ${COLONNE} FROM calendar_events e WHERE e.id = $1`, [id]);
    return e ?? null;
  }

  /** Il creatore, o un admin della stessa azienda. */
  puoGestire(ev: any, u: UtenteCorrente): boolean {
    const tipo = this.tipo(u);
    if (String(ev.owner_type).toLowerCase() === tipo.toLowerCase() && ev.owner_id === u.id) return true;
    if (tipo !== 'admin') return false;
    return ev.company_id === u.companyId;
  }

  /** Eventi in cui l'utente e' proprietario o invitato, nell'intervallo (getForUserInRange). */
  async nellIntervallo(u: UtenteCorrente, da: string, a: string) {
    const tipo = this.tipo(u);
    return this.ds.query(
      `SELECT * FROM (
         SELECT ${COLONNE}, 'owner' AS role_in_event, NULL::text AS my_status, e.start_at AS ordine
           FROM calendar_events e
          WHERE e.company_id = $1 AND e.owner_type = $2 AND e.owner_id = $3
            AND e.start_at < ($4::timestamp AT TIME ZONE 'Europe/Rome') AND e.end_at > ($5::timestamp AT TIME ZONE 'Europe/Rome')
         UNION
         SELECT ${COLONNE}, 'participant' AS role_in_event, ep.status::text AS my_status, e.start_at AS ordine
           FROM calendar_events e JOIN calendar_event_participants ep ON ep.event_id = e.id
          WHERE e.company_id = $1 AND ep.user_type = $2 AND ep.user_id = $3
            AND e.start_at < ($4::timestamp AT TIME ZONE 'Europe/Rome') AND e.end_at > ($5::timestamp AT TIME ZONE 'Europe/Rome')
       ) x ORDER BY ordine ASC, id ASC`, [u.companyId, tipo, u.id, a, da]);
  }

  async nome(tipo: string, id: number): Promise<string> {
    if (tipo === 'employee') {
      const [e] = await this.ds.query('SELECT first_name, last_name FROM employees WHERE id = $1', [id]);
      return e ? `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() : `Dipendente #${id}`;
    }
    const [x] = await this.ds.query('SELECT name FROM users WHERE id = $1 AND role = $2', [id, tipo]);
    return x ? x.name : `${tipo.charAt(0).toUpperCase()}${tipo.slice(1)} #${id}`;
  }

  async foto(tipo: string, id: number): Promise<string | null> {
    const [x] = await this.ds.query(`SELECT photo_path FROM ${tipo === 'employee' ? 'employees' : 'users'} WHERE id = $1`, [id]);
    return x?.photo_path ?? null;
  }

  async partecipanti(eventId: number) {
    const righe = await this.ds.query(
      `SELECT id, event_id, user_type, user_id, status, ${ORA('responded_at')} AS responded_at, ${ORA('created_at')} AS created_at
         FROM calendar_event_participants WHERE event_id = $1 ORDER BY user_type, user_id, id`, [eventId]);
    for (const r of righe) {
      r.name = await this.nome(r.user_type, r.user_id);
      r.photo_path = await this.foto(r.user_type, r.user_id);
    }
    return righe;
  }

  /** Solo le persone che l'utente puo' contattare (stessa azienda o staff collegato). */
  private async filtraConsentiti(u: UtenteCorrente, lista: Partecipante[]): Promise<Partecipante[]> {
    const c = await this.contatti.perUtente(u);
    return lista.filter((p) => c[p.user_type as keyof typeof c]?.some((x) => x.id === p.user_id));
  }

  async crea(u: UtenteCorrente, b: Record<string, unknown>): Promise<Esito> {
    const titolo = String(b.title ?? '').trim();
    if (!titolo || !b.start_at || !b.end_at) return { success: false, error: 'Titolo, inizio e fine sono obbligatori' };
    const [inizio, fine] = [dataOra(b.start_at), dataOra(b.end_at)];
    if (!inizio || !fine) return { success: false, error: 'Data o orario non validi' };
    if (inizio >= fine) return { success: false, error: "L'orario di fine deve essere successivo all'inizio" };
    if (titolo.length > 255) return { success: false, error: 'Titolo troppo lungo' };
    const tipo = this.tipo(u);
    const partecipanti = await this.filtraConsentiti(u, leggiPartecipanti(b.participants));
    try {
      const [{ id }] = await this.ds.query(
        `INSERT INTO calendar_events (company_id, owner_type, owner_id, title, description, location, start_at, end_at, color, all_day)
         VALUES ($1, $2, $3, $4, $5, $6, $7::timestamp AT TIME ZONE 'Europe/Rome', $8::timestamp AT TIME ZONE 'Europe/Rome', $9, $10) RETURNING id`,
        [u.companyId, tipo, u.id, titolo, b.description != null ? String(b.description).trim() : null,
          b.location != null ? String(b.location).trim().slice(0, 255) : null, inizio, fine,
          b.color ? String(b.color).slice(0, 20) : null, !!b.all_day && b.all_day !== '0']);
      for (const p of partecipanti) {
        if (p.user_type === tipo && p.user_id === u.id) continue;
        await this.ds.query(`INSERT INTO calendar_event_participants (event_id, user_type, user_id, status) VALUES ($1, $2, $3, 'pending')`, [id, p.user_type, p.user_id]);
      }
      return { success: true, id, conflicts: await this.conflitti(u, partecipanti, inizio, fine, id) };
    } catch {
      return { success: false, error: 'Errore creazione evento' };
    }
  }

  async aggiorna(id: number, u: UtenteCorrente, b: Record<string, unknown>, soloOrari: boolean): Promise<Esito> {
    const ev = await this.evento(id);
    if (!ev) return { success: false, error: 'Evento non trovato' };
    if (!this.puoGestire(ev, u)) return { success: false, error: "Non hai i permessi per modificare l'evento" };
    const [inizio, fine] = [dataOra(b.start_at), dataOra(b.end_at)];
    if (!inizio || !fine) return { success: false, error: 'Data o orario non validi' };
    if (inizio >= fine) return { success: false, error: "Fine deve essere successiva all'inizio" };
    if (soloOrari) {
      await this.ds.query(`UPDATE calendar_events SET start_at = $2::timestamp AT TIME ZONE 'Europe/Rome', end_at = $3::timestamp AT TIME ZONE 'Europe/Rome' WHERE id = $1`, [id, inizio, fine]);
      return { success: true };
    }
    const titolo = String(b.title ?? '').trim();
    if (titolo === '') return { success: false, error: 'Titolo, inizio e fine sono obbligatori' };
    await this.ds.query(
      `UPDATE calendar_events SET title = $2, description = $3, location = $4,
         start_at = $5::timestamp AT TIME ZONE 'Europe/Rome', end_at = $6::timestamp AT TIME ZONE 'Europe/Rome' WHERE id = $1`,
      [id, titolo.slice(0, 255), b.description != null ? String(b.description) : null, b.location != null ? String(b.location).slice(0, 255) : null, inizio, fine]);
    if (b.participants !== undefined) await this.sincronizza(ev, u, await this.filtraConsentiti(u, leggiPartecipanti(b.participants)));
    return { success: true };
  }

  /** Toglie chi non c'e' piu', aggiunge i nuovi (in attesa), lascia la risposta di chi resta. */
  private async sincronizza(ev: any, u: UtenteCorrente, lista: Partecipante[]) {
    const nuovi = new Map(lista.filter((p) => !(p.user_type === ev.owner_type && p.user_id === ev.owner_id)).map((p) => [`${p.user_type}:${p.user_id}`, p]));
    const esistenti: Array<{ id: number; user_type: string; user_id: number }> = await this.ds.query('SELECT id, user_type, user_id FROM calendar_event_participants WHERE event_id = $1', [ev.id]);
    const chiavi = new Set(esistenti.map((r) => `${r.user_type}:${r.user_id}`));
    for (const r of esistenti) {
      if (!nuovi.has(`${r.user_type}:${r.user_id}`)) await this.ds.query('DELETE FROM calendar_event_participants WHERE id = $1', [r.id]);
    }
    for (const [k, p] of nuovi) {
      if (!chiavi.has(k)) await this.ds.query(`INSERT INTO calendar_event_participants (event_id, user_type, user_id, status) VALUES ($1, $2, $3, 'pending')`, [ev.id, p.user_type, p.user_id]);
    }
  }

  async dettaglio(id: number, u: UtenteCorrente): Promise<Esito> {
    const ev = await this.evento(id);
    if (!ev || ev.company_id !== u.companyId) return { success: false, error: 'Evento non trovato' };
    const parti = await this.partecipanti(id);
    const tipo = this.tipo(u);
    const invitato = parti.some((p: any) => p.user_type === tipo && p.user_id === u.id);
    const gestisce = this.puoGestire(ev, u);
    if (!gestisce && !invitato) return { success: false, error: 'Evento non trovato' };
    return {
      success: true, event: ev, participants: parti, can_edit: gestisce,
      owner: { name: await this.nome(ev.owner_type, ev.owner_id), photo: await this.foto(ev.owner_type, ev.owner_id) },
    };
  }

  async elimina(id: number, u: UtenteCorrente): Promise<Esito> {
    const ev = await this.evento(id);
    if (!ev) return { success: false, error: 'Evento non trovato' };
    if (!this.puoGestire(ev, u)) return { success: false, error: "Non hai i permessi per eliminare l'evento" };
    await this.ds.query('DELETE FROM calendar_events WHERE id = $1', [id]);
    return { success: true };
  }

  async rispondi(id: number, u: UtenteCorrente, stato: string): Promise<Esito> {
    if (!['pending', 'accepted', 'declined'].includes(stato)) return { success: false, error: 'Stato non valido' };
    const [r] = await this.ds.query('SELECT id FROM calendar_event_participants WHERE event_id = $1 AND user_type = $2 AND user_id = $3', [id, this.tipo(u), u.id]);
    if (!r) return { success: false, error: 'Non sei invitato a questo evento' };
    await this.ds.query('UPDATE calendar_event_participants SET status = $2, responded_at = CURRENT_TIMESTAMP WHERE id = $1', [r.id, stato]);
    return { success: true };
  }

  /** Eventi sovrapposti e assenze (approvate o in attesa) delle persone invitate. */
  async conflitti(u: UtenteCorrente, lista: Partecipante[], inizio: string, fine: string, escludi: number | null = null) {
    const out: unknown[] = [];
    for (const p of await this.filtraConsentiti(u, lista)) {
      const par: unknown[] = [p.user_type, p.user_id, fine, inizio, u.companyId];
      let sql = `SELECT e.id, e.title, ${ORA('e.start_at')} AS start_at, ${ORA('e.end_at')} AS end_at
                   FROM calendar_events e
                   LEFT JOIN calendar_event_participants ep ON ep.event_id = e.id AND ep.user_type = $1 AND ep.user_id = $2
                  WHERE e.start_at < ($3::timestamp AT TIME ZONE 'Europe/Rome') AND e.end_at > ($4::timestamp AT TIME ZONE 'Europe/Rome')
                    AND e.company_id = $5 AND ((e.owner_type = $1 AND e.owner_id = $2) OR ep.id IS NOT NULL)`;
      if (escludi !== null) { par.push(escludi); sql += ' AND e.id <> $6'; }
      const eventi = await this.ds.query(sql + ' ORDER BY e.id', par);
      const assenze = p.user_type === 'employee'
        ? await this.ds.query(
          `SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date FROM leave_requests lr
            WHERE lr.employee_id = $1 AND lr.status IN ('approved', 'pending')
              AND $2::timestamp::date <= lr.end_date AND $3::timestamp::date >= lr.start_date ORDER BY lr.id`, [p.user_id, fine, inizio])
        : [];
      if (eventi.length || assenze.length) {
        out.push({ user_type: p.user_type, user_id: p.user_id, name: await this.nome(p.user_type, p.user_id), calendar_events: eventi, leaves: assenze });
      }
    }
    return out;
  }

  async verificaConflitti(u: UtenteCorrente, b: Record<string, unknown>): Promise<Esito> {
    const [inizio, fine] = [dataOra(b.start_at), dataOra(b.end_at)];
    if (!inizio || !fine) return { success: true, conflicts: [] };
    return { success: true, conflicts: await this.conflitti(u, leggiPartecipanti(b.participants), inizio, fine) };
  }
}
