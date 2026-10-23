// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'password_reset_requests' })
export class PasswordResetRequests {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 17 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'status', type: 'varchar', length: 9, nullable: true })
  status!: string | null;

  @Column({ name: 'email_sent', type: 'boolean', nullable: true })
  emailSent!: boolean | null;

  @Column({ name: 'token_id', type: 'integer', nullable: true })
  tokenId!: number | null;

  @Column({ name: 'requested_ip', type: 'citext' })
  requestedIp!: string;

  @Column({ name: 'resolved_by', type: 'integer', nullable: true })
  resolvedBy!: number | null;

  @Column({ name: 'resolved_at', type: 'timestamptz', nullable: true })
  resolvedAt!: Date | null;

  @Column({ name: 'notes', type: 'text', nullable: true })
  notes!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
