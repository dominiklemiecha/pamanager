import { Module } from '@nestjs/common';
import { DipendenteModule } from '../dipendente/dipendente.module';
import { AllegatiComunicazioniController, ComunicazioniDipendenteController } from './comunicazioni.controller';
import { ComunicazioniAdminService } from './comunicazioni-admin.service';
import { ComunicazioniService } from './comunicazioni.service';

@Module({ imports: [DipendenteModule], controllers: [ComunicazioniDipendenteController, AllegatiComunicazioniController], providers: [ComunicazioniService, ComunicazioniAdminService], exports: [ComunicazioniAdminService] })
export class ComunicazioniModule {}
