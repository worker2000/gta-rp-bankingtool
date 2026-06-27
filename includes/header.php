<?php
/**
 * PSB / Fortis Finance – Header Template
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/AccountManager.php';
require_once __DIR__ . '/../classes/CreditScore.php';
require_once __DIR__ . '/../classes/LicenseManager.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

LicenseManager::check();
Auth::init();

$currentUser = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$bank        = Auth::bank();
$bankId      = Auth::bankId();

// CSS-Klasse für Bank-Theme
$bankClass = $bankId === 2 ? 'bank-ff' : 'bank-psb';
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Kreditverwaltung') ?> – <?= e($bank['name'] ?? APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <?php if (!empty($bank['primary_color'])): ?>
    <style>
        /* Bank-spezifische Primärfarbe aus DB */
        :root {
            --bank-primary: <?= e($bank['primary_color']) ?>;
        }
        <?php
        // RGB für Glow berechnen
        $hex = ltrim($bank['primary_color'], '#');
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        ?>
        :root {
            --bank-glow: rgba(<?= $r ?>, <?= $g ?>, <?= $b ?>, 0.25);
            --bank-primary-rgb: <?= $r ?>, <?= $g ?>, <?= $b ?>;
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $bankClass ?>">

<?php if (Auth::check()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bank-navbar">
    <div class="container-fluid">

        <!-- Brand: Bank-Logo + Name -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= APP_URL ?>/pages/dashboard.php">
            <?php if (!empty($bank['logo_url'])): ?>
                <img src="<?= e($bank['logo_url']) ?>"
                     alt="<?= e($bank['name']) ?>"
                     style="width:28px;height:28px;object-fit:contain;border-radius:4px;">
            <?php else: ?>
                <i class="bi bi-building-fill" style="color:var(--bank-primary);"></i>
            <?php endif; ?>
            <span class="fw-semibold"><?= e($bank['name'] ?? APP_NAME) ?></span>
            <span class="bank-badge"><?= e($bank['short_code'] ?? 'PSB') ?></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                <?php if (Auth::can('loans', 'view')): ?>
                <?php
                $pendingRefsCount = 0;
                if (Auth::can('import', 'upload')) {
                    $pendingRefsCount = (int)(Database::fetchOne(
                        "SELECT COUNT(*) as cnt FROM pending_loan_refs WHERE bank_id = ? AND status = 'PENDING'",
                        [currentBankId()]
                    )['cnt'] ?? 0);
                }
                ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/loans') ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-file-earmark-text me-1"></i>Kredite
                        <?php if ($pendingRefsCount > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $pendingRefsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/pages/loans/index.php">
                                <i class="bi bi-list-ul me-2"></i>Alle Kredite
                            </a>
                        </li>
                        <?php if (Auth::can('import', 'upload') && $pendingRefsCount > 0): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/pages/loans/pending_refs.php">
                                <i class="bi bi-hourglass-split me-2 text-warning"></i>Ausstehende Referenzen
                                <span class="badge bg-warning text-dark ms-1"><?= $pendingRefsCount ?></span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if (Auth::can('borrowers', 'view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/borrowers') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/borrowers/index.php">
                        <i class="bi bi-people me-1"></i>Kreditnehmer
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/accounts') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/accounts/index.php">
                        <i class="bi bi-wallet2 me-1"></i>Konten
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/documents') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/documents/index.php">
                        <i class="bi bi-folder2-open me-1"></i>Schreiben
                    </a>
                </li>
                <?php if (Auth::can('import', 'upload')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/import') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/import/index.php">
                        <i class="bi bi-upload me-1"></i>Import
                    </a>
                </li>
                <?php endif; ?>
                <?php if (Auth::can('dunning', 'view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/collections') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/collections/index.php">
                        <i class="bi bi-exclamation-triangle me-1"></i>Mahnwesen
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($bankId === 2): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/insurance') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                        <i class="bi bi-heart-pulse me-1"></i>Krankenversicherung
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/insurance/index') || (str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/insurance/') && !str_contains($_SERVER['REQUEST_URI'], '/employers') && !str_contains($_SERVER['REQUEST_URI'], '/group')) ? 'active' : '' ?>"
                               href="<?= APP_URL ?>/pages/insurance/index.php">
                                <i class="bi bi-person-vcard me-2"></i>Einzelverträge
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= str_contains($_SERVER['REQUEST_URI'], '/insurance/employers') || str_contains($_SERVER['REQUEST_URI'], '/insurance/group') ? 'active' : '' ?>"
                               href="<?= APP_URL ?>/pages/insurance/employers/index.php">
                                <i class="bi bi-building me-2"></i>Arbeitgeber
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/safeboxes') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/safeboxes/index.php">
                        <i class="bi bi-safe me-1"></i>Schließfächer
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/reports') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/reports/index.php">
                        <i class="bi bi-bar-chart-line me-1"></i>Berichte
                    </a>
                </li>
                <?php if (Auth::can('loans', 'view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/schufa') ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/pages/schufa/index.php"
                       title="Interbanken-Kreditauskunft">
                        <i class="bi bi-shield-check me-1"></i>Kreditauskunft
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav">
                <!-- Super-Admin: Bank wechseln -->
                <?php if (Auth::isSuperAdmin()): ?>
                <li class="nav-item me-2">
                    <form method="POST" action="<?= APP_URL ?>/pages/switch_bank.php" class="d-flex align-items-center">
                        <select name="bank_id" class="form-select form-select-sm"
                                style="background:#0d1117;border-color:#30363d;color:#e6edf3;width:auto;"
                                onchange="this.form.submit()">
                            <?php
                            try {
                                $allBanks = Database::fetchAll("SELECT id, name, short_code FROM banks WHERE is_active=1 ORDER BY id");
                            } catch (Exception $e) {
                                $allBanks = [];
                            }
                            foreach ($allBanks as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $b['id'] == $bankId ? 'selected' : '' ?>>
                                <?= e($b['short_code']) ?> – <?= e($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </li>
                <?php endif; ?>

                <!-- Benutzer-Menü -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= e($currentUser['full_name']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li>
                            <span class="dropdown-item-text text-muted small">
                                <?= e(implode(', ', $currentUser['roles'])) ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if (Auth::hasRole('director') || Auth::isSuperAdmin()): ?>
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/pages/settings/index.php">
                                <i class="bi bi-gear me-2"></i>Einstellungen
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/pages/users/index.php">
                                <i class="bi bi-people-fill me-2"></i>Benutzer
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/pages/templates/index.php">
                                <i class="bi bi-file-text me-2"></i>Textvorlagen
                            </a>
                        </li>
                        <?php if (Auth::isSuperAdmin()): ?>
                        <?php
                        $supportCount = (int)(Database::fetchOne(
                            "SELECT (SELECT COUNT(*) FROM loans WHERE dunning_hold=1) + (SELECT COUNT(*) FROM insurance_contracts WHERE dunning_hold=1) as cnt"
                        )['cnt'] ?? 0);
                        ?>
                        <li>
                            <a class="dropdown-item d-flex justify-content-between align-items-center"
                               href="<?= APP_URL ?>/pages/reports/support_cases.php">
                                <span><i class="bi bi-headset me-2"></i>Support-Fälle</span>
                                <?php if ($supportCount > 0): ?>
                                <span class="badge bg-warning text-dark"><?= $supportCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?= str_starts_with($_SERVER['REQUEST_URI'], APP_URL.'/pages/admin') ? 'active' : '' ?>"
                               href="<?= APP_URL ?>/pages/admin/index.php">
                                <i class="bi bi-shield-lock-fill me-2 text-danger"></i>Administration
                            </a>
                        </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item" href="<?= APP_URL ?>/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Abmelden
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="<?= Auth::check() ? 'container-fluid py-4' : '' ?>">
    <?php showFlash(); ?>
