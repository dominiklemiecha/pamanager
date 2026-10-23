import { execFileSync } from 'child_process';

/**
 * Esegue codice PHP dentro il container della baseline, con config.php caricato
 * (classi, database MariaDB della baseline, fuso di Roma) e restituisce il JSON
 * stampato. Serve per confrontare le funzioni del vecchio codice con le nuove.
 */
export function php<T = any>(codice: string): T {
  const script = `require '/var/www/html/config/config.php'; ${codice}`;
  const out = execFileSync('docker', ['exec', 'cora-baseline-app-1', 'php', '-d', 'display_errors=stderr', '-r', script], { maxBuffer: 64 * 1024 * 1024 }).toString();
  const inizio = out.search(/[[{]/);
  if (inizio < 0) throw new Error(`Risposta PHP senza JSON: ${out.slice(0, 500)}`);
  return JSON.parse(out.slice(inizio));
}
