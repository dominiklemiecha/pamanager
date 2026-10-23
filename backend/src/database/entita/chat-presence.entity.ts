// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity({ name: 'chat_presence' })
export class ChatPresence {
  @PrimaryColumn({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @PrimaryColumn({ name: 'user_type', type: 'citext' })
  userType!: string;

  @PrimaryColumn({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'last_seen_at', type: 'timestamptz', nullable: true })
  lastSeenAt!: Date | null;

  @Column({ name: 'last_active_at', type: 'timestamptz', nullable: true })
  lastActiveAt!: Date | null;

  @Column({ name: 'in_call_until', type: 'timestamptz', nullable: true })
  inCallUntil!: Date | null;

  @Column({ name: 'manual_status', type: 'varchar', length: 14, nullable: true })
  manualStatus!: string | null;
}
