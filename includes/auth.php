<?php
/**
 * PSB Kreditverwaltung - Authentifizierung
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

class Auth {
    /**
     * Startet die Session
     */
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            ini_set('session.cookie_lifetime', SESSION_LIFETIME);
            ini_set('session.cookie_path', '/');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            // Secure-Cookie nur wenn HTTPS aktiv (Produktion)
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    /**
     * Prüft ob Benutzer eingeloggt ist
     */
    public static function check(): bool {
        self::init();
        return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
    }

    /**
     * Gibt den aktuellen Benutzer zurück
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        return $_SESSION['user'] ?? null;
    }

    /**
     * Gibt die User-ID zurück
     */
    public static function userId(): ?int {
        return self::check() ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Gibt die aktuelle Bank-ID zurück (aus Session)
     */
    public static function bankId(): int {
        return (int)($_SESSION['bank_id'] ?? 1);
    }

    /**
     * Gibt die aktuellen Bank-Daten zurück (name, short_code, primary_color, etc.)
     */
    public static function bank(): array {
        return $_SESSION['bank'] ?? [
            'id'            => 1,
            'name'          => 'Pacific State Bank',
            'short_code'    => 'PSB',
            'primary_color' => '#0d6efd',
            'logo_url'      => null,
        ];
    }

    /**
     * Prüft ob der Benutzer Super-Admin ist (Zugriff auf alle Banken)
     */
    public static function isSuperAdmin(): bool {
        $user = self::user();
        return $user && ($user['is_super_admin'] ?? false);
    }

    /**
     * Login durchführen – bank_id kommt aus dem Login-Formular (Bank-Auswahl).
     */
    public static function login(string $username, string $password, int $bankId = 1): bool {
        $user = Database::fetchOne(
            "SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.name) as roles,
                    GROUP_CONCAT(r.permissions ORDER BY r.name) as all_permissions
             FROM users u
             LEFT JOIN user_roles ur ON u.id = ur.user_id
             LEFT JOIN roles r ON ur.role_id = r.id
             WHERE u.username = ? AND u.is_active = 1
             GROUP BY u.id",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $roles = array_filter(explode(',', $user['roles'] ?? ''));
        $isSuperAdmin = in_array('super_admin', $roles);

        // Bank-Zugriff prüfen: eigene Bank oder Super-Admin
        if (!$isSuperAdmin && (int)$user['bank_id'] !== $bankId) {
            return false;
        }

        // Bank-Daten laden
        $bank = Database::fetchOne(
            "SELECT * FROM banks WHERE id = ? AND is_active = 1",
            [$bankId]
        );

        if (!$bank) {
            return false;
        }

        // Session setzen
        self::init();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['bank_id'] = $bankId;
        $_SESSION['bank']    = $bank;
        $_SESSION['user']    = [
            'id'                  => $user['id'],
            'username'            => $user['username'],
            'full_name'           => $user['full_name'],
            'email'               => $user['email'],
            'roles'               => array_values($roles),
            'permissions'         => self::parsePermissions($user['all_permissions'] ?? ''),
            'is_super_admin'      => $isSuperAdmin,
            'must_change_password'=> (bool)($user['must_change_password'] ?? false),
        ];

        // Last Login aktualisieren
        Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // Audit Log
        AuditLog::log('LOGIN', 'user', $user['id']);

        return true;
    }

    /**
     * Logout durchführen
     */
    public static function logout(): void {
        self::init();
        $userId = self::userId();

        if ($userId) {
            AuditLog::log('LOGOUT', 'user', $userId);
        }

        $_SESSION = [];
        session_destroy();
    }

    /**
     * Prüft ob Benutzer eine bestimmte Rolle hat.
     * Super-Admins haben implizit jede Rolle.
     */
    public static function hasRole(string $role): bool {
        $user = self::user();
        if (!$user) return false;
        if ($user['is_super_admin'] ?? false) return true;
        return in_array($role, $user['roles']);
    }

    /**
     * Prüft ob Benutzer eine bestimmte Berechtigung hat
     */
    public static function can(string $resource, string $action): bool {
        $user = self::user();
        if (!$user) return false;

        // Director und Super-Admin haben alle Rechte
        if (in_array('director', $user['roles']) || ($user['is_super_admin'] ?? false)) {
            return true;
        }

        $permissions = $user['permissions'] ?? [];
        if (isset($permissions[$resource])) {
            return in_array($action, $permissions[$resource]) || in_array('all', $permissions[$resource]);
        }

        return false;
    }

    /**
     * Erzwingt Login – leitet zur Login-Seite um.
     * Leitet außerdem auf Passwort-Änderung weiter, wenn must_change_password gesetzt ist.
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: ' . APP_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
        $user = self::user();
        if (!empty($user['must_change_password'])) {
            $changeUrl = APP_URL . '/pages/change-password.php';
            if (strpos($_SERVER['REQUEST_URI'], '/pages/change-password.php') === false) {
                header('Location: ' . $changeUrl);
                exit;
            }
        }
    }

    /**
     * Gibt zurück ob der aktuelle User sein Passwort ändern muss.
     */
    public static function mustChangePassword(): bool {
        $user = self::user();
        return !empty($user['must_change_password']);
    }

    /**
     * Erzwingt bestimmte Berechtigung
     */
    public static function requirePermission(string $resource, string $action): void {
        self::requireLogin();
        if (!self::can($resource, $action)) {
            http_response_code(403);
            die('Keine Berechtigung für diese Aktion.');
        }
    }

    /**
     * Parst Berechtigungen aus JSON-Strings
     */
    private static function parsePermissions(string $permissionsJson): array {
        $result = [];
        $parts = explode(',', $permissionsJson);

        foreach ($parts as $json) {
            if (empty($json)) continue;
            $perms = json_decode($json, true);
            if (!$perms) continue;

            foreach ($perms as $resource => $actions) {
                if (!isset($result[$resource])) {
                    $result[$resource] = [];
                }
                if (is_array($actions)) {
                    $result[$resource] = array_merge($result[$resource], $actions);
                } elseif ($actions === true) {
                    $result[$resource] = ['all'];
                }
            }
        }

        return $result;
    }
}
