import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { urlPubblico } from '../../config/app';
import { MailerService, escapeHtml } from '../notifiche/mailer.service';
import { NotificheService } from '../notifiche/notifiche.service';

export const ANTICIPO_AVVISO = 14;
const dmy = (d: string | null) => (d ? `${String(d).slice(8, 10)}/${String(d).slice(5, 7)}/${String(d).slice(0, 4)}` : '');

/**
 * Periodo di prova (Probation.php): avvisi 14 giorni prima della fine,
 * decisione dell'HR, disattivazione alla fine prova se non confermata,
 * esito al consulente. Nessun cron: i controlli girano all'apertura della
 * dashboard HR e sono idempotenti.
 *
 * Correzione: gli admin senza azienda si avvisano solo se globali veri o
 * collegati all'azienda (nel PHP tutti quelli con azienda vuota).
 */
@Injectable()
export class ProvaService {
  private readonly logger = new Logger('Prova');
  constructor(private readonly ds: DataSource, private readonly notifiche: NotificheService, private readonly mailer: MailerService) {}

  private async adminAzienda(cid: number) {
    return this.ds.query(
      `SELECT u.id, u.name, u.email FROM users u WHERE u.role = 'admin' AND u.is_active = true AND (u.company_id = $1
         OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = $1)
         OR (u.company_id IS NULL AND NOT EXISTS (SELECT 1 FROM user_companies uc2 WHERE uc2.user_id = u.id))) ORDER BY u.id`, [cid]);
  }

  /**
   * Fine prova letta dal contratto (Probation::applyContractSuggestion): si
   * imposta solo se il dipendente non ne ha gia' una, e l'HR viene avvisato.
   */
  async suggerimentoDaContratto(employeeId: number, fine: string | null, cid: number): Promise<void> {
    if (!fine) return;
    const [e] = await this.ds.query('SELECT first_name, last_name, probation_end_date, probation_decision FROM employees WHERE id = $1 AND company_id = $2', [employeeId, cid]);
    if (!e || e.probation_end_date || e.probation_decision) return;
    const [, n] = await this.ds.query('UPDATE employees SET probation_end_date = $2 WHERE id = $1 AND probation_end_date IS NULL AND probation_decision IS NULL', [employeeId, fine]);
    if (!n) return;
    const chi = `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
    for (const a of await this.adminAzienda(cid)) {
      await this.notifiche.crea(cid, {
        recipient_type: 'admin', recipient_id: a.id, type: 'probation_detected', title: 'Periodo di prova rilevato dal contratto',
        message: `Per ${chi} e' stata rilevata una fine periodo di prova il ${dmy(fine)}. Verifica la data nella scheda del dipendente.`, link: `/admin/employees.php?id=${employeeId}`,
      }).catch(() => undefined);
    }
  }

  /** Dipendenti nella finestra di avviso ancora senza decisione (widget dashboard). */
  async daDecidere(cid: number) {
    return this.ds.query(
      `SELECT id, first_name, last_name, photo_path, hire_date, probation_end_date, (probation_end_date - CURRENT_DATE)::int AS days_left
         FROM employees WHERE company_id = $1 AND is_active = true AND probation_end_date IS NOT NULL AND probation_decision IS NULL
          AND probation_end_date <= CURRENT_DATE + $2::int ORDER BY probation_end_date ASC, id ASC`, [cid, ANTICIPO_AVVISO]);
  }

  async controlli(cid: number) {
    if (cid <= 0) return;
    try {
      await this.apriAvvisi(cid);
      await this.disattivazioni(cid);
    } catch (e: any) {
      this.logger.error(`controlli: ${e?.message ?? e}`);
    }
  }

  private async apriAvvisi(cid: number) {
    const dovuti = await this.ds.query(
      `SELECT id, first_name, last_name, probation_end_date, (probation_end_date - CURRENT_DATE)::int AS days_left FROM employees
        WHERE company_id = $1 AND is_active = true AND probation_end_date IS NOT NULL AND probation_decision IS NULL
          AND probation_alert_notified_at IS NULL AND probation_end_date <= CURRENT_DATE + $2::int ORDER BY id`, [cid, ANTICIPO_AVVISO]);
    if (!dovuti.length) return;
    const admin = await this.adminAzienda(cid);
    for (const e of dovuti) {
      const [, n] = await this.ds.query('UPDATE employees SET probation_alert_notified_at = CURRENT_TIMESTAMP WHERE id = $1 AND probation_alert_notified_at IS NULL', [e.id]);
      if (n < 1) continue;
      const chi = `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
      const quando = dmy(e.probation_end_date);
      const giorni = Number(e.days_left);
      const msg = giorni >= 0
        ? `Il periodo di prova di ${chi} termina il ${quando} (tra ${giorni} ${giorni === 1 ? 'giorno' : 'giorni'}). Conferma o non confermare l'assunzione.`
        : `Il periodo di prova di ${chi} e' terminato il ${quando} (in ritardo). Conferma o non confermare l'assunzione.`;
      for (const a of admin) {
        await this.notifiche.crea(cid, { recipient_type: 'admin', recipient_id: a.id, type: 'probation_alert', title: 'Periodo di prova in scadenza', message: msg, link: '/admin/index.php' });
        await this.emailAdmin(a, chi, quando, giorni);
      }
    }
  }

  private async disattivazioni(cid: number) {
    const righe = await this.ds.query(
      `SELECT id, first_name, last_name, probation_end_date FROM employees WHERE company_id = $1 AND is_active = true
          AND probation_decision = 'not_confirmed' AND probation_deactivated_at IS NULL AND probation_end_date <= CURRENT_DATE ORDER BY id`, [cid]);
    for (const e of righe) {
      const [, n] = await this.ds.query('UPDATE employees SET is_active = false, probation_deactivated_at = CURRENT_TIMESTAMP WHERE id = $1 AND is_active = true AND probation_deactivated_at IS NULL', [e.id]);
      if (n < 1) continue;
      const chi = `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
      for (const a of await this.adminAzienda(cid)) {
        await this.notifiche.crea(cid, {
          recipient_type: 'admin', recipient_id: a.id, type: 'probation_deactivated', title: 'Dipendente disattivato a fine prova',
          message: `${chi} e' stato disattivato: periodo di prova non superato (fine prova ${dmy(e.probation_end_date)}).`, link: `/admin/employees.php?id=${e.id}`,
        });
      }
    }
  }

  /** Decisione dell'HR (Probation::decide): vince la prima, la non conferma passata disattiva subito. */
  async decidi(employeeId: number, decisione: string, adminId: number, cid: number): Promise<{ success: boolean; error?: string; deactivated_now?: boolean }> {
    if (!['confirmed', 'not_confirmed'].includes(decisione)) return { success: false, error: 'Decisione non valida' };
    const [e] = await this.ds.query('SELECT id, company_id, first_name, last_name, probation_end_date FROM employees WHERE id = $1', [employeeId]);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    if (e.company_id !== cid) return { success: false, error: "Dipendente non appartiene all'azienda corrente" };
    if (!e.probation_end_date) return { success: false, error: 'Nessun periodo di prova impostato per questo dipendente' };
    const [, n] = await this.ds.query(
      `UPDATE employees SET probation_decision = $2, probation_decided_at = CURRENT_TIMESTAMP, probation_decided_by_user_id = $3 WHERE id = $1 AND probation_decision IS NULL`,
      [employeeId, decisione, adminId]);
    if (n < 1) return { success: false, error: 'Decisione gia registrata' };
    let subito = false;
    if (decisione === 'not_confirmed' && String(e.probation_end_date) <= oggiRoma()) {
      const [, d] = await this.ds.query('UPDATE employees SET is_active = false, probation_deactivated_at = CURRENT_TIMESTAMP WHERE id = $1 AND is_active = true AND probation_deactivated_at IS NULL', [employeeId]);
      subito = d >= 1;
    }
    await this.esitoAlConsulente(e, decisione, cid);
    return { success: true, deactivated_now: subito };
  }

  private async esitoAlConsulente(e: any, decisione: string, cid: number) {
    const [c] = await this.ds.query(
      `SELECT u.id, u.name, u.email FROM users u JOIN user_companies uc ON uc.user_id = u.id
        WHERE uc.company_id = $1 AND u.role = 'consulente_lavoro' AND u.is_active = true ORDER BY u.id LIMIT 1`, [cid]);
    if (!c) return;
    const chi = `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
    const quando = dmy(e.probation_end_date);
    const ok = decisione === 'confirmed';
    await this.notifiche.crea(cid, {
      recipient_type: 'consulente_lavoro', recipient_id: c.id, type: 'probation_decision', title: ok ? 'Periodo di prova superato' : 'Periodo di prova non superato',
      message: `L'azienda ha comunicato l'esito del periodo di prova di ${chi}: ${ok ? 'superato' : 'NON superato'}.` + (ok ? '' : ` Il dipendente verra' disattivato alla data di fine prova (${quando}).`),
      link: '/consulente-lavoro/index.php',
    });
    if (!c.email) return;
    const html = `<p>Ciao ${escapeHtml(c.name)},</p><p>L'azienda ha comunicato l'esito del <strong>periodo di prova</strong> di <strong>${escapeHtml(chi)}</strong>:</p>`
      + `<p style="font-size:16px;"><strong>${ok ? 'Prova superata ✅' : 'Prova NON superata ⛔'}</strong></p>`
      + (ok ? '<p>Nessuna azione richiesta: il rapporto prosegue regolarmente.</p>'
        : `<p>Il dipendente verra' disattivato dal gestionale alla data di fine prova (${escapeHtml(quando)}). Procedi con le pratiche di cessazione.</p>`);
    const testo = `Esito periodo di prova di ${chi}: ${ok ? 'SUPERATA' : 'NON SUPERATA'}.` + (ok ? '' : ` Disattivazione prevista il ${quando}. Procedi con la cessazione.`);
    await this.mailer.invia(c.email, c.name, `Esito periodo di prova: ${chi}`, html, testo).catch(() => undefined);
  }

  private async emailAdmin(a: any, chi: string, quando: string, giorni: number) {
    if (!a.email) return;
    const link = urlPubblico('/admin/index.php');
    const finestra = giorni >= 0
      ? `termina il <strong>${escapeHtml(quando)}</strong> (tra ${giorni} ${giorni === 1 ? 'giorno' : 'giorni'})`
      : `e' terminato il <strong>${escapeHtml(quando)}</strong> (in ritardo)`;
    const html = `<p>Ciao ${escapeHtml(a.name ?? '')},</p><p>Il <strong>periodo di prova</strong> di <strong>${escapeHtml(chi)}</strong> ${finestra}.</p>`
      + `<p>Accedi al gestionale per decidere se confermare o non confermare l'assunzione.</p><p><a href="${escapeHtml(link)}">Apri la dashboard</a></p>`;
    const testo = `Periodo di prova di ${chi}: fine prova ${quando}. Accedi al gestionale per confermare o non confermare l'assunzione: ${link}`;
    await this.mailer.invia(a.email, a.name ?? '', `Periodo di prova in scadenza: ${chi}`, html, testo).catch(() => undefined);
  }
}
