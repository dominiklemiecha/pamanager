import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';

/**
 * Compleanni di oggi e domani dei dipendenti attivi (widget-birthday-banner.php),
 * per chi guarda dallo staff (non e' mai il festeggiato).
 */
export async function compleanni(ds: DataSource, cid: number) {
  const oggi = oggiRoma();
  const domani = new Date(`${oggi}T12:00:00Z`);
  domani.setUTCDate(domani.getUTCDate() + 1);
  const [mdOggi, mdDomani] = [oggi.slice(5), domani.toISOString().slice(5, 10)];
  const righe = await ds.query(
    `SELECT id, first_name, to_char(birth_date, 'MM-DD') AS md FROM employees WHERE is_active = true AND birth_date IS NOT NULL AND company_id = $1
        AND to_char(birth_date, 'MM-DD') IN ($2, $3) ORDER BY id`, [cid, mdOggi, mdDomani]);
  return {
    todayOthers: righe.filter((r: any) => r.md === mdOggi).map((r: any) => r.first_name) as string[],
    tomorrowOthers: righe.filter((r: any) => r.md === mdDomani).map((r: any) => r.first_name) as string[],
  };
}
