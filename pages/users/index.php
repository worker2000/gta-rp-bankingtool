<?php
ob_start();
/**
 * PSB Kreditverwaltung - Benutzerverwaltung
 */
$pageTitle = 'Benutzerverwaltung';
require_once __DIR__ . '/../../includes/header.php';

if (!Auth::hasRole('director')) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$isSuperAdmin = Auth::isSuperAdmin();
$currentBankId = currentBankId();

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $roleId   = intval($_POST['role_id'] ?? 0);
        $bankId   = $isSuperAdmin ? intval($_POST['bank_id'] ?? $currentBankId) : $currentBankId;

        if ($username && $password && $fullName && $roleId) {
            $id = Database::insert('users', [
                'bank_id'       => $bankId,
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => $fullName,
                'email'         => $email ?: null,
            ]);
            Database::insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            AuditLog::log('CREATE', 'user', $id);
            setFlash('success', 'Benutzer "' . $fullName . '" angelegt.');
        } else {
            setFlash('error', 'Bitte alle Pflichtfelder ausfüllen.');
        }

    } elseif ($action === 'update') {
        $userId   = intval($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $roleId   = intval($_POST['role_id'] ?? 0);
        $bankId   = $isSuperAdmin ? intval($_POST['bank_id'] ?? 0) : null;
        $password = $_POST['password'] ?? '';

        if ($userId && $fullName && $roleId) {
            // Sicherstellen, dass nur eigene Bank (außer super_admin)
            $targetUser = Database::fetchOne("SELECT id, bank_id FROM users WHERE id = ?", [$userId]);
            if ($targetUser && ($isSuperAdmin || $targetUser['bank_id'] === $currentBankId)) {
                $data = ['full_name' => $fullName, 'email' => $email ?: null];
                if ($isSuperAdmin && $bankId) $data['bank_id'] = $bankId;
                if ($password) $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);

                Database::update('users', $data, 'id = ?', [$userId]);

                // Rolle aktualisieren
                Database::query("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
                Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);

                AuditLog::log('UPDATE', 'user', $userId);
                setFlash('success', "Benutzer aktualisiert.");
            }
        }

    } elseif ($action === 'toggle') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId && $userId !== Auth::userId()) {
            $user = Database::fetchOne("SELECT is_active FROM users WHERE id = ?", [$userId]);
            Database::update('users', ['is_active' => !$user['is_active']], 'id = ?', [$userId]);
            AuditLog::log('TOGGLE_ACTIVE', 'user', $userId);
            setFlash('success', 'Benutzerstatus aktualisiert.');
        }
    }

    header('Location: ' . APP_URL . '/pages/users/index.php');
    exit;
}

// Benutzerliste – Super-Admin sieht alle
if ($isSuperAdmin) {
    $users = Database::fetchAll("
        SELECT u.*, b.short_code as bank_short, b.name as bank_name,
               GROUP_CONCAT(r.id ORDER BY r.id) as role_ids,
               GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ',') as roles,
               GROUP_CONCAT(r.description ORDER BY r.id SEPARATOR ', ') as role_descriptions
        FROM users u
        LEFT JOIN banks b ON u.bank_id = b.id
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        GROUP BY u.id
        ORDER BY u.bank_id, u.full_name
    ");
} else {
    $users = Database::fetchAll("
        SELECT u.*, b.short_code as bank_short, b.name as bank_name,
               GROUP_CONCAT(r.id ORDER BY r.id) as role_ids,
               GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ',') as roles,
               GROUP_CONCAT(r.description ORDER BY r.id SEPARATOR ', ') as role_descriptions
        FROM users u
        LEFT JOIN banks b ON u.bank_id = b.id
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.bank_id = ?
        GROUP BY u.id
        ORDER BY u.full_name
    ", [$currentBankId]);
}

$roles = Database::fetchAll("SELECT * FROM roles ORDER BY name");
$banks = Database::fetchAll("SELECT * FROM banks ORDER BY id");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-people-fill me-2"></i>Benutzerverwaltung</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-person-plus me-2"></i>Neuer Benutzer
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Benutzername</th>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <?php if ($isSuperAdmin): ?>
                        <th>Bank</th>
                        <?php endif; ?>
                        <th>Rolle</th>
                        <th>Letzter Login</th>
                        <th>Status</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= e($user['username']) ?></strong></td>
                        <td><?= e($user['full_name']) ?></td>
                        <td class="text-muted small"><?= $user['email'] ? e($user['email']) : '–' ?></td>
                        <?php if ($isSuperAdmin): ?>
                        <td>
                            <span class="badge <?= $user['bank_id'] == 1 ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                <?= e($user['bank_short'] ?? '?') ?>
                            </span>
                        </td>
                        <?php endif; ?>
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
                                    class="btn btn-sm btn-outline-primary btn-action"
                                    title="Bearbeiten"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                        'id'       => $user['id'],
                                        'full_name'=> $user['full_name'],
                                        'email'    => $user['email'] ?? '',
                                        'bank_id'  => $user['bank_id'],
                                        'role_ids' => $user['role_ids'] ? array_map('intval', explode(',', $user['role_ids'])) : [],
                                    ]), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($user['id'] !== Auth::userId()): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-<?= $user['is_active'] ? 'warning' : 'success' ?> btn-action"
                                        title="<?= $user['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>">
                                    <i class="bi bi-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
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

<!-- Neuer-Benutzer-Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Neuer Benutzer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Benutzername *</label>
                        <input type="text" class="form-control" name="username" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Passwort *</label>
                        <input type="password" class="form-control" name="password" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Voller Name *</label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                    <?php if ($isSuperAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Bank *</label>
                        <select class="form-select" name="bank_id" required>
                            <?php foreach ($banks as $bank): ?>
                            <option value="<?= $bank['id'] ?>"><?= e($bank['name']) ?> (<?= e($bank['short_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Rolle *</label>
                        <select class="form-select" name="role_id" required>
                            <option value="">– Rolle wählen –</option>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= e($role['description']) ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Bearbeiten-Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Benutzer bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Voller Name *</label>
                        <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                    </div>
                    <?php if ($isSuperAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Bank</label>
                        <select class="form-select" name="bank_id" id="edit_bank_id">
                            <?php foreach ($banks as $bank): ?>
                            <option value="<?= $bank['id'] ?>"><?= e($bank['name']) ?> (<?= e($bank['short_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Rolle *</label>
                        <select class="form-select" name="role_id" id="edit_role_id" required>
                            <option value="">– Rolle wählen –</option>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= e($role['description']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label text-muted">Neues Passwort <small>(leer lassen = unverändert)</small></label>
                        <input type="password" class="form-control" name="password" autocomplete="new-password">
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

<script>
function openEditModal(user) {
    document.getElementById('edit_user_id').value  = user.id;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value    = user.email || '';

    <?php if ($isSuperAdmin): ?>
    document.getElementById('edit_bank_id').value  = user.bank_id;
    <?php endif; ?>

    const roleSelect = document.getElementById('edit_role_id');
    roleSelect.value = user.role_ids.length > 0 ? user.role_ids[0] : '';

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
