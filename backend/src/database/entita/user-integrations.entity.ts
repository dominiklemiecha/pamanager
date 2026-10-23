// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'user_integrations' })
export class UserIntegrations {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'provider', type: 'citext' })
  provider!: string;

  @Column({ name: 'access_token', type: 'text', nullable: true })
  accessToken!: string | null;

  @Column({ name: 'config', type: 'text', nullable: true })
  config!: string | null;

  @Column({ name: 'is_active', type: 'boolean' })
  isActive!: boolean;

  @Column({ name: 'last_error', type: 'citext', nullable: true })
  lastError!: string | null;

  @Column({ name: 'last_success_at', type: 'timestamptz', nullable: true })
  lastSuccessAt!: Date | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'updated_at', type: 'timestamptz' })
  updatedAt!: Date;
}
