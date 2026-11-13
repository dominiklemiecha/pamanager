import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';

export const TIPI_GIORNATA = ['festivo', 'chiusura', 'mezza_giornata', 'smart_working', 'presenza'] as const;
export const TEMI = ['natale', 'semplice'] as const;

export interface Giornata { id: number; day: string; kind: string; label: string }
export interface Avviso {
  id: number; title: string; message: string; resume_note: string; signature: string;
  start_date: string; end_date: string; theme: string; giornate: Giornata[];
}
type Esito = { success: true; [k: string]: unknown } | { success: false; error: string };

const DATA = /^\d{4}-\d{2}-\d{2}$/;
const valida = (v: unknown): v is string => typeof v === 'string' && DATA.test(v)
  && new Date(`${v}T12:00:00Z`).toISOString().slice(0, 10) === v;
const testo = (v: unknown, max: number) => String(v ?? '').trim().slice(0, max);
const GIORNO = `to_char(day, 'YYYY-MM-DD')`;

/**
 * Giornate aziendali (chiusure, mezze giornate, smart working / presenza)
 * e avviso del periodo (es. feste di Natale) mostrati nel calendario.
 * Sempre filtrate sull'azienda di chi chiama.
 */
@Injectable()
export class GiornateService {
  constructor(private readonly ds: DataSource) {}

  async nellIntervallo(cid: number, da: string, a: string): Promise<Record<string, Giornata>> {
    const righe: Giornata[] = await this.ds.query(
      `SELECT id, ${GIORNO} AS day, kind, label FROM company_calendar_days
        WHERE company_id = $1 AND day BETWEEN $2 AND $3 ORDER BY day`, [cid, da, a]);
    return Object.fromEntries(righe.map((r) => [r.day, r]));
  }

  /** Avviso ancora in corso (non finito), con le giornate del suo periodo. */
  async avvisoAttivo(cid: number, oggi: string): Promise<Avviso | null> {
    const [n] = await this.ds.query(
      `SELECT id, title, message, resume_note, signature, to_char(start_date, 'YYYY-MM-DD') AS start_date,
              to_char(end_date, 'YYYY-MM-DD') AS end_date, theme
         FROM company_calendar_notices WHERE company_id = $1 AND end_date >= $2
        ORDER BY start_date, id LIMIT 1`, [cid, oggi]);
    if (!n) return null;
    n.giornate = await this.ds.query(
      `SELECT id, ${GIORNO} AS day, kind, label FROM company_calendar_days
        WHERE company_id = $1 AND day BETWEEN $2 AND $3 ORDER BY day`, [cid, n.start_date, n.end_date]);
    return n;
  }

  /** Per la gestione HR: giornate da oggi in poi (e dell'ultimo mese) e avvisi non finiti. */
  async elenco(cid: number, oggi: string) {
    const giornate = await this.ds.query(
      `SELECT id, ${GIORNO} AS day, kind, label FROM company_calendar_days
        WHERE company_id = $1 AND day >= ($2::date - 31) ORDER BY day`, [cid, oggi]);
    const avvisi = await this.ds.query(
      `SELECT id, title, message, resume_note, signature, to_char(start_date, 'YYYY-MM-DD') AS start_date,
              to_char(end_date, 'YYYY-MM-DD') AS end_date, theme
         FROM company_calendar_notices WHERE company_id = $1 AND end_date >= $2 ORDER BY start_date, id`, [cid, oggi]);
    return { giornate, avvisi };
  }

  /** Una giornata per data: se esiste gia' si aggiorna. */
  async salvaGiornata(cid: number, utente: number, b: Record<string, unknown>): Promise<Esito> {
    if (!valida(b.day)) return { success: false, error: 'Data non valida' };
    const kind = String(b.kind ?? '');
    if (!(TIPI_GIORNATA as readonly string[]).includes(kind)) return { success: false, error: 'Tipo di giornata non valido' };
    const label = testo(b.label, 80);
    const [r] = await this.ds.query(
      `INSERT INTO company_calendar_days (company_id, day, kind, label, created_by_user_id) VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (company_id, day) DO UPDATE SET kind = EXCLUDED.kind, label = EXCLUDED.label RETURNING id`,
      [cid, b.day, kind, label, utente]);
    return { success: true, id: r.id };
  }

  async eliminaGiornata(cid: number, id: number): Promise<Esito> {
    const r = await this.ds.query('DELETE FROM company_calendar_days WHERE id = $1 AND company_id = $2 RETURNING id', [id, cid]);
    return r.length ? { success: true } : { success: false, error: 'Giornata non trovata' };
  }

  /** Crea o aggiorna (con id) un avviso del periodo. */
  async salvaAvviso(cid: number, utente: number, b: Record<string, unknown>): Promise<Esito> {
    const title = testo(b.title, 150);
    if (!title) return { success: false, error: 'Titolo obbligatorio' };
    if (!valida(b.start_date) || !valida(b.end_date)) return { success: false, error: 'Date non valide' };
    if (b.end_date < b.start_date) return { success: false, error: 'La fine deve essere dopo l\'inizio' };
    const theme = (TEMI as readonly string[]).includes(String(b.theme)) ? String(b.theme) : 'natale';
    const valori = [title, testo(b.message, 4000), testo(b.resume_note, 1000), testo(b.signature, 100), b.start_date, b.end_date, theme];
    const id = Number.parseInt(String(b.id ?? ''), 10) || 0;
    if (id) {
      const r = await this.ds.query(
        `UPDATE company_calendar_notices SET title = $3, message = $4, resume_note = $5, signature = $6, start_date = $7,
                end_date = $8, theme = $9, updated_at = CURRENT_TIMESTAMP WHERE id = $1 AND company_id = $2 RETURNING id`,
        [id, cid, ...valori]);
      return r.length ? { success: true, id } : { success: false, error: 'Avviso non trovato' };
    }
    const [r] = await this.ds.query(
      `INSERT INTO company_calendar_notices (company_id, title, message, resume_note, signature, start_date, end_date, theme, created_by_user_id)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING id`, [cid, ...valori, utente]);
    return { success: true, id: r.id };
  }

  async eliminaAvviso(cid: number, id: number): Promise<Esito> {
    const r = await this.ds.query('DELETE FROM company_calendar_notices WHERE id = $1 AND company_id = $2 RETURNING id', [id, cid]);
    return r.length ? { success: true } : { success: false, error: 'Avviso non trovato' };
  }
}
