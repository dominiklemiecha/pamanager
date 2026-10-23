import 'reflect-metadata';
import { DataSource } from 'typeorm';
import { opzioniDatabase } from '../../src/database/data-source';
import { SaldiService } from '../../src/modules/saldi/saldi.service';
import { mariadb, postgres, ripristina } from './ambiente';
import { php } from './php-cli';

/**
 * Saldi ferie/permessi: stessi numeri dal PHP (LeaveBalance) e dal nuovo
 * servizio, per ogni dipendente sintetico, e stesse righe scritte dalla
 * maturazione automatica.
 */
let ds: DataSource;
let saldi: SaldiService;

beforeAll(async () => {
  ripristina();
  process.env.APP_ENV = process.env.APP_ENV || 'development';
  ds = new DataSource({ ...opzioniDatabase(), entities: [] });
  await ds.initialize();
  saldi = new SaldiService(ds);
});
afterAll(() => ds?.destroy());

const righeSaldi = (fn: typeof mariadb) => fn(
  `SELECT employee_id, year, leave_type, entitled, carried_over, balance_set_at, manual_used, accrual_last_month
     FROM employee_leave_balances ORDER BY employee_id, year, leave_type`);

describe('saldi identici al PHP', () => {
  const ids = Array.from({ length: 13 }, (_, i) => i + 1);

  it('saldo completo (con maturazione del mese) per ogni dipendente', async () => {
    const attesi = php<Record<string, unknown>>(`echo json_encode(array_map(fn($id) => LeaveBalance::getForEmployee($id), array_combine([${ids}], [${ids}])));`);
    for (const id of ids) {
      const nuovo = await saldi.perDipendente(id);
      expect({ id, saldo: nuovo }).toEqual({ id, saldo: attesi[String(id)] });
    }
  });

  it('righe scritte dalla maturazione uguali nei due database', () => {
    const norm = (r: Record<string, string>) => ({ ...r, entitled: Number(r.entitled), carried_over: Number(r.carried_over), manual_used: Number(r.manual_used) });
    expect(righeSaldi(postgres).map(norm)).toEqual(righeSaldi(mariadb).map(norm));
  });

  it('seconda lettura nello stesso mese: niente cambia (idempotente)', async () => {
    const prima = righeSaldi(postgres);
    for (const id of ids) await saldi.perDipendente(id);
    expect(righeSaldi(postgres)).toEqual(prima);
  });

  it('allineamento da busta paga, anche di dicembre e con correzione a mano', async () => {
    const casi: Array<[number, Record<string, unknown>, number, number, Record<string, number | null>]> = [
      [2, { ferie: { residuo: 12.5, goduto: 3 }, permesso: { residuo: 40.25, goduto: 8 } }, 2026, 9, {}],
      [9, { ferie: { residuo: 5, goduto: 1 } }, 2025, 12, {}],
      [10, { ferie: { residuo: 7, goduto: 2 }, permesso: { residuo: 10 } }, 2026, 8, { ferie: 9 }],
    ];
    for (const [id, b, y, m, corr] of casi) {
      const [e] = postgres(`SELECT company_id FROM employees WHERE id = ${id}`);
      const atteso = php(`echo json_encode(LeaveBalance::applyFromPayslip(${id}, ${e.company_id}, json_decode('${JSON.stringify(b)}', true), ${y}, ${m}, 1, json_decode('${JSON.stringify(corr)}', true)));`);
      const nuovo = await saldi.daBustaPaga(id, Number(e.company_id), b as any, y, m, 1, corr as any);
      expect(nuovo).toEqual(atteso);
    }
    const norm = (r: Record<string, string>) => ({ ...r, entitled: Number(r.entitled), carried_over: Number(r.carried_over), manual_used: Number(r.manual_used) });
    expect(righeSaldi(postgres).map(norm)).toEqual(righeSaldi(mariadb).map(norm));
  });
});
