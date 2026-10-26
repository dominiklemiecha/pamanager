import type { Response } from 'express';
import type { FileCaricato } from '../../common/file/caricamenti';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';

/**
 * Pagine dell'area staff portate dal PHP. Ogni pagina ha:
 *  - dati(): le variabili che il template PHP usava (stessi nomi), calcolate
 *    dal backend; il frontend le passa al template convertito (php2js);
 *  - azione(): il ramo POST della pagina PHP (redirect o nuove variabili
 *    per ridisegnare la pagina con l'errore, come faceva il PHP);
 *  - file(): i download che la pagina PHP serviva con parametri GET.
 */
export interface ContestoPagina {
  u: UtenteCorrente;
  req: RichiestaAutenticata;
  query: Record<string, string>;
  /** Contesto per l'audit (ip, user agent, sessione, chi agisce). */
  audit: { ip: string | null; userAgent: string; sessioneId: string; tipo: string; id: number };
}

export type Variabili = Record<string, unknown>;
/** json: risposta diretta agli script della pagina (endpoint AJAX del PHP). */
export type EsitoPagina = { redirect: string } | { titolo: string; V: Variabili } | { json: unknown; status?: number } | { file: FilePagina };
/** File prodotto da un POST (es. anteprima PDF aperta in una nuova scheda). */
export interface FilePagina { dati: Buffer; tipo: string; nome: string; inLinea?: boolean }

export interface Pagina {
  /** Ruoli ammessi (Auth::requireUser del PHP). */
  readonly ruoli: string[];
  dati(c: ContestoPagina): Promise<EsitoPagina>;
  azione?(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>): Promise<EsitoPagina>;
  /**
   * Limiti dei file caricati (default 25MB in memoria). suDisco: file grandi
   * (video dei corsi) in una cartella temporanea invece che in memoria.
   */
  readonly caricamenti?: { dimensione: number; file?: number; suDisco?: boolean };
  /** true se ha gestito un download (con i parametri GET della pagina PHP). */
  file?(c: ContestoPagina, res: Response): Promise<boolean>;
}

const registro = new Map<string, new (...a: any[]) => Pagina>();

/** Registra una pagina con il suo indirizzo PHP, es. @PaginaPhp('admin/index'). */
export function PaginaPhp(percorso: string) {
  return (classe: new (...a: any[]) => Pagina) => { registro.set(percorso, classe); };
}

export function classiPagine() { return registro; }

export const redirect = (url: string): EsitoPagina => ({ redirect: url });
export const vista = (titolo: string, V: Variabili): EsitoPagina => ({ titolo, V });
export const json = (dati: unknown, status = 200): EsitoPagina => ({ json: dati, status });
export const filePagina = (f: FilePagina): EsitoPagina => ({ file: f });
