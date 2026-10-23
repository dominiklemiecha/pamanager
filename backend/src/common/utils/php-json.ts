/**
 * JSON scritto come json_encode() di PHP con le opzioni di default:
 * "/" diventa "\/" e i caratteri non ASCII diventano \uXXXX.
 *
 * Serve dove il testo salvato deve restare identico a quello del PHP
 * (log di audit, campi JSON letti da codice o persone che li confrontano).
 */
export function phpJson(valore: unknown): string {
  return JSON.stringify(valore)
    .replace(/\//g, '\\/')
    .replace(/[^\x00-\x7f]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
}
