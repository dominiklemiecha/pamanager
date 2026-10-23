import { Module } from '@nestjs/common';
import { DipendenteModule } from '../dipendente/dipendente.module';
import { AllegatiComunicazioniController, ComunicazioniDipendenteController } from './comunicazioni.controller';
import { ComunicazioniService } from './comunicazioni.service';

@Module({ imports: [DipendenteModule], controllers: [ComunicazioniDipendenteController, AllegatiComunicazioniController], providers: [ComunicazioniService] })
export class ComunicazioniModule {}
