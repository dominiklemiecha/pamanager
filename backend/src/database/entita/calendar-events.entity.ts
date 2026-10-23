// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'calendar_events' })
export class CalendarEvents {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'owner_type', type: 'varchar', length: 17 })
  ownerType!: string;

  @Column({ name: 'owner_id', type: 'integer' })
  ownerId!: number;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'description', type: 'text', nullable: true })
  description!: string | null;

  @Column({ name: 'location', type: 'citext', nullable: true })
  location!: string | null;

  @Column({ name: 'start_at', type: 'timestamptz' })
  startAt!: Date;

  @Column({ name: 'end_at', type: 'timestamptz' })
  endAt!: Date;

  @Column({ name: 'color', type: 'citext', nullable: true })
  color!: string | null;

  @Column({ name: 'all_day', type: 'boolean' })
  allDay!: boolean;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'updated_at', type: 'timestamptz' })
  updatedAt!: Date;
}
