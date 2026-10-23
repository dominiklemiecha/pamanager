import { Module } from '@nestjs/common';
import { DipendenteModule } from '../dipendente/dipendente.module';
import { CompatController } from './compat.controller';

@Module({ imports: [DipendenteModule], controllers: [CompatController] })
export class CompatModule {}

/** Percorsi /api/*.php fuori dal prefisso api/v1 (stessi indirizzi del PHP). */
export const PERCORSI_COMPAT = ['uploads/co-:azienda/profile_photos/:nome', 'uploads/users/:nome', 'communication_file.php', 'academy-file.php', 'employee/contract-pdf.php', 'api/assistenza-sessione.php', 'api/employee-tour.php', 'api/availability.php', 'api/notifications-read.php', 'api/push.php'];
