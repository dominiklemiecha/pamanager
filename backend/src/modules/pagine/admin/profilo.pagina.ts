import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { PasswordService } from '../../auth/password.service';
import { ProfiloService } from '../../profilo/profilo.service';
import { schedeConfigurazione, emailStaffOccupata } from '../comune';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const MESSAGGI: Record<string, string> = {
  updated: 'Profilo aggiornato.', password: 'Password cambiata.', photo: 'Foto profilo aggiornata.', photo_removed: 'Foto profilo rimossa.',
};
/** Indirizzo email come FILTER_VALIDATE_EMAIL (forma comune: locale@dominio.tld). */
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;

/**
 * Profilo dello staff (admin/profile.php): nome ed email, foto, password.
 *
 * Correzioni: nome ed email oltre la lunghezza massima danno un errore
 * chiaro invece del messaggio del database; dopo il cambio password le
 * altre sessioni dell'account si chiudono.
 */
@Injectable()
@PaginaPhp('admin/profile')
export class ProfiloStaffPagina implements Pagina {
  readonly ruoli = ['admin', 'accountant', 'consulente_lavoro'];
  constructor(private readonly ds: DataSource, private readonly profilo: ProfiloService, private readonly password: PasswordService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const id = c.u.id;
    switch (corpo.action ?? '') {
      case 'update_info': {
        const nome = String(corpo.name ?? '').trim();
        const email = String(corpo.email ?? '').trim();
        if (nome === '') return this.dati(c, 'Nome obbligatorio');
        if (email !== '' && (!EMAIL.test(email) || email.length > 100)) return this.dati(c, 'Email non valida');
        if (nome.length > 100) return this.dati(c, 'Nome troppo lungo (max 100 caratteri)');
        if (await emailStaffOccupata(this.ds, email, id)) return this.dati(c, 'Email gia in uso da un altro account');
        await senzaIsolamento(() => this.ds.query('UPDATE users SET name = $2, email = $3 WHERE id = $1', [id, nome, email || null]));
        return redirect('/admin/profile.php?message=updated');
      }
      case 'upload_photo': {
        const errore = await this.profilo.caricaFotoUtente(id, file.photo?.[0]);
        return errore ? this.dati(c, errore) : redirect('/admin/profile.php?message=photo');
      }
      case 'remove_photo':
        await this.profilo.rimuoviFotoUtente(id);
        return redirect('/admin/profile.php?message=photo_removed');
      case 'change_password': {
        const nuova = corpo.new_password ?? '';
        if (nuova !== (corpo.confirm_password ?? '')) return this.dati(c, 'Le password non coincidono');
        const r = await this.password.cambia('user', id, c.u.ruolo, corpo.current_password ?? '', nuova, nuova, c.audit);
        if (r.ok) return redirect('/admin/profile.php?message=password');
        return this.dati(c, r.errore ?? (r.errori ?? ['Errore cambio password']).join(' '));
      }
      default:
        return this.dati(c);
    }
  }

  async dati(c: ContestoPagina, error = '') {
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT id, username, name, email, role, photo_path FROM users WHERE id = $1', [c.u.id]));
    return vista('Il mio profilo', {
      user: u, baseUrl: '', error,
      message: MESSAGGI[String(c.query.message ?? '')] ?? '',
      ...(await schedeConfigurazione(this.ds, c, 'profile')),
    });
  }
}
