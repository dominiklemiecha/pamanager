import { Module } from '@nestjs/common';
import { AcademyModule } from '../academy/academy.module';
import { AuthModule } from '../auth/auth.module';
import { ProfiloModule } from '../profilo/profilo.module';
import { UtentiModule } from '../utenti/utenti.module';
import { FerieModule } from '../ferie/ferie.module';
import { ProvaModule } from '../prova/prova.module';
import { DashboardAdminPagina } from './admin/dashboard.pagina';
import { DecisioneProvaPagina } from './admin/decisione-prova.pagina';
import { AziendePagina } from './admin/aziende.pagina';
import { BuoniPastoPagina, OrarioLavorativoPagina, PortaImpostazioniPagina, PortaLogPagina, SmtpPagina, TimbratureImpostazioniPagina } from './admin/configurazione.pagina';
import { FerieAdminPagina } from './admin/ferie.pagina';
import { PasswordResetPagina } from './admin/password-reset.pagina';
import { ProfiloStaffPagina } from './admin/profilo.pagina';
import { RepartiPagina, RepartiService, RepartoDettaglioPagina } from './admin/reparti.pagina';
import { PagineController } from './pagine.controller';
import { HeatmapService } from './widget/heatmap.service';

/** Pagine staff portate dal PHP (vedi pagina.ts). */
@Module({
  imports: [FerieModule, ProvaModule, AcademyModule, AuthModule, ProfiloModule, UtentiModule],
  controllers: [PagineController],
  providers: [HeatmapService, DashboardAdminPagina, DecisioneProvaPagina, FerieAdminPagina,
    OrarioLavorativoPagina, TimbratureImpostazioniPagina, BuoniPastoPagina, PortaImpostazioniPagina, PortaLogPagina, SmtpPagina, ProfiloStaffPagina, PasswordResetPagina,
    RepartiService, RepartiPagina, RepartoDettaglioPagina, AziendePagina],
})
export class PagineModule {}
