import { Module } from '@nestjs/common';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { DipendenteModule } from '../dipendente/dipendente.module';
import { AREE_STAFF } from '../pagine/pagine.controller';
import { CompatController } from './compat.controller';
import { CrmController } from './crm.controller';
import { EsterniController } from './esterni.controller';
import { AcademyModule } from '../academy/academy.module';
import { DocumentiModule } from '../documenti/documenti.module';
import { PresenzeModule } from '../presenze/presenze.module';

@Module({ imports: [DipendenteModule, AcademyModule, DocumentiModule, PresenzeModule], controllers: [CompatController, EsterniController, CrmController], providers: [LimitiDbService] })
export class CompatModule {}

/** Percorsi /api/*.php fuori dal prefisso api/v1 (stessi indirizzi del PHP). */
export const PERCORSI_COMPAT = ['uploads/co-:azienda/profile_photos/:nome', 'uploads/users/:nome', 'communication_file.php', 'academy-file.php', 'employee/contract-pdf.php', 'api/assistenza-sessione.php', 'api/employee-tour.php', 'api/availability.php', 'api/notifications-read.php', 'api/push.php', 'api/lookup.php', 'api/download.php', 'api/ui-click.php', 'api/push-log.php', 'api/door.php', 'api/auth.php', 'api/attendance.php', 'punch.php', 'verifica-attestato.php',
  // Pagine staff agli indirizzi del PHP (chiamate AJAX degli script delle pagine)
  ...AREE_STAFF.map((a) => `${a}/:file`)];
