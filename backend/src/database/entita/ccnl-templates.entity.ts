// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'ccnl_templates' })
export class CcnlTemplates {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer', nullable: true })
  companyId!: number | null;

  @Column({ name: 'code', type: 'citext' })
  code!: string;

  @Column({ name: 'name', type: 'citext' })
  name!: string;

  @Column({ name: 'ferie_days_year', type: 'numeric', precision: 5, scale: 2, transformer: numero })
  ferieDaysYear!: number;

  @Column({ name: 'permessi_hours_year', type: 'numeric', precision: 6, scale: 2, transformer: numero })
  permessiHoursYear!: number;

  @Column({ name: 'notes', type: 'citext', nullable: true })
  notes!: string | null;

  @Column({ name: 'is_system', type: 'boolean' })
  isSystem!: boolean;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'updated_at', type: 'timestamptz' })
  updatedAt!: Date;
}
