import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { urlPubblico } from '../../../config/app';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { floatPhp, schedeConfigurazione, slugPhp, veroGlobale } from '../comune';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const GIORNI = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const ORA = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
const lista = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v === undefined || v === null || v === '' ? [] : [String(v)]);
const azienda = (ds: DataSource, sql: string, p: unknown[]) => senzaIsolamento(() => ds.query(sql, p));

/** Slug libero tra le aziende (Database::exists('companies', 'slug = ? AND id != ?')). */
async function slugOccupato(ds: DataSource, slug: string, cid: number) {
  return (await azienda(ds, 'SELECT 1 FROM companies WHERE slug = $1 AND id <> $2 LIMIT 1', [slug, cid])).length > 0;
}

/**
 * Orario lavorativo dell'azienda (admin/work-schedule.php): giorni e ore,
 * CCNL di default, smart working ricorrente, pausa pranzo.
 *
 * Correzioni: il CCNL deve essere uno di quelli disponibili all'azienda
 * (il PHP accettava l'id di un CCNL di un'altra azienda) e gli orari della
 * pausa devono essere orari validi.
 */
@Injectable()
@PaginaPhp('admin/work-schedule')
export class OrarioLavorativoPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  async azione(c: ContestoPagina, corpo: Record<string, any>) {
    const cid = c.u.companyId!;
    const giorni = GIORNI.filter((g) => lista(corpo['working_days[]'] ?? corpo.working_days).includes(g));
    if (!giorni.length) return this.dati(c, '', 'Seleziona almeno un giorno lavorativo.');
    const ore = floatPhp(String(corpo.hours_per_day ?? '8').trim().replace(/,/g, '.'));
    if (ore <= 0 || ore > 24) return this.dati(c, '', 'Ore/giorno deve essere tra 0 e 24.');
    const ccnl = corpo.default_ccnl_id ? Number.parseInt(String(corpo.default_ccnl_id), 10) || 0 : null;
    const sw = GIORNI.filter((g) => lista(corpo['smart_working_days[]'] ?? corpo.smart_working_days).includes(g));
    const da = String(corpo.lunch_break_start ?? '').trim();
    const a = String(corpo.lunch_break_end ?? '').trim();
    let errore = '';
    if ((da !== '') !== (a !== '')) errore = 'Pausa pranzo: indica sia inizio che fine (o lascia entrambi vuoti).';
    else if (da !== '' && (!ORA.test(da) || !ORA.test(a))) errore = 'Pausa pranzo: orario non valido.';
    else if (da !== '' && a <= da) errore = "Pausa pranzo: la fine deve essere dopo l'inizio.";
    if (!errore && ccnl !== null && !(await this.ccnl(cid)).some((x: any) => x.id === ccnl)) errore = 'CCNL non valido.';
    const campi: Record<string, unknown> = {
      working_days: giorni.join(','), hours_per_day: ore, default_ccnl_id: ccnl,
      smart_working_days: sw.length ? sw.join(',') : null, lunch_break_start: da || null, lunch_break_end: a || null,
    };
    const slug = slugPhp(String(corpo.nfc_slug ?? '').trim());
    if (slug !== '') {
      if (await slugOccupato(this.ds, slug, cid)) errore = `Slug '${slug}' già usato da un'altra azienda. Scegli un altro nome.`;
      else campi.slug = slug;
    }
    if (errore) return this.dati(c, '', errore);
    const k = Object.keys(campi);
    await azienda(this.ds, `UPDATE companies SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [cid, ...k.map((x) => campi[x])]);
    return this.dati(c, 'Impostazioni salvate.');
  }

  private ccnl(cid: number) {
    return this.ds.query(
      `SELECT id, code, name, ferie_days_year, permessi_hours_year FROM ccnl_templates WHERE company_id IS NULL OR company_id = $1
        ORDER BY is_system DESC, name ASC`, [cid]);
  }

  async dati(c: ContestoPagina, message = '', error = '') {
    const cid = c.u.companyId!;
    const [r] = await azienda(this.ds, `SELECT working_days, hours_per_day, default_ccnl_id, slug, name, smart_working_days,
        lunch_break_start, lunch_break_end FROM companies WHERE id = $1`, [cid]);
    const giorni = String(r?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean);
    // Slug generato se manca (aziende create prima degli indirizzi per azienda)
    if (r && !r.slug && r.name) {
      const base = slugPhp(String(r.name));
      let candidato = base || `az${cid}`;
      let i = 1;
      while (await slugOccupato(this.ds, candidato, cid)) candidato = `${base}-${++i}`;
      await azienda(this.ds, 'UPDATE companies SET slug = $2 WHERE id = $1', [cid, candidato]);
      r.slug = candidato;
    }
    return vista('Configurazione · Orario lavorativo', {
      message, error,
      defaults: { days: giorni, hours: r?.hours_per_day != null ? Number(r.hours_per_day) : 8.0 },
      ccnls: await this.ccnl(cid),
      compRow: r ?? false,
      companySwDays: r?.smart_working_days ? String(r.smart_working_days).split(',').map((x) => x.trim()).filter(Boolean) : [],
      currentDefaultCcnl: r?.default_ccnl_id ?? null,
      ...(await schedeConfigurazione(this.ds, c, 'work-schedule')),
    });
  }
}

/**
 * Timbrature NFC (admin/punch-settings.php): attivazione e identificativo
 * dell'azienda nell'indirizzo da scrivere sulla carta.
 *
 * Correzione: l'indirizzo si costruisce da APP_URL, non dall'header Host
 * della richiesta (che il client puo' falsificare).
 */
@Injectable()
@PaginaPhp('admin/punch-settings')
export class TimbratureImpostazioniPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  async azione(c: ContestoPagina, corpo: Record<string, any>) {
    const cid = c.u.companyId!;
    const campi: Record<string, unknown> = { timbratura_enabled: !!corpo.timbratura_enabled && corpo.timbratura_enabled !== '0' };
    const slug = slugPhp(String(corpo.nfc_slug ?? '').trim());
    if (slug !== '') {
      if (await slugOccupato(this.ds, slug, cid)) return this.dati(c, '', `Identificativo '${slug}' già usato da un'altra azienda.`);
      campi.slug = slug;
    }
    const k = Object.keys(campi);
    await azienda(this.ds, `UPDATE companies SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [cid, ...k.map((x) => campi[x])]);
    return this.dati(c, 'Impostazioni salvate.');
  }

  async dati(c: ContestoPagina, message = '', error = '') {
    const cid = c.u.companyId!;
    const [r] = await azienda(this.ds, 'SELECT slug, name, timbratura_enabled FROM companies WHERE id = $1', [cid]);
    let slug = r?.slug ?? '';
    if (!slug && r?.name) slug = slugPhp(String(r.name)) || `az${cid}`;
    const base = urlPubblico('/punch.php');
    return vista('Configurazione · Timbrature NFC', {
      message, error, companySlug: slug, isEnabled: r ? (r.timbratura_enabled ? 1 : 0) : 1,
      __pubPath: urlPubblico(''), punchUrl: `${base}?c=${new URLSearchParams({ x: slug }).toString().slice(2)}`,
      _GET: c.query,
      ...(await schedeConfigurazione(this.ds, c, 'punch-settings')),
    });
  }
}

/** Buoni pasto (admin/buoni-pasto.php): attivazione, soglia ore, smart working. */
@Injectable()
@PaginaPhp('admin/buoni-pasto')
export class BuoniPastoPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  async azione(c: ContestoPagina, corpo: Record<string, any>) {
    const ore = floatPhp(String(corpo.buoni_pasto_min_hours ?? '6').trim().replace(/,/g, '.'));
    if (ore <= 0 || ore > 24) return this.dati(c, '', 'La soglia ore deve essere tra 0 e 24.');
    await azienda(this.ds, `UPDATE companies SET buoni_pasto_enabled = $2, buoni_pasto_min_hours_enabled = $3, buoni_pasto_min_hours = $4,
        buoni_pasto_sw_eligible = $5 WHERE id = $1`,
      [c.u.companyId, corpo.buoni_pasto_enabled !== undefined, corpo.buoni_pasto_min_hours_enabled !== undefined, ore, corpo.buoni_pasto_sw_eligible !== undefined]);
    return this.dati(c, 'Impostazioni salvate.');
  }

  async dati(c: ContestoPagina, message = '', error = '') {
    const [cfg] = await azienda(this.ds, `SELECT buoni_pasto_enabled, buoni_pasto_min_hours_enabled, buoni_pasto_min_hours, buoni_pasto_sw_eligible,
        smart_working_days FROM companies WHERE id = $1`, [c.u.companyId]);
    const ETICHETTE: Record<string, string> = { mon: 'Lun', tue: 'Mar', wed: 'Mer', thu: 'Gio', fri: 'Ven', sat: 'Sab', sun: 'Dom' };
    const sw = cfg?.smart_working_days ? String(cfg.smart_working_days).split(',').map((x) => x.trim()).filter(Boolean) : [];
    return vista('Configurazione · Buoni pasto', {
      message, error,
      bpEnabled: !!cfg?.buoni_pasto_enabled, bpMinOn: !!cfg?.buoni_pasto_min_hours_enabled,
      bpMinHours: cfg?.buoni_pasto_min_hours != null ? Number(cfg.buoni_pasto_min_hours) : 6.0, bpSw: !!cfg?.buoni_pasto_sw_eligible,
      swDaysLabel: sw.length ? sw.map((g) => ETICHETTE[g] ?? g).join(', ') : 'nessuno',
      ...(await schedeConfigurazione(this.ds, c, 'buoni-pasto')),
    });
  }
}

/** Apertura porta (admin/door-settings.php): solo l'admin globale. */
@Injectable()
@PaginaPhp('admin/door-settings')
export class PortaImpostazioniPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  async dati(c: ContestoPagina) {
    if (!(await veroGlobale(this.ds, c))) return redirect('/admin/');
    return vista('Configurazione · Apertura porta', { ...(await schedeConfigurazione(this.ds, c, 'door-settings')) });
  }
}

const MOTIVI: Record<string, string> = {
  ok: 'Accesso autorizzato', unknown_uid: 'UID sconosciuto', employee_inactive: 'Dipendente disattivato',
  invalid_key: 'API key non valida', module_disabled: 'Modulo disabilitato', invalid_uid: 'UID non valido',
};

/**
 * Log dell'apertura porta (admin/door-log.php): solo l'admin globale.
 * Correzione: una data non valida nel filtro non fa fallire la pagina.
 */
@Injectable()
@PaginaPhp('admin/door-log')
export class PortaLogPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  async dati(c: ContestoPagina) {
    if (!(await veroGlobale(this.ds, c))) return redirect('/admin/');
    const esito = c.query.esito !== undefined ? String(c.query.esito) : 'all';
    const data = c.query.date !== undefined ? String(c.query.date) : '';
    const p: unknown[] = [c.u.companyId];
    let sql = `SELECT l.*, concat(e.first_name, ' ', e.last_name) AS employee_name FROM door_access_log l
                 LEFT JOIN employees e ON e.id = l.employee_id WHERE l.company_id = $1`;
    if (esito === 'granted') sql += ' AND l.granted = true';
    if (esito === 'denied') sql += ' AND l.granted = false';
    let rows: unknown[] = [];
    if (data === '' || /^\d{4}-\d{2}-\d{2}$/.test(data)) {
      if (data !== '') { p.push(data); sql += ` AND (l.created_at AT TIME ZONE 'Europe/Rome')::date = $2`; }
      rows = await this.ds.query(`${sql} ORDER BY l.created_at DESC LIMIT 200`, p);
    }
    return vista('Log apertura porta', {
      filterEsito: esito, filterDate: data, rows, reasonLabels: MOTIVI,
      ...(await schedeConfigurazione(this.ds, c, 'door-log')),
    });
  }
}

/** admin/smtp-settings.php: la configurazione email e' nel profilo. */
@Injectable()
@PaginaPhp('admin/smtp-settings')
export class SmtpPagina implements Pagina {
  readonly ruoli = ['admin'];
  async dati() { return redirect('/admin/profile.php'); }
}
