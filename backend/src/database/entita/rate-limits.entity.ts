// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'rate_limits' })
export class RateLimits {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'identifier', type: 'citext' })
  identifier!: string;

  @Column({ name: 'action', type: 'citext' })
  action!: string;

  @Column({ name: 'attempts', type: 'integer', nullable: true })
  attempts!: number | null;

  @Column({ name: 'window_start', type: 'timestamptz', nullable: true })
  windowStart!: Date | null;
}
