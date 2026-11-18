<?php
declare(strict_types=1);

namespace Cloud;

use PDO;
use RuntimeException;

class MemberManager
{
    public static function listMembers(): array
    {
        $pdo = Database::get();
        $rows = $pdo->query("
            SELECT *
            FROM club_members
            ORDER BY last_name COLLATE NOCASE ASC, first_name COLLATE NOCASE ASC, id ASC
        ")->fetchAll();

        $todayY = (int)date('Y');
        $todayMd = date('m-d');
        $departments = [];

        $members = [];
        foreach ($rows as $r) {
            $age = null;
            if (!empty($r['birth_date']) && preg_match('/^(\d{4})-(\d{2}-\d{2})$/', (string)$r['birth_date'], $m)) {
                $age = $todayY - (int)$m[1] - ($todayMd < $m[2] ? 1 : 0);
            }

            $years = null;
            if (!empty($r['joined_at']) && preg_match('/^(\d{4})/', (string)$r['joined_at'], $jm)) {
                $years = max(0, $todayY - (int)$jm[1]);
            }

            $dept = trim((string)($r['department'] ?? ''));
            if ($dept !== '') {
                $departments[$dept] = true;
            }

            $rawIban = (string)($r['iban'] ?? '');
            $ibanCheck = $rawIban !== '' ? self::validateIban($rawIban) : null;

            $members[] = [
                'id'          => (int)$r['id'],
                'member_no'   => $r['member_no'] ?? '',
                'first_name'  => $r['first_name'] ?? '',
                'last_name'   => $r['last_name'] ?? '',
                'birth_date'  => $r['birth_date'],
                'age'         => $age,
                'street'      => $r['street'] ?? '',
                'zip'         => $r['zip'] ?? '',
                'city'        => $r['city'] ?? '',
                'phone'       => $r['phone'] ?? '',
                'email'       => $r['email'] ?? '',
                'status'      => $r['status'] ?: 'Aktiv',
                'department'  => $dept,
                'joined_at'   => $r['joined_at'],
                'left_at'     => $r['left_at'],
                'years'       => $years,
                'fee_amount'  => (float)($r['fee_amount'] ?? 0),
                'iban'        => $rawIban,
                'iban_valid'  => $ibanCheck ? $ibanCheck['valid'] : null,
                'iban_error'  => $ibanCheck ? $ibanCheck['error'] : null,
                'mandate_ref' => $r['mandate_ref'] ?? '',
                'notes'       => $r['notes'] ?? '',
                'updated_at'  => $r['updated_at'],
            ];
        }

        $deptList = array_keys($departments);
        natcasesort($deptList);

        return [
            'members'     => $members,
            'departments' => array_values($deptList),
            'club_config' => self::getClubConfig(),
        ];
    }

    public static function getClubConfig(): array
    {
        $s = Database::getSettings();
        $appName = trim((string)($s['app_name'] ?? 'VereinsCloud'));
        return [
            'club_name'          => trim((string)($s['club_name'] ?? '')) ?: $appName,
            'club_city'          => trim((string)($s['club_city'] ?? '')),
            'club_sign1'         => trim((string)($s['club_sign1'] ?? '')) ?: '1. Vorsitzende/r',
            'club_sign2'         => trim((string)($s['club_sign2'] ?? '')) ?: 'Schatzmeister/in',
            'sepa_creditor_iban' => trim((string)($s['sepa_creditor_iban'] ?? '')),
            'sepa_creditor_bic'  => trim((string)($s['sepa_creditor_bic'] ?? '')),
            'sepa_creditor_id'   => trim((string)($s['sepa_creditor_id'] ?? '')),
            'sepa_remittance'    => trim((string)($s['sepa_remittance'] ?? '')) ?: ('Mitgliedsbeitrag ' . date('Y')),
        ];
    }

    public static function saveClubConfig(array $input): array
    {
        $clubName = mb_substr(trim((string)($input['club_name'] ?? '')), 0, 100);
        if ($clubName === '') {
            throw new RuntimeException('Bitte geben Sie den Vereinsnamen an.');
        }
        $clubCity  = mb_substr(trim((string)($input['club_city'] ?? '')), 0, 100);
        $clubSign1 = mb_substr(trim((string)($input['club_sign1'] ?? '')), 0, 100) ?: '1. Vorsitzende/r';
        $clubSign2 = mb_substr(trim((string)($input['club_sign2'] ?? '')), 0, 100) ?: 'Schatzmeister/in';

        $credIbanRaw = trim((string)($input['sepa_creditor_iban'] ?? ''));
        $ibanInfo    = self::validateIban($credIbanRaw);
        if (!$ibanInfo['valid']) {
            throw new RuntimeException('Ungültige Vereins-IBAN: ' . $ibanInfo['error']);
        }
        $credIban = $ibanInfo['normalized'];
        $credBic  = strtoupper(str_replace(' ', '', mb_substr(trim((string)($input['sepa_creditor_bic'] ?? '')), 0, 11)));
        $credId   = strtoupper(str_replace(' ', '', mb_substr(trim((string)($input['sepa_creditor_id'] ?? '')), 0, 35)));
        $remit    = mb_substr(trim((string)($input['sepa_remittance'] ?? '')), 0, 100) ?: ('Mitgliedsbeitrag ' . date('Y'));

        Database::setSetting('club_name', $clubName);
        Database::setSetting('club_city', $clubCity);
        Database::setSetting('club_sign1', $clubSign1);
        Database::setSetting('club_sign2', $clubSign2);
        Database::setSetting('sepa_creditor_iban', $credIban);
        Database::setSetting('sepa_creditor_bic', $credBic);
        Database::setSetting('sepa_creditor_id', $credId);
        Database::setSetting('sepa_remittance', $remit);

        return self::getClubConfig();
    }

    private static function normalizeDate(?string $raw): ?string
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return null;
        }
        // German format DD.MM.YYYY
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        // ISO format YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return null;
    }

    public static function validateIban(?string $raw): array
    {
        $clean = strtoupper((string)preg_replace('/[\s\-]+/', '', (string)$raw));
        if ($clean === '') {
            return ['valid' => true, 'normalized' => '', 'error' => null];
        }

        if (!preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/', $clean, $m)) {
            return [
                'valid'      => false,
                'normalized' => $clean,
                'error'      => 'Ungültiges Format (muss mit 2 Länderbuchstaben und 2 Prüfziffern beginnen, z. B. DE…).',
            ];
        }

        $country = $m[1];
        $bban    = $m[3];
        $lengths = [
            'DE' => 22, 'AT' => 20, 'CH' => 21, 'LI' => 21, 'LU' => 20,
            'NL' => 18, 'BE' => 16, 'FR' => 27, 'IT' => 27, 'ES' => 24,
            'PL' => 28, 'CZ' => 24, 'DK' => 18, 'SE' => 24, 'GB' => 22,
            'IE' => 22, 'PT' => 25, 'FI' => 18, 'NO' => 15, 'GR' => 27,
        ];

        $len = strlen($clean);
        if (isset($lengths[$country])) {
            if ($len !== $lengths[$country]) {
                return [
                    'valid'      => false,
                    'normalized' => $clean,
                    'error'      => sprintf('IBAN für %s muss %d Stellen lang sein (aktuell %d Stellen).', $country, $lengths[$country], $len),
                ];
            }
        } elseif ($len < 15 || $len > 34) {
            return [
                'valid'      => false,
                'normalized' => $clean,
                'error'      => sprintf('Ungültige IBAN-Länge (%d Stellen).', $len),
            ];
        }

        if (in_array($country, ['DE', 'AT', 'LU', 'BE', 'ES', 'PL', 'CZ', 'DK', 'SE', 'PT'], true) && !ctype_digit($bban)) {
            return [
                'valid'      => false,
                'normalized' => $clean,
                'error'      => sprintf('IBAN für %s darf nach den ersten 4 Zeichen nur Ziffern enthalten.', $country),
            ];
        }

        // ISO 7064 Mod 97-10 check
        $rearranged = substr($clean, 4) . substr($clean, 0, 4);
        $numeric = '';
        $rLen = strlen($rearranged);
        for ($i = 0; $i < $rLen; $i++) {
            $ch = $rearranged[$i];
            if ($ch >= 'A' && $ch <= 'Z') {
                $numeric .= (string)(ord($ch) - 55);
            } else {
                $numeric .= $ch;
            }
        }

        $rem = 0;
        $nLen = strlen($numeric);
        for ($i = 0; $i < $nLen; $i++) {
            $rem = ($rem * 10 + (int)$numeric[$i]) % 97;
        }

        if ($rem !== 1) {
            return [
                'valid'      => false,
                'normalized' => $clean,
                'error'      => 'IBAN-Prüfziffer ungültig (Modulo-97-Prüfung fehlgeschlagen – Zahlendreher?).',
            ];
        }

        return ['valid' => true, 'normalized' => $clean, 'error' => null];
    }

    public static function saveMember(?int $id, array $input): int
    {
        $firstName = mb_substr(trim((string)($input['first_name'] ?? '')), 0, 100);
        $lastName  = mb_substr(trim((string)($input['last_name'] ?? '')), 0, 100);
        if ($firstName === '' && $lastName === '') {
            throw new RuntimeException('Bitte geben Sie mindestens Vor- oder Nachnamen an.');
        }

        $memberNo   = mb_substr(trim((string)($input['member_no'] ?? '')), 0, 40);
        $birthDate  = self::normalizeDate(isset($input['birth_date']) ? (string)$input['birth_date'] : null);
        $street     = mb_substr(trim((string)($input['street'] ?? '')), 0, 150);
        $zip        = mb_substr(trim((string)($input['zip'] ?? '')), 0, 20);
        $city       = mb_substr(trim((string)($input['city'] ?? '')), 0, 100);
        $phone      = mb_substr(trim((string)($input['phone'] ?? '')), 0, 80);
        $email      = mb_substr(trim((string)($input['email'] ?? '')), 0, 150);
        $allowedSt  = ['Aktiv', 'Passiv', 'Ehrenmitglied', 'Jugend', 'Ausgetreten'];
        $status     = in_array($input['status'] ?? '', $allowedSt, true) ? (string)$input['status'] : 'Aktiv';
        $department = mb_substr(trim((string)($input['department'] ?? '')), 0, 100);
        $joinedAt   = self::normalizeDate(isset($input['joined_at']) ? (string)$input['joined_at'] : null);
        $leftAt     = self::normalizeDate(isset($input['left_at']) ? (string)$input['left_at'] : null);
        $feeRaw     = str_replace(',', '.', trim((string)($input['fee_amount'] ?? '0')));
        $feeAmount  = is_numeric($feeRaw) ? round((float)$feeRaw, 2) : 0.0;
        $ibanInfo   = self::validateIban(isset($input['iban']) ? (string)$input['iban'] : '');
        if (!$ibanInfo['valid']) {
            throw new RuntimeException('Ungültige IBAN: ' . $ibanInfo['error']);
        }
        $iban       = $ibanInfo['normalized'];
        $mandateRef = mb_substr(trim((string)($input['mandate_ref'] ?? '')), 0, 80);
        $notes      = mb_substr(trim((string)($input['notes'] ?? '')), 0, 2000);

        $pdo = Database::get();

        if ($id !== null && $id > 0) {
            $upd = $pdo->prepare("
                UPDATE club_members
                SET member_no = :mno, first_name = :fn, last_name = :ln, birth_date = :bd,
                    street = :str, zip = :zip, city = :city, phone = :ph, email = :em,
                    status = :st, department = :dep, joined_at = :ja, left_at = :la,
                    fee_amount = :fee, iban = :iban, mandate_ref = :mref, notes = :not,
                    updated_at = datetime('now')
                WHERE id = :id
            ");
            $upd->execute([
                ':mno'  => $memberNo,
                ':fn'   => $firstName,
                ':ln'   => $lastName,
                ':bd'   => $birthDate,
                ':str'  => $street,
                ':zip'  => $zip,
                ':city' => $city,
                ':ph'   => $phone,
                ':em'   => $email,
                ':st'   => $status,
                ':dep'  => $department,
                ':ja'   => $joinedAt,
                ':la'   => $leftAt,
                ':fee'  => $feeAmount,
                ':iban' => $iban,
                ':mref' => $mandateRef,
                ':not'  => $notes,
                ':id'   => $id,
            ]);
            return $id;
        }

        $ins = $pdo->prepare("
            INSERT INTO club_members (
                member_no, first_name, last_name, birth_date, street, zip, city, phone, email,
                status, department, joined_at, left_at, fee_amount, iban, mandate_ref, notes, updated_at
            ) VALUES (
                :mno, :fn, :ln, :bd, :str, :zip, :city, :ph, :em,
                :st, :dep, :ja, :la, :fee, :iban, :mref, :not, datetime('now')
            )
        ");
        $ins->execute([
            ':mno'  => $memberNo,
            ':fn'   => $firstName,
            ':ln'   => $lastName,
            ':bd'   => $birthDate,
            ':str'  => $street,
            ':zip'  => $zip,
            ':city' => $city,
            ':ph'   => $phone,
            ':em'   => $email,
            ':st'   => $status,
            ':dep'  => $department,
            ':ja'   => $joinedAt,
            ':la'   => $leftAt,
            ':fee'  => $feeAmount,
            ':iban' => $iban,
            ':mref' => $mandateRef,
            ':not'  => $notes,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function deleteMember(int $id): void
    {
        Database::get()->prepare('DELETE FROM club_members WHERE id = :id')->execute([':id' => $id]);
    }

    public static function streamCsvExport(): never
    {
        $data = self::listMembers()['members'];
        $filename = 'mitgliederliste-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for direct opening in Microsoft Excel with German umlauts
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Mitgliedsnummer',
            'Nachname',
            'Vorname',
            'Geburtsdatum',
            'Alter',
            'Straße',
            'PLZ',
            'Ort',
            'Telefon',
            'E-Mail',
            'Status',
            'Abteilung',
            'Eintrittsdatum',
            'Austrittsdatum',
            'Beitrag',
            'IBAN',
            'Mandatsreferenz',
            'Notizen',
        ], ';');

        $fmtDate = fn(?string $d) => ($d && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) ? "{$m[3]}.{$m[2]}.{$m[1]}" : '';

        foreach ($data as $m) {
            fputcsv($out, [
                $m['member_no'],
                $m['last_name'],
                $m['first_name'],
                $fmtDate($m['birth_date']),
                $m['age'] ?? '',
                $m['street'],
                $m['zip'],
                $m['city'],
                $m['phone'],
                $m['email'],
                $m['status'],
                $m['department'],
                $fmtDate($m['joined_at']),
                $fmtDate($m['left_at']),
                number_format((float)$m['fee_amount'], 2, ',', ''),
                $m['iban'],
                $m['mandate_ref'],
                $m['notes'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    public static function importCsv(string $rawCsv): int
    {
        if (str_starts_with($rawCsv, "\xEF\xBB\xBF")) {
            $rawCsv = substr($rawCsv, 3);
        } elseif ($rawCsv !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($rawCsv, 'UTF-8')) {
            $rawCsv = mb_convert_encoding($rawCsv, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($rawCsv));
        if (!$lines || count($lines) < 2) {
            throw new RuntimeException('Die CSV-Datei enthält keine importierbaren Zeilen.');
        }

        $firstLine = $lines[0];
        $delim = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';
        $headers = array_map(fn($h) => mb_strtolower(trim((string)$h)), str_getcsv($firstLine, $delim));

        $findCol = function (array $aliases) use ($headers): ?int {
            foreach ($aliases as $a) {
                $idx = array_search($a, $headers, true);
                if ($idx !== false) {
                    return (int)$idx;
                }
            }
            return null;
        };

        $colMap = [
            'member_no'   => $findCol(['mitgliedsnummer', 'mitgliedsnr', 'mitglieds-nr', 'nr', 'nr.', 'id']),
            'last_name'   => $findCol(['nachname', 'familienname', 'name']),
            'first_name'  => $findCol(['vorname']),
            'birth_date'  => $findCol(['geburtsdatum', 'geburtstag', 'geb.', 'geb']),
            'street'      => $findCol(['straße', 'strasse', 'anschrift', 'adresse']),
            'zip'         => $findCol(['plz', 'postleitzahl']),
            'city'        => $findCol(['ort', 'wohnort', 'stadt']),
            'phone'       => $findCol(['telefon', 'tel', 'mobil', 'handy', 'telefonnummer']),
            'email'       => $findCol(['e-mail', 'email', 'mail']),
            'status'      => $findCol(['status', 'mitgliedsstatus']),
            'department'  => $findCol(['abteilung', 'sparte', 'gruppe', 'funktion']),
            'joined_at'   => $findCol(['eintrittsdatum', 'eintritt', 'mitglied seit']),
            'left_at'     => $findCol(['austrittsdatum', 'austritt']),
            'fee_amount'  => $findCol(['beitrag', 'jahresbeitrag', 'beitragssatz']),
            'iban'        => $findCol(['iban']),
            'mandate_ref' => $findCol(['mandatsreferenz', 'mandat', 'sepa-mandat']),
            'notes'       => $findCol(['notizen', 'bemerkung', 'bemerkungen', 'hinweis']),
        ];

        if ($colMap['last_name'] === null && $colMap['first_name'] === null) {
            throw new RuntimeException('Spalten "Nachname" bzw. "Vorname" wurden in der CSV-Kopfzeile nicht gefunden.');
        }

        $pdo = Database::get();
        $pdo->beginTransaction();
        $imported = 0;
        try {
            for ($i = 1, $n = count($lines); $i < $n; $i++) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                $cols = str_getcsv($line, $delim);
                $get = fn(?int $idx) => ($idx !== null && isset($cols[$idx])) ? trim((string)$cols[$idx]) : '';

                $fn = $get($colMap['first_name']);
                $ln = $get($colMap['last_name']);
                if ($fn === '' && $ln === '') {
                    continue;
                }

                self::saveMember(null, [
                    'member_no'   => $get($colMap['member_no']),
                    'first_name'  => $fn,
                    'last_name'   => $ln,
                    'birth_date'  => $get($colMap['birth_date']),
                    'street'      => $get($colMap['street']),
                    'zip'         => $get($colMap['zip']),
                    'city'        => $get($colMap['city']),
                    'phone'       => $get($colMap['phone']),
                    'email'       => $get($colMap['email']),
                    'status'      => $get($colMap['status']) ?: 'Aktiv',
                    'department'  => $get($colMap['department']),
                    'joined_at'   => $get($colMap['joined_at']),
                    'left_at'     => $get($colMap['left_at']),
                    'fee_amount'  => $get($colMap['fee_amount']),
                    'iban'        => $get($colMap['iban']),
                    'mandate_ref' => $get($colMap['mandate_ref']),
                    'notes'       => $get($colMap['notes']),
                ]);
                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $imported;
    }

    private static function sepaClean(string $text, int $maxLen = 70): string
    {
        $s = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ß' => 'ss', '&' => '+',
        ]);
        $s = (string)preg_replace("/[^A-Za-z0-9\/\-?:().,'+ ]+/", ' ', $s);
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            $s = '-';
        }
        return substr($s, 0, $maxLen);
    }

    public static function streamSepaPain008Xml(array $params): never
    {
        $cfg = self::getClubConfig();
        $credName = $cfg['club_name'];
        $credIban = $cfg['sepa_creditor_iban'];
        $credBic  = $cfg['sepa_creditor_bic'];
        $credId   = $cfg['sepa_creditor_id'];

        if ($credIban === '' || !self::validateIban($credIban)['valid']) {
            throw new RuntimeException('Bitte hinterlegen Sie zuerst eine gültige Vereins-IBAN in den SEPA-/Vereinseinstellungen.');
        }
        if ($credId === '') {
            throw new RuntimeException('Bitte hinterlegen Sie zuerst die Gläubiger-Identifikationsnummer (z. B. DE98ZZZ...) in den SEPA-Einstellungen.');
        }

        $collDate = trim((string)($params['collection_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $collDate)) {
            $collDate = date('Y-m-d', strtotime('+5 days'));
        }
        $seqType = in_array($params['seq_type'] ?? '', ['RCUR', 'FRST', 'OOFF'], true)
            ? (string)$params['seq_type']
            : 'RCUR';
        $remitBase = trim((string)($params['remittance'] ?? '')) ?: $cfg['sepa_remittance'];
        $deptFilter = trim((string)($params['department'] ?? 'all'));

        $all = self::listMembers()['members'];
        $txList = [];
        $totalSum = 0.0;

        foreach ($all as $m) {
            if ($m['status'] === 'Ausgetreten') {
                continue;
            }
            if ($deptFilter !== '' && $deptFilter !== 'all' && $m['department'] !== $deptFilter) {
                continue;
            }
            $fee = round((float)$m['fee_amount'], 2);
            if ($fee <= 0) {
                continue;
            }
            if ($m['iban'] === '' || !$m['iban_valid'] || trim((string)$m['mandate_ref']) === '') {
                continue;
            }
            $txList[] = $m;
            $totalSum += $fee;
        }

        if (count($txList) === 0) {
            throw new RuntimeException('Keine abrufbaren SEPA-Lastschriften gefunden (Mitglieder benötigen Beitrag > 0 €, gültige IBAN und Mandatsreferenz).');
        }

        $msgId    = 'SEPA-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $pmtInfId = 'PMT-' . date('YmdHis');
        $creDtTm  = gmdate('Y-m-d\TH:i:s\Z');
        $nbOfTxs  = count($txList);
        $ctrlSum  = number_format($totalSum, 2, '.', '');
        $xEsc     = fn(string $v) => htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        $credAgXml = $credBic !== ''
            ? '<BIC>' . $xEsc($credBic) . '</BIC>'
            : '<Othr><Id>NOTPROVIDED</Id></Othr>';

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02 pain.008.001.02.xsd">';
        $xml[] = '  <CstmrDrctDbtInitn>';
        $xml[] = '    <GrpHdr>';
        $xml[] = '      <MsgId>' . $xEsc($msgId) . '</MsgId>';
        $xml[] = '      <CreDtTm>' . $xEsc($creDtTm) . '</CreDtTm>';
        $xml[] = '      <NbOfTxs>' . $nbOfTxs . '</NbOfTxs>';
        $xml[] = '      <CtrlSum>' . $ctrlSum . '</CtrlSum>';
        $xml[] = '      <InitgPty><Nm>' . $xEsc(self::sepaClean($credName, 70)) . '</Nm></InitgPty>';
        $xml[] = '    </GrpHdr>';
        $xml[] = '    <PmtInf>';
        $xml[] = '      <PmtInfId>' . $xEsc($pmtInfId) . '</PmtInfId>';
        $xml[] = '      <PmtMtd>DD</PmtMtd>';
        $xml[] = '      <BtchBookg>true</BtchBookg>';
        $xml[] = '      <NbOfTxs>' . $nbOfTxs . '</NbOfTxs>';
        $xml[] = '      <CtrlSum>' . $ctrlSum . '</CtrlSum>';
        $xml[] = '      <PmtTpInf>';
        $xml[] = '        <SvcLvl><Cd>SEPA</Cd></SvcLvl>';
        $xml[] = '        <LclInstrm><Cd>CORE</Cd></LclInstrm>';
        $xml[] = '        <SeqTp>' . $xEsc($seqType) . '</SeqTp>';
        $xml[] = '      </PmtTpInf>';
        $xml[] = '      <ReqdColltnDt>' . $xEsc($collDate) . '</ReqdColltnDt>';
        $xml[] = '      <Cdtr><Nm>' . $xEsc(self::sepaClean($credName, 70)) . '</Nm></Cdtr>';
        $xml[] = '      <CdtrAcct><Id><IBAN>' . $xEsc($credIban) . '</IBAN></Id></CdtrAcct>';
        $xml[] = '      <CdtrAgt><FinInstnId>' . $credAgXml . '</FinInstnId></CdtrAgt>';
        $xml[] = '      <ChrgBr>SLEV</ChrgBr>';
        $xml[] = '      <CdtrSchmeId><Id><PrvtId><Othr><Id>' . $xEsc($credId) . '</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>';

        foreach ($txList as $m) {
            $feeStr = number_format((float)$m['fee_amount'], 2, '.', '');
            $e2eId  = self::sepaClean('MB-' . ($m['member_no'] ?: $m['id']) . '-' . date('Y'), 35);
            $mndtId = self::sepaClean((string)$m['mandate_ref'], 35);
            $sgntr  = (!empty($m['joined_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$m['joined_at']))
                ? (string)$m['joined_at']
                : '2020-01-01';
            $dbtrNm = self::sepaClean(trim($m['first_name'] . ' ' . $m['last_name']), 70);
            $ustrd  = self::sepaClean($remitBase . ' - ' . trim($m['first_name'] . ' ' . $m['last_name']) . ($m['member_no'] ? ' (' . $m['member_no'] . ')' : ''), 140);

            $xml[] = '      <DrctDbtTxInf>';
            $xml[] = '        <PmtId><EndToEndId>' . $xEsc($e2eId) . '</EndToEndId></PmtId>';
            $xml[] = '        <InstdAmt Ccy="EUR">' . $feeStr . '</InstdAmt>';
            $xml[] = '        <DrctDbtTx><MndtRltdInf><MndtId>' . $xEsc($mndtId) . '</MndtId><DtOfSgntr>' . $xEsc($sgntr) . '</DtOfSgntr></MndtRltdInf></DrctDbtTx>';
            $xml[] = '        <DbtrAgt><FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId></DbtrAgt>';
            $xml[] = '        <Dbtr><Nm>' . $xEsc($dbtrNm) . '</Nm></Dbtr>';
            $xml[] = '        <DbtrAcct><Id><IBAN>' . $xEsc($m['iban']) . '</IBAN></Id></DbtrAcct>';
            $xml[] = '        <RmtInf><Ustrd>' . $xEsc($ustrd) . '</Ustrd></RmtInf>';
            $xml[] = '      </DrctDbtTxInf>';
        }

        $xml[] = '    </PmtInf>';
        $xml[] = '  </CstmrDrctDbtInitn>';
        $xml[] = '</Document>';

        $filename = 'sepa-lastschrift-' . $collDate . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo implode("\r\n", $xml);
        exit;
    }
}
