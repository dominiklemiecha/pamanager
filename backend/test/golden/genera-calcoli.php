<?php
date_default_timezone_set('Europe/Rome');
foreach (['ItalianHolidays','LeaveBalance','MealVoucher','LeaveRequest'] as $c) require "/var/www/html/src/classes/$c.php";
mt_srand(20261023);
$days = ['mon','tue','wed','thu','fri','sat','sun'];
$rd = function() { return sprintf('%04d-%02d-%02d', mt_rand(2024, 2027), mt_rand(1, 12), mt_rand(1, 28)); };
$out = ['festivita' => [], 'giorni' => [], 'lunven' => [], 'ore' => [], 'buoni' => [], 'round' => []];
for ($y = 2000; $y <= 2040; $y++) $out['festivita'][$y] = ItalianHolidays::forYear($y);
// intervalli a cavallo dei cambi d'ora e casuali
$fissi = [['2026-03-27','2026-03-31'],['2026-10-19','2026-10-30'],['2026-10-24','2026-10-27'],['2025-10-20','2025-11-03'],['2027-03-22','2027-04-02'],['2026-12-28','2027-01-08']];
for ($i = 0; $i < 300; $i++) { $a = $rd(); $b = date('Y-m-d', strtotime($a) + mt_rand(0, 40) * 86400); $fissi[] = [$a, $b]; }
foreach ($fissi as [$a, $b]) {
  $set = array_values(array_filter($days, fn() => mt_rand(0, 9) < 7));
  $out['giorni'][] = [$a, $b, $set, LeaveBalance::countWorkingDays($a, $b, $set)];
  $out['lunven'][] = [$a, $b, LeaveRequest::calculateWorkingDays($a, $b)];
}
for ($i = 0; $i < 300; $i++) {
  $t = fn() => sprintf('%02d:%02d:00', mt_rand(6, 20), [0,15,30,45][mt_rand(0,3)]);
  $s = $t(); $e = $t(); $ls = mt_rand(0,3) ? '13:00:00' : null; $le = $ls ? (mt_rand(0,1) ? '14:00:00' : '13:30:00') : null;
  $out['ore'][] = [$s, $e, $ls, $le, LeaveBalance::effectiveHours($s, $e, $ls, $le)];
}
for ($i = 0; $i < 200; $i++) {
  $cfg = ['enabled' => mt_rand(0,9) > 0, 'excluded' => mt_rand(0,9) == 0,
    'working_days' => array_values(array_filter($days, fn() => mt_rand(0, 9) < 7)),
    'hours_per_day' => [8, 7.5, 6.5, 4, 6][mt_rand(0,4)], 'smart_working_days' => mt_rand(0,2) ? [] : ['fri'],
    'min_hours_enabled' => mt_rand(0,3) > 0, 'min_hours' => [6, 4, 7.5][mt_rand(0,2)], 'sw_eligible' => mt_rand(0,1) == 1];
  $y = mt_rand(2025, 2027); $m = mt_rand(1, 12); $leave = [];
  for ($k = 0; $k < mt_rand(0, 6); $k++) {
    $ymd = sprintf('%04d-%02d-%02d', $y, $m, mt_rand(1, 28));
    $leave[$ymd] = ['full' => mt_rand(0,2) == 0, 'hours' => [0, 1, 2.5, 3, 4][mt_rand(0,4)], 'sw' => mt_rand(0,4) == 0];
  }
  $out['buoni'][] = [$cfg, $y, $m, $leave, MealVoucher::monthlyCount($cfg, $y, $m, $leave)];
}
foreach ([1.005, 2.675, -1.5, 0.285, 1.955, 12.344999, 7.125, 0.5, 2.5, -2.5, 3.14159, 1/3, 20.1 * 3, 0.1 + 0.2] as $v) $out['round'][] = [$v, round($v, 2), round($v)];
echo json_encode($out, JSON_PRESERVE_ZERO_FRACTION);
