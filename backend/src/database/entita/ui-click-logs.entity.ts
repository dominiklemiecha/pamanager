// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'ui_click_logs' })
export class UiClickLogs {
  @PrimaryGeneratedColumn({ name: 'id', type: 'bigint' })
  id!: string;

  @Column({ name: 'company_id', type: 'integer', nullable: true })
  companyId!: number | null;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'page', type: 'citext' })
  page!: string;

  @Column({ name: 'area', type: 'citext' })
  area!: string;

  @Column({ name: 'kind', type: 'citext' })
  kind!: string;

  @Column({ name: 'label', type: 'citext' })
  label!: string;

  @Column({ name: 'target', type: 'citext', nullable: true })
  target!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;
}
