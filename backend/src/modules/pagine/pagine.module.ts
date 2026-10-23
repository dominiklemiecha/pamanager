import { Module } from '@nestjs/common';
import { AcademyModule } from '../academy/academy.module';
import { FerieModule } from '../ferie/ferie.module';
import { ProvaModule } from '../prova/prova.module';
import { DashboardAdminPagina } from './admin/dashboard.pagina';
import { DecisioneProvaPagina } from './admin/decisione-prova.pagina';
import { BuoniPastoPagina, OrarioLavorativoPagina, PortaImpostazioniPagina, PortaLogPagina, SmtpPagina, TimbratureImpostazioniPagina } from './admin/configurazione.pagina';
import { FerieAdminPagina } from './admin/ferie.pagina';
import { PagineController } from './pagine.controller';
import { HeatmapService } from './widget/heatmap.service';

/** Pagine staff portate dal PHP (vedi pagina.ts). */
@Module({
  imports: [FerieModule, ProvaModule, AcademyModule],
  controllers: [PagineController],
  providers: [HeatmapService, DashboardAdminPagina, DecisioneProvaPagina, FerieAdminPagina,
    OrarioLavorativoPagina, TimbratureImpostazioniPagina, BuoniPastoPagina, PortaImpostazioniPagina, PortaLogPagina, SmtpPagina],
})
export class PagineModule {}
