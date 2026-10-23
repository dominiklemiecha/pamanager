// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'employee_leave_balances' })
export class EmployeeLeaveBalances {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'year', type: 'smallint' })
  year!: number;

  @Column({ name: 'leave_type', type: 'varchar', length: 8 })
  leaveType!: string;

  @Column({ name: 'entitled', type: 'numeric', precision: 6, scale: 2, transformer: numero })
  entitled!: number;

  @Column({ name: 'carried_over', type: 'numeric', precision: 6, scale: 2, transformer: numero })
  carriedOver!: number;

  @Column({ name: 'balance_set_at', type: 'date', nullable: true })
  balanceSetAt!: string | null;

  @Column({ name: 'manual_used', type: 'numeric', precision: 6, scale: 2, transformer: numero })
  manualUsed!: number;

  @Column({ name: 'accrual_last_month', type: 'citext', nullable: true })
  accrualLastMonth!: string | null;

  @Column({ name: 'notes', type: 'citext', nullable: true })
  notes!: string | null;

  @Column({ name: 'updated_by', type: 'integer', nullable: true })
  updatedBy!: number | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
