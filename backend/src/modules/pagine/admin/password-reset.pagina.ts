import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { PasswordService } from '../../auth/password.service';
import { schedeConfigurazione, veroGlobale } from '../comune';
import { ContestoPagina, PaginaPhp, Pagina, vista } from '../pagina';

const h = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

/**
 * Reset password dell'HR (admin/password-resets.php): password temporanea
 * per un account dell'azienda, approvazione o rifiuto delle richieste.
 *
 * Correzioni: solo account e richieste dell'azienda attiva (il PHP
 * accettava qualsiasi id, anche dell'admin globale, e ne mostrava la nuova
 * password); gli admin senza azienda non sono nell'elenco; dopo il reset le
 * sessioni dell'account si chiudono; il link di recupero nasce da APP_URL.
 */
@Injectable()
@PaginaPhp('admin/password-resets')
export class PasswordResetPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly password: PasswordService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    const globale = await veroGlobale(this.ds, c);
    const id = Number.parseInt(corpo.request_id ?? '', 10) || 0;
    switch (corpo.action ?? '') {
      case 'approve': {
        const r = await this.password.approvaRichiesta(id, cid, globale, c.audit);
        if (!r.ok) return this.dati(c, '', r.errore);
        return this.dati(c, `Richiesta approvata! Invia questo link all'utente:<br><br>
                    <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:1rem;margin:0.5rem 0;">
                        <input type="text" value="${h(r.link)}"
                               id="resetLink" readonly
                               style="width:100%;padding:0.5rem;border:1px solid #cbd5e0;border-radius:4px;font-size:0.85rem;">
                        <button onclick="copyResetLink()" style="margin-top:0.5rem;padding:0.4rem 1rem;background:#38a169;color:white;border:none;border-radius:4px;cursor:pointer;">
                            Copia Link
                        </button>
                        <span id="copyMsg" style="margin-left:0.5rem;color:#276749;display:none;">Copiato!</span>
                    </div>
                    <small style="color:#718096;">Il link scade tra 24 ore.</small>`);
      }
      case 'reject': {
        const r = await this.password.rifiutaRichiesta(id, cid, globale, String(corpo.notes ?? '').trim(), c.audit);
        return r.ok ? this.dati(c, 'Richiesta rifiutata') : this.dati(c, '', r.errore);
      }
      case 'manual_reset': {
        const tipo = String(corpo.user_type ?? '');
        const uid = Number.parseInt(corpo.user_id ?? '', 10) || 0;
        if (!tipo || !uid) return this.dati(c);
        const r = await this.password.reimpostaDaAdmin(tipo, uid, cid, globale, c.audit);
        return r.ok ? this.dati(c, `Password resettata. Nuova password temporanea: <code>${h(r.password)}</code>`) : this.dati(c, '', r.errore);
      }
      default:
        return this.dati(c);
    }
  }

  async dati(c: ContestoPagina, message = '', error = '') {
    const { users, employees } = await this.password.accountReimpostabili(c.u.companyId!, await veroGlobale(this.ds, c));
    return vista('Configurazione · Reset Password', {
      message, error, users, employees,
      ...(await schedeConfigurazione(this.ds, c, 'password-resets')),
    });
  }
}
