import { execFile } from 'child_process';

/**
 * Testo di un PDF con pdftotext (poppler), come PayslipParser::extractText.
 * Stringa vuota se il programma manca o il file non si legge.
 */
export function testoPdf(percorso: string): Promise<string> {
  return new Promise((ok) => {
    execFile(process.env.PDFTOTEXT_BIN || 'pdftotext', ['-layout', '-nopgbrk', percorso, '-'], { timeout: 20_000, maxBuffer: 20 * 1024 * 1024 },
      (err, stdout) => ok(err ? '' : String(stdout ?? '')));
  });
}
