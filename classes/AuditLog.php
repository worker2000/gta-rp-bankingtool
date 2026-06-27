<?php
/**
 * PSB / Fortis Finance – Audit Log
 */

class AuditLog {
    /**
     * Schreibt einen Audit-Log-Eintrag (bank_id wird automatisch aus Session gesetzt)
     */
    public static function log(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $userId = $_SESSION['user_id'] ?? null;
            $bankId = $_SESSION['bank_id'] ?? 1;

            Database::insert('audit_log', [
                'bank_id'     => $bankId,
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues ? json_encode($oldValues) : null,
                'new_values'  => $newValues ? json_encode($newValues) : null,
                'ip_address'  => self::anonymizeIp($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
            ]);
        } catch (Exception $e) {
            error_log("AuditLog error: " . $e->getMessage());
        }
    }

    /**
     * Holt Log-Einträge für eine Entity (bank-gefiltert)
     */
    public static function getForEntity(string $entityType, int $entityId, int $limit = 50): array {
        $bankId = $_SESSION['bank_id'] ?? 1;

        return Database::fetchAll(
            "SELECT al.*, u.username, u.full_name
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.entity_type = ? AND al.entity_id = ? AND al.bank_id = ?
             ORDER BY al.created_at DESC
             LIMIT ?",
            [$entityType, $entityId, $bankId, $limit]
        );
    }

    /**
     * IP-Adresse anonymisieren (letztes Oktet bei IPv4, letzten Block bei IPv6)
     */
    private static function anonymizeIp(?string $ip): ?string {
        if (!$ip) return null;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return preg_replace('/:[^:]+$/', ':0', $ip);
        }
        return null;
    }
}
