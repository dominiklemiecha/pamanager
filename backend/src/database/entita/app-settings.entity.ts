// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity({ name: 'app_settings' })
export class AppSettings {
  @PrimaryColumn({ name: 'setting_key', type: 'citext' })
  settingKey!: string;

  @Column({ name: 'setting_value', type: 'text', nullable: true })
  settingValue!: string | null;

  @Column({ name: 'is_encrypted', type: 'boolean' })
  isEncrypted!: boolean;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;

  @Column({ name: 'updated_by', type: 'integer', nullable: true })
  updatedBy!: number | null;

  @PrimaryColumn({ name: 'company_id', type: 'integer' })
  companyId!: number;
}
