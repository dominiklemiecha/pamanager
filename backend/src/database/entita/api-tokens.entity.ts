// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'api_tokens' })
export class ApiTokens {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'token_hash', type: 'citext' })
  tokenHash!: string;

  @Column({ name: 'user_type', type: 'varchar', length: 10 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'expires_at', type: 'timestamptz' })
  expiresAt!: Date;

  @Column({ name: 'revoked_at', type: 'timestamptz', nullable: true })
  revokedAt!: Date | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
