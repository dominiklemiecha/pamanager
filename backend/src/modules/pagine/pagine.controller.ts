import { Body, Controller, ForbiddenException, Get, HttpCode, NotFoundException, Param, Post, Query, Req, Res, UploadedFiles, UseInterceptors } from '@nestjs/common';
import { ModuleRef } from '@nestjs/core';
import { AnyFilesInterceptor } from '@nestjs/platform-express';
import type { Response } from 'express';
import { memoryStorage } from 'multer';
import type { FileCaricato } from '../../common/file/caricamenti';
import { AuditService } from '../audit/audit.service';
import { Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { normalizza } from './normalizza';
import { classiPagine, ContestoPagina, EsitoPagina, Pagina } from './pagina';

/**
 * Pagine staff portate dal PHP: GET = variabili del template (o download),
 * POST = ramo POST della pagina (redirect o pagina ridisegnata).
 * Il ruolo si controlla qui per ogni pagina, prima di qualsiasi lettura.
 */
@Controller('pagine')
export class PagineController {
  constructor(private readonly moduli: ModuleRef, private readonly audit: AuditService) {}

  private async pagina(area: string, nome: string, u: UtenteCorrente, req: RichiestaAutenticata): Promise<Pagina> {
    const classe = classiPagine().get(`${area}/${nome}`);
    if (!classe) throw new NotFoundException({ statusCode: 404, message: 'Pagina non trovata' });
    const p = this.moduli.get(classe, { strict: false });
    const ruolo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    if (!p.ruoli.includes(ruolo)) {
      await this.audit.log('unauthorized_access', ruolo, u.id, this.contesto(u, req, {}).audit, null, null, null,
        { resource: `/${area}/${nome}.php`, required_role: p.ruoli.length === 1 ? p.ruoli[0] : p.ruoli, actual_role: ruolo }, 'critical');
      throw new ForbiddenException({ statusCode: 403, message: 'Accesso non autorizzato' });
    }
    return p;
  }

  private contesto(u: UtenteCorrente, req: RichiestaAutenticata, query: Record<string, string>): ContestoPagina {
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    return { u, req, query, audit: { ip: req.ip ?? null, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId, tipo, id: u.id } };
  }

  private risposta(e: EsitoPagina) {
    return 'redirect' in e ? e : { titolo: e.titolo, V: normalizza(e.V) };
  }

  @Get(':area/:nome')
  async leggi(@Param('area') area: string, @Param('nome') nome: string, @Query() query: Record<string, string>,
    @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const p = await this.pagina(area, nome, u, req);
    const c = this.contesto(u, req, query);
    if (p.file && (await p.file(c, res))) return;
    res.json(this.risposta(await p.dati(c)));
  }

  @Post(':area/:nome')
  @HttpCode(200)
  @UseInterceptors(AnyFilesInterceptor({ storage: memoryStorage(), limits: { fileSize: 25 * 1024 * 1024, files: 60, fields: 400 } }))
  async scrivi(@Param('area') area: string, @Param('nome') nome: string, @Query() query: Record<string, string>, @Body() corpo: Record<string, string>,
    @UploadedFiles() file: FileCaricato[] & { fieldname?: string }[], @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const p = await this.pagina(area, nome, u, req);
    if (!p.azione) throw new NotFoundException({ statusCode: 404, message: 'Azione non disponibile' });
    const perCampo: Record<string, FileCaricato[]> = {};
    for (const f of (file ?? []) as any[]) (perCampo[f.fieldname] ??= []).push(f);
    return this.risposta(await p.azione(this.contesto(u, req, query), corpo ?? {}, perCampo));
  }
}
