import { Module } from '@nestjs/common';
import { DocumentiModule } from '../documenti/documenti.module';
import { WrikeModule } from '../wrike/wrike.module';
import { ContrattoController, ContrattoPdfController } from './contratto.controller';
import { ContrattoService } from './contratto.service';

@Module({ imports: [DocumentiModule, WrikeModule], controllers: [ContrattoController, ContrattoPdfController], providers: [ContrattoService] })
export class ContrattoModule {}
