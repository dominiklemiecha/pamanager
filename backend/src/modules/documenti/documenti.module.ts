import { Module } from '@nestjs/common';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { DocumentiDipendenteController } from './documenti.controller';
import { DocumentiPersonaliService } from './documenti-personali.service';
import { DocumentiService } from './documenti.service';

@Module({ controllers: [DocumentiDipendenteController], providers: [DocumentiService, DocumentiPersonaliService, LimitiDbService], exports: [DocumentiService, DocumentiPersonaliService] })
export class DocumentiModule {}
