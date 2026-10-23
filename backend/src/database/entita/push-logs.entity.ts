// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'push_logs' })
export class PushLogs {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'subscription_id', type: 'integer', nullable: true })
  subscriptionId!: number | null;

  @Column({ name: 'notification_id', type: 'integer', nullable: true })
  notificationId!: number | null;

  @Column({ name: 'status', type: 'varchar', length: 7 })
  status!: string;

  @Column({ name: 'error_message', type: 'text', nullable: true })
  errorMessage!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
