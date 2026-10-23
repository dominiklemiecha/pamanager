import { Body, Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Query, Req, Res } from '@nestjs/common';
import type { Response } from 'express';
import * as path from 'path';
import { fileSicuro } from '../../common/file/caricamenti';
import { AuditService } from '../audit/audit.service';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { AcademyService, Attore, DICHIARAZIONE, ETICHETTE_STATO } from './academy.service';

const attore = (req: RichiestaAutenticata, u: UtenteCorrente): Attore => ({
  tipo: u.tipo === 'employee' ? 'employee' : u.ruolo, id: u.id, ip: req.ip ?? null, userAgent: String(req.headers['user-agent'] ?? ''),
});
/** Errori del completamento come codici (il PHP metteva il testo nell'indirizzo). */
const CODICI: Record<string, string> = {
  'Per completare devi spuntare la dichiarazione': 'dichiarazione',
  'Apri tutti i materiali del corso prima di completarlo': 'materiali',
};

/** Academy del dipendente (employee/academy.php). */
@Controller('dipendente/academy')
@Ruoli('employee')
export class AcademyDipendenteController {
  constructor(private readonly academy: AcademyService) {}

  @Get()
  async pagina(@Query('course') corso: string, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    await this.academy.promemoriaScadenza(u.companyId!, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? '') });
    const id = Number.parseInt(String(corso ?? ''), 10) || 0;
    if (id > 0) {
      const a = await this.academy.assegnazione(u.id, id, u.companyId!);
      if (!a) throw new HttpException({ statusCode: 404, message: 'Corso non trovato' }, 404);
      const c = await this.academy.corso(id, u.companyId!);
      const materiali = await this.academy.materiali(id, u.companyId!);
      return {
        corso: c, assegnazione: a, materiali, aperti: await this.academy.aperti(a.id),
        stato: this.academy.statoDi(a, c?.due_date ?? null), etichette: ETICHETTE_STATO, dichiarazione: DICHIARAZIONE,
      };
    }
    const corsi = await this.academy.corsiDelDipendente(u.id, u.companyId!);
    return { corsi: corsi.map((c: any) => ({ ...c, stato: this.academy.statoDi(c, c.due_date) })), etichette: ETICHETTE_STATO };
  }

  @Post(':corso/avvia')
  @HttpCode(200)
  async avvia(@Param('corso', ParseIntPipe) corso: number, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const a = await this.academy.assegnazione(u.id, corso, u.companyId!);
    if (!a) throw new HttpException({ statusCode: 404, message: 'Corso non trovato' }, 404);
    await this.academy.avvia({ ...a, company_id: u.companyId }, attore(req, u));
    return { ok: true };
  }

  @Post(':corso/completa')
  @HttpCode(200)
  async completa(@Param('corso', ParseIntPipe) corso: number, @Body() b: Record<string, unknown>, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const a = await this.academy.assegnazione(u.id, corso, u.companyId!);
    if (!a) throw new HttpException({ statusCode: 404, message: 'Corso non trovato' }, 404);
    const r = await this.academy.completa(a, !!b?.declaration, attore(req, u));
    return r.success ? { ok: true } : { ok: false, errore: CODICI[r.error!] ?? 'altro' };
  }
}

/**
 * File dell'Academy allo stesso indirizzo del PHP (academy-file.php):
 * materiali (con registrazione dell'apertura per il dipendente) e attestati.
 * Le richieste Range per i video le gestisce sendFile.
 */
@Controller()
@Ruoli('employee', 'admin', 'consulente_lavoro', 'admin_reparto', 'formatore')
export class AcademyFileController {
  constructor(private readonly academy: AcademyService, private readonly audit: AuditService) {}

  private invia(res: Response, percorso: string, mime: string, nome: string, inLinea: boolean) {
    res.set({
      'X-Content-Type-Options': 'nosniff',
      'X-Frame-Options': 'SAMEORIGIN',
      // Niente sandbox: il visualizzatore PDF di Chrome non partirebbe (come nel PHP).
      'Content-Security-Policy': "frame-ancestors 'self'",
      'Cache-Control': 'private, no-store',
      'Content-Type': mime,
      'Content-Disposition': `${inLinea ? 'inline' : 'attachment'}; filename="${nome.replace(/[^A-Za-z0-9._-]/g, '_')}"`,
    });
    res.sendFile(percorso, { acceptRanges: true, cacheControl: false, lastModified: false, etag: false });
  }

  @Get('academy-file.php')
  async file(@Query('m') m: string, @Query('cert') cert: string, @Query('dl') dl: string, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const dipendente = u.tipo === 'employee';
    const testo = (s: number, t: string) => { res.status(s).type('text/plain').send(t); };

    if (cert) {
      const id = Number.parseInt(cert, 10) || 0;
      const r = dipendente
        ? await this.academy.attestatoDipendente(id, u.id, u.companyId!, attore(req, u))
        : await this.academy.attestatoStaff(id, u.ruolo, u.departmentId ?? null, u.companyId!);
      if (!r.ok) { testo(403, r.errore); return; }
      this.invia(res, r.percorso, 'application/pdf', r.nome, !dl);
      return;
    }

    if (m) {
      const mat = await this.academy.materiale(Number.parseInt(m, 10) || 0, u.companyId!);
      if (!mat) { testo(404, 'Materiale non trovato'); return; }
      if (dipendente) {
        const a = await this.academy.assegnazione(u.id, mat.course_id, u.companyId!);
        if (!a) {
          await this.audit.log('unauthorized_access', 'employee', u.id, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId },
            null, null, null, { resource: 'academy_material', material_id: mat.id, employee_id: u.id }, 'critical');
          testo(403, 'Accesso non autorizzato');
          return;
        }
        // Le richieste successive di un video (Range oltre l'inizio) non sono nuove aperture.
        const range = String(req.headers.range ?? '');
        if (!range || /bytes=0-/.test(range)) await this.academy.aperturaMateriale({ ...a, company_id: u.companyId }, mat, attore(req, u));
      }
      if (mat.kind === 'link') {
        // Solo indirizzi web: un link salvato male non diventa un javascript: o simili.
        if (!/^https?:\/\//i.test(String(mat.url ?? ''))) { testo(404, 'Link non valido'); return; }
        res.set('Referrer-Policy', 'no-referrer').redirect(302, mat.url);
        return;
      }
      const percorso = fileSicuro(mat.file_path);
      if (!percorso) { testo(404, 'File non trovato sul server'); return; }
      const mime = mat.mime_type || 'application/octet-stream';
      this.invia(res, percorso, mime, mat.original_name || path.basename(percorso), !dl && this.academy.inLinea(mime));
      return;
    }
    testo(400, 'Richiesta non valida');
  }
}
