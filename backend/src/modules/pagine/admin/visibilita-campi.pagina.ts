import { Injectable } from '@nestjs/common';
import { VisibilitaCampiService } from '../../impostazioni/impostazioni.service';
import { ContestoPagina, PaginaPhp, Pagina, vista } from '../pagina';

/** Visibilita' dei campi anagrafici per ruolo (admin/field-visibility.php). */
@Injectable()
@PaginaPhp('admin/field-visibility')
export class VisibilitaCampiPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly visibilita: VisibilitaCampiService) {}

  async azione(c: ContestoPagina, corpo: Record<string, any>) {
    const v = (corpo.visibility && typeof corpo.visibility === 'object') ? corpo.visibility : {};
    await this.visibilita.salva(c.u.companyId!, v, c.u.id);
    return this.dati(c, true);
  }

  async dati(c: ContestoPagina, saved = false) {
    return vista('Visibilita campi anagrafici', { saved, error: null, config: await this.visibilita.configurazione(c.u.companyId!) });
  }
}
