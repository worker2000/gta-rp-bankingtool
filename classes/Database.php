<?php
/**
 * PSB Kreditverwaltung - Datenbank-Klasse (PDO Wrapper)
 */

class Database {
    private static ?PDO $instance = null;

    /**
     * Singleton - Gibt die PDO-Instanz zurück
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                die("Datenbankverbindung fehlgeschlagen. Bitte Administrator kontaktieren.");
            }
        }
        return self::$instance;
    }

    /**
     * Führt eine Query mit Parametern aus
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Holt einen einzelnen Datensatz
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Holt alle Datensätze
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Fügt einen Datensatz ein und gibt die ID zurück
     */
    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Aktualisiert Datensätze
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * Beginnt eine Transaktion
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Bestätigt eine Transaktion
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Macht eine Transaktion rückgängig
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }
}
