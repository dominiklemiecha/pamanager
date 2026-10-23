import type { NextFunction, Request, Response } from 'express';

/**
 * Barriera contro l'assegnazione di massa tra aziende (come in crm-connecteed).
 *
 * Molti endpoint salvano il corpo della richiesta cosi' com'e'
 * (`create({ ...dto, companyId })`, `Object.assign(entita, dto)` + `save`).
 * Con un `id` nel corpo, `save()` aggiorna la riga con quell'id anche se e'
 * di un'altra azienda; con un `companyId` la sposta. Qui, prima di qualsiasi
 * controller, si tolgono dal corpo i campi che il client non deve mai
 * decidere:
 *  - `companyId` / `company_id` / `company` a qualsiasi profondita': l'azienda viene
 *    sempre dall'utente collegato;
 *  - `id`, date di sistema e relazioni salvate a cascata al primo livello:
 *    la riga da modificare la dice l'indirizzo (`/risorsa/:id`), non il corpo.
 * Gli `id` dentro gli elenchi (righe, riordini, assegnatari) restano: li
 * usano i servizi, che li cercano sempre dentro l'azienda.
 */
// `company` e' la relazione: con { company: { id } } TypeORM sposterebbe la riga.
const SEMPRE_VIA = new Set(['companyId', 'company_id', 'company', 'tenantId', 'tenant_id']);
const VIA_IN_CIMA = new Set(['id', 'createdAt', 'updatedAt', 'deletedAt', 'created_at', 'updated_at']);

function pulisci(valore: unknown, profondita: number): unknown {
  if (profondita > 20 || valore === null || typeof valore !== 'object') return valore;
  if (Array.isArray(valore)) {
    for (let i = 0; i < valore.length; i++) valore[i] = pulisci(valore[i], profondita + 1);
    return valore;
  }
  const oggetto = valore as Record<string, unknown>;
  for (const chiave of Object.keys(oggetto)) {
    if (SEMPRE_VIA.has(chiave) || (profondita === 0 && VIA_IN_CIMA.has(chiave))) {
      delete oggetto[chiave];
      continue;
    }
    oggetto[chiave] = pulisci(oggetto[chiave], profondita + 1);
  }
  return oggetto;
}

export function bodyGuard(req: Request, _res: Response, next: NextFunction): void {
  if (req.body && typeof req.body === 'object') pulisci(req.body, 0);
  next();
}
