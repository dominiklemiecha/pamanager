// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'communication_reads' })
export class CommunicationReads {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'communication_id', type: 'integer' })
  communicationId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'read_at', type: 'timestamptz', nullable: true })
  readAt!: Date | null;
}
