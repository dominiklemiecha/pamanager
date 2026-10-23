// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'csrf_tokens' })
export class CsrfTokens {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'token', type: 'citext' })
  token!: string;

  @Column({ name: 'session_id', type: 'citext' })
  sessionId!: string;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'expires_at', type: 'timestamptz' })
  expiresAt!: Date;
}
