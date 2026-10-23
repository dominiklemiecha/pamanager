// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity({ name: 'user_companies' })
export class UserCompanies {
  @PrimaryColumn({ name: 'user_id', type: 'integer' })
  userId!: number;

  @PrimaryColumn({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
