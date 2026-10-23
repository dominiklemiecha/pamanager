import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { UtenteCorrente } from '../auth/auth.types';
import { TenantService } from '../auth/tenant.service';
import { WidgetService } from '../widget/widget.service';

/** Giorni di anticipo dell'avviso di fine prova (Probation::ALERT_LEAD_DAYS). */
export const ANTICIPO_PROVA = 14;

/**
 * Dati della cornice dello staff (header-admin.php, footer-admin.php):
 * contatori del menu, aziende del selettore e attivita' nelle altre aziende.
 * Un utente dello staff ha sempre un ruolo e un'azienda corrente.
 */
@Injectable()
export class StaffService {
  constructor(private readonly ds: DataSource, private readonly tenant: TenantService, private readonly widget: WidgetService) {}

  private async n(sql: string, p: unknown[]): Promise<number> {
    const [r] = await this.ds.query(sql, p);
    return Number(r?.n ?? 0);
  }

  /** Messaggi non letti dello staff (Chat::countUnread). */
  async chatNonLette(companyId: number, tipo: string, id: number): Promise<number> {
    return this.n(
      `SELECT COUNT(*)::int AS n FROM chat_messages cm JOIN chat_conversations cc ON cm.conversation_id = cc.id
         LEFT JOIN chat_reads cr ON cr.conversation_id = cc.id AND cr.user_type = $2 AND cr.user_id = $3
        WHERE cc.company_id = $1
          AND ((cc.conv_type <> 'group' AND ((cc.participant1_type = $2 AND cc.participant1_id = $3) OR (cc.participant2_type = $2 AND cc.participant2_id = $3)))
               OR EXISTS (SELECT 1 FROM chat_extra_participants ep WHERE ep.conversation_id = cc.id AND ep.participant_type = $2 AND ep.participant_id = $3))
          AND NOT (cm.sender_type = $2 AND cm.sender_id = $3)
          AND ((cr.last_read_at IS NULL AND cm.is_read = false) OR (cr.last_read_at IS NOT NULL AND cm.created_at > cr.last_read_at))
          AND NOT EXISTS (SELECT 1 FROM chat_conversation_prefs hp WHERE hp.conversation_id = cc.id AND hp.user_type = $2 AND hp.user_id = $3
                            AND hp.hidden_at IS NOT NULL AND hp.hidden_at >= cm.created_at)
          AND NOT EXISTS (SELECT 1 FROM employees ei WHERE ei.is_active = false
                            AND ((cc.participant1_type = 'employee' AND cc.participant1_id = ei.id) OR (cc.participant2_type = 'employee' AND cc.participant2_id = ei.id)))`,
      [companyId, tipo, id]);
  }

  async invitiInAttesa(tipo: string, id: number): Promise<number> {
    return this.n(
      `SELECT COUNT(*)::int AS n FROM calendar_event_participants ep JOIN calendar_events e ON e.id = ep.event_id
        WHERE ep.user_type = $1 AND ep.user_id = $2 AND ep.status = 'pending' AND e.end_at >= CURRENT_TIMESTAMP`, [tipo, id]);
  }

  async attestatiDaGenerare(companyId: number, corso: number | null = null): Promise<number> {
    return this.n(
      `SELECT COUNT(*)::int AS n FROM academy_assignments a LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
        WHERE a.company_id = $1 AND a.completed_at IS NOT NULL AND ce.id IS NULL${corso !== null ? ' AND a.course_id = $2' : ''}`,
      corso !== null ? [companyId, corso] : [companyId]);
  }

  async proveDaDecidere(companyId: number): Promise<number> {
    return this.n(
      `SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true AND probation_end_date IS NOT NULL
         AND probation_decision IS NULL AND probation_end_date <= CURRENT_DATE + $2::int`, [companyId, ANTICIPO_PROVA]);
  }

  /** Attivita' da vedere per ogni azienda accessibile (Tenant::activityByCompany). */
  async attivitaPerAzienda(u: UtenteCorrente, accessibili: number[]): Promise<Record<number, number>> {
    const out: Record<number, number> = Object.fromEntries(accessibili.map((c) => [c, 0]));
    if (!accessibili.length) return out;
    // Conteggi su piu' aziende: solo quelle a cui l'utente ha accesso.
    return senzaIsolamento(async () => {
      const somma = (righe: any[]) => { for (const r of righe) out[r.company_id] = (out[r.company_id] ?? 0) + Number(r.n); };
      somma(await this.ds.query(`SELECT company_id, COUNT(*)::int AS n FROM leave_requests WHERE company_id = ANY($1::int[]) AND status = 'pending' GROUP BY company_id`, [accessibili]));
      if (u.ruolo === 'admin') {
        somma(await this.ds.query(`SELECT company_id, COUNT(*)::int AS n FROM hire_requests WHERE company_id = ANY($1::int[]) AND status = 'prospects_review' GROUP BY company_id`, [accessibili]));
      } else if (u.ruolo === 'consulente_lavoro') {
        somma(await this.ds.query(
          `SELECT company_id, COUNT(*)::int AS n FROM hire_requests WHERE company_id = ANY($1::int[]) AND status IN ('awaiting_prospects', 'approved')
             AND (assigned_consulente_user_id = $2 OR assigned_consulente_user_id IS NULL) GROUP BY company_id`, [accessibili, u.id]));
      }
      somma(await this.ds.query(
        `SELECT cc.company_id, COUNT(*)::int AS n FROM chat_messages cm JOIN chat_conversations cc ON cm.conversation_id = cc.id
          WHERE cc.company_id = ANY($1::int[]) AND ((cc.participant1_type = $2 AND cc.participant1_id = $3) OR (cc.participant2_type = $2 AND cc.participant2_id = $3))
            AND NOT (cm.sender_type = $2 AND cm.sender_id = $3) AND cm.is_read = false GROUP BY cc.company_id`, [accessibili, u.ruolo, u.id]));
      if (['admin', 'consulente_lavoro'].includes(u.ruolo)) {
        somma(await this.ds.query(
          `SELECT a.company_id, COUNT(*)::int AS n FROM academy_assignments a LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
            WHERE a.company_id = ANY($1::int[]) AND a.completed_at IS NOT NULL AND ce.id IS NULL GROUP BY a.company_id`, [accessibili]));
      }
      return out;
    });
  }

  async cornice(u: UtenteCorrente) {
    const cid = u.companyId!;
    const ruolo = u.ruolo;
    const admin = ruolo === 'admin';
    const consulente = ruolo === 'consulente_lavoro';
    const formatore = ruolo === 'formatore';
    const tipoChat = admin ? 'admin' : consulente ? 'consulente_lavoro' : 'accountant';
    const [me] = await senzaIsolamento(() => this.ds.query('SELECT name, photo_path, company_id FROM users WHERE id = $1', [u.id]));

    const sb = { emp: '', comm: '', pres: '', dept: '' };
    if (admin) {
      const tot = await this.n('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
      const nuovi = await this.n(`SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true AND created_at >= date_trunc('month', CURRENT_DATE)`, [cid]);
      sb.emp = `${tot} attivi` + (nuovi > 0 ? ` · +${nuovi} questo mese` : '');
      sb.comm = `${await this.n(`SELECT COUNT(*)::int AS n FROM communications WHERE company_id = $1 AND is_published = true AND publish_date <= CURRENT_DATE AND (expire_date IS NULL OR expire_date >= CURRENT_DATE)`, [cid])} attive`;
      const oggi = oggiRoma();
      const assenti = await this.n(`SELECT COUNT(DISTINCT employee_id)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'approved' AND start_date <= $2 AND end_date >= $2`, [cid, oggi]);
      sb.pres = `${Math.max(0, tot - assenti)} di ${tot} in ufficio`;
      sb.dept = `${await this.n('SELECT COUNT(*)::int AS n FROM departments WHERE company_id = $1 AND is_active = true', [cid])} reparti`;
    }

    let assunzioni = 0;
    if (admin) assunzioni = await this.n(`SELECT COUNT(*)::int AS n FROM hire_requests WHERE company_id = $1 AND status = 'prospects_review'`, [cid]);
    else if (consulente) {
      assunzioni = await this.n(`SELECT COUNT(*)::int AS n FROM hire_requests WHERE company_id = $1 AND status IN ('awaiting_prospects', 'approved')
        AND (assigned_consulente_user_id = $2 OR assigned_consulente_user_id IS NULL)`, [cid, u.id]);
    }

    // Selettore azienda: l'admin vede sempre la lista, gli altri solo se ne hanno piu' di una.
    let aziende: Array<{ id: number; name: string; is_active: boolean; attivita: number }> = [];
    let altrove = 0;
    let puoUscire = false;
    if (['admin', 'accountant', 'consulente_lavoro'].includes(ruolo)) {
      const acc = { id: u.id, role: ruolo, companyId: u.companyIdAccount };
      const ids = await this.tenant.accessibili(acc);
      const righe = await senzaIsolamento(() => this.ds.query('SELECT id, name, is_active FROM companies WHERE id = ANY($1::int[]) AND is_active = true ORDER BY name, id', [ids]));
      const attivita = await this.attivitaPerAzienda(u, ids);
      aziende = righe.map((c: any) => ({ id: c.id, name: c.name, is_active: !!c.is_active, attivita: attivita[c.id] ?? 0 }));
      for (const [c, k] of Object.entries(attivita)) if (Number(c) !== cid) altrove += k;
      puoUscire = ['accountant', 'consulente_lavoro'].includes(ruolo) && aziende.length > 1;
    }
    const [corrente] = await senzaIsolamento(() => this.ds.query('SELECT id, name, is_active FROM companies WHERE id = $1', [cid]));
    const iniziali = (s: string) => {
      let out = '';
      for (const p of String(s ?? '').trim().split(/\s+/)) { if (p) out += [...p][0]; if ([...out].length >= 2) break; }
      return out.toUpperCase();
    };

    return {
      ruolo, area: admin ? 'admin' : consulente ? 'consulente-lavoro' : formatore ? 'formatore' : 'accountant', nome: me?.name ?? '', iniziali: iniziali(me?.name ?? '') || 'U', foto: me?.photo_path ? '/' + String(me.photo_path).replace(/^\/+/, '') : '',
      contatori: {
        ferie: admin ? await this.n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE status = 'pending' AND company_id = $1`, [cid]) : 0,
        chat: formatore ? 0 : await this.chatNonLette(cid, tipoChat, u.id),
        inviti: formatore ? 0 : await this.invitiInAttesa(tipoChat, u.id),
        assunzioni,
        attestati: admin || consulente || formatore ? await this.attestatiDaGenerare(cid) : 0,
        prove: admin ? await this.proveDaDecidere(cid) : 0,
      },
      sottotitoli: sb,
      aziende, altrove, puoUscire,
      azienda: corrente ? { id: corrente.id, name: corrente.name, is_active: !!corrente.is_active } : null,
      aziendaIniziali: iniziali(corrente?.name ?? ''),
      widget: this.widget.tag(),
    };
  }
}
