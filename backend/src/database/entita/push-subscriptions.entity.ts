// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'push_subscriptions' })
export class PushSubscriptions {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 17 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'endpoint', type: 'citext' })
  endpoint!: string;

  @Column({ name: 'p256dh', type: 'citext' })
  p256dh!: string;

  @Column({ name: 'auth', type: 'citext' })
  auth!: string;

  @Column({ name: 'user_agent', type: 'citext', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
