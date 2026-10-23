import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { hashPassword, passwordTemporanea } from '../../../common/sicurezza/password';
import { urlPubblico } from '../../../config/app';
import { AuditService } from '../../audit/audit.service';
import { SessioniService } from '../../auth/sessioni.service';
import { TenantService } from '../../auth/tenant.service';
import { escapeHtml, MailerService } from '../../notifiche/mailer.service';
import { UtentiService } from '../../utenti/utenti.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const h = escapeHtml;
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;
const lista = (v: unknown): number[] => (Array.isArray(v) ? v : v === undefined || v === null || v === '' ? [] : [v]).map((x) => Number.parseInt(String(x), 10)).filter((x) => x > 0);

interface Ruolo {
  ROLE: string;
  LABEL: string;
  LABEL_PLURAL: string;
  SELF: string;
  ICON_PATH: string;
  MULTI_COMPANY: boolean;
  BACK_URL?: string;
  BACK_LABEL?: string;
}

/**
 * Gestione degli utenti esterni per ruolo (admin/_user-role-manager.inc.php):
 * commercialisti, consulenti del lavoro, responsabili formazione.
 *
 * Correzioni: ogni azione vale solo per utenti del ruolo della pagina e
 * visibili all'azienda di lavoro (il PHP modificava, eliminava, reimpostava
 * la password o disattivava qualsiasi utente, anche l'admin globale o gli HR
 * di altre aziende); le aziende collegate sono solo quelle gestite dall'HR
 * (i collegamenti ad altre aziende restano); un utente condiviso da un'altra
 * azienda non si puo' disattivare; la password reimpostata va cambiata al
 * primo accesso e chiude le sessioni aperte.
 */
@Injectable()
abstract class UtentiEsterniPagina implements Pagina {
  readonly ruoli = ['admin'];
  protected abstract readonly cfg: Ruolo;
  constructor(protected readonly ds: DataSource, protected readonly tenant: TenantService, protected readonly utenti: UtentiService,
    protected readonly mailer: MailerService, protected readonly sessioni: SessioniService, protected readonly audit: AuditService) {}

  private q(sql: string, p: unknown[] = []) { return senzaIsolamento(() => this.ds.query(sql, p)); }
  private accessibili(c: ContestoPagina) { return this.tenant.accessibili({ id: c.u.id, role: c.u.ruolo, companyId: c.u.companyIdAccount ?? null }); }

  /** User::getAll(ruolo): utenti del ruolo nell'azienda di lavoro (e, per i consulenti, collegati alle aziende gestite). */
  private async elenco(c: ContestoPagina, accessibili: number[]) {
    const cid = c.u.companyId!;
    const acc = accessibili.length ? accessibili : [cid];
    const condivisi = ['accountant', 'consulente_lavoro'].includes(this.cfg.ROLE);
    return this.q(
      `SELECT DISTINCT u.id, u.username, u.role, u.name, u.email, u.is_active, u.created_at, u.last_login, u.company_id, u.department_id
         FROM users u WHERE (u.company_id = $1 ${condivisi ? 'OR u.id IN (SELECT user_id FROM user_companies WHERE company_id = ANY($3::int[]))' : ''})
          AND u.role = $2 ORDER BY u.name ASC`, condivisi ? [cid, this.cfg.ROLE, acc] : [cid, this.cfg.ROLE]);
  }

  /** Utente del ruolo visibile all'azienda di lavoro, altrimenti null. */
  private async bersaglio(c: ContestoPagina, id: number, accessibili: number[]) {
    return (await this.elenco(c, accessibili)).find((u: any) => u.id === id) ?? null;
  }

  private ospite(u: { company_id: number | null }, accessibili: number[]) { return !!u.company_id && !accessibili.includes(u.company_id); }

  private async collegamenti(id: number): Promise<number[]> {
    return (await this.q('SELECT company_id FROM user_companies WHERE user_id = $1 ORDER BY company_id', [id])).map((r: any) => r.company_id);
  }

  /** Collegamenti: quelli scelti tra le aziende gestite + quelli ad aziende non gestite (che restano). */
  private async collega(id: number, scelte: number[], accessibili: number[]) {
    const esterni = (await this.collegamenti(id)).filter((x) => !accessibili.includes(x));
    const tutti = [...new Set([...esterni, ...scelte.filter((x) => accessibili.includes(x))])];
    await this.q('DELETE FROM user_companies WHERE user_id = $1', [id]);
    for (const cid of tutti) await this.q('INSERT INTO user_companies (user_id, company_id) VALUES ($1, $2) ON CONFLICT DO NOTHING', [id, cid]);
  }

  /** urm_send_credentials_email. */
  private async credenziali(email: string, nome: string, username: string, password: string): Promise<{ sent: boolean; error: string | null }> {
    if (!email || !this.mailer.configurato()) return { sent: false, error: 'SMTP non configurato o email mancante' };
    const login = urlPubblico('/auth/login.php');
    const html = `<p>Ciao ${h(nome)},</p>`
      + `<p>E' stato creato un account come <strong>${h(this.cfg.LABEL)}</strong> nel portale Connecteed HR. Queste sono le tue credenziali di accesso:</p>`
      + `<ul><li><strong>Username:</strong> ${h(username)}</li><li><strong>Password temporanea:</strong> <code>${h(password)}</code></li></ul>`
      + `<p><a href="${login}" style="display:inline-block;padding:10px 20px;background:#3182ce;color:white;border-radius:6px;text-decoration:none;font-weight:600;">Accedi al portale</a></p>`
      + '<p style="font-size:13px;color:#718096;">Per motivi di sicurezza, ti consigliamo di cambiare la password al primo accesso. Non condividere queste credenziali con altre persone.</p>';
    const testo = `Ciao ${nome},\n\nE' stato creato un account come ${this.cfg.LABEL} nel portale Connecteed HR.\n\nUsername: ${username}\nPassword temporanea: ${password}\n\nLogin: ${login}\n\nTi consigliamo di cambiare la password al primo accesso.`;
    const ok = await this.mailer.invia(email, nome, 'Le tue credenziali di accesso Connecteed HR', html, testo);
    return ok ? { sent: true, error: null } : { sent: false, error: 'Invio email fallito' };
  }

  async azione(c: ContestoPagina, corpo: Record<string, any>) {
    const { ROLE, LABEL, SELF, MULTI_COMPANY } = this.cfg;
    const base = `/admin/${SELF}`;
    const accessibili = await this.accessibili(c);
    const id = corpo.id !== undefined && corpo.id !== '' ? Number.parseInt(String(corpo.id), 10) || 0 : Number.parseInt(String(c.query.id ?? ''), 10) || 0;
    const minuscolo = LABEL.toLowerCase();
    switch (corpo.action ?? '') {
      case 'create': {
        const auto = !!corpo.autogen_password;
        const password = auto ? passwordTemporanea() : String(corpo.password ?? '');
        const r = await this.utenti.crea({ username: corpo.username ?? '', password, name: corpo.name ?? '', email: corpo.email ?? '', role: ROLE },
          c.u.companyId!, c.audit);
        if (!r.success) {
          let errore = h(r.error);
          if (r.error === 'EMAIL_EXISTS') {
            const [e] = await this.q('SELECT id, role FROM users WHERE lower(email) = lower($1) LIMIT 1', [String(corpo.email ?? '').trim()]);
            errore = e && e.role === ROLE
              ? `Esiste gia' un ${LABEL} con questa email. Usa <a href="${h(SELF)}?action=link" style="color:#0b3aa4;font-weight:600;">Aggiungi esistente</a> per collegarlo alle tue aziende.`
              : `Email <strong>${h(String(corpo.email ?? ''))}</strong> gia' usata da un altro utente del sistema.`;
          }
          return this.dati(c, { azione: 'new', errore, post: corpo });
        }
        if (MULTI_COMPANY) {
          const scelte = lista(corpo['company_ids[]'] ?? corpo.company_ids);
          if (scelte.length) await this.collega(r.id, scelte, accessibili);
        }
        const mail = await this.credenziali(String(corpo.email ?? ''), String(corpo.name ?? ''), String(corpo.username ?? ''), password);
        if (!auto) return redirect(`${base}?message=created&email=${mail.sent ? 'ok' : 'fail'}`);
        const nota = mail.sent ? ` Le credenziali sono state inviate via email a <strong>${h(String(corpo.email ?? ''))}</strong>.`
          : mail.error ? ` <em>(Email NON inviata: ${h(mail.error)}. Comunicale manualmente.)</em>` : '';
        return this.dati(c, { azione: 'list', password, messaggio: `${LABEL} creato. Password generata: <code>${h(password)}</code> &mdash; <strong>copiala adesso, non sara' piu' visualizzata.</strong>${nota}` });
      }
      case 'update': {
        if (!id) break;
        const u = await this.bersaglio(c, id, accessibili);
        if (!u) return this.dati(c, { azione: 'list', errore: 'Utente non trovato' });
        if (this.ospite(u, accessibili)) {
          if (MULTI_COMPANY) await this.collega(id, lista(corpo['company_ids[]'] ?? corpo.company_ids), accessibili);
          return redirect(`${base}?message=updated`);
        }
        const errore = await this.aggiorna(u, corpo, c);
        if (errore) return this.dati(c, { azione: 'edit', id, errore: h(errore), post: corpo });
        if (MULTI_COMPANY) await this.collega(id, lista(corpo['company_ids[]'] ?? corpo.company_ids), accessibili);
        return redirect(`${base}?message=updated`);
      }
      case 'delete': {
        if (!id) break;
        const u = await this.bersaglio(c, id, accessibili);
        if (!u) return this.dati(c, { errore: 'Utente non trovato' });
        if (this.ospite(u, accessibili)) return this.dati(c, { errore: `Non puoi eliminare un ${minuscolo} condiviso da un altro tenant. Usa "Rimuovi dalle mie aziende".` });
        try {
          await this.q('DELETE FROM users WHERE id = $1', [id]);
        } catch {
          return this.dati(c, { errore: "Errore durante l'eliminazione: l'utente ha documenti o comunicazioni collegati. Disattivalo invece di eliminarlo." });
        }
        await this.audit.log('user_deleted', 'admin', c.u.id, c.audit, 'user', id, u, null);
        return redirect(`${base}?message=deleted`);
      }
      case 'reset_password': {
        if (!id) break;
        const u = await this.bersaglio(c, id, accessibili);
        if (!u) return this.dati(c, { errore: 'Utente non trovato' });
        if (this.ospite(u, accessibili)) return this.dati(c, { errore: `Non puoi resettare la password di un ${minuscolo} condiviso.` });
        const password = passwordTemporanea(12);
        await this.q('UPDATE users SET password_hash = $2, failed_attempts = 0, locked_until = NULL, must_change_password = true WHERE id = $1', [id, await hashPassword(password)]);
        await this.audit.log('password_reset', 'admin', c.u.id, c.audit, 'user', id);
        await this.sessioni.chiudiTutte('user', id, 'password_reimpostata');
        const mail = await this.credenziali(u.email ?? '', u.name ?? '', u.username ?? '', password);
        const nota = mail.sent ? ` La nuova password e' stata inviata via email a <strong>${h(u.email ?? '')}</strong>.`
          : mail.error ? ` <em>(Email NON inviata: ${h(mail.error)}. Comunicale manualmente.)</em>` : '';
        return this.dati(c, { password, messaggio: `Nuova password generata: <code>${h(password)}</code> &mdash; <strong>copiala adesso.</strong>${nota}` });
      }
      case 'link_existing': {
        const email = String(corpo.link_email ?? '').trim();
        const scelte = lista(corpo['link_company_ids[]'] ?? corpo.link_company_ids).filter((x) => accessibili.includes(x));
        const indietro = (errore: string) => this.dati(c, { azione: 'link', errore, post: corpo });
        if (email === '' || !EMAIL.test(email)) return indietro(`Inserisci una email valida del ${minuscolo} esistente.`);
        if (!scelte.length) return indietro(`Seleziona almeno un'azienda a cui collegare il ${minuscolo}.`);
        const [e] = await this.q('SELECT id, is_active FROM users WHERE lower(email) = lower($1) AND role = $2 LIMIT 1', [email, ROLE]);
        if (!e) return indietro(`Nessun ${minuscolo} attivo trovato con questa email. Verifica o crea un nuovo account.`);
        if (!e.is_active) return indietro(`Account ${minuscolo} disattivato. Contatta l'amministrazione.`);
        const gia = await this.collegamenti(e.id);
        let aggiunte = 0;
        for (const cid of scelte) {
          if (gia.includes(cid)) continue;
          await this.q('INSERT INTO user_companies (user_id, company_id) VALUES ($1, $2) ON CONFLICT DO NOTHING', [e.id, cid]);
          aggiunte++;
        }
        await this.audit.log('linked_to_companies', 'admin', c.u.id, c.audit, 'user', e.id, null, { company_ids: scelte });
        return redirect(`${base}?message=linked&n=${aggiunte}`);
      }
      case 'unlink_from_my': {
        if (!id || !accessibili.length) break;
        const u = await this.bersaglio(c, id, accessibili);
        if (!u) return this.dati(c, { errore: 'Utente non trovato' });
        await this.q('DELETE FROM user_companies WHERE user_id = $1 AND company_id = ANY($2::int[])', [id, accessibili]);
        await this.audit.log('unlinked_from_companies', 'admin', c.u.id, c.audit, 'user', id, null, { company_ids: accessibili });
        return redirect(`${base}?message=unlinked`);
      }
      case 'toggle_active': {
        if (!id) break;
        const u = await this.bersaglio(c, id, accessibili);
        if (!u || this.ospite(u, accessibili)) return this.dati(c, { errore: 'Utente non trovato' });
        await this.q('UPDATE users SET is_active = $2 WHERE id = $1', [id, !u.is_active]);
        if (u.is_active) await this.sessioni.chiudiTutte('user', id, 'account_disattivato');
        await this.audit.log('user_updated', 'admin', c.u.id, c.audit, 'user', id, u, { is_active: !u.is_active ? 1 : 0 });
        return redirect(`${base}?message=updated`);
      }
    }
    return this.dati(c);
  }

  /** User::update per username, nome, email e stato. */
  private async aggiorna(u: any, corpo: Record<string, any>, c: ContestoPagina): Promise<string | null> {
    const campi: Record<string, unknown> = {};
    if (corpo.name) campi.name = String(corpo.name).slice(0, 100);
    if (corpo.email !== undefined) {
      const e = String(corpo.email).trim();
      if (e !== '' && e.toLowerCase() !== String(u.email ?? '').toLowerCase()) {
        if (!EMAIL.test(e) || e.length > 100) return 'Email non valida';
        if ((await this.q('SELECT 1 FROM users WHERE lower(email) = lower($1) AND id <> $2', [e, u.id])).length) return 'Email gia usata da un altro utente';
      }
      campi.email = e === '' ? null : e;
    }
    campi.is_active = corpo.is_active !== undefined;
    if (corpo.username !== undefined && corpo.username !== u.username) {
      const un = String(corpo.username);
      if (!/^[A-Za-z0-9_.]{3,50}$/.test(un)) return 'Username non valido (lettere, numeri, punto e underscore, 3-50)';
      if ((await this.q('SELECT 1 FROM users WHERE username = $1 AND id <> $2', [un, u.id])).length) return 'Username già esistente';
      campi.username = un;
    }
    const k = Object.keys(campi);
    await this.q(`UPDATE users SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [u.id, ...k.map((x) => campi[x])]);
    if (u.is_active && !campi.is_active) await this.sessioni.chiudiTutte('user', u.id, 'account_disattivato');
    await this.audit.log('user_updated', 'admin', c.u.id, c.audit, 'user', u.id, u, campi);
    return null;
  }

  async dati(c: ContestoPagina, o: { azione?: string; id?: number; errore?: string; messaggio?: string; password?: string; post?: Record<string, any> } = {}) {
    const { ROLE, LABEL, SELF } = this.cfg;
    const accessibili = await this.accessibili(c);
    const action = o.azione ?? (c.query.action !== undefined ? String(c.query.action) : 'list');
    const id = o.id ?? (c.query.id !== undefined ? Number.parseInt(String(c.query.id), 10) || 0 : null);
    let message = o.messaggio ?? '';
    if (!message && c.query.message !== undefined) {
      const stato = String(c.query.email ?? '');
      const suffisso = c.query.message === 'created'
        ? stato === 'ok' ? ' &mdash; credenziali inviate via email.' : stato === 'fail' ? ' &mdash; email <strong>NON inviata</strong>, comunica le credenziali manualmente.' : ''
        : '';
      const n = Number.parseInt(String(c.query.n ?? '0'), 10) || 0;
      message = ({
        created: `${LABEL} creato con successo${suffisso}`, updated: `${LABEL} aggiornato`, deleted: `${LABEL} eliminato`,
        linked: `${LABEL} collegato a ${n} azienda${n === 1 ? '' : 'e'}`, unlinked: `${LABEL} rimosso dalle tue aziende`,
      } as Record<string, string>)[String(c.query.message)] ?? '';
    }
    let users: any[] = [];
    let current: any = null;
    if (action === 'list') users = await this.elenco(c, accessibili);
    else if ((action === 'edit' || action === 'view') && id) {
      current = await this.bersaglio(c, id, accessibili);
      if (!current || current.role !== ROLE) return redirect(`/admin/${SELF}?error=not_found`);
    }
    const aziende = accessibili.length
      ? await this.q('SELECT id, name FROM companies WHERE is_active = true AND id = ANY($1::int[]) ORDER BY name', [accessibili]) : [];
    const titolo = action === 'new' ? `Nuovo ${LABEL}` : action === 'edit' ? `Modifica ${LABEL}` : action === 'link' ? `Aggiungi ${LABEL} esistente` : `Gestione ${this.cfg.LABEL_PLURAL}`;
    return vista(titolo, {
      ...this.cfg, action, id, users, current, message, error: o.errore ?? '', generatedPassword: o.password ?? null,
      _POST: o.post ?? {}, accessibleCids: accessibili, __aziendeGestibili: aziende,
      __aziendeAssegnate: current ? await this.collegamenti(current.id) : [],
    });
  }
}

@Injectable()
@PaginaPhp('admin/accountant')
export class CommercialistiPagina extends UtentiEsterniPagina {
  protected readonly cfg: Ruolo = {
    ROLE: 'accountant', LABEL: 'Commercialista', LABEL_PLURAL: 'Commercialisti', SELF: 'accountant.php', MULTI_COMPANY: true,
    ICON_PATH: 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z',
  };
}

@Injectable()
@PaginaPhp('admin/consulente-lavoro')
export class ConsulentiPagina extends UtentiEsterniPagina {
  protected readonly cfg: Ruolo = {
    ROLE: 'consulente_lavoro', LABEL: 'Consulente del lavoro', LABEL_PLURAL: 'Consulenti del lavoro', SELF: 'consulente-lavoro.php', MULTI_COMPANY: true,
    ICON_PATH: 'M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z',
  };
}

@Injectable()
@PaginaPhp('admin/formatori')
export class FormatoriPagina extends UtentiEsterniPagina {
  protected readonly cfg: Ruolo = {
    ROLE: 'formatore', LABEL: 'Responsabile formazione', LABEL_PLURAL: 'Responsabili formazione', SELF: 'formatori.php', MULTI_COMPANY: false,
    BACK_URL: 'academy.php', BACK_LABEL: 'Academy', ICON_PATH: 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5',
  };
}
