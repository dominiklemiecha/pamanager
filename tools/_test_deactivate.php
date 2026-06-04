<?php
require __DIR__ . '/../config/config.php';
$id = (int)($argv[1] ?? 0);
if ($id <= 0) die("uso: php _test_deactivate.php <employee_id>\n");
$emp = Database::fetchOne("SELECT id, company_id, is_active, username FROM employees WHERE id = ?", [$id]);
if (!$emp) die("Employee $id non trovato\n");
echo "Prima: " . json_encode($emp) . "\n";
$_SESSION['tenant_company_id'] = (int)$emp['company_id'];
// Simula sessione admin
session_start();
$admin = Database::fetchOne("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
$_SESSION['user_id'] = (int)$admin['id'];
$_SESSION['user_role'] = $admin['role'];
$_SESSION['user_email'] = $admin['email'] ?? '';
$_SESSION['user_name'] = $admin['name'] ?? '';
$_SESSION['user_company_id'] = $emp['company_id'];

echo "Tentando Employee::deactivate($id)...\n";
$r = Employee::deactivate($id);
echo "Risultato: " . json_encode($r) . "\n";
$emp2 = Database::fetchOne("SELECT id, is_active FROM employees WHERE id = ?", [$id]);
echo "Dopo: " . json_encode($emp2) . "\n";
