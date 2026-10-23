// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'academy_courses' })
export class AcademyCourses {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'description', type: 'text', nullable: true })
  description!: string | null;

  @Column({ name: 'duration_hours', type: 'numeric', precision: 6, scale: 2, transformer: numero, nullable: true })
  durationHours!: number | null;

  @Column({ name: 'due_date', type: 'date', nullable: true })
  dueDate!: string | null;

  @Column({ name: 'certificate_template_id', type: 'integer', nullable: true })
  certificateTemplateId!: number | null;

  @Column({ name: 'status', type: 'varchar', length: 9 })
  status!: string;

  @Column({ name: 'created_by_user_id', type: 'integer', nullable: true })
  createdByUserId!: number | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
