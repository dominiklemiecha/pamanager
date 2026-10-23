import * as fs from 'fs';
import * as path from 'path';
import { festivitaAnno } from './festivita';
import { buoniPastoMese, contaGiorniLavorativi, contaGiorniLunVen, oreEffettive } from './orario';
import { phpRound } from './php';

/**
 * Equivalenza con il PHP: test/golden/calcoli.json e' prodotto da
 * test/golden/genera-calcoli.php eseguito sul vecchio codice (classi
 * ItalianHolidays, LeaveBalance, MealVoucher, LeaveRequest).
 */
const g = JSON.parse(fs.readFileSync(path.join(__dirname, '../../../test/golden/calcoli.json'), 'utf8'));

describe('calcoli identici al PHP', () => {
  it('festivita 2000-2040', () => {
    for (const [anno, mappa] of Object.entries(g.festivita)) {
      expect(Object.fromEntries(festivitaAnno(Number(anno)))).toEqual(mappa);
    }
  });
  it.each(g.giorni.map((c: any[]) => [c[0], c[1], c[2].join(','), c[3]]))('giorni lavorativi %s -> %s [%s] = %d', (a, b, set, atteso) => {
    expect(contaGiorniLavorativi(a, b, set ? set.split(',') : [])).toBe(atteso);
  });
  it('giorni lun-ven delle richieste', () => {
    for (const [a, b, atteso] of g.lunven) expect([a, b, contaGiorniLunVen(a, b)]).toEqual([a, b, atteso]);
  });
  it('ore al netto della pausa', () => {
    for (const [s, e, ls, le, atteso] of g.ore) expect([s, e, ls, le, oreEffettive(s, e, ls, le)]).toEqual([s, e, ls, le, atteso]);
  });
  it('buoni pasto del mese', () => {
    for (const [cfg, y, m, leave, atteso] of g.buoni) {
      const n = buoniPastoMese({
        enabled: cfg.enabled, excluded: cfg.excluded, workingDays: cfg.working_days, hoursPerDay: cfg.hours_per_day,
        smartWorkingDays: cfg.smart_working_days, minHoursEnabled: cfg.min_hours_enabled, minHours: cfg.min_hours, swEligible: cfg.sw_eligible,
      }, y, m, leave);
      expect({ cfg, y, m, n }).toEqual({ cfg, y, m, n: atteso });
    }
  });
  it('arrotondamenti come round() di PHP', () => {
    for (const [v, r2, r0] of g.round) expect([v, phpRound(v, 2), phpRound(v)]).toEqual([v, r2, r0]);
  });
});
