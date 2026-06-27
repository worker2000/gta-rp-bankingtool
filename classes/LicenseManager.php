<?php
/**
 * PSB – License verification via flessinglabs licensing API.
 *
 * Flow:
 *   1. Admin visits https://flessinglabs.com, registers and gets a licenseKey.
 *   2. Admin enters the licenseKey in Admin → Lizenz.
 *   3. This class verifies the key on every boot (cached for 1 h).
 *
 * No auto-claim — licenses are managed via the FlessingLabs shop.
 */
class LicenseManager {

    private const API_BASE    = 'https://licensing.flessinglabs.com/api';
    private const PROGRAM_KEY = '8fb130f0-fd9b-4c37-95f3-b9a0365bcfd1';
    private const CONFIG_FILE = __DIR__ . '/../storage/license.json';
    private const CACHE_TTL   = 3600; // seconds

    // ── public API ───────────────────────────────────────────────────────────

    /**
     * Call once at bootstrap. Hard-blocks (403 page) only if a licenseKey is
     * stored but is explicitly invalid. Shows a soft notice page if no key
     * has been entered yet.
     */
    public static function check(): void {
        // Admin pages are always accessible so the license key can be entered.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/pages/admin/')) {
            return;
        }

        $cfg = self::loadConfig();

        if (empty($cfg['licenseKey'])) {
            self::showNoLicensePage();
            exit;
        }

        // Skip API call if cached as valid within TTL
        $age = time() - (int)($cfg['lastChecked'] ?? 0);
        if (($cfg['lastValid'] ?? false) && $age < self::CACHE_TTL) {
            return;
        }

        try {
            self::verify($cfg);
        } catch (LicenseInvalidException $e) {
            self::showInvalidPage($e->getMessage());
            exit;
        } catch (Exception $e) {
            // Network / parse error → soft-fail, log and continue
            error_log('[LicenseManager] ' . $e->getMessage());
        }
    }

    /**
     * Save a license key entered via the admin panel and immediately verify it.
     * Returns ['valid' => bool, 'message' => string].
     */
    public static function saveLicenseKey(string $licenseKey): array {
        $cfg = self::loadConfig();
        $cfg['licenseKey']   = trim($licenseKey);
        $cfg['lastChecked']  = 0;
        $cfg['lastValid']    = false;
        self::saveConfig($cfg);

        try {
            self::verify($cfg);
            return ['valid' => true, 'message' => 'Lizenz erfolgreich aktiviert.'];
        } catch (LicenseInvalidException $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        } catch (Exception $e) {
            return ['valid' => false, 'message' => 'Netzwerkfehler – bitte erneut versuchen.'];
        }
    }

    /** Return the current config (for display in admin panel). */
    public static function config(): array {
        return self::loadConfig();
    }

    /** Return (and persist) the installation's instance ID. */
    public static function instanceId(): string {
        $cfg = self::loadConfig();
        if (empty($cfg['instanceId'])) {
            $cfg['instanceId'] = self::uuid4();
            self::saveConfig($cfg);
        }
        return $cfg['instanceId'];
    }

    // ── private helpers ──────────────────────────────────────────────────────

    private static function verify(array &$cfg): void {
        $result = self::post('/license/verify', [
            'licenseKey' => $cfg['licenseKey'],
            'instanceId' => $cfg['instanceId'] ?? self::instanceId(),
        ]);

        $valid = (bool)($result['valid'] ?? false);
        $cfg['lastChecked'] = time();
        $cfg['lastValid']   = $valid;
        self::saveConfig($cfg);

        if (!$valid) {
            throw new LicenseInvalidException($result['message'] ?? 'Lizenz ungültig oder abgelaufen.');
        }
    }

    private static function post(string $endpoint, array $body): array {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-Program-Key: " . self::PROGRAM_KEY,
                'content'       => json_encode($body),
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::API_BASE . $endpoint, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Netzwerkfehler beim Licensing-API-Call');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ungültige API-Antwort');
        }
        return $decoded;
    }

    private static function loadConfig(): array {
        if (file_exists(self::CONFIG_FILE)) {
            $data = json_decode(file_get_contents(self::CONFIG_FILE), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return ['instanceId' => self::uuid4(), 'licenseKey' => '', 'lastChecked' => 0, 'lastValid' => false];
    }

    private static function saveConfig(array $cfg): void {
        file_put_contents(self::CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT));
    }

    private static function uuid4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    // ── error pages ──────────────────────────────────────────────────────────

    private static function showNoLicensePage(): void {
        $adminUrl = (defined('APP_URL') ? APP_URL : '') . '/pages/admin/login.php';
        http_response_code(402);
        echo self::pageWrap(
            'bi-key text-warning',
            'Lizenzschlüssel erforderlich',
            '<p style="color:#8b949e;margin:0 0 1.5rem;">
                Um PSB Kreditverwaltung zu nutzen, benötigst du einen kostenlosen Lizenzschlüssel.
                Registriere dich auf <strong style="color:#e6edf3;">flessinglabs.com</strong>
                und trage den erhaltenen Key im Admin-Panel ein.
             </p>
             <a href="https://flessinglabs.com" target="_blank" rel="noopener"
                style="display:inline-block;padding:.55rem 1.4rem;background:#0d6efd;color:#fff;
                       border-radius:8px;text-decoration:none;font-weight:600;margin-bottom:.75rem;">
                &#127760; Kostenlose Lizenz holen
             </a>
             <br>
             <a href="' . htmlspecialchars($adminUrl) . '"
                style="font-size:.82rem;color:#484f58;text-decoration:none;">
                &larr; Key bereits vorhanden? Im Admin-Panel eintragen
             </a>'
        );
    }

    private static function showInvalidPage(string $message): void {
        http_response_code(403);
        echo self::pageWrap(
            'bi-shield-x text-danger',
            'Lizenz ungültig',
            '<p style="color:#8b949e;margin:0 0 1.5rem;">' . htmlspecialchars($message) . '</p>
             <a href="https://flessinglabs.com" target="_blank" rel="noopener"
                style="display:inline-block;padding:.55rem 1.4rem;background:#dc3545;color:#fff;
                       border-radius:8px;text-decoration:none;font-weight:600;">
                &#128274; Lizenz prüfen / erneuern
             </a>'
        );
    }

    private static function pageWrap(string $icon, string $title, string $body): string {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>' . htmlspecialchars($title) . ' – PSB</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
            </head>
            <body style="margin:0;background:#0d1117;font-family:system-ui,sans-serif;
                         display:flex;align-items:center;justify-content:center;min-height:100vh;">
            <div style="max-width:400px;width:100%;text-align:center;padding:2rem;">
                <i class="bi ' . $icon . '" style="font-size:3rem;display:block;margin-bottom:1rem;"></i>
                <h2 style="color:#e6edf3;margin:0 0 1rem;font-size:1.4rem;">' . htmlspecialchars($title) . '</h2>
                ' . $body . '
            </div></body></html>';
    }
}

class LicenseInvalidException extends RuntimeException {}
