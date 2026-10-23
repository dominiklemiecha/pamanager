import { Injectable } from '@nestjs/common';
import { ProvaService } from '../../prova/prova.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect } from '../pagina';

/** Decisione sul periodo di prova dal widget della dashboard (admin/probation-decision.php, solo POST). */
@Injectable()
@PaginaPhp('admin/probation-decision')
export class DecisioneProvaPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly prova: ProvaService) {}

  async dati() {
    return redirect('/admin/index.php');
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const id = Number.parseInt(corpo.employee_id ?? '', 10) || 0;
    const decisione = String(corpo.decision ?? '');
    let esito = 'err';
    if (id > 0 && ['confirmed', 'not_confirmed'].includes(decisione)) {
      const r = await this.prova.decidi(id, decisione, c.u.id, c.u.companyId!);
      if (r.success) esito = decisione;
    }
    return redirect(`/admin/index.php?probation=${esito}`);
  }
}
