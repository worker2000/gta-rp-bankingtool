<?php
/**
 * PSB / Fortis Finance – Hilfsfunktionen
 */

// Globales Übersetzungs-Array; wird via loadLanguage() befüllt
$GLOBALS['translations'] = [];

/**
 * Lädt die Sprachdatei für die gegebene Locale.
 * Fallback: Deutsch.
 */
function loadLanguage(string $locale = 'de'): void {
    $allowed = ['de', 'en'];
    if (!in_array($locale, $allowed, true)) {
        $locale = 'de';
    }
    $file = __DIR__ . '/../lang/' . $locale . '.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/../lang/de.php';
    }
    $GLOBALS['translations'] = require $file;
}

/**
 * Übersetzt einen Schlüssel. Gibt den Schlüssel zurück, wenn keine Übersetzung vorhanden.
 * Optionaler $fallback wird zurückgegeben, wenn Schlüssel nicht gefunden.
 */
function t(string $key, string $fallback = ''): string {
    if (!empty($GLOBALS['translations'][$key])) {
        return $GLOBALS['translations'][$key];
    }
    return $fallback !== '' ? $fallback : $key;
}

/**
 * Gibt die aktuelle Sprache zurück (aus Session).
 */
function currentLang(): string {
    return $_SESSION['lang'] ?? 'de';
}

/**
 * Gibt die aktuelle Bank-ID zurück (aus Session)
 */
function currentBankId(): int {
    return Auth::bankId();
}

/**
 * Formatiert einen Geldbetrag
 */
function formatMoney(float $amount): string {
    return number_format(round($amount), 0, '', '.') . ' $';
}

/**
 * Formatiert ein Datum
 */
function formatDate(?string $date): string {
    if (!$date) return '-';
    return date('d.m.Y', strtotime($date));
}

/**
 * Formatiert Datum und Uhrzeit
 */
function formatDateTime(?string $datetime): string {
    if (!$datetime) return '-';
    return date('d.m.Y H:i', strtotime($datetime));
}

/**
 * Escape für HTML-Ausgabe
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generiert ein Aktenzeichen – Bank-spezifisches Präfix verhindert Kollisionen
 */
function generateFileNumber(string $productType): string {
    $bankId = currentBankId();

    // Bank-Präfix: PSB = leer, Fortis Finance = "FF-"
    $bankPrefix = $bankId === 2 ? 'FF-' : '';

    $typePrefix = match($productType) {
        'AUTO'     => 'AK',
        'BUSINESS' => 'BK',
        default    => 'PK'
    };

    $year   = date('Y');
    $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

    return "{$bankPrefix}{$typePrefix}-{$year}-{$random}";
}

/**
 * Generiert eine Zahlungsreferenz
 */
function generatePaymentReference(string $fileNumber): string {
    return "RATE-" . str_replace('-', '', $fileNumber);
}

/**
 * Berechnet den Ratenplan (gleichmäßige Raten).
 * Optional: $customFinalRate = variable Restrate/Schlussrate.
 */
function calculateSchedule(
    float $loanAmount,
    float $interestRate,
    int $termWeeks,
    string $startDate,
    ?float $customFinalRate = null
): array {
    $totalInterest = round($loanAmount * $interestRate);
    $totalAmount   = round($loanAmount + $totalInterest);

    if ($customFinalRate !== null && $customFinalRate > 0 && $termWeeks > 1) {
        $finalRate       = round($customFinalRate);
        $remainingAmount = $totalAmount - $finalRate;
        $weeklyRate      = round($remainingAmount / ($termWeeks - 1));
        $regularTotal    = $weeklyRate * ($termWeeks - 1);
        $correction      = $remainingAmount - $regularTotal;
    } else {
        $weeklyRate      = round($totalAmount / $termWeeks);
        $finalRate       = $totalAmount - ($weeklyRate * ($termWeeks - 1));
        $customFinalRate = null;
        $correction      = 0;
    }

    $schedule    = [];
    $currentDate = new DateTime($startDate);

    for ($i = 1; $i <= $termWeeks; $i++) {
        $currentDate->modify('+7 days');

        if ($i === $termWeeks) {
            $payment = $finalRate;
        } elseif ($customFinalRate !== null && $i === $termWeeks - 1) {
            $payment = $weeklyRate + $correction;
        } else {
            $payment = $weeklyRate;
        }

        $schedule[] = [
            'installment_number' => $i,
            'due_date'           => $currentDate->format('Y-m-d'),
            'amount_due'         => $payment,
            'amount_outstanding' => $payment
        ];
    }

    return [
        'total_interest'  => $totalInterest,
        'total_amount'    => $totalAmount,
        'weekly_rate'     => $weeklyRate,
        'custom_final_rate' => $customFinalRate,
        'end_date'        => $currentDate->format('Y-m-d'),
        'items'           => $schedule
    ];
}


/**
 * Übersetzt Loan-Status (sprachbewusst)
 */
function translateLoanStatus(string $status): string {
    $fallbacks = [
        'APPLICATION_RECEIVED' => 'Antrag eingegangen',
        'IN_REVIEW'            => 'In Prüfung',
        'APPROVED'             => 'Genehmigt',
        'REJECTED'             => 'Abgelehnt',
        'CONTRACT_CREATED'     => 'Vertrag erstellt',
        'ACTIVE'               => 'Aktiv',
        'DUNNING_L1'           => 'Mahnung Stufe 1',
        'DUNNING_L2'           => 'Mahnung Stufe 2',
        'TERMINATED'           => 'Gekündigt',
        'REPOSSESSION'         => 'Sicherstellung',
        'CLOSED'               => 'Abgeschlossen',
        'WITHDRAWN'            => 'Widerrufen',
    ];
    return t('status.' . $status, $fallbacks[$status] ?? $status);
}

/**
 * Gibt die CSS-Klasse für einen Status zurück
 */
function getStatusBadgeClass(string $status): string {
    return match($status) {
        'APPLICATION_RECEIVED', 'IN_REVIEW' => 'bg-info',
        'APPROVED', 'CONTRACT_CREATED'      => 'bg-primary',
        'ACTIVE'                             => 'bg-success',
        'DUNNING_L1'                         => 'bg-warning',
        'DUNNING_L2'                         => 'bg-orange',
        'TERMINATED', 'REPOSSESSION'         => 'bg-danger',
        'REJECTED'                           => 'bg-secondary',
        'CLOSED'                             => 'bg-dark',
        'WITHDRAWN'                          => 'bg-light text-secondary border',
        default                              => 'bg-secondary'
    };
}

/**
 * Übersetzt Produkttyp (sprachbewusst)
 */
function translateProductType(string $type): string {
    $fallbacks = [
        'AUTO'      => 'Autokredit',
        'BUSINESS'  => 'Geschäftskredit',
        'PRIVATE'   => 'Privatkredit',
        'INSURANCE' => 'Krankenversicherung',
    ];
    return t('product.' . $type, $fallbacks[$type] ?? $type);
}

/**
 * Holt eine Policy – bank-spezifisch gefiltert
 */
function getPolicy(string $key, $default = null) {
    static $cache = [];

    $bankId   = currentBankId();
    $cacheKey = "{$bankId}:{$key}";

    if (!isset($cache[$cacheKey])) {
        $policy = Database::fetchOne(
            "SELECT policy_value FROM loan_policies
             WHERE policy_key = ? AND bank_id = ?
             AND valid_from <= CURDATE()
             AND (valid_until IS NULL OR valid_until >= CURDATE())
             ORDER BY valid_from DESC LIMIT 1",
            [$key, $bankId]
        );
        $cache[$cacheKey] = $policy ? $policy['policy_value'] : $default;
    }

    return $cache[$cacheKey];
}

/**
 * Setzt eine Flash-Nachricht
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Zeigt Flash-Nachricht an
 */
function showFlash(): void {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        $class = match($flash['type']) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info'
        };
        echo '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">';
        echo $flash['message']; // kann sicheres HTML enthalten (intern generiert)
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
        unset($_SESSION['flash']);
    }
}

/**
 * CSRF Token generieren
 */
function csrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token prüfen
 */
function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Generiert CSRF-Hidden-Field
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}
