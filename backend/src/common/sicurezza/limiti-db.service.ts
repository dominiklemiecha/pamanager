import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../database/isolamento';

/**
 * Limiti di frequenza salvati nel database (tabella rate_limits), come
 * checkRateLimitDb() del PHP: valgono anche con piu' istanze del backend e
 * sopravvivono ai riavvii. Usati per download (50 l'ora per utente) e simili.
 */
@Injectable()
export class LimitiDbService {
  constructor(private readonly ds: DataSource) {}

  /** true se l'azione e' ancora permessa (e la conta). */
  async consenti(identificativo: string, azione: string, massimo: number, finestraSecondi: number): Promise<boolean> {
    return senzaIsolamento(async () => {
      await this.ds.query(`DELETE FROM rate_limits WHERE window_start < CURRENT_TIMESTAMP - make_interval(secs => $1)`, [finestraSecondi]);
      const [r] = await this.ds.query('SELECT * FROM rate_limits WHERE identifier = $1 AND action = $2', [identificativo, azione]);
      if (!r) {
        await this.ds.query('INSERT INTO rate_limits (identifier, action, attempts, window_start) VALUES ($1, $2, 1, CURRENT_TIMESTAMP)', [identificativo, azione]);
        return true;
      }
      if (Date.now() - new Date(r.window_start).getTime() > finestraSecondi * 1000) {
        await this.ds.query('UPDATE rate_limits SET attempts = 1, window_start = CURRENT_TIMESTAMP WHERE id = $1', [r.id]);
        return true;
      }
      const [[agg]] = await this.ds.query('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = $1 RETURNING attempts', [r.id]);
      return agg.attempts <= massimo;
    });
  }

  /** true se l'azione ha gia' superato il massimo nella finestra (senza contarla). */
  async superato(identificativo: string, azione: string, massimo: number, finestraSecondi: number): Promise<boolean> {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT attempts FROM rate_limits WHERE identifier = $1 AND action = $2 AND window_start >= CURRENT_TIMESTAMP - make_interval(secs => $3)`, [identificativo, azione, finestraSecondi]));
    return !!r && r.attempts >= massimo;
  }
}
