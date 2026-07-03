<?php
/**
 * MealVoucher — conteggio mensile buoni pasto (ticket restaurant).
 * Logica pura, nessun accesso DB: la config arriva già risolta (azienda + override
 * dipendente) e le assenze per-giorno arrivano precalcolate dal chiamante.
 *
 * Regola: 1 ticket per ogni giorno lavorativo non festivo in cui
 *   ore_effettive = hours_per_day - permessi_a_ore >= min_hours
 * e non c'è un'assenza a giornata intera. Se la soglia è disattivata
 * (min_hours_enabled=false) basta ore_effettive > 0. I giorni di smart working
 * ricorrente danno ticket solo se sw_eligible.
 */
class MealVoucher
{
    /** Chiavi giorno settimana index 0=lunedì..6=domenica (allineate a date('N')-1). */
    private const DAY_KEYS = ['mon','tue','wed','thu','fri','sat','sun'];

    /**
     * Numero di ticket spettanti nel mese.
     *
     * @param array $cfg config risolta:
     *   enabled bool, excluded bool, working_days string[], hours_per_day float,
     *   smart_working_days string[], min_hours_enabled bool, min_hours float,
     *   sw_eligible bool
     * @param array $dailyLeave ['YYYY-MM-DD' => ['full' => bool, 'hours' => float, 'sw' => bool]]
     *   assenze approvate del dipendente nel mese (giornata intera / permessi a ore);
     *   'sw' = giorno di smart working occasionale da richiesta approvata
     */
    public static function monthlyCount(array $cfg, int $year, int $month, array $dailyLeave = []): int
    {
        if (empty($cfg['enabled']) || !empty($cfg['excluded'])) return 0;

        $workingDays = array_flip($cfg['working_days'] ?? []);
        $swDays      = array_flip($cfg['smart_working_days'] ?? []);
        $hoursPerDay = (float) ($cfg['hours_per_day'] ?? 8.0);
        $minHoursOn  = !array_key_exists('min_hours_enabled', $cfg) || !empty($cfg['min_hours_enabled']);
        $minHours    = (float) ($cfg['min_hours'] ?? 6.0);
        $swEligible  = !empty($cfg['sw_eligible']);

        $count = 0;
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts  = mktime(0, 0, 0, $month, $d, $year);
            $ymd = date('Y-m-d', $ts);
            $dayKey = self::DAY_KEYS[((int) date('N', $ts)) - 1];

            if (!isset($workingDays[$dayKey])) continue;
            if (class_exists('ItalianHolidays') && ItalianHolidays::isHoliday($ymd)) continue;

            $leave = $dailyLeave[$ymd] ?? null;
            // Giorno SW: ricorrente (giorno della settimana) o occasionale (richiesta approvata)
            $isSwDay = isset($swDays[$dayKey]) || ($leave && !empty($leave['sw']));
            if ($isSwDay && !$swEligible) continue;
            if ($leave && !empty($leave['full'])) continue;
            $oreEff = $hoursPerDay - ($leave ? (float) ($leave['hours'] ?? 0.0) : 0.0);
            if ($minHoursOn ? $oreEff < $minHours : $oreEff <= 0) continue;

            $count++;
        }
        return $count;
    }
}
