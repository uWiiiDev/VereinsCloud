<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Cloud\Auth;
use Cloud\Database;
use Cloud\MemberManager;
use Cloud\TwoFactor;

Auth::startSession();
Auth::sendSecurityHeaders();

$pdo = Database::get();

// Ensure table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS membership_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'pending',
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        street TEXT NOT NULL,
        zip TEXT NOT NULL,
        city TEXT NOT NULL,
        birth_date TEXT NULL,
        birth_place TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        fax TEXT NOT NULL DEFAULT '',
        mobile TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL,
        school_student_until TEXT NOT NULL DEFAULT '',
        special_pass TEXT NOT NULL DEFAULT '',
        join_date TEXT NOT NULL DEFAULT '',
        fee_type TEXT NOT NULL DEFAULT 'aktiv',
        fee_amount REAL NOT NULL DEFAULT 0,
        department TEXT NOT NULL DEFAULT '',
        iban TEXT NOT NULL DEFAULT '',
        bic TEXT NOT NULL DEFAULT '',
        account_holder TEXT NOT NULL DEFAULT '',
        mandate_ref TEXT NOT NULL DEFAULT 'DE47ZZZ00000777063',
        signature_png TEXT NOT NULL,
        sepa_signature_png TEXT NOT NULL DEFAULT '',
        submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
        verified_at TEXT NULL,
        ip_address TEXT NOT NULL DEFAULT '',
        user_agent TEXT NOT NULL DEFAULT ''
    );
");

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

// =============================================================================
// Helper: Protocol and Base URL
// =============================================================================
function getBaseUrl(): string {
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
             (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $proto . $host . ($dir ? $dir : '');
}

// =============================================================================
// AJAX POST: Antrag einreichen (Submit)
// =============================================================================
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige JSON-Anfrage.']);
        exit;
    }

    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName  = trim((string)($input['last_name'] ?? ''));
    $street    = trim((string)($input['street'] ?? ''));
    $zip       = trim((string)($input['zip'] ?? ''));
    $city      = trim((string)($input['city'] ?? ''));
    $birthDate = trim((string)($input['birth_date'] ?? ''));
    $birthPlace= trim((string)($input['birth_place'] ?? ''));
    $phone     = trim((string)($input['phone'] ?? ''));
    $fax       = trim((string)($input['fax'] ?? ''));
    $mobile    = trim((string)($input['mobile'] ?? ''));
    $email     = trim((string)($input['email'] ?? ''));
    $emailRep  = trim((string)($input['email_repeat'] ?? ''));
    $school    = trim((string)($input['school_student_until'] ?? ''));
    $specialPass = trim((string)($input['special_pass'] ?? ''));
    $joinDate  = trim((string)($input['join_date'] ?? '')) ?: date('Y-m-d');
    $feeType   = trim((string)($input['fee_type'] ?? 'aktiv'));
    
    // Departments: support single, multiple or custom
    $depts = [];
    if (isset($input['department'])) {
        if (is_array($input['department'])) {
            $depts = array_filter(array_map('trim', $input['department']));
        } elseif (trim((string)$input['department']) !== '') {
            $depts[] = trim((string)$input['department']);
        }
    }
    $deptCustom = trim((string)($input['department_custom'] ?? ''));
    if ($deptCustom !== '') {
        $depts[] = $deptCustom;
    }
    $department = implode(', ', array_unique($depts));
    if ($department === '') {
        $department = 'Hauptverein';
    }

    $terms     = !empty($input['terms_accepted']);

    // SEPA
    $accHolder = trim((string)($input['account_holder'] ?? '')) ?: ($firstName . ' ' . $lastName);
    $iban      = strtoupper(str_replace(' ', '', trim((string)($input['iban'] ?? ''))));
    $bic       = strtoupper(str_replace(' ', '', trim((string)($input['bic'] ?? ''))));
    $mandateRef= trim((string)($input['mandate_ref'] ?? '')) ?: 'DE47ZZZ00000777063';

    // Signatures (Base64 PNG)
    $sig1      = trim((string)($input['signature_png'] ?? ''));
    $sig2      = trim((string)($input['sepa_signature_png'] ?? '')) ?: $sig1;

    // Validation
    if ($firstName === '' || $lastName === '') {
        echo json_encode(['ok' => false, 'error' => 'Bitte geben Sie Ihren vollständigen Namen an.']);
        exit;
    }
    if ($street === '' || $zip === '' || $city === '') {
        echo json_encode(['ok' => false, 'error' => 'Bitte füllen Sie Ihre Anschrift vollständig aus.']);
        exit;
    }
    if ($birthDate === '') {
        echo json_encode(['ok' => false, 'error' => 'Bitte geben Sie Ihr Geburtsdatum an.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.']);
        exit;
    }
    if ($emailRep !== '' && strtolower($email) !== strtolower($emailRep)) {
        echo json_encode(['ok' => false, 'error' => 'Die wiederholte E-Mail-Adresse stimmt nicht überein.']);
        exit;
    }
    if (!$terms) {
        echo json_encode(['ok' => false, 'error' => 'Bitte bestätigen Sie die allgemeinen Bedingungen und Satzungsbestimmungen.']);
        exit;
    }
    if ($iban === '') {
        echo json_encode(['ok' => false, 'error' => 'Bitte geben Sie Ihre IBAN für das SEPA-Lastschriftmandat an.']);
        exit;
    }
    $ibanCheck = MemberManager::validateIban($iban);
    if (!$ibanCheck['valid']) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige IBAN: ' . $ibanCheck['error']]);
        exit;
    }
    if (strpos($sig1, 'data:image/png;base64,') !== 0 && strlen($sig1) < 100) {
        echo json_encode(['ok' => false, 'error' => 'Bitte unterschreiben Sie das Formular im Unterschriftenfeld.']);
        exit;
    }

    // Determine fee amount
    $feeAmount = 75.0;
    if (str_contains(strtolower($feeType), 'passiv')) $feeAmount = 40.0;
    elseif (str_contains(strtolower($feeType), 'jugend') || str_contains(strtolower($feeType), 'schueler')) $feeAmount = 45.0;
    elseif (str_contains(strtolower($feeType), 'familie')) $feeAmount = 130.0;

    $token = bin2hex(random_bytes(24));
    $ip    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua    = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO membership_applications (
            token, status, first_name, last_name, street, zip, city,
            birth_date, birth_place, phone, fax, mobile, email,
            school_student_until, special_pass, join_date, fee_type, fee_amount,
            department, iban, bic, account_holder, mandate_ref,
            signature_png, sepa_signature_png, submitted_at, ip_address, user_agent
        ) VALUES (
            :tok, 'pending', :fn, :ln, :st, :zip, :ct,
            :bd, :bp, :ph, :fax, :mob, :em,
            :sch, :sp, :jd, :ft, :fa,
            :dp, :ib, :bic, :ah, :mr,
            :sig1, :sig2, datetime('now'), :ip, :ua
        )
    ");

    $stmt->execute([
        ':tok' => $token,
        ':fn'  => $firstName,
        ':ln'  => $lastName,
        ':st'  => $street,
        ':zip' => $zip,
        ':ct'  => $city,
        ':bd'  => $birthDate,
        ':bp'  => $birthPlace,
        ':ph'  => $phone,
        ':fax' => $fax,
        ':mob' => $mobile,
        ':em'  => $email,
        ':sch' => $school,
        ':sp'  => $specialPass,
        ':jd'  => $joinDate,
        ':ft'  => $feeType,
        ':fa'  => $feeAmount,
        ':dp'  => $department,
        ':ib'  => $iban,
        ':bic' => $bic,
        ':ah'  => $accHolder,
        ':mr'  => $mandateRef,
        ':sig1'=> $sig1,
        ':sig2'=> $sig2,
        ':ip'  => $ip,
        ':ua'  => $ua,
    ]);

    $verifyUrl = getBaseUrl() . '/antrag.php?action=verify&token=' . urlencode($token);

    // E-Mail-Versand vorbereiten
    $mailSubject = 'Bitte bestätigen: Mitgliedsantrag SGV Böbingen 1920 e.V.';
    $mailBody = "Hallo {$firstName} {$lastName},\n\n"
              . "vielen Dank für deinen Online-Mitgliedsantrag beim Sport- u. Gesangverein Böbingen 1920 e.V.!\n\n"
              . "Um deine E-Mail-Adresse zu bestätigen und deinen Antrag verbindlich einzureichen, klicke bitte auf den folgenden Link:\n\n"
              . "{$verifyUrl}\n\n"
              . "Nach der Bestätigung kannst du deine offizielle Beitrittserklärung sofort als PDF herunterladen und ausdrucken.\n\n"
              . "Solltest du diesen Antrag nicht selbst ausgefüllt haben, kannst du diese Nachricht einfach ignorieren.\n\n"
              . "Sportliche Grüße,\n"
              . "Sport- u. Gesangverein Böbingen 1920 e.V.\n"
              . "Bucher Straße 17, 73560 Böbingen\n";

    $mailSent = false;
    $mailError = null;
    try {
        TwoFactor::sendSystemEmail($email, $mailSubject, $mailBody);
        $mailSent = true;
    } catch (\Throwable $e) {
        $mailError = $e->getMessage();
    }

    echo json_encode([
        'ok'          => true,
        'email'       => $email,
        'token'       => $token,
        'verify_url'  => $verifyUrl,
        'mail_sent'   => $mailSent,
        'mail_error'  => $mailError,
    ]);
    exit;
}

// =============================================================================
// GET: Antrag verifizieren (Double-Opt-In Confirmation)
// =============================================================================
if ($action === 'verify') {
    $token = trim((string)($_GET['token'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM membership_applications WHERE token = :t");
    $stmt->execute([':t' => $token]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    $verifiedNow = false;
    if ($app) {
        if ($app['status'] === 'pending') {
            $pdo->prepare("UPDATE membership_applications SET status = 'verified', verified_at = datetime('now') WHERE id = :id")
                ->execute([':id' => $app['id']]);
            $app['status'] = 'verified';
            $app['verified_at'] = date('Y-m-d H:i:s');
            $verifiedNow = true;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Mitgliedsantrag bestätigt – SGV Böbingen 1920 e.V.</title>
        <link rel="stylesheet" href="assets/css/app.css">
        <style>
            body { background: #0f172a; color: #f8fafc; font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1rem; }
            .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; max-width: 560px; width: 100%; padding: 2rem; box-shadow: 0 15px 35px rgba(0,0,0,0.4); text-align: center; }
            .badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; padding: 0.4rem 0.8rem; border-radius: 999px; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; }
            .btn { display: inline-block; padding: 0.75rem 1.5rem; font-size: 1rem; font-weight: 600; text-decoration: none; border-radius: 8px; cursor: pointer; transition: background 0.15s; }
            .btn-primary { background: #3b82f6; color: #fff; border: none; }
            .btn-primary:hover { background: #2563eb; }
            .btn-ghost { background: transparent; color: #94a3b8; border: 1px solid #475569; }
            .btn-ghost:hover { background: #334155; color: #fff; }
        </style>
    </head>
    <body>
        <div class="card">
            <?php if (!$app): ?>
                <div style="font-size:3rem;margin-bottom:1rem">⚠️</div>
                <h2 style="margin:0 0 0.5rem">Ungültiger Bestätigungslink</h2>
                <p style="color:#94a3b8">Der Bestätigungslink ist ungültig oder abgelaufen.</p>
                <a href="antrag.php" class="btn btn-primary" style="margin-top:1rem">Zum Mitgliedsantrag</a>
            <?php else: ?>
                <div style="font-size:3.5rem;margin-bottom:0.75rem">🎉</div>
                <div class="badge-success" style="margin-bottom:1rem">✓ E-Mail-Adresse erfolgreich bestätigt</div>
                <h2 style="margin:0 0 0.5rem">Vielen Dank, <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>!</h2>
                <p style="color:#cbd5e1;line-height:1.6;margin-bottom:1.5rem">
                    Dein Mitgliedsantrag für den <strong>Sport- u. Gesangverein Böbingen 1920 e.V.</strong> wurde verbindlich eingereicht und liegt dem Vereinsvorstand nun digital vor.
                </p>
                <div style="background:#0f172a;border-radius:8px;padding:1rem;margin-bottom:1.5rem;text-align:left;font-size:0.9rem">
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem">
                        <span style="color:#94a3b8">Antragsteller:</span>
                        <strong><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem">
                        <span style="color:#94a3b8">E-Mail:</span>
                        <span><?= htmlspecialchars($app['email']) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem">
                        <span style="color:#94a3b8">Sparte / Abteilung:</span>
                        <span><?= htmlspecialchars($app['department'] ?: 'Allgemein') ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:#94a3b8">Status:</span>
                        <strong style="color:#10b981">✓ Verifiziert am <?= date('d.m.Y H:i', strtotime($app['verified_at'] ?? 'now')) ?> Uhr</strong>
                    </div>
                </div>

                <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
                    <a href="antrag.php?action=view&token=<?= urlencode($app['token']) ?>" target="_blank" class="btn btn-primary">
                        📄 Beitrittserklärung anzeigen / drucken
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =============================================================================
// GET: Druckansicht / Offizielle Beitrittserklärung (1:1 A4 Papierformat)
// =============================================================================
if ($action === 'view') {
    $token = trim((string)($_GET['token'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM membership_applications WHERE token = :t");
    $stmt->execute([':t' => $token]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        http_response_code(404);
        die('Antrag nicht gefunden.');
    }
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <title>Beitrittserklärung – <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></title>
        <style>
            @page {
                size: A4 portrait;
                margin: 12mm 15mm 12mm 15mm;
            }
            * { box-sizing: border-box; }
            body {
                font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
                font-size: 11pt;
                color: #000;
                background: #f1f5f9;
                margin: 0;
                padding: 20px;
            }
            .a4-page {
                background: #fff;
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto;
                padding: 14mm 16mm;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15);
                position: relative;
            }
            .no-print-bar {
                max-width: 210mm;
                margin: 0 auto 12px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #1e293b;
                color: #fff;
                padding: 10px 16px;
                border-radius: 6px;
            }
            .btn {
                background: #2563eb;
                color: #fff;
                border: none;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
            }
            .btn:hover { background: #1d4ed8; }
            h1.doc-title {
                font-size: 26pt;
                font-weight: 900;
                margin: 0 0 8px 0;
                letter-spacing: -0.5px;
            }
            .top-grid {
                display: flex;
                gap: 15px;
                margin-bottom: 12px;
            }
            .internal-box {
                flex: 1.3;
                border: 1px solid #777;
                font-size: 8.5pt;
            }
            .internal-box-header {
                background: #e5e5e5;
                padding: 3px 6px;
                font-weight: bold;
                border-bottom: 1px solid #777;
            }
            .internal-table {
                width: 100%;
                border-collapse: collapse;
            }
            .internal-table td {
                padding: 2px 6px;
                border-bottom: 1px dotted #aaa;
                height: 18px;
            }
            .internal-table td:first-child {
                width: 45%;
                border-right: 1px solid #aaa;
                color: #333;
            }
            .club-header-box {
                flex: 1.4;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .club-logo {
                width: 82px;
                height: auto;
                object-fit: contain;
            }
            .club-address {
                font-size: 11pt;
                line-height: 1.35;
            }
            .club-address strong {
                font-size: 12pt;
                display: block;
            }
            .section-header {
                background: #d8d8d8;
                font-weight: bold;
                padding: 4px 6px;
                font-size: 9.5pt;
                margin-top: 10px;
                border: 1px solid #888;
            }
            .data-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 4px;
                font-size: 9pt;
            }
            .data-table td {
                padding: 4px 6px;
                border-bottom: 1px solid #ccc;
                vertical-align: middle;
            }
            .data-table td.label {
                width: 22%;
                color: #444;
                font-weight: normal;
            }
            .data-table td.val {
                width: 28%;
                font-weight: bold;
            }
            .legal-text {
                font-size: 7.2pt;
                line-height: 1.35;
                color: #222;
                margin: 8px 0;
                text-align: justify;
                border: 1px solid #ccc;
                padding: 6px;
                background: #fafafa;
            }
            .sig-area {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                margin-top: 6px;
                padding-top: 4px;
            }
            .sig-box {
                width: 45%;
                border-top: 1px solid #000;
                text-align: center;
                font-size: 8pt;
                padding-top: 2px;
            }
            .sig-img {
                max-height: 46px;
                max-width: 180px;
                display: block;
                margin: 0 auto;
            }
            .sepa-box {
                border: 1px solid #777;
                padding: 6px 8px;
                margin-top: 10px;
                font-size: 8pt;
                line-height: 1.3;
            }
            .sepa-box h4 {
                margin: 0 0 4px;
                font-size: 9pt;
            }
            .iban-display {
                font-family: monospace;
                font-size: 11pt;
                letter-spacing: 2px;
                font-weight: bold;
                padding: 4px 8px;
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                display: inline-block;
            }
            .verification-stamp {
                margin-top: 8px;
                border: 1px dashed #10b981;
                background: #ecfdf5;
                color: #065f46;
                padding: 4px 8px;
                font-size: 7.5pt;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            @media print {
                body { background: #fff; padding: 0; }
                .no-print-bar { display: none !important; }
                .a4-page {
                    box-shadow: none;
                    width: 100%;
                    min-height: auto;
                    padding: 0;
                    margin: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="no-print-bar">
            <span>📄 Offizielle Beitrittserklärung (Druckansicht)</span>
            <div style="display:flex;gap:8px">
                <button type="button" class="btn" onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
                <a href="antrag.php" class="btn" style="background:#475569">Neuer Antrag</a>
            </div>
        </div>

        <div class="a4-page">
            <h1 class="doc-title">Beitrittserklärung</h1>

            <div class="top-grid">
                <div class="internal-box">
                    <div class="internal-box-header">Interne Vermerke (werden vom Verein ausgefüllt)</div>
                    <table class="internal-table">
                        <tr><td>ID-Nr. (MS Access)</td><td></td></tr>
                        <tr><td>Zuord. Nr. (SGV)</td><td></td></tr>
                        <tr><td>Status</td><td>Aktiv</td></tr>
                        <tr><td>Bereich</td><td><?= htmlspecialchars($app['department'] ?: 'Allgemein') ?></td></tr>
                        <tr><td>Abteilung</td><td><?= htmlspecialchars($app['department'] ?: 'Hauptverein') ?></td></tr>
                        <tr><td>Funktion</td><td>Mitglied</td></tr>
                        <tr><td>Mitgl. Stufe</td><td><?= htmlspecialchars(ucfirst($app['fee_type'])) ?></td></tr>
                    </table>
                </div>

                <div class="club-header-box">
                    <img src="assets/img/sgv_logo.png" alt="SGV Böbingen 1920" class="club-logo">
                    <div class="club-address">
                        <strong>Sport- u. Gesangverein<br>Böbingen 1920 e.V.</strong>
                        Bucher Straße 17<br>
                        73560 Böbingen
                    </div>
                </div>
            </div>

            <div class="section-header">Ich beantrage hiermit die Mitgliedschaft im Sport- u. Gesangverein Böbingen 1920 e.V.</div>

            <table class="data-table">
                <tr>
                    <td class="label">Name:</td>
                    <td class="val"><?= htmlspecialchars($app['last_name']) ?></td>
                    <td class="label">Telefon:</td>
                    <td class="val"><?= htmlspecialchars($app['phone'] ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="label">Vorname:</td>
                    <td class="val"><?= htmlspecialchars($app['first_name']) ?></td>
                    <td class="label">Telefax:</td>
                    <td class="val"><?= htmlspecialchars($app['fax'] ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="label">Straße:</td>
                    <td class="val"><?= htmlspecialchars($app['street']) ?></td>
                    <td class="label">Mobil:</td>
                    <td class="val"><?= htmlspecialchars($app['mobile'] ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="label">PLZ / Wohnort:</td>
                    <td class="val"><?= htmlspecialchars($app['zip'] . ' ' . $app['city']) ?></td>
                    <td class="label">E-Mail-Adresse:</td>
                    <td class="val"><?= htmlspecialchars($app['email']) ?></td>
                </tr>
                <tr>
                    <td class="label">Geburtsdatum:</td>
                    <td class="val"><?= htmlspecialchars(date('d.m.Y', strtotime($app['birth_date'] ?? 'now'))) ?></td>
                    <td class="label">Schüler/Student bis:</td>
                    <td class="val"><?= htmlspecialchars($app['school_student_until'] ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="label">Geburtsort:</td>
                    <td class="val"><?= htmlspecialchars($app['birth_place'] ?: '—') ?></td>
                    <td class="label">Sonstiger Ausweis:</td>
                    <td class="val"><?= htmlspecialchars($app['special_pass'] ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="label">Beitrittsdatum:</td>
                    <td class="val"><?= htmlspecialchars(date('d.m.Y', strtotime($app['join_date'] ?: 'now'))) ?></td>
                    <td class="label">Gewählte Abteilung:</td>
                    <td class="val"><?= htmlspecialchars($app['department'] ?: 'Allgemein') ?></td>
                </tr>
            </table>

            <div class="section-header" style="margin-top:8px">Mitglieds-Jahresbeitrag in Euro</div>
            <table class="data-table" style="font-size:8.5pt">
                <tr>
                    <td style="width:35%"><strong>Beitragsstufe / Bezeichnung</strong></td>
                    <td style="width:40%"><strong>Sparte / Abteilung</strong></td>
                    <td style="width:25%;text-align:right"><strong>Jahresbeitrag</strong></td>
                </tr>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($app['fee_type'])) ?></td>
                    <td><?= htmlspecialchars($app['department'] ?: 'Hauptverein') ?></td>
                    <td style="text-align:right"><strong><?= number_format((float)$app['fee_amount'], 2, ',', '.') ?> €</strong></td>
                </tr>
            </table>

            <div class="legal-text">
                <strong>Allgemeine Bedingungen:</strong> Mit meiner Unterschrift anerkenne ich die Satzung des Sport- u. Gesangvereins Böbingen 1920 e.V. sowie seiner Mitgliedsverbände. Ich verpflichte mich, den jeweils fälligen Jahresbeitrag im Einzugsverfahren zu leisten. Änderungen der Anschrift werde ich dem Verein umgehend mitteilen. Bei Austritt aus dem Verein habe ich gemäß der Satzung schriftlich zu kündigen. Zum Zwecke der modernen Datenverarbeitung willige ich ein, dass meine Daten elektronisch gespeichert werden. Zu keinem Zeitpunkt werden Daten an Dritte zum Zwecke der Werbung oder für andere unzulässige Zwecke weitergeleitet. Die Behandlung der Daten erfolgt nach dem Bundesdatenschutzgesetz (BDSG), daraus ergeben sich auch die Rechte des betroffenen, gem. §6 Abs. 1 BDSG.
            </div>

            <div class="sig-area">
                <div class="sig-box">
                    <?= htmlspecialchars($app['city']) ?>, den <?= date('d.m.Y', strtotime($app['submitted_at'])) ?><br>
                    <strong>Ort / Datum</strong>
                </div>
                <div class="sig-box">
                    <?php if (!empty($app['signature_png'])): ?>
                        <img src="<?= $app['signature_png'] ?>" alt="Unterschrift" class="sig-img">
                    <?php endif; ?>
                    <strong>Unterschrift</strong><br>
                    <span style="font-size:7pt;color:#555">(bei Minderjährigen Unterschrift des Erziehungsberechtigten)</span>
                </div>
            </div>

            <div class="sepa-box">
                <h4>SEPA-Lastschriftmandat:</h4>
                Hiermit ermächtige ich den <strong>Sport- u. Gesangverein Böbingen 1920 e.V.</strong> (Mandatsreferenz: <strong><?= htmlspecialchars($app['mandate_ref']) ?></strong>, Gläubiger-ID: <strong>DE47ZZZ00000777063</strong>) widerruflich, die von mir zu entrichtenden Beiträge bei Fälligkeit zu Lasten meines angegebenen Kontos mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die vom Zahlungsempfänger Sport- u. Gesangverein Böbingen 1920 e.V. auf mein Konto gezogenen Lastschriften einzulösen.<br>
                <div style="margin:6px 0;display:flex;gap:15px;align-items:center;flex-wrap:wrap">
                    <span>Kontoinhaber: <strong><?= htmlspecialchars($app['account_holder']) ?></strong></span>
                    <span>BIC: <strong><?= htmlspecialchars($app['bic'] ?: '—') ?></strong></span>
                </div>
                <div>IBAN: <span class="iban-display"><?= htmlspecialchars(chunk_split($app['iban'], 4, ' ')) ?></span></div>
                <div style="font-size:7pt;color:#444;margin-top:4px">
                    Wenn mein Konto die erforderliche Deckung nicht aufweist, besteht seitens des kontoführenden Kreditinstitutes keine Verpflichtung zur Einlösung. Evtl. anfallende Gebühren bei Rückschrift gehen zu meinen Lasten.
                </div>

                <div class="sig-area" style="margin-top:4px">
                    <div class="sig-box">
                        <?= htmlspecialchars($app['city']) ?>, den <?= date('d.m.Y', strtotime($app['submitted_at'])) ?><br>
                        <strong>Ort / Datum</strong>
                    </div>
                    <div class="sig-box">
                        <?php $s2 = !empty($app['sepa_signature_png']) ? $app['sepa_signature_png'] : $app['signature_png']; ?>
                        <?php if (!empty($s2)): ?>
                            <img src="<?= $s2 ?>" alt="Unterschrift Kontoinhaber" class="sig-img">
                        <?php endif; ?>
                        <strong>Unterschrift (Kontoinhaber/in)</strong>
                    </div>
                </div>
            </div>

            <div class="verification-stamp">
                <span>🛡️ <strong>Elektronische Beitrittserklärung via VereinsCloud</strong></span>
                <span>
                    E-Mail verifiziert: <strong><?= $app['status'] === 'verified' || $app['status'] === 'accepted' ? '✓ Ja (' . date('d.m.Y H:i', strtotime($app['verified_at'] ?? 'now')) . ')' : '⏳ Ausstehend' ?></strong> · Token: <?= htmlspecialchars(substr($app['token'], 0, 12)) ?>…
                </span>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =============================================================================
// Standardansicht: Das Online-Formular (Mobile-First mit Signature Pad)
// =============================================================================
$clubConfig = MemberManager::getClubConfig();
$rawDepts = $clubConfig['club_departments'] ?? '';
$configuredDepts = array_filter(array_map('trim', preg_split('/[,;\n\r]+/', $rawDepts)));
if (empty($configuredDepts)) {
    $configuredDepts = ['Fußball', 'Turnen & Gymnastik', 'Gesang & Chor', 'Tennis', 'Tischtennis', 'Leichtathletik / Lauftreff', 'Freizeit- & Gesundheitssport', 'Hauptverein'];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Mitgliedsantrag – Sport- u. Gesangverein Böbingen 1920 e.V.</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        :root {
            --app-bg: #0f172a;
            --app-card: #1e293b;
            --app-card-border: #334155;
            --app-primary: #2563eb;
            --app-primary-hover: #1d4ed8;
            --app-text: #f8fafc;
            --app-muted: #94a3b8;
        }
        body {
            background-color: var(--app-bg);
            color: var(--app-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 1.5rem 1rem 3rem;
            min-height: 100vh;
        }
        .container {
            max-width: 780px;
            margin: 0 auto;
        }
        .header-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid var(--app-card-border);
            border-radius: 14px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .header-logo {
            width: 86px;
            height: auto;
            border-radius: 8px;
            background: #fff;
            padding: 6px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            flex-shrink: 0;
        }
        .header-text h1 {
            font-size: 1.6rem;
            margin: 0 0 0.35rem;
            color: #fff;
            letter-spacing: -0.3px;
        }
        .header-text p {
            margin: 0;
            color: var(--app-muted);
            font-size: 0.92rem;
            line-height: 1.4;
        }
        .form-section {
            background: var(--app-card);
            border: 1px solid var(--app-card-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
        }
        .section-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid var(--app-card-border);
            padding-bottom: 0.5rem;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
        }
        @media (max-width: 600px) {
            .grid-2 { grid-template-columns: 1fr; }
            .header-card { flex-direction: column; text-align: center; }
        }
        .form-group {
            margin-bottom: 0.85rem;
        }
        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 0.35rem;
        }
        .form-group label .req { color: #ef4444; }
        .input-field {
            width: 100%;
            background: #0f172a;
            border: 1px solid var(--app-card-border);
            color: #f8fafc;
            border-radius: 6px;
            padding: 0.65rem 0.8rem;
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .input-field:focus {
            border-color: var(--app-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.3);
            outline: none;
        }
        .sig-container {
            border: 2px dashed #475569;
            border-radius: 8px;
            background: #ffffff;
            position: relative;
            touch-action: none;
            cursor: crosshair;
        }
        .sig-canvas {
            display: block;
            width: 100%;
            height: 160px;
            border-radius: 6px;
        }
        .sig-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
            user-select: none;
        }
        .legal-box {
            background: #0f172a;
            border: 1px solid var(--app-card-border);
            border-radius: 8px;
            padding: 0.85rem;
            font-size: 0.78rem;
            line-height: 1.45;
            color: #cbd5e1;
            margin-bottom: 1rem;
            max-height: 130px;
            overflow-y: auto;
        }
        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            font-size: 0.86rem;
            color: #e2e8f0;
            cursor: pointer;
            user-select: none;
        }
        .checkbox-label input[type="checkbox"] {
            margin-top: 0.2rem;
            accent-color: var(--app-primary);
            width: 17px;
            height: 17px;
        }
        .btn-submit {
            background: var(--app-primary);
            color: #fff;
            border: none;
            width: 100%;
            padding: 0.9rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
            transition: background 0.15s, transform 0.1s;
        }
        .btn-submit:hover {
            background: var(--app-primary-hover);
        }
        .btn-submit:active {
            transform: translateY(1px);
        }
        .btn-clear {
            background: #334155;
            color: #f8fafc;
            border: none;
            padding: 0.4rem 0.75rem;
            border-radius: 4px;
            font-size: 0.78rem;
            cursor: pointer;
            margin-top: 0.4rem;
        }
        .btn-clear:hover { background: #475569; }
        .success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1000;
        }
        .success-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            max-width: 540px;
            width: 100%;
            padding: 2.2rem;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header-card">
            <img src="assets/img/sgv_logo.png" alt="SGV Böbingen 1920 Logo" class="header-logo">
            <div class="header-text">
                <h1>Beitrittserklärung</h1>
                <p>
                    <strong>Sport- u. Gesangverein Böbingen 1920 e.V.</strong><br>
                    Bucher Straße 17 · 73560 Böbingen · Gläubiger-ID: DE47ZZZ00000777063
                </p>
            </div>
        </div>

        <form id="membership-form">
            <!-- 1. Persönliche Angaben -->
            <div class="form-section">
                <div class="section-title">👤 1. Angaben zur Person</div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Vorname <span class="req">*</span></label>
                        <input type="text" name="first_name" class="input-field" placeholder="z. B. Max" required>
                    </div>
                    <div class="form-group">
                        <label>Nachname <span class="req">*</span></label>
                        <input type="text" name="last_name" class="input-field" placeholder="z. B. Mustermann" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Straße & Hausnummer <span class="req">*</span></label>
                    <input type="text" name="street" class="input-field" placeholder="z. B. Hauptstraße 12" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Postleitzahl <span class="req">*</span></label>
                        <input type="text" name="zip" class="input-field" placeholder="73560" required>
                    </div>
                    <div class="form-group">
                        <label>Wohnort <span class="req">*</span></label>
                        <input type="text" name="city" class="input-field" placeholder="Böbingen" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Geburtsdatum <span class="req">*</span></label>
                        <input type="date" name="birth_date" class="input-field" required>
                    </div>
                    <div class="form-group">
                        <label>Geburtsort (optional)</label>
                        <input type="text" name="birth_place" class="input-field" placeholder="z. B. Schwäbisch Gmünd">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>E-Mail-Adresse (für Bestätigungslink) <span class="req">*</span></label>
                        <input type="email" name="email" class="input-field" placeholder="max@beispiel.de" required>
                    </div>
                    <div class="form-group">
                        <label>E-Mail-Adresse wiederholen <span class="req">*</span></label>
                        <input type="email" name="email_repeat" class="input-field" placeholder="max@beispiel.de" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Mobiltelefon</label>
                        <input type="tel" name="mobile" class="input-field" placeholder="0170 1234567">
                    </div>
                    <div class="form-group">
                        <label>Festnetz-Telefon</label>
                        <input type="tel" name="phone" class="input-field" placeholder="07173 12345">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Schüler / Student bis (optional)</label>
                        <input type="text" name="school_student_until" class="input-field" placeholder="z. B. 08/2027">
                    </div>
                    <div class="form-group">
                        <label>Sonstiger Ausweis (optional)</label>
                        <input type="text" name="special_pass" class="input-field" placeholder="z. B. Schwerbehindert / Rentner">
                    </div>
                </div>
            </div>

            <!-- 2. Mitgliedsbeitrag & Sparte -->
            <div class="form-section">
                <div class="section-title">⚽ 2. Mitgliedschaft & Abteilung(en)</div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Mitglieds-Beitragsstufe <span class="req">*</span></label>
                        <select name="fee_type" class="input-field">
                            <option value="aktiv" selected>Vereinsbeitrag aktiv (75,00 € / Jahr)</option>
                            <option value="passiv">Vereinsbeitrag passiv / Fördermitglied (40,00 € / Jahr)</option>
                            <option value="jugend">Schüler / Jugendlicher / Student (45,00 € / Jahr)</option>
                            <option value="familie">Familienbeitrag (130,00 € / Jahr)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gewünschtes Beitrittsdatum</label>
                        <input type="date" name="join_date" class="input-field" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-top:0.5rem">
                    <label style="font-weight:600;display:block;margin-bottom:0.4rem">
                        Gewünschte Sparte(n) / Abteilung(en) <span class="req">*</span>
                        <span style="font-size:0.75rem;font-weight:normal;color:var(--app-muted)">(Mehrfachauswahl möglich)</span>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:0.55rem;margin-bottom:0.85rem">
                        <?php foreach ($configuredDepts as $idx => $deptName): ?>
                            <label class="checkbox-label" style="background:#0f172a;border:1px solid var(--app-card-border);padding:0.55rem 0.75rem;border-radius:6px;transition:border-color 0.15s">
                                <input type="checkbox" name="departments[]" value="<?= htmlspecialchars($deptName) ?>" <?= $idx === 0 ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($deptName) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label style="font-size:0.8rem;color:var(--app-muted)">Sonstige / weitere Abteilung:</label>
                        <input type="text" name="department_custom" class="input-field" placeholder="z. B. Ski, Wandern, Volleyball, Jedermann…">
                    </div>
                </div>
            </div>

            <!-- 3. Allgemeine Bedingungen & Datenschutz -->
            <div class="form-section">
                <div class="section-title">📜 3. Satzung & Datenschutz</div>

                <div class="legal-box">
                    <strong>Allgemeine Bedingungen des Sport- u. Gesangvereins Böbingen 1920 e.V.:</strong><br>
                    Mit meiner Unterschrift anerkenne ich die Satzung des Sport- u. Gesangvereins Böbingen 1920 e.V. sowie seiner Mitgliedsverbände. Ich verpflichte mich, den jeweils fälligen Jahresbeitrag im Einzugsverfahren zu leisten. Änderungen der Anschrift werde ich dem Verein umgehend mitteilen. Bei Austritt aus dem Verein habe ich gemäß der Satzung schriftlich zu kündigen. Zum Zwecke der modernen Datenverarbeitung willige ich ein, dass meine Daten elektronisch gespeichert werden. Zu keinem Zeitpunkt werden Daten an Dritte zum Zwecke der Werbung oder für andere unzulässige Zwecke weitergeleitet. Die Behandlung der Daten erfolgt nach dem Bundesdatenschutzgesetz (BDSG), daraus ergeben sich auch die Rechte des betroffenen, gem. §6 Abs. 1 BDSG.
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" name="terms_accepted" value="1" required>
                    <span>Ich habe die Satzung und die Datenschutzhinweise gelesen und erkenne diese verbindlich an. <span class="req">*</span></span>
                </label>
            </div>

            <!-- 4. Digitale Unterschrift -->
            <div class="form-section">
                <div class="section-title">✍️ 4. Unterschrift (Mitglied / Erziehungsberechtigte)</div>
                <p style="font-size:0.85rem;color:var(--app-muted);margin:0 0 0.75rem">
                    Bitte unterschreiben Sie mit dem <strong>Finger auf dem Smartphone</strong> oder mit gedrückter <strong>Maustaste am PC</strong>. (Bei Minderjährigen Unterschrift des Erziehungsberechtigten).
                </p>

                <div class="sig-container" id="sig-wrap-1">
                    <canvas class="sig-canvas" id="canvas-sig-1"></canvas>
                    <div class="sig-placeholder" id="sig-placeholder-1">Hier mit Finger oder Maus unterschreiben</div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <button type="button" class="btn-clear" id="btn-clear-sig-1">✕ Unterschrift löschen</button>
                    <span style="font-size:0.75rem;color:var(--app-muted)">Touch & Maus optimiert</span>
                </div>
            </div>

            <!-- 5. SEPA-Lastschriftmandat -->
            <div class="form-section">
                <div class="section-title">🏦 5. SEPA-Lastschriftmandat</div>

                <div class="legal-box" style="max-height:none">
                    <strong>SEPA-Lastschriftmandat:</strong><br>
                    Hiermit ermächtige ich den Sport- u. Gesangverein Böbingen 1920 e.V. (Mandatsreferenz: <strong>DE47ZZZ00000777063</strong>) widerruflich, die von mir zu entrichtenden Beiträge bei Fälligkeit zu Lasten meines angegebenen Kontos mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die vom Zahlungsempfänger Sport- u. Gesangverein Böbingen 1920 e.V. auf mein Konto gezogenen Lastschriften einzulösen.<br>
                    <em style="color:#94a3b8;font-size:0.72rem;display:block;margin-top:4px">Hinweis: Wenn mein Konto die erforderliche Deckung nicht aufweist, besteht seitens des kontoführenden Kreditinstitutes keine Verpflichtung zur Einlösung. Evtl. anfallende Gebühren bei Rückschrift gehen zu meinen Lasten.</em>
                </div>

                <div class="form-group">
                    <label>Kontoinhaber/in <span class="req">*</span></label>
                    <input type="text" name="account_holder" id="inp-acc-holder" class="input-field" placeholder="Vor- und Nachname des Kontoinhabers" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>IBAN <span class="req">*</span></label>
                        <input type="text" name="iban" id="inp-iban" class="input-field" placeholder="DE00 0000 0000 0000 0000 00" maxlength="34" required style="font-family:monospace;letter-spacing:1px">
                        <div id="iban-feedback" style="font-size:0.75rem;margin-top:0.25rem"></div>
                    </div>
                    <div class="form-group">
                        <label>BIC (optional)</label>
                        <input type="text" name="bic" class="input-field" placeholder="z. B. GENODEF1..." maxlength="11">
                    </div>
                </div>

                <label class="checkbox-label" style="margin-top:0.75rem">
                    <input type="checkbox" id="chk-same-sig" checked>
                    <span>Gleiche Unterschrift wie oben für das SEPA-Lastschriftmandat verwenden</span>
                </label>

                <div id="sepa-sig-area" style="display:none;margin-top:0.85rem">
                    <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:0.35rem">Separate Unterschrift des Kontoinhabers:</label>
                    <div class="sig-container" id="sig-wrap-2">
                        <canvas class="sig-canvas" id="canvas-sig-2"></canvas>
                        <div class="sig-placeholder" id="sig-placeholder-2">Unterschrift des Kontoinhabers</div>
                    </div>
                    <button type="button" class="btn-clear" id="btn-clear-sig-2">✕ Unterschrift löschen</button>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-submit" id="btn-submit-app">
                ✉️ Mitgliedsantrag verbindlich einreichen
            </button>
        </form>
    </div>

    <!-- Success Modal Overlay -->
    <div class="success-overlay" id="success-overlay" style="display:none">
        <div class="success-card">
            <div style="font-size:3.5rem;margin-bottom:0.5rem">📬</div>
            <h2 style="margin:0 0 0.5rem;color:#fff">Fast geschafft!</h2>
            <p style="color:#cbd5e1;line-height:1.5;margin-bottom:1.5rem" id="success-msg">
                Wir haben eine Bestätigungs-E-Mail an deine Adresse gesendet. Bitte klicke auf den Link in der E-Mail, um deinen Mitgliedsantrag verbindlich abzuschließen.
            </p>
            <div id="dev-verify-link" style="margin-bottom:1.2rem;font-size:0.85rem"></div>
            <button type="button" class="btn-submit" onclick="location.reload()" style="max-width:240px;margin:0 auto">Fertig</button>
        </div>
    </div>

    <script>
    (function() {
        // --- Signature Pad Canvas Helper ---
        function initSignaturePad(canvasId, placeholderId, clearBtnId) {
            const canvas = document.getElementById(canvasId);
            const placeholder = document.getElementById(placeholderId);
            const clearBtn = document.getElementById(clearBtnId);
            const ctx = canvas.getContext('2d');

            let isDrawing = false;
            let hasDrawn = false;

            function resizeCanvas() {
                const rect = canvas.getBoundingClientRect();
                const dpr = window.devicePixelRatio || 1;
                // Save current drawing
                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = canvas.width;
                tempCanvas.height = canvas.height;
                const tempCtx = tempCanvas.getContext('2d');
                tempCtx.drawImage(canvas, 0, 0);

                canvas.width = rect.width * dpr;
                canvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#0f172a';

                if (hasDrawn) {
                    ctx.drawImage(tempCanvas, 0, 0, rect.width, rect.height);
                }
            }

            window.addEventListener('resize', resizeCanvas);
            setTimeout(resizeCanvas, 50);

            function getPos(e) {
                const rect = canvas.getBoundingClientRect();
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                const clientY = e.touches ? e.touches[0].clientY : e.clientY;
                return {
                    x: clientX - rect.left,
                    y: clientY - rect.top
                };
            }

            function startDraw(e) {
                isDrawing = true;
                const pos = getPos(e);
                ctx.beginPath();
                ctx.moveTo(pos.x, pos.y);
                if (placeholder) placeholder.style.display = 'none';
                hasDrawn = true;
                e.preventDefault();
            }

            function draw(e) {
                if (!isDrawing) return;
                const pos = getPos(e);
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
                e.preventDefault();
            }

            function stopDraw(e) {
                if (!isDrawing) return;
                isDrawing = false;
            }

            canvas.addEventListener('mousedown', startDraw);
            canvas.addEventListener('mousemove', draw);
            window.addEventListener('mouseup', stopDraw);

            canvas.addEventListener('touchstart', startDraw, { passive: false });
            canvas.addEventListener('touchmove', draw, { passive: false });
            window.addEventListener('touchend', stopDraw);

            clearBtn?.addEventListener('click', () => {
                const rect = canvas.getBoundingClientRect();
                ctx.clearRect(0, 0, rect.width, rect.height);
                hasDrawn = false;
                if (placeholder) placeholder.style.display = 'block';
            });

            return {
                isEmpty: () => !hasDrawn,
                toDataURL: () => canvas.toDataURL('image/png'),
                clear: () => clearBtn.click()
            };
        }

        const sig1 = initSignaturePad('canvas-sig-1', 'sig-placeholder-1', 'btn-clear-sig-1');
        const sig2 = initSignaturePad('canvas-sig-2', 'sig-placeholder-2', 'btn-clear-sig-2');

        // Toggle separate SEPA signature
        const chkSameSig = document.getElementById('chk-same-sig');
        const sepaSigArea = document.getElementById('sepa-sig-area');
        chkSameSig?.addEventListener('change', () => {
            if (chkSameSig.checked) {
                sepaSigArea.style.display = 'none';
            } else {
                sepaSigArea.style.display = 'block';
                window.dispatchEvent(new Event('resize'));
            }
        });

        // Auto-fill account holder from name inputs
        const fnInp = document.querySelector('input[name="first_name"]');
        const lnInp = document.querySelector('input[name="last_name"]');
        const ahInp = document.getElementById('inp-acc-holder');
        function syncAccountHolder() {
            if (!ahInp.dataset.userEdited) {
                const full = (fnInp.value.trim() + ' ' + lnInp.value.trim()).trim();
                ahInp.value = full;
            }
        }
        fnInp?.addEventListener('input', syncAccountHolder);
        lnInp?.addEventListener('input', syncAccountHolder);
        ahInp?.addEventListener('input', () => { ahInp.dataset.userEdited = '1'; });

        // IBAN auto-format in 4-character blocks
        const ibanInp = document.getElementById('inp-iban');
        const ibanFeedback = document.getElementById('iban-feedback');
        ibanInp?.addEventListener('input', (e) => {
            let val = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (val.length > 34) val = val.substring(0, 34);
            const formatted = val.replace(/(.{4})/g, '$1 ').trim();
            e.target.value = formatted;

            if (val.length >= 15) {
                if (val.startsWith('DE') && val.length === 22) {
                    ibanFeedback.innerHTML = '<span style="color:#10b981">✓ Deutsche IBAN vollständig (22 Stellen)</span>';
                } else if (val.length >= 15) {
                    ibanFeedback.innerHTML = '<span style="color:#38bdf8">IBAN: ' + val.length + ' Stellen</span>';
                }
            } else {
                ibanFeedback.innerHTML = '';
            }
        });

        // Form Submit
        const form = document.getElementById('membership-form');
        const submitBtn = document.getElementById('btn-submit-app');

        form?.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (sig1.isEmpty()) {
                alert('Bitte unterschreiben Sie das Formular im Unterschriftenfeld mit Finger oder Maus.');
                document.getElementById('sig-wrap-1').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const isSame = chkSameSig.checked;
            if (!isSame && sig2.isEmpty()) {
                alert('Bitte unterschreiben Sie das SEPA-Lastschriftmandat.');
                document.getElementById('sig-wrap-2').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const fd = new FormData(form);
            const data = Object.fromEntries(fd.entries());
            data.signature_png = sig1.toDataURL();
            data.sepa_signature_png = isSame ? data.signature_png : sig2.toDataURL();
            data.terms_accepted = form.terms_accepted.checked ? 1 : 0;

            const depts = [];
            form.querySelectorAll('input[name="departments[]"]:checked').forEach(cb => depts.push(cb.value));
            data.department = depts;
            data.department_custom = form.department_custom ? form.department_custom.value.trim() : '';

            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Wird eingereicht…';

            try {
                const res = await fetch('antrag.php?action=submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const json = await res.json();
                if (!json.ok) {
                    alert(json.error || 'Fehler beim Einreichen des Antrags.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '✉️ Mitgliedsantrag verbindlich einreichen';
                    return;
                }

                // Show success overlay
                document.getElementById('success-msg').innerHTML =
                    'Wir haben eine Bestätigungs-E-Mail an <strong>' + json.email + '</strong> gesendet.<br><br>' +
                    'Bitte klicke auf den Bestätigungslink in der E-Mail, um deine E-Mail-Adresse zu verifizieren und den Mitgliedsantrag verbindlich abzuschließen.';

                const devDiv = document.getElementById('dev-verify-link');
                devDiv.innerHTML =
                    '<div style="background:#0f172a;border:1px solid #334155;border-radius:6px;padding:8px 12px;margin-top:10px">' +
                    '<span style="color:#94a3b8;font-size:0.75rem;display:block">Direktlink zur Bestätigung:</span>' +
                    '<a href="' + json.verify_url + '" style="color:#38bdf8;word-break:break-all;font-size:0.85rem">Hier klicken zum Bestätigen</a>' +
                    '</div>';

                document.getElementById('success-overlay').style.display = 'flex';
            } catch (err) {
                alert('Netzwerkfehler: ' + err.message);
                submitBtn.disabled = false;
                submitBtn.textContent = '✉️ Mitgliedsantrag verbindlich einreichen';
            }
        });
    })();
    </script>
</body>
</html>
