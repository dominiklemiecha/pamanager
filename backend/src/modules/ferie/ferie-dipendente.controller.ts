import { Body, Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Req, Res, UploadedFiles, UseInterceptors } from '@nestjs/common';
import { FileFieldsInterceptor } from '@nestjs/platform-express';
import type { Response } from 'express';
import { memoryStorage } from 'multer';
import { festivitaAnno } from '../../common/calcoli/festivita';
import { oggiRoma } from '../../common/calcoli/php';
import type { FileCaricato } from '../../common/file/caricamenti';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { SaldiService } from '../saldi/saldi.service';
import { FerieService, TIPI } from './ferie.service';

const FILE = FileFieldsInterceptor([{ name: 'attachment', maxCount: 1 }, { name: 'certificate', maxCount: 1 }], {
  storage: memoryStorage(),
  // Un po' oltre i 10 MB: il messaggio "troppo grande" lo da' la validazione, come nel PHP.
  limits: { fileSize: 11 * 1024 * 1024, files: 2, fields: 20 },
});

type File = { attachment?: FileCaricato[]; certificate?: FileCaricato[] };
const ctx = (req: RichiestaAutenticata, u: UtenteCorrente) => ({ ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId, tipo: 'employee', id: u.id });

/** Ferie e permessi dal lato del dipendente (employee/leave-requests.php). */
@Controller('dipendente/richieste')
@Ruoli('employee')
export class FerieDipendenteController {
  constructor(private readonly ferie: FerieService, private readonly saldi: SaldiService) {}

  @Get()
  async elenco(@Utente() u: UtenteCorrente) {
    const anno = Number(oggiRoma().slice(0, 4));
    const s = await this.saldi.perDipendente(u.id, anno);
    return {
      richieste: await this.ferie.delDipendente(u.id),
      residui: { ferie: s.ferie.residual, permesso: s.permesso.residual },
      malattieSenzaDocumenti: (await this.ferie.malattieSenzaDocumenti(u.companyId, 24, u.id)).length,
      festivita: [...festivitaAnno(anno).keys(), ...festivitaAnno(anno + 1).keys()],
    };
  }

  @Post()
  @HttpCode(200)
  @UseInterceptors(FILE)
  async crea(@Body() b: Record<string, string>, @UploadedFiles() f: File, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const tipo = String(b.leave_type ?? '');
    const motivo = String(b.reason ?? '');
    const esito = await this.ferie.crea({
      employee_id: u.id,
      leave_type: tipo,
      start_date: String(b.start_date ?? ''),
      end_date: String(b.end_date ?? ''),
      is_full_day: String(b.is_full_day ?? '1') === '1',
      start_time: b.start_time || null,
      end_time: b.end_time || null,
      // Nell'interfaccia Cora la motivazione e' facoltativa: vale il nome del tipo.
      reason: motivo.trim() === '' ? (TIPI[tipo] ?? 'Richiesta') : motivo,
      notes: b.notes ?? '',
      protocol_number: tipo === 'malattia' ? (b.protocol_number ?? '') : null,
    }, ctx(req, u));
    if (!esito.success) throw new HttpException({ statusCode: 400, message: esito.error }, 400);
    if (tipo !== 'malattia' && f?.attachment?.[0]) await this.ferie.allega(esito.id!, f.attachment[0]);
    if (tipo === 'malattia' && f?.certificate?.[0]) await this.ferie.documentiMalattia(esito.id!, u.id, null, f.certificate[0], ctx(req, u));
    return { ok: true, id: esito.id };
  }

  @Post(':id/annulla')
  @HttpCode(200)
  async annulla(@Param('id', ParseIntPipe) id: number, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const esito = await this.ferie.annulla(id, u.id, ctx(req, u));
    if (!esito.success) throw new HttpException({ statusCode: 400, message: esito.error }, 400);
    return { ok: true };
  }

  @Post(':id/documenti')
  @HttpCode(200)
  @UseInterceptors(FILE)
  async documenti(@Param('id', ParseIntPipe) id: number, @Body() b: Record<string, string>, @UploadedFiles() f: File, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const esito = await this.ferie.documentiMalattia(id, u.id, b.protocol_number ?? null, f?.certificate?.[0] ?? null, ctx(req, u));
    if (!esito.success) throw new HttpException({ statusCode: 400, message: esito.error }, 400);
    return { ok: true };
  }

  /** Certificato medico della propria richiesta (?download_cert= del PHP). */
  @Get(':id/certificato')
  async certificato(@Param('id', ParseIntPipe) id: number, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const r = await this.ferie.file(id, 'certificate', u, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId });
    if (!r.ok) { res.status(403).type('text/plain').send(r.error); return; }
    res.download(r.percorso, r.nome.replace(/[^a-zA-Z0-9._-]/g, '_'), { headers: { 'Content-Type': r.mime, 'Cache-Control': 'private, no-store' } });
  }
}
