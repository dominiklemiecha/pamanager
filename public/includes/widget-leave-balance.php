<?php
/**
 * Widget Saldo ferie e permessi.
 * Required: $widgetEmployeeId (int), opzionale $widgetYear (int).
 */
if (!isset($widgetEmployeeId)) return;
$widgetYear = $widgetYear ?? (int) date('Y');
$balances = LeaveBalance::getForEmployee((int) $widgetEmployeeId, (int) $widgetYear);

$labels = ['ferie' => 'Ferie ' . $widgetYear, 'permesso' => 'Permessi'];
?>
<div class="card leave-balance-card">
    <div class="card-h">
        <h3>Saldo ferie</h3>
    </div>
    <div class="leave-progress">
        <?php foreach (LeaveBalance::TYPES as $type):
            $b = $balances[$type];
            $pct = $b['total'] > 0 ? round($b['percent']) : 0;
            $low = $b['total'] > 0 && ($b['residual'] / max($b['total'], 0.01)) < 0.2;
        ?>
        <div>
            <div class="leave-line">
                <span class="label"><?= htmlspecialchars($labels[$type]) ?></span>
                <span class="value">
                    <?= rtrim(rtrim(number_format($b['used'], 2, ',', '.'), '0'), ',') ?> /
                    <?= rtrim(rtrim(number_format($b['total'], 2, ',', '.'), '0'), ',') ?> <?= htmlspecialchars($b['unit']) ?>
                </span>
            </div>
            <div class="leave-bar<?= $low ? ' warning' : '' ?>">
                <div class="fill" style="width: <?= $pct ?>%"></div>
            </div>
            <?php if ($b['total'] <= 0): ?>
                <small class="leave-empty-hint">Saldo non configurato</small>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
