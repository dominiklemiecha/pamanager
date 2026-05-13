<?php
/**
 * Dipendenti del Reparto - Admin Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = $user['department_id'] ?? null;

if (!$departmentId) {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<h2>Nessun reparto assegnato</h2>';
    echo '<p>Contatta l\'amministratore per essere assegnato a un reparto.</p>';
    echo '</div>';
    exit;
}

$department = Department::getById($departmentId);
$employees = Department::getEmployees($departmentId, false);

// Filtro ricerca
$search = $_GET['search'] ?? '';
if ($search) {
    $searchLower = strtolower($search);
    $employees = array_filter($employees, function($emp) use ($searchLower) {
        return strpos(strtolower($emp['first_name'] . ' ' . $emp['last_name']), $searchLower) !== false
            || strpos(strtolower($emp['fiscal_code']), $searchLower) !== false
            || strpos(strtolower($emp['email'] ?? ''), $searchLower) !== false;
    });
}

// Statistiche
$activeCount = count(array_filter($employees, fn($e) => $e['is_active']));
$inactiveCount = count($employees) - $activeCount;

$pageTitle = 'Dipendenti - ' . htmlspecialchars($department['name']);
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
?>

<div class="dashboard">
    <!-- Statistiche -->
    <div class="stats-grid">
        <div class="stat-card stat-primary">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= count($employees) ?></span>
                <span class="stat-label">Totale Dipendenti</span>
            </div>
        </div>

        <div class="stat-card stat-success">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $activeCount ?></span>
                <span class="stat-label">Attivi</span>
            </div>
        </div>

        <div class="stat-card stat-warning">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $inactiveCount ?></span>
                <span class="stat-label">Inattivi</span>
            </div>
        </div>
    </div>

    <!-- Ricerca e Lista -->
    <section class="dashboard-card dashboard-card-full">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/>
                </svg>
                Dipendenti del Reparto <?= e($department['name']) ?>
            </h3>
            <form method="GET" class="search-form" style="gap: 0.5rem;">
                <input type="text" name="search" class="form-control" style="width: 250px; padding: 0.4rem 0.75rem; font-size: 0.875rem;"
                       placeholder="Cerca..." value="<?= e($search) ?>">
                <button type="submit" class="btn btn-sm btn-primary">Cerca</button>
                <?php if ($search): ?>
                    <a href="employees.php" class="btn btn-sm btn-secondary">Reset</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($employees)): ?>
                <div class="empty-state-small">
                    <p><?= $search ? 'Nessun dipendente trovato' : 'Nessun dipendente assegnato a questo reparto' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-hover responsive">
                        <thead>
                            <tr>
                                <th>Dipendente</th>
                                <th>Codice Fiscale</th>
                                <th>Email</th>
                                <th>Telefono</th>
                                <th>Stato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr class="<?= $emp['is_active'] ? '' : 'inactive' ?>">
                                    <td data-label="Dipendente">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <?= employeeAvatarHtml($emp, 'employee-avatar', 'width: 36px; height: 36px; font-size: 0.8rem; flex-shrink: 0;') ?>
                                            <div>
                                                <strong><?= e($emp['last_name'] . ' ' . $emp['first_name']) ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="CF">
                                        <code><?= e($emp['fiscal_code']) ?></code>
                                    </td>
                                    <td data-label="Email">
                                        <?= $emp['email'] ? e($emp['email']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td data-label="Telefono">
                                        <?= $emp['phone'] ? e($emp['phone']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td data-label="Stato">
                                        <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $emp['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="employee-documents.php?employee_id=<?= (int) $emp['id'] ?>" class="btn btn-sm btn-info">Documenti</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
