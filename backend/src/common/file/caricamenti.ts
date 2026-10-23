import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { cartellaStorage } from '../../config/app';

/** File ricevuto da multer (in memoria). */
export interface FileCaricato { originalname: string; size: number; buffer: Buffer; mimetype: string }

/** Limite e tipi dei documenti, come MAX_FILE_SIZE e ALLOWED_EXTENSIONS del PHP. */
export const DIMENSIONE_MASSIMA = 10 * 1024 * 1024;
export const ESTENSIONI_DOCUMENTI = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

/**
 * Il contenuto corrisponde davvero al tipo dichiarato dall'estensione?
 * Il PHP controllava solo l'estensione: un file .pdf poteva contenere altro.
 */
export function contenutoCoerente(estensione: string, buf: Buffer): boolean {
  const inizia = (...b: number[]) => b.every((x, i) => buf[i] === x);
  switch (estensione) {
    case 'pdf': return buf.slice(0, 5).toString('latin1') === '%PDF-';
    case 'jpg': case 'jpeg': return inizia(0xff, 0xd8, 0xff);
    case 'png': return inizia(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a);
    case 'gif': return buf.slice(0, 4).toString('latin1') === 'GIF8';
    case 'webp': return buf.slice(0, 4).toString('latin1') === 'RIFF' && buf.slice(8, 12).toString('latin1') === 'WEBP';
    case 'doc': case 'xls': case 'ppt': return inizia(0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1);
    case 'docx': case 'xlsx': case 'pptx': case 'odt': case 'ods': case 'zip': return inizia(0x50, 0x4b, 0x03, 0x04);
    case 'txt': case 'csv': return !buf.includes(0);
    default: return false;
  }
}

export const estensione = (nome: string) => path.extname(nome).slice(1).toLowerCase();

/**
 * Salva un file nella cartella privata indicata e restituisce il percorso
 * assoluto (come lo salvava il PHP: stesso volume, stesso percorso).
 */
export function salvaPrivato(sottocartella: string, prefisso: string, f: FileCaricato, byte = 16): string {
  const dir = cartellaStorage(sottocartella);
  fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
  const nome = `${prefisso}${randomBytes(byte).toString('hex')}.${estensione(f.originalname)}`;
  const percorso = path.join(dir, nome);
  fs.writeFileSync(percorso, f.buffer, { mode: 0o640 });
  return percorso;
}

/**
 * Percorso salvato nel database -> file leggibile, solo se sta dentro lo
 * storage (niente percorsi manipolati che escono dalla cartella).
 */
export function fileSicuro(salvato: string | null | undefined): string | null {
  if (!salvato) return null;
  const radice = path.resolve(cartellaStorage());
  const p = path.resolve(salvato);
  if (p !== radice && !p.startsWith(radice + path.sep)) return null;
  return fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
}

/** Tipo MIME dal contenuto, per i download (finfo del PHP). */
export function mimeDaContenuto(p: string, nome = ''): string {
  const buf = Buffer.alloc(16);
  const fd = fs.openSync(p, 'r');
  try { fs.readSync(fd, buf, 0, 16, 0); } finally { fs.closeSync(fd); }
  if (buf.slice(0, 5).toString('latin1') === '%PDF-') return 'application/pdf';
  if (buf[0] === 0xff && buf[1] === 0xd8) return 'image/jpeg';
  if (buf[0] === 0x89 && buf[1] === 0x50) return 'image/png';
  if (buf[0] === 0xd0 && buf[1] === 0xcf) return 'application/msword';
  if (buf[0] === 0x50 && buf[1] === 0x4b) {
    const ext = estensione(nome || p);
    if (ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    if (ext === 'xlsx') return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    return 'application/zip';
  }
  return 'application/octet-stream';
}
