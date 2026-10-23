import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma, romaParti } from '../../../common/calcoli/php';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { AcademyService } from '../../academy/academy.service';
import { FerieService } from '../../ferie/ferie.service';
import { ProvaService } from '../../prova/prova.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';
import { HeatmapService } from '../widget/heatmap.service';

const e = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const MESI_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const dM = (ymd: string) => `${String(ymd).slice(8, 10)} ${MESI_EN[Number(String(ymd).slice(5, 7)) - 1]}`;
const TIPI_ATTIVITA: Record<string, string> = {
  ferie: 'ferie', permesso: 'permesso', malattia: 'malattia', permesso_104: 'permesso L.104', congedo_parentale: 'congedo parentale',
  congedo_separazione: 'congedo separazione', congedo_mestruale: 'congedo', altro: 'assenza', chiusura: 'chiusura', smart_working: 'smart working',
};
/** "2026-10-22 11:05:00" ora di Roma, come le stringhe di MariaDB (per ordinare e per i template). */
const roma = (d: unknown) => {
  if (!d) return '';
  const x = d instanceof Date ? d : new Date(String(d));
  const p = romaParti(x.getTime() / 1000);
  return `${p.ymd} ${p.ora}`;
};

/**
 * Dashboard dell'HR (admin/index.php): saluto, azioni richieste, presenze
 * della settimana, attivita' recente, compleanni, periodi di prova e
 * approvazione rapida. All'apertura girano i controlli del periodo di prova
 * e i promemoria dell'Academy, come nel PHP.
 *
 * Correzione: "in ufficio oggi" contava tutti i dipendenti (la query delle
 * assenze usava una colonna inesistente e falliva in silenzio).
 */
@Injectable()
@PaginaPhp('admin/index')
export class DashboardAdminPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly ferie: FerieService, private readonly prova: ProvaService,
    private readonly academy: AcademyService, private readonly heatmap: HeatmapService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    if (corpo.action === 'tenant_setup') {
      const nome = String(corpo.company_name ?? '').trim();
      if (nome && (await this.wizard(c))) {
        await senzaIsolamento(() => this.ds.query('UPDATE companies SET name = $2, needs_setup = false WHERE id = $1', [cid, nome]));
      }
      return redirect('/admin/index.php');
    }
    const id = Number.parseInt(corpo.id ?? '', 10) || 0;
    if (id > 0 && corpo.action === 'quick_approve') await this.ferie.approva(id, cid, c.audit);
    if (id > 0 && corpo.action === 'quick_reject') await this.ferie.rifiuta(id, cid, String(corpo.reason ?? '').trim(), c.audit);
    return redirect('/admin/index.php');
  }

  private async wizard(c: ContestoPagina): Promise<boolean> {
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT company_id FROM users WHERE id = $1', [c.u.id]));
    if (!u || u.company_id) return false;
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT needs_setup FROM companies WHERE id = $1', [c.u.companyId]));
    return !!az?.needs_setup;
  }

  async dati(c: ContestoPagina) {
    const cid = c.u.companyId!;
    await this.prova.controlli(cid);
    await this.academy.promemoriaScadenza(cid, { ip: c.audit.ip, userAgent: c.audit.userAgent });

    const [me] = await senzaIsolamento(() => this.ds.query('SELECT id, name, username, role FROM users WHERE id = $1', [c.u.id]));
    const n = async (sql: string, p: unknown[]) => Number((await this.ds.query(sql, p))[0]?.n ?? 0);
    const pendingLeaves = await n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'pending'`, [cid]);
    const ora = Number(romaParti(Date.now() / 1000).ora.slice(0, 2));
    const saluto = ora >= 5 && ora < 12 ? 'Buongiorno' : ora >= 12 && ora < 18 ? 'Buon pomeriggio' : 'Buonasera';

    // Attivita' recente: dipendenti, documenti, comunicazioni, ferie (creazione ed esito)
    const attivita: Array<{ type: string; date: string; title: string; meta: string }> = [];
    for (const x of await this.ds.query('SELECT id, first_name, last_name, created_at FROM employees WHERE company_id = $1 ORDER BY created_at DESC, id DESC LIMIT 4', [cid])) {
      attivita.push({ type: 'user', date: roma(x.created_at), title: `Nuovo dipendente: <strong>${e(`${x.last_name} ${x.first_name}`)}</strong>`, meta: 'aggiunto al sistema' });
    }
    for (const d of await this.ds.query(
      `SELECT d.title, d.created_at, e.first_name, e.last_name, u.name AS uploader FROM documents d JOIN employees e ON d.employee_id = e.id
         JOIN users u ON d.uploaded_by = u.id WHERE d.company_id = $1 ORDER BY d.created_at DESC, d.id DESC LIMIT 6`, [cid])) {
      attivita.push({ type: 'doc', date: roma(d.created_at), title: `${e(d.title)} per <strong>${e(`${d.last_name} ${d.first_name}`)}</strong>`, meta: `caricato da ${e(d.uploader)}` });
    }
    for (const x of await this.ds.query(
      `SELECT c.title, c.created_at, u.name AS author FROM communications c JOIN users u ON c.created_by = u.id WHERE c.company_id = $1 ORDER BY c.created_at DESC, c.id DESC LIMIT 4`, [cid])) {
      attivita.push({ type: 'comm', date: roma(x.created_at), title: `Comunicazione: <strong>${e(x.title)}</strong>`, meta: `pubblicata da ${e(x.author)}` });
    }
    for (const lr of await this.ds.query(
      `SELECT lr.id, lr.leave_type, lr.status, lr.start_date, lr.end_date, lr.created_at, lr.approved_at, lr.created_by_admin, e.first_name, e.last_name, u.name AS approver_name
         FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.company_id = $1 ORDER BY GREATEST(lr.created_at, COALESCE(lr.approved_at, lr.created_at)) DESC, lr.id DESC LIMIT 8`, [cid])) {
      const nome = e(`${lr.last_name} ${lr.first_name}`);
      const tipo = TIPI_ATTIVITA[lr.leave_type] ?? lr.leave_type;
      const periodo = dM(lr.start_date) + (lr.start_date !== lr.end_date ? ` - ${dM(lr.end_date)}` : '');
      if (lr.approved_at && ['approved', 'rejected'].includes(lr.status)) {
        attivita.push({
          type: lr.status === 'approved' ? 'leave_ok' : 'leave_ko', date: roma(lr.approved_at),
          title: `${lr.status === 'approved' ? 'Approvata' : 'Rifiutata'} richiesta ${tipo} di <strong>${nome}</strong>`, meta: `${periodo} · da ${e(lr.approver_name ?? 'admin')}`,
        });
      }
      if (!lr.created_by_admin) attivita.push({ type: 'leave_new', date: roma(lr.created_at), title: `Nuova richiesta ${tipo} da <strong>${nome}</strong>`, meta: periodo });
    }
    // usort con strcmp (stabile) e i primi 8, come il PHP
    attivita.sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0));

    // Compleanni di oggi e domani (widget-birthday-banner.php, l'HR non e' un festeggiato)
    const oggi = oggiRoma();
    const domani = new Date(`${oggi}T12:00:00Z`);
    domani.setUTCDate(domani.getUTCDate() + 1);
    const [mdOggi, mdDomani] = [oggi.slice(5), domani.toISOString().slice(5, 10)];
    const compleanni = await this.ds.query(
      `SELECT id, first_name, to_char(birth_date, 'MM-DD') AS md FROM employees WHERE is_active = true AND birth_date IS NOT NULL AND company_id = $1
          AND to_char(birth_date, 'MM-DD') IN ($2, $3) ORDER BY id`, [cid, mdOggi, mdDomani]);
    const todayOthers = compleanni.filter((r: any) => r.md === mdOggi).map((r: any) => r.first_name);
    const tomorrowOthers = compleanni.filter((r: any) => r.md === mdDomani).map((r: any) => r.first_name);

    const probation = await this.prova.daDecidere(cid);
    const [azienda] = await senzaIsolamento(() => this.ds.query('SELECT id, name FROM companies WHERE id = $1', [cid]));

    return vista('Dashboard', {
      user: { id: me?.id, name: me?.name ?? '', username: me?.username, role: me?.role },
      baseUrl: '', __greeting: saluto, pendingLeaves, totalActions: pendingLeaves, activities: attivita.slice(0, 8),
      _GET: c.query,
      // widget-tenant-wizard.php
      __wTenantWizard: await this.wizard(c), company: azienda ?? null,
      // widget-birthday-banner.php
      __wCompleanni: todayOthers.length > 0 || tomorrowOthers.length > 0, todayViewerName: null, todayOthers, tomorrowOthers,
      // widget-probation-alerts.php
      __probationPending: probation,
      // widget-availability-heatmap.php
      ...(await this.heatmap.dati(cid, {
        departmentId: null, baseUrl: '/admin/index.php', scopeToggle: false, mioReparto: null, scopePredefinito: 'mine', ruolo: 'admin', employeeId: c.u.id,
      }, c.query)),
    });
  }
}
