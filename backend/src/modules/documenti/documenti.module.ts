import { Module } from '@nestjs/common';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { DocumentiDipendenteController } from './documenti.controller';
import { DocumentiService } from './documenti.service';

@Module({ controllers: [DocumentiDipendenteController], providers: [DocumentiService, LimitiDbService], exports: [DocumentiService] })
export class DocumentiModule {}
