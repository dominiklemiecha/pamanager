import { Module } from '@nestjs/common';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { SuperadminController } from './superadmin.controller';
import { SuperadminService } from './superadmin.service';

/** Superadmin: gestione dei tenant e report d'uso, con accesso separato. */
@Module({ controllers: [SuperadminController], providers: [SuperadminService, LimitiDbService] })
export class SuperadminModule {}
