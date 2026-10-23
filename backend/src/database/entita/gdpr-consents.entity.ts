// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'gdpr_consents' })
export class GdprConsents {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 10 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'consent_type', type: 'citext' })
  consentType!: string;

  @Column({ name: 'consent_given', type: 'boolean' })
  consentGiven!: boolean;

  @Column({ name: 'consent_text', type: 'text', nullable: true })
  consentText!: string | null;

  @Column({ name: 'consent_version', type: 'citext', nullable: true })
  consentVersion!: string | null;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'user_agent', type: 'text', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'given_at', type: 'timestamptz', nullable: true })
  givenAt!: Date | null;

  @Column({ name: 'revoked_at', type: 'timestamptz', nullable: true })
  revokedAt!: Date | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
