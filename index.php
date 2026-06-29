<?php
/**
 * PSB / Fortis Finance – Login mit Bank-Auswahl
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/AuditLog.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::init();
loadLanguage($_SESSION['lang'] ?? 'de');

if (Auth::check()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? APP_URL . '/pages/dashboard.php';

try {
    $availableBanks = Database::fetchAll("SELECT * FROM banks WHERE is_active = 1 ORDER BY id");
} catch (Exception $e) {
    $availableBanks = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $bankId   = (int)($_POST['bank_id'] ?? 1);

    if (empty($username) || empty($password)) {
        $error = t('login.error_empty');
    } elseif (Auth::login($username, $password, $bankId)) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = t('login.error_invalid');
    }
}

$selectedBankId = (int)($_POST['bank_id'] ?? 0);
$selectedBank   = null;
foreach ($availableBanks as $b) {
    if ($b['id'] === $selectedBankId) { $selectedBank = $b; break; }
}

// Grid-Spalten je nach Bank-Anzahl
$bankCount  = count($availableBanks);
$gridCols   = match(true) {
    $bankCount === 1 => 1,
    $bankCount === 2 => 2,
    $bankCount === 3 => 3,
    default          => 3,   // ab 4+: 3 Spalten, bricht um
};
?>
<!DOCTYPE html>
<html lang="<?= currentLang() === 'en' ? 'en' : 'de' ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('login.submit') ?> – <?= t('login.financial_group') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        :root {
            --login-accent:     #0d6efd;
            --login-accent-glow: rgba(13,110,253,0.22);
        }

        .login-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: radial-gradient(ellipse at 20% 50%, rgba(13,110,253,0.08) 0%, transparent 60%),
                        radial-gradient(ellipse at 80% 20%, rgba(201,162,39,0.05) 0%, transparent 55%),
                        linear-gradient(160deg, #0d1117 0%, #161b22 50%, #0d1117 100%);
            padding: 2rem 1rem;
        }

        .login-box {
            width: 100%;
            max-width: <?= $bankCount === 1 ? '380px' : ($bankCount === 2 ? '480px' : '600px') ?>;
        }

        /* ── Header ── */
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header .icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: rgba(13,110,253,0.1);
            border: 1px solid rgba(13,110,253,0.25);
            margin-bottom: 0.8rem;
        }
        .login-header .icon-wrap i { font-size: 1.6rem; color: #58a6ff; }
        .login-header h1 { font-size: 1.15rem; font-weight: 700; color: #e6edf3; margin-bottom: 0.2rem; }
        .login-header p  { font-size: 0.78rem; color: #6e7681; margin: 0; text-transform: uppercase; letter-spacing: 0.1em; }

        /* ── Bank-Grid ── */
        .bank-grid {
            display: grid;
            grid-template-columns: repeat(<?= $gridCols ?>, 1fr);
            gap: 0.9rem;
            margin-bottom: 0;
        }

        .bank-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.18s, transform 0.18s, box-shadow 0.18s;
            user-select: none;
        }
        .bank-card:hover {
            border-color: var(--card-color, #58a6ff);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
        }
        .bank-card .bank-icon {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            margin: 0 auto 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            border: 2px solid #30363d;
            overflow: hidden;
            background: rgba(255,255,255,0.03);
            transition: border-color 0.18s, background 0.18s;
        }
        .bank-card:hover .bank-icon {
            border-color: var(--card-color, #58a6ff);
            background: var(--card-glow, rgba(88,166,255,0.08));
        }
        .bank-card .bank-icon img { width: 100%; height: 100%; object-fit: contain; }
        .bank-card .bank-name { font-size: 0.9rem; font-weight: 600; color: #e6edf3; margin-bottom: 0.2rem; }
        .bank-card .bank-short {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 0.1rem 0.5rem;
            border-radius: 4px;
            background: rgba(255,255,255,0.06);
            color: #8b949e;
        }

        /* ── Login-Formular ── */
        .login-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 1.75rem;
            display: none;
        }
        .login-card.active {
            display: block;
            animation: fadeSlide 0.22s ease;
        }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .selected-bank-bar {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 1.4rem;
            padding: 0.65rem 0.9rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid #30363d;
            border-radius: 8px;
        }
        .selected-bank-bar .bank-dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: var(--login-accent);
            box-shadow: 0 0 7px var(--login-accent-glow);
            flex-shrink: 0;
        }
        .selected-bank-bar .bank-label { flex: 1; font-weight: 600; font-size: 0.88rem; color: #e6edf3; }
        .back-btn {
            background: none; border: none; color: #6e7681;
            font-size: 0.78rem; cursor: pointer; padding: 0;
            display: flex; align-items: center; gap: 0.25rem;
            transition: color 0.15s;
        }
        .back-btn:hover { color: #e6edf3; }

        .form-label { color: #8b949e; font-size: 0.81rem; margin-bottom: 0.3rem; }
        .form-control {
            background: #0d1117 !important; border-color: #30363d !important;
            color: #e6edf3 !important; border-radius: 8px;
        }
        .form-control:focus {
            border-color: var(--login-accent) !important;
            box-shadow: 0 0 0 3px var(--login-accent-glow) !important;
        }
        .input-group-text {
            background: #0d1117 !important; border-color: #30363d !important; color: #484f58 !important;
        }
        .btn-login {
            width: 100%; padding: 0.6rem; font-weight: 600; border-radius: 8px;
            background: var(--login-accent); border: 1px solid rgba(240,246,252,0.1);
            color: #fff; transition: filter 0.15s;
        }
        .btn-login:hover { filter: brightness(1.12); color: #fff; }

        /* ── Admin-Link ── */
        .admin-link {
            text-align: center;
            margin-top: 1.6rem;
            padding-top: 1.2rem;
            border-top: 1px solid #21262d;
        }
        .admin-link a {
            font-size: 0.78rem;
            color: #484f58;
            text-decoration: none;
            transition: color 0.15s;
        }
        .admin-link a:hover { color: #8b949e; }
        .admin-link a i { margin-right: 0.3rem; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-box">

        <div class="login-header">
            <div class="icon-wrap"><i class="bi bi-bank2"></i></div>
            <h1><?= t('login.financial_group') ?></h1>
            <p><?= t('login.select_institution') ?></p>
        </div>

        <!-- ══ Schritt 1: Bank-Auswahl ══ -->
        <div id="bank-selector">
            <?php if (empty($availableBanks)): ?>
            <div class="alert alert-warning text-center">
                <?= t('login.no_banks') ?><br>
                <a href="<?= APP_URL ?>/pages/admin/index.php" class="alert-link"><?= t('login.to_admin') ?></a>
            </div>
            <?php else: ?>
            <div class="bank-grid">
                <?php foreach ($availableBanks as $bank):
                    $hex = ltrim($bank['primary_color'] ?? '#0d6efd', '#');
                    $r   = hexdec(substr($hex,0,2));
                    $g   = hexdec(substr($hex,2,2));
                    $bv  = hexdec(substr($hex,4,2));
                    $glow = "rgba({$r},{$g},{$bv},0.15)";
                ?>
                <div class="bank-card"
                     data-bank-id="<?= $bank['id'] ?>"
                     data-bank-name="<?= e($bank['name']) ?>"
                     data-bank-color="<?= e($bank['primary_color'] ?? '#0d6efd') ?>"
                     data-bank-glow="<?= e($glow) ?>"
                     style="--card-color:<?= e($bank['primary_color'] ?? '#0d6efd') ?>;--card-glow:<?= e($glow) ?>;">
                    <div class="bank-icon">
                        <?php if (!empty($bank['logo_url'])): ?>
                            <img src="<?= e($bank['logo_url']) ?>" alt="<?= e($bank['name']) ?>">
                        <?php else: ?>
                            <i class="bi bi-building-fill" style="color:<?= e($bank['primary_color'] ?? '#58a6ff') ?>;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="bank-name"><?= e($bank['name']) ?></div>
                    <span class="bank-short"><?= e($bank['short_code']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="admin-link">
                <a href="<?= APP_URL ?>/pages/admin/login.php">
                    <i class="bi bi-shield-lock"></i>Administration
                </a>
            </div>
        </div>

        <!-- ══ Schritt 2: Login-Formular ══ -->
        <div id="login-card" class="login-card <?= $selectedBank ? 'active' : '' ?>">

            <div class="selected-bank-bar">
                <span class="bank-dot" id="bank-dot"></span>
                <span class="bank-label" id="selected-bank-label">
                    <?= $selectedBank ? e($selectedBank['name']) : '' ?>
                </span>
                <button type="button" class="back-btn" id="back-btn">
                    <i class="bi bi-arrow-left"></i> <?= t('login.change_bank') ?>
                </button>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-3" style="border-radius:8px;font-size:.875rem;">
                <i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <input type="hidden" name="bank_id"  value="<?= $selectedBank ? $selectedBank['id'] : '' ?>" id="bank-id-field">

                <div class="mb-3">
                    <label for="username" class="form-label"><?= t('login.username') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               required autocomplete="username" autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label"><?= t('login.password') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i><?= t('login.submit') ?>
                </button>
            </form>

            <div class="admin-link">
                <a href="<?= APP_URL ?>/pages/admin/login.php">
                    <i class="bi bi-shield-lock"></i><?= t('login.admin_link') ?>
                </a>
            </div>
        </div>

    </div>
</div>

<!-- Sprachumschalter (Login-Seite, unten rechts – dynamisch: alle lang/*.php werden erkannt) -->
<div style="position:fixed;bottom:1rem;right:1rem;display:flex;gap:0.4rem;z-index:99;">
    <?php
    $loginLangs = array_map(
        fn($f) => basename($f, '.php'),
        glob(__DIR__ . '/lang/*.php') ?: []
    );
    foreach ($loginLangs as $l):
    ?>
    <a href="<?= APP_URL ?>/pages/set_lang.php?lang=<?= $l ?>"
       class="btn btn-sm <?= currentLang() === $l ? 'btn-primary' : 'btn-outline-secondary' ?>"
       style="font-size:0.78rem;padding:0.2rem 0.6rem;"><?= strtoupper($l) ?></a>
    <?php endforeach; ?>
</div>

<script>
(function () {
    const bankSelector = document.getElementById('bank-selector');
    const loginCard    = document.getElementById('login-card');
    const bankIdField  = document.getElementById('bank-id-field');
    const bankDot      = document.getElementById('bank-dot');
    const bankLabel    = document.getElementById('selected-bank-label');
    const backBtn      = document.getElementById('back-btn');

    function applyColor(color, glow) {
        document.documentElement.style.setProperty('--login-accent', color);
        document.documentElement.style.setProperty('--login-accent-glow', glow);
        if (bankDot) bankDot.style.background = color;
    }

    function selectBank(id, name, color, glow) {
        bankIdField.value     = id;
        bankLabel.textContent = name;
        applyColor(color, glow);

        bankSelector.style.display = 'none';
        loginCard.classList.add('active');
        setTimeout(() => { const u = document.getElementById('username'); if (u) u.focus(); }, 50);
    }

    document.querySelectorAll('.bank-card').forEach(card => {
        card.addEventListener('click', function () {
            selectBank(
                this.dataset.bankId,
                this.dataset.bankName,
                this.dataset.bankColor,
                this.dataset.bankGlow
            );
        });
    });

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            bankSelector.style.display = '';
            loginCard.classList.remove('active');
            bankIdField.value = '';
        });
    }

    // Fehler-Rückkehr: Farbe der gewählten Bank wiederherstellen
    <?php if ($selectedBank):
        $hex = ltrim($selectedBank['primary_color'] ?? '#0d6efd', '#');
        $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $bv = hexdec(substr($hex,4,2));
        $glow = "rgba({$r},{$g},{$bv},0.22)";
    ?>
    applyColor(<?= json_encode($selectedBank['primary_color']) ?>, <?= json_encode($glow) ?>);
    bankSelector.style.display = 'none';
    <?php endif; ?>
})();
</script>
</body>
</html>
