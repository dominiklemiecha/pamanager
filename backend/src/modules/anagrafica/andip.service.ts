import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { AnagraficaService, codiceFiscaleValido } from './anagrafica.service';
import { leggiAndip, RigaAndip } from './andip';

type Ctx = Parameters<AnagraficaService['aggiorna']>[3];

/** Campo dell'anagrafica Cora aggiornato dal tracciato, con l'etichetta per l'anteprima. */
const CAMPI: Array<[string, string]> = [
  ['last_name', 'Cognome'], ['first_name', 'Nome'], ['birth_date', 'Data di nascita'], ['hire_date', 'Data di assunzione'],
  ['termination_date', 'Data di cessazione'], ['address', 'Indirizzo'], ['email', 'Email'], ['phone', 'Telefono'], ['iban', 'IBAN'],
  ['matricola', 'Matricola'], ['filiale', 'Filiale'], ['part_time_percent', '% part-time'],
];

export interface Modifica { campo: string; etichetta: string; prima: string | null; dopo: string | null }
export interface VoceAnteprima {
  riga: number; codiceFiscale: string; cognome: string; nome: string; matricola: string; filiale: number | null; descrFiliale: string;
  qualifica: string; partTime: number | null; dataCessazione: string | null; email: string | null;
  /** nuovo = da creare in Cora; cessato = non in Cora e gia' cessato, non si crea. */
  stato: 'aggiorna' | 'invariato' | 'nuovo' | 'cessato' | 'errore';
  dipendenteId: number | null; attivo: boolean | null; disattiva: boolean; modifiche: Modifica[]; errori: string[];
}
/** Dipendente da creare scelto dall'HR: email e reparto li indica l'HR. */
export interface NuovoScelto { codiceFiscale: string; email: string; repartoId: number }

const str = (v: unknown) => (v === null || v === undefined || v === '' ? null : String(v));
/** Uguali senza badare a maiuscole e spazi (il tracciato e' tutto in maiuscolo). */
const uguali = (a: unknown, b: unknown) => (str(a) ?? '').replace(/\s+/g, ' ').trim().toLowerCase() === (str(b) ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
/** "DE VINCENTI" -> "De Vincenti", "MONGIOVI'" -> "Mongiovi'": il tracciato e' tutto in maiuscolo. */
const iniziali = (s: string) => s.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (_, sep: string, l: string) => sep + l.toUpperCase());

/**
 * Import dell'anagrafica dal tracciato ANDIP di TeamSystem.
 *
 * Il dipendente si riconosce dal codice fiscale, solo nell'azienda di
 * lavoro. I dati del tracciato sovrascrivono quelli di Cora; un campo vuoto
 * nel tracciato non cancella il dato gia' presente. Nome, cognome e
 * indirizzo uguali a meno delle maiuscole restano come sono in Cora.
 * Con una data di cessazione gia' passata il dipendente viene disattivato.
 * Si salva anche il record completo del tracciato (tutte le colonne).
 *
 * I dipendenti non presenti in Cora si creano solo se l'HR li sceglie e
 * indica email (per le credenziali) e reparto, che il tracciato non ha.
 * Chi e' gia' cessato non viene creato.
 */
@Injectable()
export class AndipService {
  constructor(private readonly ds: DataSource, private readonly anagrafica: AnagraficaService) {}

  async anteprima(contenuto: string, cid: number) {
    const letto = leggiAndip(contenuto);
    if (!letto.ok) return { success: false as const, error: letto.errore };
    const aziende = [...new Set(letto.righe.map((r) => r.azienda).filter(Boolean))];
    if (aziende.length > 1) return { success: false as const, error: `Il file contiene più aziende TeamSystem (${aziende.join(', ')}): carica un file per azienda` };
    const [c] = await this.ds.query('SELECT teamsystem_code FROM companies WHERE id = $1', [cid]);
    const codice = aziende[0] ?? null;
    if (c?.teamsystem_code && codice && String(c.teamsystem_code) !== codice) {
      return { success: false as const, error: `Il file è dell'azienda TeamSystem ${codice}, ma questa azienda in Cora è collegata al codice ${c.teamsystem_code}` };
    }
    const esistenti: any[] = await this.ds.query(
      `SELECT id, fiscal_code, first_name, last_name, birth_date, hire_date, termination_date, address, email, phone, iban, matricola, filiale,
              part_time_percent, teamsystem_data, is_active FROM employees WHERE company_id = $1`, [cid]);
    const reparti: Array<{ id: number; nome: string }> = (await this.ds.query(
      'SELECT id, name FROM departments WHERE company_id = $1 AND is_active = true ORDER BY name', [cid])).map((r: any) => ({ id: Number(r.id), nome: r.name }));
    const perCf = new Map(esistenti.map((e) => [String(e.fiscal_code).toUpperCase(), e]));
    const oggi = oggiRoma();
    const visti = new Set<string>();
    const voci: VoceAnteprima[] = letto.righe.map((r) => {
      const errori = [...r.errori];
      if (r.codiceFiscale && !codiceFiscaleValido(r.codiceFiscale)) errori.push('Codice fiscale non valido');
      if (r.codiceFiscale && visti.has(r.codiceFiscale)) errori.push('Codice fiscale ripetuto nel file');
      visti.add(r.codiceFiscale);
      const e = perCf.get(r.codiceFiscale);
      const cessato = !!r.dataCessazione && r.dataCessazione <= oggi;
      const base = {
        riga: r.riga, codiceFiscale: r.codiceFiscale, cognome: r.cognome, nome: r.nome, matricola: r.matricola, filiale: r.filiale,
        descrFiliale: r.descrFiliale, qualifica: r.qualifica, partTime: r.partTime, dataCessazione: r.dataCessazione, email: r.email,
        dipendenteId: e ? Number(e.id) : null, attivo: e ? !!e.is_active : null, errori,
      };
      if (errori.length) return { ...base, stato: 'errore', disattiva: false, modifiche: [] };
      if (!e) return { ...base, stato: cessato ? 'cessato' : 'nuovo', disattiva: false, modifiche: [] };
      const modifiche = this.modifiche(e, r);
      const disattiva = !!e.is_active && cessato;
      return { ...base, stato: modifiche.length || disattiva ? 'aggiorna' : 'invariato', disattiva, modifiche };
    });
    const nelFile = new Set(letto.righe.map((r) => r.codiceFiscale));
    const assenti = esistenti.filter((e) => e.is_active && !nelFile.has(String(e.fiscal_code).toUpperCase()))
      .map((e) => ({ id: Number(e.id), nome: e.first_name, cognome: e.last_name, codiceFiscale: e.fiscal_code }));
    return { success: true as const, azienda: codice, voci, assenti, reparti, righe: letto.righe };
  }

  /**
   * Applica il tracciato, ricalcolando l'anteprima: aggiorna i dipendenti
   * scelti (per codice fiscale) e crea i nuovi scelti con email e reparto.
   */
  async applica(contenuto: string, scelti: string[], nuovi: NuovoScelto[], cid: number, ctx: Ctx) {
    const a = await this.anteprima(contenuto, cid);
    if (!a.success) return a;
    const sel = new Set(scelti.map((x) => String(x).toUpperCase()));
    let aggiornati = 0;
    let disattivati = 0;
    let creati = 0;
    let emailInviate = 0;
    const errori: Array<{ codiceFiscale: string; errore: string }> = [];
    const righe = new Map(a.righe.map((r) => [r.codiceFiscale, r]));
    for (const v of a.voci) {
      if (v.stato !== 'aggiorna' || !sel.has(v.codiceFiscale) || !v.dipendenteId) continue;
      const d: Record<string, unknown> = Object.fromEntries(v.modifiche.map((m) => [m.campo, m.dopo]));
      if ('teamsystem_data' in d) d.teamsystem_data = righe.get(v.codiceFiscale)?.dati ?? null;
      if (v.disattiva) d.is_active = false;
      const esito = await this.anagrafica.aggiorna(v.dipendenteId, d, cid, ctx);
      if (!esito.success) { errori.push({ codiceFiscale: v.codiceFiscale, errore: esito.error }); continue; }
      aggiornati++;
      if (v.disattiva) disattivati++;
    }
    const daCreare = new Map(a.voci.filter((v) => v.stato === 'nuovo').map((v) => [v.codiceFiscale, v]));
    for (const n of nuovi) {
      const cf = String(n.codiceFiscale ?? '').toUpperCase();
      const v = daCreare.get(cf);
      const r = righe.get(cf);
      if (!v || !r) { errori.push({ codiceFiscale: cf, errore: 'Dipendente non creabile da questo file' }); continue; }
      daCreare.delete(cf);
      const nome = iniziali(r.nome);
      const cognome = iniziali(r.cognome);
      const creato = await this.anagrafica.crea({
        username: await this.anagrafica.usernameLibero(nome, cognome, cid), fiscal_code: cf, first_name: nome, last_name: cognome,
        email: String(n.email ?? '').trim(), department_id: Number(n.repartoId) || null, birth_date: r.dataNascita, hire_date: r.dataAssunzione,
        address: r.indirizzo, phone: r.telefono, iban: r.iban,
      }, cid, ctx);
      if (!creato.success) { errori.push({ codiceFiscale: cf, errore: creato.error }); continue; }
      creati++;
      if (creato.email_sent) emailInviate++;
      // Dati TeamSystem (la creazione non li gestisce)
      await this.anagrafica.aggiorna(creato.id, {
        matricola: r.matricola, filiale: r.filiale, part_time_percent: r.partTime, teamsystem_data: r.dati,
        ...(r.dataCessazione ? { termination_date: r.dataCessazione } : {}),
      }, cid, ctx);
    }
    if (a.azienda && (aggiornati || creati)) {
      await this.ds.query('UPDATE companies SET teamsystem_code = $2 WHERE id = $1 AND teamsystem_code IS NULL', [cid, a.azienda]);
    }
    return { success: true as const, aggiornati, disattivati, creati, emailInviate, errori };
  }

  private modifiche(e: any, r: RigaAndip): Modifica[] {
    const nuovi: Record<string, string | null> = {
      last_name: r.cognome || null, first_name: r.nome || null, birth_date: r.dataNascita, hire_date: r.dataAssunzione,
      termination_date: r.dataCessazione, address: r.indirizzo, email: r.email, phone: r.telefono, iban: r.iban,
      matricola: r.matricola || null, filiale: r.filiale === null ? null : String(r.filiale),
      part_time_percent: r.partTime === null ? null : String(r.partTime),
    };
    const out: Modifica[] = [];
    for (const [campo, etichetta] of CAMPI) {
      const dopo = nuovi[campo];
      const prima = str(e[campo]);
      // Vuoto nel tracciato: il dato di Cora resta (anche la data di cessazione).
      if (dopo === null) continue;
      if (campo === 'part_time_percent' ? prima !== null && Number(prima) === Number(dopo) : uguali(prima, dopo)) continue;
      out.push({ campo, etichetta, prima, dopo });
    }
    // Tutte le altre colonne del tracciato, salvate cosi' come sono
    const prima: Record<string, string> | null = e.teamsystem_data && typeof e.teamsystem_data === 'object' ? e.teamsystem_data : null;
    if (!prima) out.push({ campo: 'teamsystem_data', etichetta: 'Dati TeamSystem', prima: null, dopo: `prima importazione (${Object.keys(r.dati).length} colonne)` });
    else {
      const cambiate = Object.keys({ ...prima, ...r.dati }).filter((k) => (prima[k] ?? '') !== (r.dati[k] ?? ''));
      if (cambiate.length) out.push({ campo: 'teamsystem_data', etichetta: 'Dati TeamSystem', prima: null, dopo: `cambiano ${cambiate.length === 1 ? '1 colonna' : `${cambiate.length} colonne`}: ${cambiate.join(', ')}` });
    }
    return out;
  }
}
