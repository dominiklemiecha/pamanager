<?php
/**
 * Widget dashboard admin: periodi di prova in scadenza (entro 14 giorni) o scaduti
 * senza decisione. Per ogni dipendente: Conferma prova / Non conferma.
 * Richiede: $baseUrl (PUBLIC_URL). Usa Probation::pendingDecisions().
 */
if (!class_exists('Probation')) return;

$__pcid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$__probationPending = Probation::pendingDecisions($__pcid);
if (empty($__probationPending)) return;

$__fmt = static function ($d) {
    $ts = $d ? strtotime($d) : false;
    return $ts ? date('d/m/Y', $ts) : '';
};
?>
<div class="card" style="margin-bottom: var(--sp-4); border-left: 4px solid var(--warning-500, #f59e0b);">
    <div class="card-h" style="display:flex; align-items:center; gap:.6rem;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <h3 style="margin:0;">Periodi di prova da decidere</h3>
        <span class="badge badge-warning" style="margin-left:auto;"><?= count($__probationPending) ?></span>
    </div>
    <div class="card-b" style="display:flex; flex-direction:column; gap:.5rem;">
        <?php foreach ($__probationPending as $__p):
            $__name = trim(($__p['first_name'] ?? '') . ' ' . ($__p['last_name'] ?? ''));
            $__days = (int)($__p['days_left'] ?? 0);
            if ($__days < 0)      { $__when = 'Scaduta il ' . $__fmt($__p['probation_end_date']); $__cls = 'badge-danger'; }
            elseif ($__days === 0){ $__when = 'Scade oggi (' . $__fmt($__p['probation_end_date']) . ')'; $__cls = 'badge-warning'; }
            else                  { $__when = 'Fine ' . $__fmt($__p['probation_end_date']) . ' · tra ' . $__days . ($__days === 1 ? ' giorno' : ' giorni'); $__cls = 'badge-warning'; }
            $__ini = '';
            foreach (preg_split('/\s+/', $__name) as $__w) { if ($__w !== '') $__ini .= mb_substr($__w, 0, 1); if (mb_strlen($__ini) >= 2) break; }
            $__ini = mb_strtoupper($__ini ?: '?');
        ?>
        <div class="probation-row" style="display:flex; align-items:center; gap:.75rem; padding:.55rem .25rem; border-bottom:1px solid var(--border-100, #f1f5f9); flex-wrap:wrap;">
            <?php if (!empty($__p['photo_path'])): ?>
                <img src="<?= htmlspecialchars($baseUrl . '/' . ltrim($__p['photo_path'], '/')) ?>" alt="" style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex:none;">
            <?php else: ?>
                <span style="width:38px;height:38px;border-radius:50%;background:rgba(11,58,164,.10);color:#0b3aa4;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex:none;"><?= htmlspecialchars($__ini) ?></span>
            <?php endif; ?>
            <div style="flex:1; min-width:140px;">
                <a href="<?= $baseUrl ?>/admin/employees.php?id=<?= (int)$__p['id'] ?>" style="font-weight:600; color:inherit; text-decoration:none;"><?= htmlspecialchars($__name) ?></a>
                <div><span class="badge <?= $__cls ?>" style="margin-top:.15rem;"><?= htmlspecialchars($__when) ?></span></div>
            </div>
            <div style="display:flex; gap:.4rem; flex:none;">
                <form method="POST" action="<?= $baseUrl ?>/admin/probation-decision.php" style="margin:0;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="employee_id" value="<?= (int)$__p['id'] ?>">
                    <input type="hidden" name="decision" value="confirmed">
                    <button type="submit" class="btn btn-sm" style="background:var(--success-600,#16a34a); color:#fff; border:none;">Conferma prova</button>
                </form>
                <form method="POST" action="<?= $baseUrl ?>/admin/probation-decision.php" style="margin:0;"
                      onsubmit="return confirm('Non confermare l\'assunzione di <?= htmlspecialchars(addslashes($__name)) ?>? Il dipendente verra\' disattivato alla data di fine prova e il consulente sara\' avvisato.');">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="employee_id" value="<?= (int)$__p['id'] ?>">
                    <input type="hidden" name="decision" value="not_confirmed">
                    <button type="submit" class="btn btn-sm btn-outline" style="border-color:var(--danger-500,#ef4444); color:var(--danger-600,#dc2626);">Non conferma</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
