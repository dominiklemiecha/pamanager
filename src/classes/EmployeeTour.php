<?php
/**
 * Guida interattiva del dipendente: stato per utente (migration 063).
 */
class EmployeeTour
{
    public const STATUSES = ['completed', 'skipped'];

    /**
     * Stato salvato: null = guida da proporre, 'completed' / 'skipped' = conclusa.
     * Se la colonna non esiste (migration non ancora eseguita) la guida non viene proposta.
     */
    public static function getStatus(int $employeeId): ?string
    {
        try {
            $status = Database::fetchColumn("SELECT tour_status FROM employees WHERE id = ?", [$employeeId]);
            return $status ?: null;
        } catch (Throwable $e) {
            return 'completed';
        }
    }

    /** Registra completamento o salto solo la prima volta: rivedere la guida non sovrascrive. */
    public static function setStatus(int $employeeId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        Database::query(
            "UPDATE employees SET tour_status = ?, tour_status_at = NOW() WHERE id = ? AND tour_status IS NULL",
            [$status, $employeeId]
        );
        return true;
    }

    /** True se il dipendente vede la Home con grafica Figma (ENV EMPLOYEE_FIGMA_DASHBOARD_EMAILS). */
    public static function usesFigmaDashboard(int $employeeId): bool
    {
        $emails = array_filter(array_map('trim', explode(',', strtolower(
            (string) (getenv('EMPLOYEE_FIGMA_DASHBOARD_EMAILS') ?: 'dominik.lemiecha25@gmail.com')
        ))));
        try {
            $email = strtolower(trim((string) Database::fetchColumn("SELECT email FROM employees WHERE id = ?", [$employeeId])));
        } catch (Throwable $e) {
            return false;
        }
        return $email !== '' && in_array($email, $emails, true);
    }
}
