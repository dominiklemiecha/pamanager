<?php
/**
 * Classe Database - Wrapper PDO sicuro
 * PAManager - Comune
 */

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    private function __construct() {}
    private function __clone() {}

    /**
     * Ottiene l'istanza singleton della connessione PDO
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$config = require CONFIG_PATH . '/database.php';
            self::connect();
        }
        return self::$instance;
    }

    /**
     * Crea la connessione al database
     */
    private static function connect(): void
    {
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            self::$config['driver'],
            self::$config['host'],
            self::$config['port'],
            self::$config['database'],
            self::$config['charset']
        );

        try {
            self::$instance = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                self::$config['options']
            );

            // Allinea il timezone della sessione MySQL a quello di PHP (Europe/Rome).
            // Senza questo, NOW()/CURRENT_TIMESTAMP usano UTC del container e gli orari sballano.
            try {
                $offset = (new DateTime())->format('P'); // '+02:00' / '+01:00' (DST-aware)
                self::$instance->exec("SET time_zone = '" . $offset . "'");
            } catch (Throwable $__tz) {
                self::logError('Impossibile impostare time_zone MySQL: ' . $__tz->getMessage());
            }
        } catch (PDOException $e) {
            self::logError('Connessione database fallita: ' . $e->getMessage());
            throw new RuntimeException('Errore di connessione al database');
        }
    }

    /**
     * Esegue una query con prepared statement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            self::logError('Query fallita: ' . $e->getMessage() . ' - SQL: ' . $sql);
            throw new RuntimeException('Errore durante l\'esecuzione della query: ' . $e->getMessage());
        }
    }

    /**
     * Ottiene una singola riga
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Alias per fetchOne - compatibilità
     */
    public static function fetch(string $sql, array $params = []): ?array
    {
        return self::fetchOne($sql, $params);
    }

    /**
     * Esegue una query senza restituire risultati (UPDATE, DELETE, INSERT)
     * Restituisce il PDOStatement per accedere a rowCount()
     */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        return self::query($sql, $params);
    }

    /**
     * Ottiene tutte le righe
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Ottiene un singolo valore
     */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return self::query($sql, $params)->fetchColumn($column);
    }

    /**
     * Inserisce una riga e restituisce l'ID
     */
    public static function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));

        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Aggiorna righe
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        $params = array_merge(array_values($data), $whereParams);
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Elimina righe
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Inizia una transazione
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Conferma una transazione
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Annulla una transazione
     */
    public static function rollback(): bool
    {
        return self::getInstance()->rollBack();
    }

    /**
     * Verifica se esiste almeno una riga
     */
    public static function exists(string $table, string $where, array $params = []): bool
    {
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return self::fetchColumn($sql, $params) !== false;
    }

    /**
     * Conta le righe
     */
    public static function count(string $table, string $where = '1=1', array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        return (int) self::fetchColumn($sql, $params);
    }

    /**
     * Log degli errori
     */
    private static function logError(string $message): void
    {
        $logFile = LOGS_PATH . '/database.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }

        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Chiude la connessione
     */
    public static function close(): void
    {
        self::$instance = null;
    }
}
