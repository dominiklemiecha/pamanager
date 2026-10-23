import { Body, Controller, ForbiddenException, Get, HttpCode, Injectable, NotFoundException, Param, Post, Query, Req, Res, UploadedFiles, UseInterceptors } from '@nestjs/common';
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

const CARICAMENTI = AnyFilesInterceptor({ storage: memoryStorage(), limits: { fileSize: 25 * 1024 * 1024, files: 60, fields: 400 } });

/** Aree staff con pagine portate dal PHP (stessi indirizzi). */
export const AREE_STAFF = ['admin', 'admin-reparto', 'accountant', 'consulente-lavoro', 'formatore'];

/** Ricerca della pagina, controllo del ruolo e contesto: comune alle due vie d'accesso. */
@Injectable()
export class MotorePagine {
  constructor(private readonly moduli: ModuleRef, private readonly audit: AuditService) {}

  async pagina(area: string, nome: string, u: UtenteCorrente, req: RichiestaAutenticata): Promise<Pagina> {
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

  contesto(u: UtenteCorrente, req: RichiestaAutenticata, query: Record<string, string>): ContestoPagina {
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    return { u, req, query, audit: { ip: req.ip ?? null, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId, tipo, id: u.id } };
  }

  risposta(e: EsitoPagina) {
    if ('json' in e) return e;
    return 'redirect' in e ? e : { titolo: e.titolo, V: normalizza(e.V) };
  }

  perCampo(file: Array<FileCaricato & { fieldname?: string }> | undefined) {
    const r: Record<string, FileCaricato[]> = {};
    for (const f of file ?? []) (r[f.fieldname ?? ''] ??= []).push(f);
    return r;
  }
}

/**
 * Pagine staff portate dal PHP: GET = variabili del template (o download),
 * POST = ramo POST della pagina (redirect o pagina ridisegnata).
 * Il ruolo si controlla qui per ogni pagina, prima di qualsiasi lettura.
 */
@Controller('pagine')
export class PagineController {
  constructor(private readonly motore: MotorePagine) {}

  @Get(':area/:nome')
  async leggi(@Param('area') area: string, @Param('nome') nome: string, @Query() query: Record<string, string>,
    @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const p = await this.motore.pagina(area, nome, u, req);
    const c = this.motore.contesto(u, req, query);
    if (p.file && (await p.file(c, res))) return;
    res.json(this.motore.risposta(await p.dati(c)));
  }

  @Post(':area/:nome')
  @HttpCode(200)
  @UseInterceptors(CARICAMENTI)
  async scrivi(@Param('area') area: string, @Param('nome') nome: string, @Query() query: Record<string, string>, @Body() corpo: Record<string, string>,
    @UploadedFiles() file: Array<FileCaricato & { fieldname?: string }>, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const p = await this.motore.pagina(area, nome, u, req);
    if (!p.azione) throw new NotFoundException({ statusCode: 404, message: 'Azione non disponibile' });
    const e = await p.azione(this.motore.contesto(u, req, query), corpo ?? {}, this.motore.perCampo(file));
    return 'json' in e ? e.json : this.motore.risposta(e);
  }
}

/**
 * Stesse pagine agli indirizzi del PHP (/admin/pagina.php), per le chiamate
 * AJAX degli script delle pagine (POST o GET non di navigazione): la
 * risposta e' quella del PHP (JSON, redirect o file).
 */
@Controller()
export class PagineLegacyController {
  constructor(private readonly motore: MotorePagine) {}

  private nome(file: string) {
    if (!/^[a-z0-9_-]+\.php$/.test(file)) throw new NotFoundException({ statusCode: 404, message: 'Pagina non trovata' });
    return file.slice(0, -4);
  }

  private invia(res: Response, e: EsitoPagina) {
    if ('json' in e) { res.status(e.status ?? 200).json(e.json); return; }
    if ('redirect' in e) { res.redirect(302, e.redirect); return; }
    res.json(this.motore.risposta(e));
  }

  @Get(AREE_STAFF.map((a) => `${a}/:file`))
  async leggi(@Param('file') file: string, @Query() query: Record<string, string>, @Req() req: RichiestaAutenticata, @Res() res: Response,
    @Utente() u: UtenteCorrente) {
    const area = req.path.split('/')[1];
    const p = await this.motore.pagina(area, this.nome(file), u, req);
    const c = this.motore.contesto(u, req, query);
    if (p.file && (await p.file(c, res))) return;
    this.invia(res, await p.dati(c));
  }

  @Post(AREE_STAFF.map((a) => `${a}/:file`))
  @UseInterceptors(CARICAMENTI)
  async scrivi(@Param('file') file: string, @Query() query: Record<string, string>, @Body() corpo: Record<string, string>,
    @UploadedFiles() caricati: Array<FileCaricato & { fieldname?: string }>, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const area = req.path.split('/')[1];
    const p = await this.motore.pagina(area, this.nome(file), u, req);
    if (!p.azione) throw new NotFoundException({ statusCode: 404, message: 'Azione non disponibile' });
    this.invia(res, await p.azione(this.motore.contesto(u, req, query), corpo ?? {}, this.motore.perCampo(caricati)));
  }
}
