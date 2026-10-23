// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'door_access_log' })
export class DoorAccessLog {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer', nullable: true })
  employeeId!: number | null;

  @Column({ name: 'nfc_uid', type: 'citext' })
  nfcUid!: string;

  @Column({ name: 'granted', type: 'boolean' })
  granted!: boolean;

  @Column({ name: 'reason', type: 'citext', nullable: true })
  reason!: string | null;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'user_agent', type: 'citext', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
