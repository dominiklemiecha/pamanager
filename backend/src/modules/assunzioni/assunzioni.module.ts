import { Module } from '@nestjs/common';
import { AnagraficaModule } from '../anagrafica/anagrafica.module';
import { DocumentiModule } from '../documenti/documenti.module';
import { WrikeModule } from '../wrike/wrike.module';
import { AssunzioniService } from './assunzioni.service';

@Module({ imports: [AnagraficaModule, DocumentiModule, WrikeModule], providers: [AssunzioniService], exports: [AssunzioniService] })
export class AssunzioniModule {}
