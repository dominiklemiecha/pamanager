import { Controller, Get, Param, Res } from '@nestjs/common';
import type { Response } from 'express';
import * as fs from 'fs';
import * as path from 'path';
import { cartellaUpload } from '../../config/app';
import { Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';

const NOME = /^[A-Za-z0-9._-]+\.(webp|jpe?g|png|gif)$/;
const MIME: Record<string, string> = { webp: 'image/webp', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif' };

function invia(res: Response, ...parti: string[]) {
  const radice = cartellaUpload();
  const p = path.resolve(radice, ...parti);
  if (!p.startsWith(radice + path.sep) || !fs.existsSync(p) || !fs.statSync(p).isFile()) { res.status(404).end(); return; }
  res.set({
    'Content-Type': MIME[path.extname(p).slice(1).toLowerCase()],
    'X-Content-Type-Options': 'nosniff',
    'Cache-Control': 'private, max-age=86400',
    'Content-Security-Policy': "default-src 'none'; sandbox",
  });
  res.sendFile(p);
}

/**
 * Foto profilo agli stessi indirizzi del PHP (/uploads/...).
 * Correzione: nel PHP erano pubbliche (chiunque con l'indirizzo); qui solo
 * per chi e' collegato, e le foto dei dipendenti solo nella stessa azienda.
 * Gli altri file sotto uploads (certificati, documenti di assunzione) non
 * sono mai serviti da qui.
 */
@Controller('uploads')
export class FotoController {
  @Get('co-:azienda/profile_photos/:nome')
  dipendente(@Param('azienda') azienda: string, @Param('nome') nome: string, @Res() res: Response, @Utente() u: UtenteCorrente) {
    if (!/^\d+$/.test(azienda) || Number(azienda) !== u.companyId || !NOME.test(nome)) { res.status(404).end(); return; }
    invia(res, `co-${azienda}`, 'profile_photos', nome);
  }

  @Get('users/:nome')
  staff(@Param('nome') nome: string, @Res() res: Response) {
    if (!NOME.test(nome)) { res.status(404).end(); return; }
    invia(res, 'users', nome);
  }
}
