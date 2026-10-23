import { Injectable } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import type { FileCaricato } from '../../common/file/caricamenti';
import { senzaIsolamento } from '../../common/database/isolamento';
import { cartellaUpload } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';

// Le definizioni di tipo di sharp sono solo ESM: con moduli CommonJS si usa require.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const sharp: (input: Buffer, opzioni?: { limitInputPixels?: number }) => any = require('sharp');

const FOTO_MASSIMA = 3 * 1024 * 1024;

/** Tipo dell'immagine dai primi byte (mime_content_type del PHP). */
function tipoImmagine(b: Buffer): string | null {
  if (b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'image/jpeg';
  if (b.slice(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return 'image/png';
  if (b.slice(0, 4).toString('latin1') === 'RIFF' && b.slice(8, 12).toString('latin1') === 'WEBP') return 'image/webp';
  if (b.slice(0, 4).toString('latin1') === 'GIF8') return 'image/gif';
  return null;
}

/** "LT71 3250 •••• •••• 9309": prime 8 cifre e ultime 4, come la pagina PHP. */
export function ibanMascherato(iban: string | null | undefined): string {
  const i = String(iban ?? '').replace(/\s+/g, '').toUpperCase();
  if (i === '') return '—';
  return i.length > 12 ? `${i.slice(0, 4)} ${i.slice(4, 8)} •••• •••• ${i.slice(-4)}` : i;
}

/**
 * Profilo del dipendente (employee/profile.php e _cora/profile.inc.php):
 * nome e cognome, foto, preferenze di notifica. I dati contrattuali sono
 * solo in lettura e l'IBAN esce gia' mascherato.
 */
@Injectable()
export class ProfiloService {
  constructor(private readonly ds: DataSource, private readonly audit: AuditService) {}

  async dati(employeeId: number) {
    const [e] = await this.ds.query(
      `SELECT e.first_name, e.last_name, e.email, e.username, e.fiscal_code, e.position, e.hire_date, e.iban, e.photo_path,
              e.notify_email, e.notify_push, e.availability_status, c.name AS company_name
         FROM employees e LEFT JOIN companies c ON c.id = e.company_id WHERE e.id = $1`, [employeeId]);
    const { iban, photo_path, ...resto } = e;
    return { ...resto, iban: ibanMascherato(iban), foto: photo_path ? '/' + String(photo_path).replace(/^\/+/, '') : '' };
  }

  async aggiornaDati(employeeId: number, nome: string, cognome: string, ctx: ContestoAudit): Promise<string | null> {
    const n = String(nome ?? '').trim();
    const c = String(cognome ?? '').trim();
    if (n === '' || c === '') return 'Nome e cognome obbligatori';
    // Il PHP lasciava l'errore al database (colonne di 50 caratteri).
    if ([...n].length > 50 || [...c].length > 50) return 'Nome e cognome possono avere al massimo 50 caratteri';
    const [prima] = await this.ds.query('SELECT first_name, last_name FROM employees WHERE id = $1', [employeeId]);
    await this.ds.query('UPDATE employees SET first_name = $1, last_name = $2 WHERE id = $3', [n, c, employeeId]);
    // Il PHP non registrava le modifiche fatte dal dipendente stesso.
    await this.audit.log('employee_profile_updated', 'employee', employeeId, ctx, 'employee', employeeId, prima, { first_name: n, last_name: c });
    return null;
  }

  /** Nuova foto: controllo del contenuto, ridimensionata a 256 px in WebP (ImageProcessor::toWebp). */
  async caricaFoto(employeeId: number, companyId: number, f: FileCaricato | undefined): Promise<string | null> {
    if (!f || !f.size) return 'Seleziona un file immagine valido';
    if (!tipoImmagine(f.buffer)) return 'Formato non supportato (usa JPG, PNG, WEBP o GIF)';
    if (f.size > FOTO_MASSIMA) return 'File troppo grande (max 3MB)';
    let webp: Buffer;
    try {
      webp = await sharp(f.buffer, { limitInputPixels: 40_000_000 })
        .rotate() // orientamento EXIF delle foto da telefono
        .resize(256, 256, { fit: 'inside', withoutEnlargement: true })
        .webp({ quality: 82 })
        .toBuffer();
    } catch {
      return 'Conversione foto fallita: Immagine non valida';
    }
    const relativo = `uploads/co-${companyId}/profile_photos/emp_${employeeId}_${randomBytes(4).toString('hex')}.webp`;
    const dest = this.percorsoUpload(relativo)!;
    fs.mkdirSync(path.dirname(dest), { recursive: true, mode: 0o775 });
    fs.writeFileSync(dest, webp, { mode: 0o644 });
    try {
      const vecchia = await this.fotoAttuale(employeeId);
      await this.ds.query('UPDATE employees SET photo_path = $1 WHERE id = $2', [relativo, employeeId]);
      this.elimina(vecchia);
    } catch {
      fs.rmSync(dest, { force: true });
      return 'Errore salvataggio foto';
    }
    return null;
  }

  async rimuoviFoto(employeeId: number) {
    const vecchia = await this.fotoAttuale(employeeId);
    await this.ds.query('UPDATE employees SET photo_path = NULL WHERE id = $1', [employeeId]);
    this.elimina(vecchia);
  }

  /**
   * Foto dello staff (admin/profile.php): uploads/users/u{id}_xxxx.webp.
   * Correzione: oltre all'estensione si controlla il contenuto del file.
   */
  async caricaFotoUtente(userId: number, f: FileCaricato | undefined): Promise<string | null> {
    if (!f || !f.size) return 'Foto mancante o errore upload';
    if (f.size > FOTO_MASSIMA) return 'File troppo grande (max 3MB)';
    const ext = path.extname(f.originalname ?? '').slice(1).toLowerCase();
    if (!['jpg', 'jpeg', 'png', 'webp'].includes(ext) || !['image/jpeg', 'image/png', 'image/webp'].includes(tipoImmagine(f.buffer) ?? '')) {
      return 'Solo immagini JPG, PNG, WEBP';
    }
    let webp: Buffer;
    try {
      webp = await sharp(f.buffer, { limitInputPixels: 40_000_000 }).rotate()
        .resize(256, 256, { fit: 'inside', withoutEnlargement: true }).webp({ quality: 82 }).toBuffer();
    } catch {
      return 'Conversione foto fallita';
    }
    const relativo = `uploads/users/u${userId}_${randomBytes(4).toString('hex')}.webp`;
    const dest = this.percorsoUpload(relativo)!;
    fs.mkdirSync(path.dirname(dest), { recursive: true, mode: 0o775 });
    fs.writeFileSync(dest, webp, { mode: 0o644 });
    const vecchia = await this.fotoUtente(userId);
    await senzaIsolamento(() => this.ds.query('UPDATE users SET photo_path = $1 WHERE id = $2', [relativo, userId]));
    this.elimina(vecchia);
    return null;
  }

  async rimuoviFotoUtente(userId: number) {
    const vecchia = await this.fotoUtente(userId);
    await senzaIsolamento(() => this.ds.query('UPDATE users SET photo_path = NULL WHERE id = $1', [userId]));
    this.elimina(vecchia);
  }

  private async fotoUtente(userId: number): Promise<string | null> {
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT photo_path FROM users WHERE id = $1', [userId]));
    return u?.photo_path ?? null;
  }

  async notifiche(employeeId: number, email: boolean, push: boolean) {
    await this.ds.query('UPDATE employees SET notify_email = $1, notify_push = $2 WHERE id = $3', [email, push, employeeId]);
  }

  private async fotoAttuale(employeeId: number): Promise<string | null> {
    const [e] = await this.ds.query('SELECT photo_path FROM employees WHERE id = $1', [employeeId]);
    return e?.photo_path ?? null;
  }

  private elimina(relativo: string | null) {
    const p = relativo ? this.percorsoUpload(relativo) : null;
    if (p && fs.existsSync(p) && fs.statSync(p).isFile()) fs.rmSync(p, { force: true });
  }

  /** "uploads/co-1/..." salvato nel database -> file nella cartella dei caricamenti, mai fuori. */
  private percorsoUpload(relativo: string): string | null {
    const radice = cartellaUpload();
    const p = path.resolve(radice, String(relativo).replace(/^\/*uploads\//, ''));
    return p.startsWith(radice + path.sep) ? p : null;
  }
}
