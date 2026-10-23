// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'notifications' })
export class Notifications {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'recipient_type', type: 'varchar', length: 17 })
  recipientType!: string;

  @Column({ name: 'recipient_id', type: 'integer' })
  recipientId!: number;

  @Column({ name: 'type', type: 'citext' })
  type!: string;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'message', type: 'text' })
  message!: string;

  @Column({ name: 'link', type: 'citext', nullable: true })
  link!: string | null;

  @Column({ name: 'is_read', type: 'boolean', nullable: true })
  isRead!: boolean | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
