import * as fs from 'fs';
import { PDFDocument, rgb, StandardFonts } from 'pdf-lib';

const MM = 72 / 25.4;

/**
 * Filigrana di tracciamento sui PDF scaricati, come Document::addPdfWatermark
 * del PHP (FPDI/TCPDF): una riga Helvetica 6 pt grigia (128,128,128) in basso
 * a sinistra su ogni pagina, a 5 mm dal bordo, con chi ha scaricato, quando,
 * da quale IP e il numero del documento.
 *
 * Il PHP ricostruiva il PDF da capo; qui si aggiunge la riga al file
 * originale: stesso testo e stessa posizione, il resto del documento intatto.
 * Se il PDF non si apre si restituisce null e si scarica l'originale (come il PHP).
 */
export async function pdfConFiligrana(percorso: string, testo: string): Promise<Buffer | null> {
  try {
    const pdf = await PDFDocument.load(fs.readFileSync(percorso), { ignoreEncryption: true });
    const font = await pdf.embedFont(StandardFonts.Helvetica);
    // Helvetica standard copre WinAnsi: i caratteri fuori set diventano "?".
    const sicuro = [...testo].map((c) => { try { font.encodeText(c); return c; } catch { return '?'; } }).join('');
    for (const pagina of pdf.getPages()) {
      // Cella TCPDF alta 3 mm che inizia a 5 mm dal fondo, testo centrato in verticale,
      // margine interno della cella di 1 mm.
      pagina.drawText(sicuro, { x: 6 * MM, y: 2.8 * MM, size: 6, font, color: rgb(128 / 255, 128 / 255, 128 / 255) });
    }
    return Buffer.from(await pdf.save());
  } catch {
    return null;
  }
}
