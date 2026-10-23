// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'gdpr_requests' })
export class GdprRequests {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 10 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'request_type', type: 'varchar', length: 13 })
  requestType!: string;

  @Column({ name: 'status', type: 'varchar', length: 11, nullable: true })
  status!: string | null;

  @Column({ name: 'request_details', type: 'text', nullable: true })
  requestDetails!: string | null;

  @Column({ name: 'response_details', type: 'text', nullable: true })
  responseDetails!: string | null;

  @Column({ name: 'handled_by', type: 'integer', nullable: true })
  handledBy!: number | null;

  @Column({ name: 'requested_at', type: 'timestamptz', nullable: true })
  requestedAt!: Date | null;

  @Column({ name: 'completed_at', type: 'timestamptz', nullable: true })
  completedAt!: Date | null;
}
