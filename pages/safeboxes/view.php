<?php
ob_start();
/**
 * PSB / Fortis Finance – Schließfach Details
 */
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

$bid = currentBankId();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/safeboxes/index.php');
    exit;
}

$box = Database::fetchOne("
    SELECT s.*, b.first_name, b.last_name, b.customer_number, b.id as bid, b.phone, b.email
    FROM safeboxes s
    LEFT JOIN borrowers b ON s.borrower_id = b.id
    WHERE s.id = ? AND s.bank_id = ?", [$id, $bid]);

if (!$box) {
    setFlash('error', 'Schließfach nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/safeboxes/index.php');
    exit;
}

$pageTitle = 'Schließfach ' . $box['box_number'];

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'payment') {
        // Letzte Zahlung buchen
        $paymentDate = trim($_POST['payment_date'] ?? '');
        if (!$paymentDate) $paymentDate = date('Y-m-d');

        Database::update('safeboxes', ['last_payment_date' => $paymentDate], 'id = ?', [$id]);
        AuditLog::log('UPDATE', 'safebox', $id,
            ['last_payment_date' => $box['last_payment_date']],
            ['last_payment_date' => $paymentDate, 'action' => 'Zahlung gebucht']
        );
        setFlash('success', 'Zahlung vom ' . date('d.m.Y', strtotime($paymentDate)) . ' gebucht.');

    } elseif ($action === 'release') {
        // Schließfach freigeben
        $releasedBy   = trim($_POST['released_by']   ?? Auth::user()['full_name']);
        $releaseNotes = trim($_POST['release_notes']  ?? '');
        $releasedAt   = date('Y-m-d H:i:s');

        Database::update('safeboxes', [
            'status'      => 'RELEASED',
            'released_at' => $releasedAt,
            'released_by' => $releasedBy,
            'notes'       => $box['notes'] . ($releaseNotes ? "\n[Freigabe " . date('d.m.Y') . "]: " . $releaseNotes : ''),
        ], 'id = ?', [$id]);
        AuditLog::log('UPDATE', 'safebox', $id,
            ['status' => 'ACTIVE'],
            ['status' => 'RELEASED', 'released_by' => $releasedBy]
        );
        setFlash('success', 'Schließfach freigegeben.');

    } elseif ($action === 'reactivate') {
        Database::update('safeboxes', [
            'status'      => 'ACTIVE',
            'released_at' => null,
            'released_by' => null,
        ], 'id = ?', [$id]);
        AuditLog::log('UPDATE', 'safebox', $id, ['status' => 'RELEASED'], ['status' => 'ACTIVE']);
        setFlash('success', 'Schließfach reaktiviert.');

    } elseif ($action === 'edit') {
        $borrowerId    = intval($_POST['borrower_id']     ?? 0);
        $iban          = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $weeklyFee     = floatval($_POST['weekly_fee']    ?? 0);
        $staffInitials = trim($_POST['staff_initials']    ?? '');
        $notes         = trim($_POST['notes']             ?? '');

        Database::update('safeboxes', [
            'borrower_id'    => $borrowerId ?: null,
            'iban'           => $iban ?: null,
            'weekly_fee'     => $weeklyFee,
            'staff_initials' => $staffInitials ?: null,
            'notes'          => $notes ?: null,
        ], 'id = ?', [$id]);
        AuditLog::log('UPDATE', 'safebox', $id, null, ['weekly_fee' => $weeklyFee, 'borrower_id' => $borrowerId]);
        setFlash('success', 'Schließfach aktualisiert.');
    }

    header('Location: ' . APP_URL . '/pages/safeboxes/view.php?id=' . $id);
    exit;
}

// Kreditnehmer für Edit-Dropdown
$borrowers = Database::fetchAll(
    "SELECT id, customer_number, first_name, last_name FROM borrowers WHERE bank_id = ? AND is_active = 1 ORDER BY last_name, first_name",
    [$bid]
);

$daysSincePayment = $box['last_payment_date']
    ? (int)((time() - strtotime($box['last_payment_date'])) / 86400)
    : null;
$paymentWarning = $box['status'] === 'ACTIVE' && ($daysSincePayment === null || $daysSincePayment > 14);

$sizeBadge = match($box['box_size']) {
    'KLEIN'  => ['bg-secondary', 'Klein'],
    'MITTEL' => ['bg-info text-dark', 'Mittel'],
    'GROSS'  => ['bg-primary', 'Groß'],
    default  => ['bg-secondary', $box['box_size']],
};
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/safeboxes/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-safe me-2"></i>Schließfach <?= e($box['box_number']) ?>
            <span class="badge <?= $sizeBadge[0] ?> ms-2"><?= $sizeBadge[1] ?></span>
            <?php if ($box['status'] === 'ACTIVE'): ?>
            <span class="badge bg-success ms-1">Aktiv</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1">Freigegeben</span>
            <?php endif; ?>
        </h4>
    </div>
    <div class="d-flex gap-2">
        <?php if ($box['status'] === 'ACTIVE'): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayment">
            <i class="bi bi-cash me-2"></i>Zahlung buchen
        </button>
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalRelease">
            <i class="bi bi-unlock me-2"></i>Freigeben
        </button>
        <?php else: ?>
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="btn btn-outline-success"
                    onclick="return confirm('Schließfach reaktivieren?')">
                <i class="bi bi-lock me-2"></i>Reaktivieren
            </button>
        </form>
        <?php endif; ?>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit">
            <i class="bi bi-pencil me-2"></i>Bearbeiten
        </button>
    </div>
</div>

<?php if ($paymentWarning): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>
        <?php if ($daysSincePayment === null): ?>
        <strong>Keine Zahlung erfasst.</strong> Für dieses Schließfach wurde noch keine Wochengebühr gebucht.
        <?php else: ?>
        <strong>Zahlung überfällig.</strong> Letzte Zahlung vor <?= $daysSincePayment ?> Tagen (<?= formatDate($box['last_payment_date']) ?>).
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Fach-Daten -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-safe me-2"></i>Schließfach-Daten</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td class="text-muted" style="width:45%">Fach-Nummer</td>
                        <td><strong><?= e($box['box_number']) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Größe</td>
                        <td><span class="badge <?= $sizeBadge[0] ?>"><?= $sizeBadge[1] ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wochengebühr</td>
                        <td><strong><?= formatMoney($box['weekly_fee']) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">IBAN Mieter</td>
                        <td><code><?= e($box['iban'] ?: '–') ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Letzte Zahlung</td>
                        <td>
                            <?php if ($box['last_payment_date']): ?>
                            <?= formatDate($box['last_payment_date']) ?>
                            <?php if ($daysSincePayment !== null): ?>
                            <span class="badge <?= $daysSincePayment > 14 ? 'bg-danger' : 'bg-success' ?> ms-1">
                                <?= $daysSincePayment ?>d
                            </span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Mitarbeiter</td>
                        <td><?= e($box['staff_initials'] ?: '–') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td>
                            <?php if ($box['status'] === 'ACTIVE'): ?>
                            <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Freigegeben</span>
                            <?php if ($box['released_at']): ?>
                            <br><small class="text-muted"><?= formatDateTime($box['released_at']) ?>
                            <?php if ($box['released_by']): ?> von <?= e($box['released_by']) ?><?php endif; ?></small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Angelegt</td>
                        <td><?= formatDateTime($box['created_at']) ?></td>
                    </tr>
                </table>

                <?php if ($box['notes']): ?>
                <hr>
                <h6 class="text-muted">Notizen</h6>
                <p class="mb-0"><?= nl2br(e($box['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mieter -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person me-2"></i>Mieter</div>
            <div class="card-body">
                <?php if ($box['bid']): ?>
                <table class="table table-sm">
                    <tr>
                        <td class="text-muted" style="width:45%">Name</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $box['bid'] ?>">
                                <strong><?= e($box['last_name'] . ', ' . $box['first_name']) ?></strong>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kundennummer</td>
                        <td><code><?= e($box['customer_number']) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Telefon</td>
                        <td><?= e($box['phone'] ?: '–') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">E-Mail</td>
                        <td><?= e($box['email'] ?: '–') ?></td>
                    </tr>
                </table>
                <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $box['bid'] ?>"
                   class="btn btn-sm btn-outline-primary mt-2">
                    <i class="bi bi-person me-1"></i>Kundenprofil öffnen
                </a>
                <?php else: ?>
                <div class="empty-state py-4">
                    <i class="bi bi-person-x text-muted"></i>
                    <p class="mb-0">Kein Mieter zugewiesen</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Zahlung buchen -->
<div class="modal fade" id="modalPayment" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="payment">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cash me-2"></i>Zahlung buchen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Wochengebühr: <strong><?= formatMoney($box['weekly_fee']) ?></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Zahlungsdatum</label>
                        <input type="date" class="form-control" name="payment_date"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-2"></i>Zahlung erfassen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Freigeben -->
<div class="modal fade" id="modalRelease" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="release">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-unlock me-2"></i>Schließfach freigeben</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Das Schließfach wird als freigegeben markiert.</p>
                    <div class="mb-3">
                        <label class="form-label">Freigegeben von (Initialen/Name)</label>
                        <input type="text" class="form-control" name="released_by"
                               value="<?= e($box['staff_initials'] ?: '') ?>" placeholder="z.B. LdM">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notiz zur Freigabe</label>
                        <textarea class="form-control" name="release_notes" rows="2"
                                  placeholder="Optional: Grund, Bemerkungen..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-unlock me-2"></i>Jetzt freigeben
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Bearbeiten -->
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Schließfach bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Mieter</label>
                            <select class="form-select" name="borrower_id">
                                <option value="">– kein Mieter –</option>
                                <?php foreach ($borrowers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $box['bid'] == $b['id'] ? 'selected' : '' ?>>
                                    <?= e($b['customer_number']) ?> – <?= e($b['last_name'] . ', ' . $b['first_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Wochengebühr ($)</label>
                            <input type="number" class="form-control" name="weekly_fee" step="0.01" min="0"
                                   value="<?= e($box['weekly_fee']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN Mieter</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($box['iban']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mitarbeiter-Initialen</label>
                            <input type="text" class="form-control" name="staff_initials" maxlength="10"
                                   value="<?= e($box['staff_initials']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($box['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
