import { Body, Controller, Get, HttpCode, Post, Query, UseInterceptors } from '@nestjs/common';
import { NoFilesInterceptor } from '@nestjs/platform-express';
import { nomeFestivita } from '../../common/calcoli/festivita';
import { oggiRoma } from '../../common/calcoli/php';
import { Ruoli, Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';
import { ContattiService } from '../contatti/contatti.service';
import { CalendarioService } from './calendario.service';

const MESI = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const giorno = (ymd: string, delta: number) => {
  const d = new Date(`${ymd}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + delta);
  return d.toISOString().slice(0, 10);
};
const valida = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(`${v}T12:00:00Z`)) && new Date(`${v}T12:00:00Z`).toISOString().slice(0, 10) === v;

/**
 * Calendario condiviso (_calendar.inc.php): dati della vista giorno/settimana
 * e azioni dello script della pagina (stesse risposte JSON del PHP).
 */
@Controller('calendario')
@Ruoli('employee', 'admin', 'consulente_lavoro')
export class CalendarioController {
  constructor(private readonly calendario: CalendarioService, private readonly contatti: ContattiService) {}

  @Get()
  async vista(@Query('view') vista: string, @Query('d') d: string, @Utente() u: UtenteCorrente) {
    const settimana = vista !== 'day';
    const oggi = oggiRoma();
    // Il PHP rimandava nella pagina il parametro d cosi' com'era (anche non valido).
    const rif = d && valida(String(d)) ? String(d) : oggi;
    let giorni: string[];
    if (!settimana) giorni = [rif];
    else {
      const dow = new Date(`${rif}T12:00:00Z`).getUTCDay() || 7;
      const lun = giorno(rif, -(dow - 1));
      giorni = Array.from({ length: 7 }, (_, i) => giorno(lun, i));
    }
    const [primo, ultimo] = [giorni[0], giorni[giorni.length - 1]];
    const eventi = await this.calendario.nellIntervallo(u, `${primo} 00:00:00`, `${ultimo} 23:59:59`);
    for (const ev of eventi) {
      ev.participants = await this.calendario.partecipanti(ev.id);
      ev.owner = { name: await this.calendario.nome(ev.owner_type, ev.owner_id), photo: await this.calendario.foto(ev.owner_type, ev.owner_id) };
      ev.can_drag = this.calendario.puoGestire(ev, u);
      delete ev.ordine;
    }
    const [a, b] = [new Date(`${primo}T12:00:00Z`), new Date(`${ultimo}T12:00:00Z`)];
    const etichetta = settimana
      ? `${a.getUTCDate()} ${MESI[a.getUTCMonth() + 1]} – ${b.getUTCDate()} ${MESI[b.getUTCMonth() + 1]}`
      : `${a.getUTCDate()} ${MESI[a.getUTCMonth() + 1]}`;
    const festivita: Record<string, string> = {};
    for (const g of giorni) { const n = nomeFestivita(g); if (n) festivita[g] = n; }
    return {
      vista: settimana ? 'week' : 'day', rif, oggi, giorni, festivita, etichetta,
      prima: giorno(rif, settimana ? -7 : -1), dopo: giorno(rif, settimana ? 7 : 1),
      eventi, contatti: await this.contatti.perUtente(u),
      chiamante: { tipo: u.tipo === 'employee' ? 'employee' : u.ruolo, id: u.id },
    };
  }

  /** Azioni dello script del calendario (POST alla pagina nel PHP). */
  @Post('azione')
  @HttpCode(200)
  @UseInterceptors(NoFilesInterceptor())
  async azione(@Body() b: Record<string, string>, @Utente() u: UtenteCorrente) {
    const id = Number.parseInt(String(b.event_id ?? ''), 10) || 0;
    switch (b.action) {
      case 'create_event': return this.calendario.crea(u, b);
      case 'update_event': return this.calendario.aggiorna(id, u, b, false);
      case 'move_event': return this.calendario.aggiorna(id, u, b, true);
      case 'get_event': return this.calendario.dettaglio(id, u);
      case 'delete_event': return this.calendario.elimina(id, u);
      case 'respond_event': return this.calendario.rispondi(id, u, String(b.status ?? ''));
      case 'check_conflicts': return this.calendario.verificaConflitti(u, b);
      default: return { success: false, error: 'Azione non valida' };
    }
  }
}
