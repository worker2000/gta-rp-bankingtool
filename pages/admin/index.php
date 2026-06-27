<?php
ob_start();
$pageTitle = 'Administration';
require_once __DIR__ . '/../../includes/header.php';

if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$activeTab = $_GET['tab'] ?? 'banks';

// ── POST-Handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action    = $_POST['action']   ?? '';
    $redirectTab = $_POST['tab']    ?? 'banks';

    // ── BANKEN ────────────────────────────────────────────────────────────
    if ($action === 'bank_create') {
        $name       = trim($_POST['name']         ?? '');
        $shortCode  = strtoupper(trim($_POST['short_code']  ?? ''));
        $mainAcct   = trim($_POST['main_account'] ?? '');
        $color      = trim($_POST['primary_color'] ?? '#0d6efd');
        $logoUrl    = trim($_POST['logo_url']     ?? '') ?: null;

        if ($name && $shortCode) {
            $newBankId = Database::insert('banks', [
                'name'          => $name,
                'short_code'    => $shortCode,
                'main_account'  => $mainAcct ?: null,
                'primary_color' => $color,
                'logo_url'      => $logoUrl,
                'is_active'     => 1,
            ]);
            // Default-Policies für neue Bank
            $defaultPolicies = [
                ['INTEREST_RATE_AUTO',              '0.10',   'Standard-Zinssatz Autokredit'],
                ['INTEREST_RATE_PRIVATE',            '0.10',   'Standard-Zinssatz Privatkredit'],
                ['INTEREST_RATE_BUSINESS',           '0.12',   'Standard-Zinssatz Geschäftskredit'],
                ['PROCESSING_FEE_RATE',              '0.02',   'Bearbeitungsgebühr (% der Kreditsumme)'],
                ['AUTO_MIN_TERM_WEEKS',              '6',      'Min. Laufzeit Autokredit (Wochen)'],
                ['AUTO_MAX_TERM_WEEKS',              '8',      'Max. Laufzeit Autokredit (Wochen)'],
                ['AUTO_MIN_DOWNPAY_RATIO',           '0.30',   'Min. Eigenkapital Autokredit'],
                ['AUTO_MAX_DOWNPAY_RATIO',           '0.40',   'Max. Eigenkapital Autokredit'],
                ['PRIVATE_MIN_TERM_WEEKS',           '1',      'Min. Laufzeit Privatkredit (Wochen)'],
                ['PRIVATE_MAX_TERM_WEEKS',           '12',     'Max. Laufzeit Privatkredit (Wochen)'],
                ['BUSINESS_MIN_TERM_WEEKS',          '1',      'Min. Laufzeit Geschäftskredit (Wochen)'],
                ['BUSINESS_MAX_TERM_WEEKS',          '16',     'Max. Laufzeit Geschäftskredit (Wochen)'],
                ['MIN_LOAN_AMOUNT',                  '1000',   'Minimaler Kreditbetrag ($)'],
                ['MAX_LOAN_AMOUNT',                  '500000', 'Maximaler Kreditbetrag ($)'],
                ['MAX_SMALL_LOAN_AMOUNT',            '50000',  'Kompetenzgrenze Sachbearbeiter ($)'],
                ['MAX_ACTIVE_LOANS_PER_CUSTOMER',    '2',      'Max. aktive Kredite pro Kreditnehmer'],
                ['MAX_RATE_INCOME_RATIO',            '0.40',   'Max. Rate/Einkommen'],
                ['DUNNING_L1_DAYS',                  '7',      'Tage bis Mahnstufe 1'],
                ['DUNNING_L2_DAYS',                  '14',     'Tage bis Mahnstufe 2'],
                ['TERMINATION_DAYS',                 '21',     'Tage bis Kündigung'],
                ['DEFAULT_LATE_WEEKLY_RATE',         '0.10',   'Verzugszins pro Woche'],
                ['DUNNING_FEE_L1',                   '500',    'Mahngebühr Stufe 1 ($)'],
                ['DUNNING_FEE_L2',                   '1000',   'Mahngebühr Stufe 2 ($)'],
                ['REMINDER_DAYS_BEFORE',             '3',      'Erinnerung Tage vorher'],
            ];
            foreach ($defaultPolicies as [$key, $val, $desc]) {
                Database::insert('loan_policies', [
                    'bank_id'      => $newBankId,
                    'policy_key'   => $key,
                    'policy_value' => $val,
                    'description'  => $desc,
                    'valid_from'   => date('Y-m-d'),
                ]);
            }
            AuditLog::log('CREATE', 'bank', $newBankId);
            setFlash('success', 'Bank "' . $name . '" angelegt.');
        } else {
            setFlash('error', 'Name und Kürzel sind Pflichtfelder.');
        }

    } elseif ($action === 'bank_update') {
        $bankId     = intval($_POST['bank_id']    ?? 0);
        $name       = trim($_POST['name']         ?? '');
        $shortCode  = strtoupper(trim($_POST['short_code']  ?? ''));
        $mainAcct   = trim($_POST['main_account'] ?? '');
        $color      = trim($_POST['primary_color'] ?? '#0d6efd');
        $logoUrl    = trim($_POST['logo_url']     ?? '') ?: null;

        if ($bankId && $name && $shortCode) {
            Database::update('banks', [
                'name'          => $name,
                'short_code'    => $shortCode,
                'main_account'  => $mainAcct ?: null,
                'primary_color' => $color,
                'logo_url'      => $logoUrl,
            ], 'id = ?', [$bankId]);
            AuditLog::log('UPDATE', 'bank', $bankId);
            setFlash('success', 'Bank aktualisiert.');
        }

    } elseif ($action === 'bank_toggle') {
        $bankId = intval($_POST['bank_id'] ?? 0);
        if ($bankId) {
            $bank = Database::fetchOne("SELECT is_active FROM banks WHERE id = ?", [$bankId]);
            Database::update('banks', ['is_active' => $bank['is_active'] ? 0 : 1], 'id = ?', [$bankId]);
            AuditLog::log('TOGGLE_ACTIVE', 'bank', $bankId);
            setFlash('success', 'Bank-Status aktualisiert.');
        }

    // ── BENUTZER ──────────────────────────────────────────────────────────
    } elseif ($action === 'user_create') {
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']       ?? '';
        $fullName  = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $roleId    = intval($_POST['role_id'] ?? 0);
        $uBankId   = intval($_POST['bank_id'] ?? 0);
        $wantsSuperAdmin = isset($_POST['is_super_admin']);

        if ($username && $password && $fullName && $roleId && $uBankId) {
            $id = Database::insert('users', [
                'bank_id'       => $uBankId,
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => $fullName,
                'email'         => $email ?: null,
                'is_active'     => 1,
            ]);
            Database::insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            if ($wantsSuperAdmin) {
                $saRole = Database::fetchOne("SELECT id FROM roles WHERE name = 'super_admin'");
                if ($saRole) {
                    Database::query(
                        "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)",
                        [$id, $saRole['id']]
                    );
                }
            }
            AuditLog::log('CREATE', 'user', $id);
            setFlash('success', 'Benutzer "' . $fullName . '" angelegt.');
        } else {
            setFlash('error', 'Bitte alle Pflichtfelder ausfüllen.');
        }

    } elseif ($action === 'user_update') {
        $userId    = intval($_POST['user_id']   ?? 0);
        $fullName  = trim($_POST['full_name']   ?? '');
        $email     = trim($_POST['email']       ?? '');
        $roleId    = intval($_POST['role_id']   ?? 0);
        $uBankId   = intval($_POST['bank_id']   ?? 0);
        $password  = $_POST['password']         ?? '';
        $wantsSuperAdmin = isset($_POST['is_super_admin']);

        if ($userId && $fullName && $roleId && $uBankId) {
            $data = [
                'full_name' => $fullName,
                'email'     => $email ?: null,
                'bank_id'   => $uBankId,
            ];
            if ($password) {
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            Database::update('users', $data, 'id = ?', [$userId]);
            Database::query("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
            Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
            if ($wantsSuperAdmin) {
                $saRole = Database::fetchOne("SELECT id FROM roles WHERE name = 'super_admin'");
                if ($saRole) {
                    Database::query(
                        "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)",
                        [$userId, $saRole['id']]
                    );
                }
            }
            AuditLog::log('UPDATE', 'user', $userId);
            setFlash('success', 'Benutzer aktualisiert.');
        }

    } elseif ($action === 'user_toggle') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId && $userId !== Auth::userId()) {
            $user = Database::fetchOne("SELECT is_active FROM users WHERE id = ?", [$userId]);
            Database::update('users', ['is_active' => $user['is_active'] ? 0 : 1], 'id = ?', [$userId]);
            AuditLog::log('TOGGLE_ACTIVE', 'user', $userId);
            setFlash('success', 'Benutzerstatus aktualisiert.');
        }

    } elseif ($action === 'user_reset_pw') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            Database::update('users', [
                'reset_token'     => $token,
                'reset_token_exp' => $expires,
            ], 'id = ?', [$userId]);
            AuditLog::log('RESET_TOKEN', 'user', $userId);
            $resetUrl = APP_URL . '/reset-password.php?token=' . $token;
            setFlash('success', 'Reset-Link generiert (24h gültig): <a href="' . e($resetUrl) . '" target="_blank" class="alert-link">' . e($resetUrl) . '</a>');
        }

    } elseif ($action === 'user_delete') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId && $userId !== Auth::userId()) {
            $target = Database::fetchOne("SELECT username, full_name FROM users WHERE id = ?", [$userId]);
            if ($target) {
                Database::query("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
                Database::query("DELETE FROM users WHERE id = ?", [$userId]);
                AuditLog::log('DELETE', 'user', $userId, ['username' => $target['username']]);
                setFlash('success', 'Benutzer "' . $target['full_name'] . '" gelöscht.');
            }
        } else {
            setFlash('error', 'Du kannst deinen eigenen Account nicht löschen.');
        }

    } elseif ($action === 'bank_delete') {
        $bankId = intval($_POST['bank_id'] ?? 0);
        if ($bankId) {
            // Prüfen ob noch Daten hängen
            $checks = [
                'Benutzer'     => Database::fetchOne("SELECT COUNT(*) as c FROM users     WHERE bank_id = ?", [$bankId])['c'],
                'Kreditnehmer' => Database::fetchOne("SELECT COUNT(*) as c FROM borrowers WHERE bank_id = ?", [$bankId])['c'],
                'Kredite'      => Database::fetchOne("SELECT COUNT(*) as c FROM loans     WHERE bank_id = ?", [$bankId])['c'],
                'Konten'       => Database::fetchOne("SELECT COUNT(*) as c FROM customer_accounts WHERE bank_id = ?", [$bankId])['c'],
            ];
            $blocking = array_filter($checks, fn($c) => $c > 0);
            if ($blocking) {
                $info = implode(', ', array_map(fn($k, $v) => "{$v} {$k}", array_keys($blocking), $blocking));
                setFlash('error', "Bank kann nicht gelöscht werden – noch vorhandene Daten: {$info}.");
            } else {
                $bank = Database::fetchOne("SELECT name FROM banks WHERE id = ?", [$bankId]);
                Database::query("DELETE FROM loan_policies WHERE bank_id = ?", [$bankId]);
                Database::query("DELETE FROM banks WHERE id = ?", [$bankId]);
                AuditLog::log('DELETE', 'bank', $bankId, ['name' => $bank['name'] ?? '']);
                setFlash('success', 'Bank "' . ($bank['name'] ?? '') . '" und alle zugehörigen Policies gelöscht.');
            }
        }

    } elseif ($action === 'license_save') {
        $key    = trim($_POST['license_key'] ?? '');
        $result = LicenseManager::saveLicenseKey($key);
        setFlash($result['valid'] ? 'success' : 'error', $result['message']);
        header('Location: ' . APP_URL . '/pages/admin/index.php?tab=license');
        exit;
    }

    header('Location: ' . APP_URL . '/pages/admin/index.php?tab=' . urlencode($redirectTab));
    exit;
}

// ── Daten laden ─────────────────────────────────────────────────────────────
$banks = Database::fetchAll("SELECT * FROM banks ORDER BY id");
$users = Database::fetchAll("
    SELECT u.*, b.short_code as bank_short, b.name as bank_name,
           GROUP_CONCAT(r.id   ORDER BY r.id)   as role_ids,
           GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ',') as roles,
           GROUP_CONCAT(r.description ORDER BY r.id SEPARATOR ', ') as role_descriptions
    FROM users u
    LEFT JOIN banks b ON u.bank_id = b.id
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.bank_id, u.full_name
");
$roles = Database::fetchAll("SELECT * FROM roles ORDER BY name");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-shield-lock-fill me-2"></i>Administration</h4>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="adminTabs">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'banks' ? 'active' : '' ?>"
           href="?tab=banks">
            <i class="bi bi-building me-1"></i>Banken
            <span class="badge bg-secondary ms-1"><?= count($banks) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>"
           href="?tab=users">
            <i class="bi bi-people-fill me-1"></i>Benutzer
            <span class="badge bg-secondary ms-1"><?= count($users) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'license' ? 'active' : '' ?>"
           href="?tab=license">
            <i class="bi bi-key-fill me-1"></i>Lizenz
        </a>
    </li>
</ul>

<!-- ═══════════════════════════════════════════════════════════ TAB: BANKEN -->
<?php if ($activeTab === 'banks'): ?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bankCreateModal">
        <i class="bi bi-plus-lg me-1"></i>Neue Bank
    </button>
</div>

<div class="row g-3">
<?php foreach ($banks as $b):
    $hex = ltrim($b['primary_color'] ?? '#0d6efd', '#');
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $bv = hexdec(substr($hex,4,2));
?>
<div class="col-md-6 col-xl-4">
    <div class="card h-100" style="border-color: <?= e($b['primary_color']) ?>33;">
        <div class="card-header d-flex align-items-center gap-2"
             style="border-bottom-color: <?= e($b['primary_color']) ?>44; background: rgba(<?= $r ?>,<?= $g ?>,<?= $bv ?>,0.08);">
            <?php if ($b['logo_url']): ?>
                <img src="<?= e($b['logo_url']) ?>" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:4px;">
            <?php else: ?>
                <div style="width:28px;height:28px;border-radius:4px;background:<?= e($b['primary_color']) ?>;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-building-fill text-white" style="font-size:.8rem;"></i>
                </div>
            <?php endif; ?>
            <div class="flex-grow-1">
                <strong><?= e($b['name']) ?></strong>
                <span class="badge ms-1" style="background:<?= e($b['primary_color']) ?>;"><?= e($b['short_code']) ?></span>
            </div>
            <?php if (!$b['is_active']): ?>
                <span class="badge bg-danger">Inaktiv</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <table class="table table-sm table-borderless mb-0 small">
                <tr>
                    <td class="text-muted ps-0" style="width:45%">Hauptkonto</td>
                    <td class="font-monospace"><?= $b['main_account'] ? e($b['main_account']) : '<span class="text-muted">–</span>' ?></td>
                </tr>
                <tr>
                    <td class="text-muted ps-0">Primärfarbe</td>
                    <td>
                        <span style="display:inline-block;width:14px;height:14px;background:<?= e($b['primary_color']) ?>;border-radius:3px;vertical-align:middle;border:1px solid #444;"></span>
                        <span class="font-monospace ms-1"><?= e($b['primary_color']) ?></span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted ps-0">Angelegt</td>
                    <td><?= formatDate($b['created_at']) ?></td>
                </tr>
                <?php
                $userCount = Database::fetchOne("SELECT COUNT(*) as cnt FROM users WHERE bank_id = ?", [$b['id']])['cnt'] ?? 0;
                ?>
                <tr>
                    <td class="text-muted ps-0">Benutzer</td>
                    <td><?= $userCount ?></td>
                </tr>
            </table>
        </div>
        <div class="card-footer d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary flex-grow-1"
                    onclick="openBankEdit(<?= htmlspecialchars(json_encode([
                        'id'            => $b['id'],
                        'name'          => $b['name'],
                        'short_code'    => $b['short_code'],
                        'main_account'  => $b['main_account'] ?? '',
                        'primary_color' => $b['primary_color'] ?? '#0d6efd',
                        'logo_url'      => $b['logo_url'] ?? '',
                    ]), ENT_QUOTES) ?>)">
                <i class="bi bi-pencil me-1"></i>Bearbeiten
            </button>
            <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="bank_toggle">
                <input type="hidden" name="bank_id" value="<?= $b['id'] ?>">
                <input type="hidden" name="tab"     value="banks">
                <button type="submit"
                        class="btn btn-sm <?= $b['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= $b['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>">
                    <i class="bi bi-<?= $b['is_active'] ? 'pause' : 'play' ?>"></i>
                </button>
            </form>
            <form method="POST" class="d-inline"
                  onsubmit="return confirm('Bank \"<?= e(addslashes($b['name'])) ?>\" wirklich löschen?\n\nNur möglich wenn keine Benutzer, Kredite oder Konten mehr vorhanden sind.')">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="bank_delete">
                <input type="hidden" name="bank_id" value="<?= $b['id'] ?>">
                <input type="hidden" name="tab"     value="banks">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Bank löschen">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Modal: Neue Bank -->
<div class="modal fade" id="bankCreateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bank_create">
                <input type="hidden" name="tab"    value="banks">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-building-add me-2"></i>Neue Bank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-control" name="name" required placeholder="z.B. Pacific State Bank">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kürzel *</label>
                        <input type="text" class="form-control" name="short_code" required maxlength="10"
                               placeholder="z.B. PSB" style="text-transform:uppercase;">
                        <div class="form-text">Wird im Login und in der Navigation angezeigt (max. 10 Zeichen).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Hauptkonto (IBAN / Kontonummer)</label>
                        <input type="text" class="form-control font-monospace" name="main_account"
                               placeholder="z.B. PS2B61225563">
                        <div class="form-text">Wird beim Kontoauszug-Import als eigene Transaktion übersprungen.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Primärfarbe</label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="color" class="form-control form-control-color" name="primary_color"
                                   value="#0d6efd" style="width:60px;">
                            <span class="text-muted small">Bestimmt das Farbthema der Oberfläche für diese Bank.</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo-URL</label>
                        <input type="url" class="form-control" name="logo_url"
                               placeholder="https://…/logo.png">
                        <div class="form-text">Optionales Logo das in der Navbar angezeigt wird.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Bank bearbeiten -->
<div class="modal fade" id="bankEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="bank_update">
                <input type="hidden" name="tab"     value="banks">
                <input type="hidden" name="bank_id" id="edit_bank_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Bank bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-control" name="name" id="edit_bank_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kürzel *</label>
                        <input type="text" class="form-control" name="short_code" id="edit_bank_short_code"
                               required maxlength="10" style="text-transform:uppercase;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Hauptkonto (IBAN / Kontonummer)</label>
                        <input type="text" class="form-control font-monospace" name="main_account" id="edit_bank_main_account">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Primärfarbe</label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="color" class="form-control form-control-color" name="primary_color"
                                   id="edit_bank_color" style="width:60px;">
                            <span class="text-muted small">Bestimmt das Farbthema der Oberfläche.</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo-URL</label>
                        <input type="url" class="form-control" name="logo_url" id="edit_bank_logo">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ TAB: BENUTZER -->
<?php if ($activeTab === 'users'): ?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userCreateModal">
        <i class="bi bi-person-plus me-1"></i>Neuer Benutzer
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Benutzername</th>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Bank</th>
                        <th>Rolle</th>
                        <th>Letzter Login</th>
                        <th>Status</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $bank = array_values(array_filter($banks, fn($bk) => $bk['id'] == $user['bank_id']))[0] ?? null;
                        $bankColor = $bank['primary_color'] ?? '#6c757d';
                        $isSuperAdmin = str_contains($user['roles'] ?? '', 'super_admin');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($user['username']) ?></strong>
                            <?php if ($isSuperAdmin): ?>
                                <span class="badge bg-danger ms-1" title="Super-Admin">SA</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($user['full_name']) ?></td>
                        <td class="text-muted small"><?= $user['email'] ? e($user['email']) : '–' ?></td>
                        <td>
                            <span class="badge" style="background:<?= e($bankColor) ?>;">
                                <?= e($user['bank_short'] ?? '?') ?>
                            </span>
                            <span class="text-muted small ms-1"><?= e($user['bank_name'] ?? '') ?></span>
                        </td>
                        <td>
                            <?php foreach (explode(',', $user['roles'] ?? '') as $role): ?>
                            <?php if (trim($role)): ?>
                            <span class="badge bg-secondary"><?= e(trim($role)) ?></span>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $user['last_login'] ? formatDateTime($user['last_login']) : 'Nie' ?>
                        </td>
                        <td>
                            <span class="badge <?= $user['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                <?= $user['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    title="Bearbeiten"
                                    onclick="openUserEdit(<?= htmlspecialchars(json_encode([
                                        'id'            => $user['id'],
                                        'full_name'     => $user['full_name'],
                                        'email'         => $user['email'] ?? '',
                                        'bank_id'       => $user['bank_id'],
                                        'role_ids'      => $user['role_ids'] ? array_map('intval', array_filter(explode(',', $user['role_ids']))) : [],
                                        'is_super_admin'=> $isSuperAdmin,
                                    ]), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="user_reset_pw">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="tab"     value="users">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Reset-Link generieren"
                                        onclick="return confirm('Reset-Link für <?= e(addslashes($user['full_name'])) ?> generieren?')">
                                    <i class="bi bi-key"></i>
                                </button>
                            </form>
                            <?php if ($user['id'] !== Auth::userId()): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="user_toggle">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="tab"     value="users">
                                <button type="submit"
                                        class="btn btn-sm <?= $user['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                        title="<?= $user['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>">
                                    <i class="bi bi-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Benutzer \"<?= e(addslashes($user['full_name'])) ?>\" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden!')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="user_delete">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="tab"     value="users">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Benutzer löschen">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Neuer Benutzer -->
<div class="modal fade" id="userCreateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="user_create">
                <input type="hidden" name="tab"    value="users">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Neuer Benutzer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Benutzername *</label>
                            <input type="text" class="form-control" name="username" required autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Passwort *</label>
                            <input type="password" class="form-control" name="password" required autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Voller Name *</label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank *</label>
                            <select class="form-select" name="bank_id" required>
                                <option value="">– Bank wählen –</option>
                                <?php foreach ($banks as $bk): ?>
                                <?php if ($bk['is_active']): ?>
                                <option value="<?= $bk['id'] ?>"><?= e($bk['name']) ?> (<?= e($bk['short_code']) ?>)</option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Rolle *</label>
                            <select class="form-select" name="role_id" required>
                                <option value="">– Rolle wählen –</option>
                                <?php foreach ($roles as $role): if ($role['name'] === 'super_admin') continue; ?>
                                <option value="<?= $role['id'] ?>" data-name="<?= e($role['name']) ?>"><?= e($role['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_super_admin" id="create_super_admin" value="1">
                                <label class="form-check-label text-warning" for="create_super_admin">
                                    <i class="bi bi-shield-fill-exclamation me-1"></i>Super-Admin (Zugriff auf alle Banken)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Benutzer bearbeiten -->
<div class="modal fade" id="userEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="user_update">
                <input type="hidden" name="tab"     value="users">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Benutzer bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Voller Name *</label>
                            <input type="text" class="form-control" name="full_name" id="edit_user_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email" id="edit_user_email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank *</label>
                            <select class="form-select" name="bank_id" id="edit_user_bank_id" required>
                                <?php foreach ($banks as $bk): ?>
                                <option value="<?= $bk['id'] ?>"><?= e($bk['name']) ?> (<?= e($bk['short_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Rolle *</label>
                            <select class="form-select" name="role_id" id="edit_user_role_id" required>
                                <option value="">– Rolle wählen –</option>
                                <?php foreach ($roles as $role): if ($role['name'] === 'super_admin') continue; ?>
                                <option value="<?= $role['id'] ?>" data-name="<?= e($role['name']) ?>"><?= e($role['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_super_admin" id="edit_super_admin" value="1">
                                <label class="form-check-label text-warning" for="edit_super_admin">
                                    <i class="bi bi-shield-fill-exclamation me-1"></i>Super-Admin (Zugriff auf alle Banken)
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <hr class="my-1">
                            <label class="form-label text-muted">Neues Passwort <small>(leer lassen = unverändert)</small></label>
                            <input type="password" class="form-control" name="password" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($activeTab === 'license'):
    $licCfg    = LicenseManager::config();
    $instanceId= LicenseManager::instanceId();
    $hasKey    = !empty($licCfg['licenseKey']);
    $isValid   = $licCfg['lastValid'] ?? false;
    $lastCheck = isset($licCfg['lastChecked']) && $licCfg['lastChecked'] > 0
                 ? date('d.m.Y H:i', $licCfg['lastChecked']) : null;
?>
<div class="row g-4">
    <!-- Linke Spalte: Status + Key eintragen -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-check text-primary"></i>
                <strong>Lizenzstatus</strong>
            </div>
            <div class="card-body">
                <?php if (!$hasKey): ?>
                <div class="alert alert-warning mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Noch kein Lizenzschlüssel eingetragen.</strong><br>
                    <span class="small">Lizenzschlüssel kostenlos auf
                        <a href="https://flessinglabs.com" target="_blank" rel="noopener">flessinglabs.com</a>
                        holen und unten eintragen.
                    </span>
                </div>
                <?php elseif ($isValid): ?>
                <div class="alert alert-success mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <strong>Lizenz aktiv</strong>
                    <?php if ($lastCheck): ?>
                    <span class="text-muted" style="font-size:.82rem;"> — zuletzt geprüft: <?= $lastCheck ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-danger mb-4">
                    <i class="bi bi-x-circle-fill me-2"></i>
                    <strong>Lizenz ungültig oder abgelaufen.</strong>
                    <?php if ($lastCheck): ?>
                    <span class="text-muted" style="font-size:.82rem;"> — zuletzt geprüft: <?= $lastCheck ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <table class="table table-sm mb-4">
                    <tr>
                        <td class="text-muted" style="width:38%">Instance ID</td>
                        <td class="font-monospace small"><?= e($instanceId) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Lizenzschlüssel</td>
                        <td class="font-monospace small">
                            <?= $hasKey ? e($licCfg['licenseKey']) : '<span class="text-muted">–</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Letzte Prüfung</td>
                        <td><?= $lastCheck ?? '–' ?></td>
                    </tr>
                </table>

                <!-- Key eintragen / ändern -->
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="license_save">
                    <input type="hidden" name="tab"    value="license">
                    <label class="form-label fw-semibold">
                        <?= $hasKey ? 'Lizenzschlüssel ändern' : 'Lizenzschlüssel eintragen' ?>
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace"
                               name="license_key"
                               placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                               value="<?= e($licCfg['licenseKey'] ?? '') ?>"
                               required>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Aktivieren
                        </button>
                    </div>
                    <div class="form-text">
                        Schlüssel wird sofort gegen die API geprüft und gespeichert.
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte: Shop-Hinweis -->
    <div class="col-lg-5">
        <div class="card border-primary h-100">
            <div class="card-header text-primary d-flex align-items-center gap-2">
                <i class="bi bi-gift-fill"></i>
                <strong>Kostenlose Lizenz holen</strong>
            </div>
            <div class="card-body d-flex flex-column">
                <p class="mb-3">
                    PSB Kreditverwaltung benötigt einen Lizenzschlüssel. Dieser ist für den
                    Eigengebrauch <strong>kostenlos</strong> und kann direkt über den
                    FlessingLabs-Shop bezogen werden.
                </p>
                <a href="https://flessinglabs.com" target="_blank" rel="noopener"
                   class="btn btn-primary mb-4">
                    <i class="bi bi-box-arrow-up-right me-2"></i>flessinglabs.com — Kostenlos registrieren
                </a>
                <div class="text-muted small mt-auto">
                    <div class="d-flex gap-2 mb-2">
                        <i class="bi bi-1-circle text-primary flex-shrink-0 mt-1"></i>
                        <span>Auf <strong>flessinglabs.com</strong> registrieren</span>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <i class="bi bi-2-circle text-primary flex-shrink-0 mt-1"></i>
                        <span>PSB Kreditverwaltung im Shop auswählen &amp; kostenlosen Schlüssel erhalten</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-3-circle text-primary flex-shrink-0 mt-1"></i>
                        <span>Schlüssel links eintragen &amp; aktivieren</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openBankEdit(bank) {
    document.getElementById('edit_bank_id').value          = bank.id;
    document.getElementById('edit_bank_name').value        = bank.name;
    document.getElementById('edit_bank_short_code').value  = bank.short_code;
    document.getElementById('edit_bank_main_account').value= bank.main_account || '';
    document.getElementById('edit_bank_color').value       = bank.primary_color || '#0d6efd';
    document.getElementById('edit_bank_logo').value        = bank.logo_url || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bankEditModal')).show();
}

function openUserEdit(user) {
    document.getElementById('edit_user_id').value       = user.id;
    document.getElementById('edit_user_name').value     = user.full_name;
    document.getElementById('edit_user_email').value    = user.email || '';
    document.getElementById('edit_user_bank_id').value  = user.bank_id;
    document.getElementById('edit_super_admin').checked = user.is_super_admin;

    // super_admin-Rolle nicht im Dropdown zeigen – nur Haupt-Rolle wählen
    const saRoleOpt = document.querySelector('#edit_user_role_id option[data-name="super_admin"]');
    const saRoleId  = saRoleOpt ? parseInt(saRoleOpt.value) : null;
    const mainRoles = user.role_ids.filter(id => id !== saRoleId);

    const roleSelect = document.getElementById('edit_user_role_id');
    roleSelect.value = mainRoles.length > 0 ? mainRoles[0] : '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('userEditModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
