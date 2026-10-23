import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { AuditService } from '../../audit/audit.service';
import { SessioniService } from '../../auth/sessioni.service';
import { TenantService } from '../../auth/tenant.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const MESSAGGI: Record<string, string> = {
  created: 'Azienda creata', updated: 'Azienda aggiornata', toggled: 'Stato modificato', deleted: 'Azienda eliminata definitivamente',
};
const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

/**
 * Dati di un'azienda da eliminare, figli prima dei genitori. Correzione: il
 * PHP cancellava solo alcune tabelle (restavano academy, calendario,
 * assunzioni, timbrature, log...) e senza transazione, con il rischio di
 * eliminazioni a meta'.
 */
const DA_ELIMINARE = [
  'chat_messages', 'chat_presence', 'chat_conversations', 'notifications', 'push_subscriptions', 'app_settings', 'communications',
  'documents', 'employee_documents', 'medical_certificates', 'leave_requests', 'employee_leave_balances', 'attendance_punches',
  'calendar_events', 'hire_requests', 'academy_events', 'academy_certificates', 'academy_assignments', 'academy_materials',
  'academy_courses', 'academy_certificate_templates', 'door_access_log', 'ui_click_logs', 'employees', 'users', 'departments', 'ccnl_templates',
];

/**
 * Aziende (admin/companies.php). L'admin globale le vede tutte, l'admin di
 * un'azienda solo le sue (azienda dell'account e collegate).
 */
@Injectable()
@PaginaPhp('admin/companies')
export class AziendePagina implements Pagina {
  readonly ruoli = ['admin'];
  private readonly logger = new Logger('Aziende');
  constructor(private readonly ds: DataSource, private readonly tenant: TenantService, private readonly sessioni: SessioniService,
    private readonly audit: AuditService) {}

  private account(c: ContestoPagina) { return { id: c.u.id, role: c.u.ruolo, companyId: c.u.companyIdAccount ?? null }; }
  private q(sql: string, p: unknown[] = []) { return senzaIsolamento(() => this.ds.query(sql, p)); }

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const globale = await this.tenant.adminGlobale(this.account(c));
    const accessibili = await this.tenant.accessibili(this.account(c));
    const id = Number.parseInt(corpo.id ?? '', 10) || 0;
    const mia = globale || accessibili.includes(id);
    switch (corpo.action ?? '') {
      case 'create': {
        const nome = String(corpo.name ?? '').trim();
        if (nome === '') return this.dati(c, 'Nome obbligatorio.');
        if (nome.length > 120) return this.dati(c, 'Nome troppo lungo (max 120 caratteri).');
        // Correzione: identificativo unico (il PHP poteva crearne uno gia' usato, ambiguo per le timbrature NFC).
        const base = slug(nome);
        let s = base;
        for (let i = 2; s && (await this.q('SELECT 1 FROM companies WHERE slug = $1', [s])).length; i++) s = `${base}-${i}`;
        const [r] = await this.q(`INSERT INTO companies (name, slug, is_active, needs_setup) VALUES ($1, $2, true, false) RETURNING id`, [nome, s]);
        if (!globale) await this.q('INSERT INTO user_companies (user_id, company_id) VALUES ($1, $2) ON CONFLICT DO NOTHING', [c.u.id, r.id]);
        await this.audit.log('company_created', 'admin', c.u.id, c.audit, 'company', r.id, null, { name: nome });
        return redirect('/admin/companies.php?msg=created');
      }
      case 'update': {
        if (!mia) return this.dati(c, 'Non puoi modificare questa azienda.');
        const nome = String(corpo.name ?? '').trim();
        if (id && nome !== '') {
          if (nome.length > 120) return this.dati(c, 'Nome troppo lungo (max 120 caratteri).');
          await this.q('UPDATE companies SET name = $2, needs_setup = false WHERE id = $1', [id, nome]);
          return redirect('/admin/companies.php?msg=updated');
        }
        return this.dati(c);
      }
      case 'toggle': {
        if (!mia) return this.dati(c, 'Non puoi modificare questa azienda.');
        const [r] = await this.q('SELECT is_active FROM companies WHERE id = $1', [id]);
        if (r && id !== 1) {
          await this.q('UPDATE companies SET is_active = $2 WHERE id = $1', [id, !r.is_active]);
          await this.audit.log(r.is_active ? 'company_deactivated' : 'company_activated', 'admin', c.u.id, c.audit, 'company', id);
          return redirect('/admin/companies.php?msg=toggled');
        }
        return this.dati(c);
      }
      case 'switch':
        if (await this.tenant.verificaCambio(this.account(c), id)) {
          await this.sessioni.scegliAzienda(c.u.sessioneId, id);
          return redirect('/admin/');
        }
        return this.dati(c, 'Impossibile passare a questa azienda.');
      case 'delete': {
        const [r] = await this.q('SELECT id, name FROM companies WHERE id = $1', [id]);
        if (!r) return this.dati(c, 'Azienda non trovata.');
        if (!mia) return this.dati(c, 'Non puoi eliminare questa azienda.');
        if (id === 1 && globale) return this.dati(c, "Non puoi eliminare l'azienda principale.");
        if (id === c.u.companyId) return this.dati(c, "Non puoi eliminare l'azienda corrente. Cambia azienda dal switcher prima di eliminarla.");
        if (String(corpo.confirm_name ?? '').trim() !== r.name) return this.dati(c, `Conferma fallita: il nome inserito non corrisponde a "${r.name}".`);
        try {
          await senzaIsolamento(() => this.ds.transaction(async (m) => {
            for (const t of DA_ELIMINARE) await m.query(`DELETE FROM ${t} WHERE company_id = $1`, [id]);
            await m.query('DELETE FROM companies WHERE id = $1', [id]);
          }));
        } catch (e: any) {
          this.logger.error(`eliminazione azienda ${id} non riuscita: ${e?.message ?? e}`);
          return this.dati(c, "Errore eliminazione: l'azienda ha dati collegati ad altre aziende (es. documenti caricati dai suoi utenti).");
        }
        await this.audit.log('company_deleted', 'admin', c.u.id, c.audit, 'company', id, { name: r.name }, null, 'critical');
        return redirect('/admin/companies.php?msg=deleted');
      }
      default:
        return this.dati(c);
    }
  }

  async dati(c: ContestoPagina, error: string | null = null) {
    const globale = await this.tenant.adminGlobale(this.account(c));
    const accessibili = await this.tenant.accessibili(this.account(c));
    const SEL = `SELECT c.id, c.name, c.slug, c.is_active, c.needs_setup,
        (SELECT COUNT(*) FROM employees WHERE company_id = c.id AND is_active = true)::int AS emp_count,
        (SELECT COUNT(*) FROM departments WHERE company_id = c.id)::int AS dept_count FROM companies c`;
    const rows = globale ? await this.q(`${SEL} ORDER BY c.is_active DESC, c.id`)
      : accessibili.length ? await this.q(`${SEL} WHERE c.id = ANY($1::int[]) ORDER BY c.is_active DESC, c.id`, [accessibili]) : [];
    const msg = c.query.msg !== undefined ? MESSAGGI[String(c.query.msg)] ?? '' : null;
    return vista('Gestione aziende', { rows, message: msg, error, currentCid: c.u.companyId });
  }
}
