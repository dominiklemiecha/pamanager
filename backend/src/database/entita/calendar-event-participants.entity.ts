// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'calendar_event_participants' })
export class CalendarEventParticipants {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'event_id', type: 'integer' })
  eventId!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 17 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'status', type: 'varchar', length: 8 })
  status!: string;

  @Column({ name: 'responded_at', type: 'timestamptz', nullable: true })
  respondedAt!: Date | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;
}
