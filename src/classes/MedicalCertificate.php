<?php
/**
 * MedicalCertificate — gestione certificati medici dipendenti.
 * File salvati in public/uploads/medical_certificates/
 */
class MedicalCertificate
{
    public const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    public static function uploadsDir(): string
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $dir = ROOT_PATH . '/public/uploads/co-' . $cid . '/medical_certificates';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    public static function uploadsRelPath(): string
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        return 'uploads/co-' . $cid . '/medical_certificates';
    }

    /**
     * Salva un upload via $_FILES.
     * @param array $file Voce di $_FILES (es. $_FILES['certificate'])
     * @param int $employeeId dipendente proprietario del certificato
     * @param array $meta ['issued_at'?, 'valid_until'?, 'notes'?, 'uploaded_by_user_id'?, 'uploaded_by_employee_id'?]
     * @return array ['ok'=>bool, 'id'?=>int, 'error'?=>string]
     */
    public static function save(array $file, int $employeeId, array $meta = []): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload fallito.'];
        }
        if ($file['size'] > self::MAX_SIZE_BYTES) {
            return ['ok' => false, 'error' => 'File troppo grande (max 5MB).'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['ok' => false, 'error' => 'Formato non valido (PDF/JPG/PNG).'];
        }
        $dir = self::uploadsDir();
        $filename = 'cert_' . $employeeId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'Impossibile salvare il file.'];
        }
        $relPath = self::uploadsRelPath() . '/' . $filename;

        try {
            $__emp = Database::fetchOne("SELECT company_id FROM employees WHERE id = ?", [$employeeId]);
            $id = Database::insert('medical_certificates', [
                'company_id'              => (int)($__emp['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1)),
                'employee_id'             => $employeeId,
                'file_path'               => $relPath,
                'original_name'           => substr($file['name'], 0, 250),
                'issued_at'               => !empty($meta['issued_at']) ? $meta['issued_at'] : null,
                'valid_until'             => !empty($meta['valid_until']) ? $meta['valid_until'] : null,
                'notes'                   => $meta['notes'] ?? null,
                'uploaded_by_user_id'     => $meta['uploaded_by_user_id'] ?? null,
                'uploaded_by_employee_id' => $meta['uploaded_by_employee_id'] ?? null,
            ]);
            return ['ok' => true, 'id' => $id, 'path' => $relPath];
        } catch (Throwable $e) {
            @unlink($dest);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function getByEmployee(int $employeeId): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        return Database::fetchAll(
            "SELECT * FROM medical_certificates WHERE company_id = ? AND employee_id = ? ORDER BY created_at DESC",
            [$cid, $employeeId]
        );
    }

    public static function getById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM medical_certificates WHERE id = ?", [$id]);
    }

    public static function delete(int $id): bool
    {
        $row = self::getById($id);
        if (!$row) return false;
        $absPath = ROOT_PATH . '/public/' . ltrim($row['file_path'], '/');
        if (is_file($absPath)) @unlink($absPath);
        Database::execute("DELETE FROM medical_certificates WHERE id = ?", [$id]);
        return true;
    }
}
