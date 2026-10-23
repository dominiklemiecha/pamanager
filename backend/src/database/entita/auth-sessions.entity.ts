// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'auth_sessions' })
export class AuthSessions {
  @PrimaryGeneratedColumn('uuid', { name: 'id' })
  id!: string;

  @Column({ name: 'token_hash', type: 'char', length: 64 })
  tokenHash!: string;

  @Column({ name: 'subject_type', type: 'varchar', length: 8 })
  subjectType!: string;

  @Column({ name: 'subject_id', type: 'integer' })
  subjectId!: number;

  @Column({ name: 'role', type: 'varchar', length: 20 })
  role!: string;

  @Column({ name: 'selected_company_id', type: 'integer', nullable: true })
  selectedCompanyId!: number | null;

  @Column({ name: 'mfa_pending', type: 'boolean' })
  mfaPending!: boolean;

  @Column({ name: 'user_agent', type: 'text' })
  userAgent!: string;

  @Column({ name: 'ip_address', type: 'varchar', length: 45, nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'last_activity_at', type: 'timestamptz' })
  lastActivityAt!: Date;

  @Column({ name: 'expires_at', type: 'timestamptz' })
  expiresAt!: Date;

  @Column({ name: 'revoked_at', type: 'timestamptz', nullable: true })
  revokedAt!: Date | null;

  @Column({ name: 'revoked_reason', type: 'varchar', length: 40, nullable: true })
  revokedReason!: string | null;
}
