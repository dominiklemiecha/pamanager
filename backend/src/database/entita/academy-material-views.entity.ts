// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'academy_material_views' })
export class AcademyMaterialViews {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'assignment_id', type: 'integer' })
  assignmentId!: number;

  @Column({ name: 'material_id', type: 'integer' })
  materialId!: number;

  @Column({ name: 'first_opened_at', type: 'timestamptz' })
  firstOpenedAt!: Date;

  @Column({ name: 'last_opened_at', type: 'timestamptz' })
  lastOpenedAt!: Date;

  @Column({ name: 'open_count', type: 'integer' })
  openCount!: number;
}
