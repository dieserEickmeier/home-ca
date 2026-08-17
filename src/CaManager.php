<?php

class CaManager {
    private string $caDir;
    private string $caKey;
    private string $caCert;
    private string $indexFile;
    private string $crlFile;
    private string $crlNumberFile;
    private PDO $db;

    public function __construct() {
        $dataDir = getenv('DATA_DIR') ?: '/app/data';
        $this->caDir = $dataDir . '/ca';
        if (!is_dir($this->caDir)) {
            mkdir($this->caDir, 0700, true);
        }
        $this->caKey = $this->caDir . '/ca.key';
        $this->caCert = $this->caDir . '/ca.crt';
        $this->indexFile = $this->caDir . '/index.txt';
        $this->crlFile = $this->caDir . '/crl.pem';
        $this->crlNumberFile = $this->caDir . '/crlnumber';
        $this->db = Database::get();
    }

    // ---------- Status ----------

    public function caExists(): bool {
        return file_exists($this->caCert) && file_exists($this->caKey);
    }

    // ---------- CA anlegen ----------

    public function createCa(string $cn, string $o, string $c, int $validityDays, string $algo, string $param): void {
        if ($cn === '') {
            throw new RuntimeException('CN erforderlich.');
        }
        $this->genKeyFile($algo, $param, $this->caKey);
        chmod($this->caKey, 0600);

        $subj = '';
        if ($c !== '') $subj .= '/C=' . $c;
        if ($o !== '') $subj .= '/O=' . $o;
        $subj .= '/CN=' . $cn;

        $this->runOpenssl([
            'req', '-x509', '-new', '-key', $this->caKey,
            '-days', (string)$validityDays, '-sha256',
            '-subj', $subj, '-out', $this->caCert,
            '-addext', 'basicConstraints=critical,CA:TRUE',
            '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
        ]);

        $stmt = $this->db->prepare("INSERT OR REPLACE INTO ca_config
            (id, subject_cn, subject_o, subject_c, key_algo, key_param, validity_days, created_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cn, $o, $c, $algo, $param, $validityDays, gmdate('c')]);

        $this->regenerateCrl();
    }

    public function getCaInfo(): array {
        $subject = $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $this->caCert, '-noout', '-subject']), 'subject=');
        $enddate = $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $this->caCert, '-noout', '-enddate']), 'notAfter=');
        $startdate = $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $this->caCert, '-noout', '-startdate']), 'notBefore=');
        $fpRaw = $this->runOpenssl(['x509', '-in', $this->caCert, '-noout', '-fingerprint', '-sha256']);
        $fingerprint = trim(preg_replace('/^.*Fingerprint=/', '', $fpRaw));

        $stmt = $this->db->query("SELECT * FROM ca_config WHERE id = 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $counts = $this->db->query("SELECT status, COUNT(*) c FROM certificates GROUP BY status")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'subject' => $subject,
            'not_before' => $startdate,
            'not_after' => $enddate,
            'fingerprint_sha256' => $fingerprint,
            'key_algo' => $config['key_algo'] ?? null,
            'key_param' => $config['key_param'] ?? null,
            'created_at' => $config['created_at'] ?? null,
            'counts' => $counts,
            'crl_url' => $config['crl_url'] ?? null,
            'ocsp_url' => $config['ocsp_url'] ?? null,
            'crl' => $this->getCrlInfo(),
            'ocsp_reachable' => $this->ocspResponderReachable(),
        ];
    }

    public function downloadCa(string $format): void {
        $pem = file_get_contents($this->caCert);
        if ($format === 'der') {
            $der = $this->pemToDer($pem);
            $this->sendFile($der, 'ca.crt.der', 'application/x-x509-ca-cert');
        } else {
            $this->sendFile($pem, 'ca.crt.pem', 'application/x-pem-file');
        }
    }

    // ---------- CSR erzeugen / hochladen ----------

    public function generateAndStoreCsr(string $cn, string $sansRaw, string $algo, string $param): array {
        if ($cn === '') {
            throw new RuntimeException('CN erforderlich.');
        }
        $sans = self::normalizeSans($sansRaw);
        $tmpKey = $this->tmp('key');
        $tmpCsr = $this->tmp('csr');
        try {
            $this->genKeyFile($algo, $param, $tmpKey);
            $args = ['req', '-new', '-key', $tmpKey, '-out', $tmpCsr, '-subj', '/CN=' . $cn, '-sha256'];
            if (!empty($sans)) {
                $args[] = '-addext';
                $args[] = 'subjectAltName=' . implode(',', $sans);
            }
            $this->runOpenssl($args);
            $csrPem = file_get_contents($tmpCsr);
            $keyPem = file_get_contents($tmpKey);
        } finally {
            @unlink($tmpKey);
            @unlink($tmpCsr);
        }

        $stmt = $this->db->prepare("INSERT INTO certificates
            (common_name, sans, key_algo, key_param, status, source, csr_pem, private_key_pem, created_at)
            VALUES (?, ?, ?, ?, 'pending', 'generated', ?, ?, ?)");
        $stmt->execute([$cn, implode(',', $sans), $algo, $param, $csrPem, $keyPem, gmdate('c')]);

        return [
            'ok' => true,
            'id' => (int)$this->db->lastInsertId(),
            'csr_pem' => $csrPem,
            'private_key_pem' => $keyPem,
        ];
    }

    public function storeUploadedCsr(string $csrPem): int {
        $csrPem = trim($csrPem);
        if ($csrPem === '' || strpos($csrPem, 'BEGIN CERTIFICATE REQUEST') === false) {
            throw new RuntimeException('Ungültiges CSR-Format.');
        }
        $tmp = $this->tmp('csr');
        file_put_contents($tmp, $csrPem);
        try {
            $this->runOpenssl(['req', '-in', $tmp, '-noout', '-verify']);
            [$cn, $sans] = $this->parseCsrFile($tmp);
        } finally {
            @unlink($tmp);
        }

        $stmt = $this->db->prepare("INSERT INTO certificates
            (common_name, sans, status, source, csr_pem, created_at)
            VALUES (?, ?, 'pending', 'uploaded', ?, ?)");
        $stmt->execute([$cn, implode(',', $sans), $csrPem, gmdate('c')]);
        return (int)$this->db->lastInsertId();
    }

    private function parseCsrFile(string $path): array {
        $text = $this->runOpenssl(['req', '-in', $path, '-noout', '-text']);
        $cn = '';
        if (preg_match('/Subject:.*?CN\s*=\s*([^,\n\/]+)/', $text, $m)) {
            $cn = trim($m[1]);
        }
        $sans = [];
        if (preg_match('/X509v3 Subject Alternative Name:\s*\n\s*(.+)/', $text, $m)) {
            $sans = array_map('trim', explode(',', $m[1]));
        }
        return [$cn, $sans];
    }

    // ---------- Liste / Details ----------

    public function listCertificates(?string $status): array {
        $sql = "SELECT id, common_name, sans, status, source, key_algo, key_param,
                key_downloaded, validity_days, created_at, issued_at, expires_at, revoked_at, serial,
                auto_renew, renew_before_days, npm_cert_id, last_renewed_at
                FROM certificates";
        if ($status) {
            $stmt = $this->db->prepare($sql . " WHERE status = ? ORDER BY id DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query($sql . " ORDER BY id DESC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCertificate(int $id): ?array {
        $stmt = $this->db->prepare("SELECT id, common_name, sans, status, source, key_algo, key_param,
            csr_pem, cert_pem, key_downloaded, reject_reason, validity_days,
            created_at, issued_at, expires_at, revoked_at, serial,
            auto_renew, renew_before_days, npm_cert_id, last_renewed_at,
            CASE WHEN private_key_pem IS NOT NULL THEN 1 ELSE 0 END AS has_private_key
            FROM certificates WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requireCertRow(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM certificates WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Zertifikat/CSR nicht gefunden.');
        }
        return $row;
    }

    // ---------- Genehmigen / Ablehnen / Widerrufen ----------

    public function approveCsr(int $id, int $validityDays, ?string $sansOverrideRaw): void {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'pending') {
            throw new RuntimeException('CSR nicht im Status pending.');
        }

        $sans = ($sansOverrideRaw !== null && $sansOverrideRaw !== '')
            ? self::normalizeSans($sansOverrideRaw)
            : ($row['sans'] ? explode(',', $row['sans']) : []);

        [$certPem, $serial, $enddate] = $this->signCsrPem($row['csr_pem'], $sans, $validityDays);

        $stmt = $this->db->prepare("UPDATE certificates SET
            status = 'issued', cert_pem = ?, serial = ?, sans = ?, validity_days = ?,
            issued_at = ?, expires_at = ? WHERE id = ?");
        $stmt->execute([$certPem, $serial, implode(',', $sans), $validityDays, gmdate('c'), $enddate, $id]);

        $this->regenerateCrl();
    }

    // Genehmigt alle aktuell ausstehenden CSRs mit einheitlicher Gültigkeit.
    // Praktisch nach einem NPM-Import mit vielen wartenden CSRs auf einmal.
    public function approveAllPending(int $validityDays = 397): array {
        $ids = $this->db->query("SELECT id, common_name FROM certificates WHERE status = 'pending'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        foreach ($ids as $row) {
            $id = (int)$row['id'];
            try {
                $this->approveCsr($id, $validityDays, null);
                $results[] = ['id' => $id, 'common_name' => $row['common_name'], 'ok' => true];
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'common_name' => $row['common_name'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    // Signiert ein CSR mit den Standard-Leaf-Extensions + aktuell konfigurierten
    // CRL/OCSP-URLs. Gemeinsam genutzt von approveCsr (Erstausstellung) und
    // renewCertificate (Erneuerung desselben CSR).
    private function signCsrPem(string $csrPem, array $sans, int $validityDays): array {
        $tmpCsr = $this->tmp('csr');
        $tmpExt = $this->tmp('ext');
        $tmpCert = $this->tmp('crt');
        try {
            file_put_contents($tmpCsr, $csrPem);
            $ext = "basicConstraints=CA:FALSE\n"
                 . "keyUsage=digitalSignature,keyEncipherment\n"
                 . "extendedKeyUsage=serverAuth,clientAuth\n";
            if (!empty($sans)) {
                $ext .= "subjectAltName=" . implode(',', $sans) . "\n";
            }
            $urls = $this->db->query("SELECT crl_url, ocsp_url FROM ca_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($urls['crl_url'])) {
                $ext .= "crlDistributionPoints=URI:" . $urls['crl_url'] . "\n";
            }
            if (!empty($urls['ocsp_url'])) {
                $ext .= "authorityInfoAccess=OCSP;URI:" . $urls['ocsp_url'] . "\n";
            }
            file_put_contents($tmpExt, $ext);

            $this->runOpenssl([
                'x509', '-req', '-in', $tmpCsr,
                '-CA', $this->caCert, '-CAkey', $this->caKey, '-CAcreateserial',
                '-days', (string)$validityDays, '-sha256',
                '-extfile', $tmpExt, '-out', $tmpCert,
            ]);
            $certPem = file_get_contents($tmpCert);
            $serial = $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $tmpCert, '-noout', '-serial']), 'serial=');
            $enddate = $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $tmpCert, '-noout', '-enddate']), 'notAfter=');
            return [$certPem, $serial, $enddate];
        } finally {
            @unlink($tmpCsr);
            @unlink($tmpExt);
            @unlink($tmpCert);
        }
    }

    public function rejectCsr(int $id, string $reason): void {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'pending') {
            throw new RuntimeException('CSR nicht im Status pending.');
        }
        $stmt = $this->db->prepare("UPDATE certificates SET status = 'rejected', reject_reason = ? WHERE id = ?");
        $stmt->execute([$reason, $id]);
    }

    public function revokeCertificate(int $id): void {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'issued') {
            throw new RuntimeException('Nur ausgestellte Zertifikate widerrufbar.');
        }
        $stmt = $this->db->prepare("UPDATE certificates SET status = 'revoked', revoked_at = ? WHERE id = ?");
        $stmt->execute([gmdate('c'), $id]);

        $this->regenerateCrl();
    }

    public function purgePrivateKey(int $id): void {
        $stmt = $this->db->prepare("UPDATE certificates SET private_key_pem = NULL, key_downloaded = 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    // ---------- Erneuerung (Renewal) ----------

    public function renewCertificate(int $id): void {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'issued') {
            throw new RuntimeException('Nur ausgestellte Zertifikate erneuerbar.');
        }
        if (!$row['csr_pem']) {
            throw new RuntimeException('Kein CSR gespeichert, Erneuerung nicht möglich.');
        }

        $sans = $row['sans'] ? explode(',', $row['sans']) : [];
        $validityDays = (int)($row['validity_days'] ?: 397);
        $oldSerial = $row['serial'];

        [$certPem, $serial, $enddate] = $this->signCsrPem($row['csr_pem'], $sans, $validityDays);

        $stmt = $this->db->prepare("UPDATE certificates SET
            cert_pem = ?, serial = ?, issued_at = ?, expires_at = ?, last_renewed_at = ?
            WHERE id = ?");
        $stmt->execute([$certPem, $serial, gmdate('c'), $enddate, gmdate('c'), $id]);

        $hist = $this->db->prepare("INSERT INTO renewal_history
            (certificate_id, old_serial, new_serial, renewed_at) VALUES (?, ?, ?, ?)");
        $hist->execute([$id, $oldSerial, $serial, gmdate('c')]);

        $this->regenerateCrl();
    }

    public function getRenewalHistory(int $id, int $limit = 10): array {
        $stmt = $this->db->prepare("SELECT * FROM renewal_history WHERE certificate_id = ? ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setAutoRenew(int $id, bool $enabled, int $renewBeforeDays, ?int $npmCertId): void {
        $stmt = $this->db->prepare("UPDATE certificates SET
            auto_renew = ?, renew_before_days = ?, npm_cert_id = ? WHERE id = ?");
        $stmt->execute([$enabled ? 1 : 0, $renewBeforeDays, $npmCertId, $id]);
    }

    // Wird per Cron periodisch aufgerufen (siehe src/cron/renew-check.php).
    // Prüft alle Zertifikate mit auto_renew=1, erneuert die fälligen und
    // pusht sie bei hinterlegter npm_cert_id direkt in NPM.
    public function processAutoRenewals(): array {
        $results = [];
        $rows = $this->db->query("SELECT * FROM certificates WHERE status = 'issued' AND auto_renew = 1")
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $daysLeft = $this->daysUntil($row['expires_at']);
            if ($daysLeft === null || $daysLeft > (int)$row['renew_before_days']) {
                continue;
            }
            $entry = ['id' => (int)$row['id'], 'common_name' => $row['common_name']];
            try {
                $this->renewCertificate((int)$row['id']);
                $entry['renewed'] = true;
                if (!empty($row['npm_cert_id'])) {
                    $pushResult = $this->pushToNpm((int)$row['id']);
                    $entry['pushed_to_npm'] = true;
                    $entry['live_check_ok'] = $pushResult['live_check']['ok'] ?? null;
                }
            } catch (Throwable $e) {
                $entry['error'] = $e->getMessage();
            }
            $results[] = $entry;
        }

        $failed = array_filter($results, fn($r) => !empty($r['error']));
        if (!empty($failed)) {
            $uniqueErrors = array_values(array_unique(array_map(fn($r) => $r['error'], $failed)));
            // Wenn alle Ausfälle exakt denselben Fehler zeigen UND der nach NPM
            // riecht (Login/Verbindung/Upload), liegt es an NPM insgesamt -
            // eine Sammelmeldung statt einer Pushover-Nachricht pro Host.
            if (count($failed) > 1 && count($uniqueErrors) === 1 && $this->looksLikeNpmIssue($uniqueErrors[0])) {
                $first = reset($failed);
                $this->sendPushoverMessage(
                    'Home CA — NPM nicht erreichbar',
                    count($failed) . " Zertifikate betroffen (u.a. {$first['common_name']}): {$uniqueErrors[0]}"
                );
            } else {
                $lines = array_map(fn($r) => "#{$r['id']} {$r['common_name']}: {$r['error']}", $failed);
                $this->sendPushoverMessage('Home CA — Renewal-Fehler', implode("\n", $lines));
            }
        }

        if ($this->caExists() && !$this->ocspResponderReachable()) {
            $this->sendPushoverMessage('Home CA — OCSP down', 'OCSP-Responder auf Port 2560 nicht erreichbar.');
        }

        return $results;
    }

    private function looksLikeNpmIssue(string $message): bool {
        return stripos($message, 'NPM') !== false;
    }

    private function daysUntil(?string $opensslDate): ?int {
        if (!$opensslDate) return null;
        $normalized = preg_replace('/\s+/', ' ', trim($opensslDate));
        $dt = DateTime::createFromFormat('M j H:i:s Y \G\M\T', $normalized, new DateTimeZone('UTC'));
        if (!$dt) return null;
        $now = new DateTime('now', new DateTimeZone('UTC'));
        return (int)floor(($dt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    // ---------- Benachrichtigungen (Pushover) ----------

    public function getNotifyConfig(): array {
        $row = $this->db->query("SELECT pushover_token, pushover_user, enabled FROM notify_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pushover_user' => $row['pushover_user'] ?? null,
            'token_set' => !empty($row['pushover_token']),
            'enabled' => !empty($row['enabled']),
        ];
    }

    public function saveNotifyConfig(?string $token, string $user, bool $enabled): void {
        $current = $this->db->query("SELECT pushover_token FROM notify_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $tokenToStore = ($token !== null && $token !== '') ? $token : ($current['pushover_token'] ?? null);
        $stmt = $this->db->prepare("INSERT INTO notify_config (id, pushover_token, pushover_user, enabled, updated_at)
            VALUES (1, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET pushover_token = excluded.pushover_token,
                pushover_user = excluded.pushover_user, enabled = excluded.enabled, updated_at = excluded.updated_at");
        $stmt->execute([$tokenToStore, $user, $enabled ? 1 : 0, gmdate('c')]);
    }

    public function testPushover(): array {
        $ok = $this->sendPushoverMessage('Home CA', 'Testbenachrichtigung — Pushover ist korrekt eingerichtet.', true);
        if (!$ok) {
            throw new RuntimeException('Pushover-Test fehlgeschlagen. Token/User-Key prüfen, "Aktiviert" muss angehakt sein.');
        }
        return ['ok' => true];
    }

    // $force=true umgeht das enabled-Flag (für den "Test senden"-Button, auch wenn
    // Benachrichtigungen noch nicht global aktiviert sind).
    private function sendPushoverMessage(string $title, string $message, bool $force = false): bool {
        $cfg = $this->db->query("SELECT * FROM notify_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$cfg || empty($cfg['pushover_token']) || empty($cfg['pushover_user'])) {
            return false;
        }
        if (!$force && empty($cfg['enabled'])) {
            return false;
        }
        $ch = curl_init('https://api.pushover.net/1/messages.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'token' => $cfg['pushover_token'],
                'user' => $cfg['pushover_user'],
                'title' => $title,
                'message' => $message,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $resp !== false && $code < 300;
    }

    // ---------- Backup ----------

    public function exportBackup(): void {
        $dataDir = getenv('DATA_DIR') ?: '/app/data';
        $tmpZip = $this->tmp('zip');

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Backup-Archiv konnte nicht erstellt werden.');
        }
        $this->addDirToZip($zip, $dataDir, '');
        $zip->close();

        $content = file_get_contents($tmpZip);
        @unlink($tmpZip);
        $this->sendFile($content, 'home-ca-backup-' . gmdate('Ymd-His') . '.zip', 'application/zip');
    }

    private function addDirToZip(ZipArchive $zip, string $dir, string $prefix): void {
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $localPath = $prefix === '' ? $item : $prefix . '/' . $item;
            if (is_dir($path)) {
                $zip->addEmptyDir($localPath);
                $this->addDirToZip($zip, $path, $localPath);
            } else {
                $zip->addFile($path, $localPath);
            }
        }
    }

    // Alle ausgestellten Zertifikate (Chain + Key, falls vorhanden) gesammelt
    // als ein ZIP - für Dokumentation oder Migration auf eine andere Instanz.
    public function exportAllCertificates(): void {
        $rows = $this->db->query("SELECT * FROM certificates WHERE status = 'issued' AND cert_pem IS NOT NULL ORDER BY common_name")
            ->fetchAll(PDO::FETCH_ASSOC);
        $caPem = file_exists($this->caCert) ? file_get_contents($this->caCert) : '';

        $tmpZip = $this->tmp('zip');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Export-Archiv konnte nicht erstellt werden.');
        }
        if ($caPem !== '') {
            $zip->addFromString('_root-ca.crt', $caPem);
        }
        foreach ($rows as $row) {
            $cn = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $row['common_name']);
            $folder = $cn . '_' . $row['id'];
            $zip->addFromString($folder . '/certificate.pem', $row['cert_pem']);
            $zip->addFromString($folder . '/chain.pem', $row['cert_pem'] . "\n" . $caPem);
            if (!empty($row['private_key_pem'])) {
                $zip->addFromString($folder . '/private.key.pem', $row['private_key_pem']);
            }
        }
        $zip->close();

        $content = file_get_contents($tmpZip);
        @unlink($tmpZip);
        $this->sendFile($content, 'home-ca-all-certificates-' . gmdate('Ymd-His') . '.zip', 'application/zip');
    }

    // ---------- NPM-Integration ----------

    public function getNpmConfig(): array {
        $row = $this->db->query("SELECT base_url, identity, secret FROM npm_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'base_url' => $row['base_url'] ?? null,
            'identity' => $row['identity'] ?? null,
            'secret_set' => !empty($row['secret']),
        ];
    }

    public function saveNpmConfig(string $baseUrl, string $identity, ?string $secret): void {
        $current = $this->db->query("SELECT secret FROM npm_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $secretToStore = ($secret !== null && $secret !== '') ? $secret : ($current['secret'] ?? null);
        $stmt = $this->db->prepare("INSERT INTO npm_config (id, base_url, identity, secret, updated_at)
            VALUES (1, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET base_url = excluded.base_url, identity = excluded.identity,
                secret = excluded.secret, updated_at = excluded.updated_at");
        $stmt->execute([$baseUrl, $identity, $secretToStore, gmdate('c')]);
    }

    public function testNpmConnection(): array {
        $cfg = $this->db->query("SELECT * FROM npm_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$cfg || empty($cfg['base_url']) || empty($cfg['identity']) || empty($cfg['secret'])) {
            throw new RuntimeException('NPM-Zugangsdaten unvollständig.');
        }
        $token = NpmClient::getToken($cfg['base_url'], $cfg['identity'], $cfg['secret']);
        $certs = NpmClient::listCertificates($cfg['base_url'], $token);
        return ['ok' => true, 'certificate_count' => count($certs)];
    }

    public function listNpmCertificates(): array {
        $cfg = $this->db->query("SELECT * FROM npm_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$cfg || empty($cfg['base_url']) || empty($cfg['identity']) || empty($cfg['secret'])) {
            throw new RuntimeException('NPM-Zugangsdaten unvollständig.');
        }
        $token = NpmClient::getToken($cfg['base_url'], $cfg['identity'], $cfg['secret']);
        $certs = NpmClient::listCertificates($cfg['base_url'], $token);
        $out = [];
        foreach ($certs as $c) {
            $out[] = [
                'id' => $c['id'] ?? null,
                'nice_name' => $c['nice_name'] ?? '',
                'provider' => $c['provider'] ?? '',
                'domain_names' => $c['domain_names'] ?? [],
            ];
        }
        return $out;
    }

    // Recovery-Werkzeug: legt pro ausgewähltem NPM-Zertifikat ein frisches CSR an
    // (CN/SANs aus den in NPM hinterlegten Domainnamen übernommen), verknüpft es
    // direkt mit der jeweiligen NPM-Zertifikat-ID und aktiviert Auto-Renew.
    // Nach Genehmigung reicht "Nur zu NPM pushen" - dieselbe NPM-ID wird
    // wiederbefüllt, keine Proxy-Host-Neukonfiguration in NPM nötig.
    public function importFromNpm(array $items): array {
        $results = [];
        foreach ($items as $item) {
            $npmCertId = (int)($item['npm_cert_id'] ?? 0);
            $cn = trim((string)($item['cn'] ?? ''));
            $sansRaw = (string)($item['sans'] ?? '');
            if ($cn === '' || $npmCertId <= 0) {
                $results[] = ['npm_cert_id' => $npmCertId, 'ok' => false, 'error' => 'Kein gültiger Domainname.'];
                continue;
            }
            try {
                $gen = $this->generateAndStoreCsr($cn, $sansRaw, 'RSA', '2048');
                $this->setAutoRenew((int)$gen['id'], true, 30, $npmCertId);
                $results[] = ['npm_cert_id' => $npmCertId, 'ok' => true, 'id' => $gen['id'], 'cn' => $cn];
            } catch (Throwable $e) {
                $results[] = ['npm_cert_id' => $npmCertId, 'ok' => false, 'cn' => $cn, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    public function pushToNpm(int $id): array {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'issued' || !$row['cert_pem']) {
            throw new RuntimeException('Zertifikat nicht ausgestellt.');
        }
        if (empty($row['npm_cert_id'])) {
            throw new RuntimeException('Kein NPM-Zertifikat verknüpft.');
        }
        if (empty($row['private_key_pem'])) {
            throw new RuntimeException('Kein privater Schlüssel auf Server — Push nach NPM erfordert serverseitig erzeugten und aufbewahrten Schlüssel.');
        }
        $cfg = $this->db->query("SELECT * FROM npm_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$cfg || empty($cfg['base_url']) || empty($cfg['identity']) || empty($cfg['secret'])) {
            throw new RuntimeException('NPM-Zugangsdaten nicht konfiguriert.');
        }
        $token = NpmClient::getToken($cfg['base_url'], $cfg['identity'], $cfg['secret']);
        $caPem = file_get_contents($this->caCert);
        NpmClient::uploadCertificate($cfg['base_url'], $token, (int)$row['npm_cert_id'], $row['cert_pem'], $row['private_key_pem'], $caPem);

        // Der reine Upload-Endpunkt lädt nginx bei NPM nicht zuverlässig neu
        // (bestätigtes NPM-Verhalten). Betroffene Proxy-Hosts unverändert
        // neu speichern erzwingt Config-Regenerierung + Reload zuverlässig.
        try {
            $hosts = NpmClient::listProxyHosts($cfg['base_url'], $token);
            foreach ($hosts as $host) {
                if ((int)($host['certificate_id'] ?? 0) === (int)$row['npm_cert_id']) {
                    NpmClient::touchProxyHost($cfg['base_url'], $token, (int)$host['id'], $host);
                }
            }
        } catch (Throwable $e) {
            // Zertifikat ist bereits erfolgreich hochgeladen — der Reload-Trigger
            // ist best effort. Nicht die ganze Push-Aktion daran scheitern lassen,
            // aber sichtbar machen.
            throw new RuntimeException('Zertifikat hochgeladen, aber Proxy-Host-Reload fehlgeschlagen: ' . $e->getMessage() . ' (ggf. manuell "nginx -s reload" im NPM-Container ausführen)');
        }

        // Live-Check: liefert der Host jetzt wirklich die neue Serial aus?
        // Trennt "Push kam an" von "wird auch tatsächlich ausgeliefert" -
        // genau der Fall, der beim NPM-Reload-Gap sonst stumm bleibt.
        // NPMs Reload nach dem Proxy-Host-Touch braucht manchmal einen Moment,
        // deshalb mehrere Versuche statt sofort aufzugeben.
        $liveCheck = $this->verifyLiveCertificate($row['common_name'], $row['serial']);
        for ($attempt = 0; $attempt < 2 && !$liveCheck['ok']; $attempt++) {
            sleep(2);
            $liveCheck = $this->verifyLiveCertificate($row['common_name'], $row['serial']);
        }

        $histId = $this->db->query(
            "SELECT id FROM renewal_history WHERE certificate_id = " . (int)$id . " ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        if ($histId) {
            $stmt = $this->db->prepare("UPDATE renewal_history SET pushed_to_npm = 1, live_check_ok = ?, live_check_note = ? WHERE id = ?");
            $stmt->execute([$liveCheck['ok'] ? 1 : 0, $liveCheck['note'], (int)$histId]);
        }

        return ['live_check' => $liveCheck];
    }

    // Verbindet sich per TLS zum Hostnamen des Zertifikats und vergleicht die
    // dort tatsächlich ausgelieferte Seriennummer mit der erwarteten. Nutzt
    // PHP-eigenes stream_socket_client statt openssl-CLI, da hier kein
    // Dateisystemzugriff nötig ist und der Peer-Zertifikatsinhalt direkt
    // aus dem Kontext gelesen werden kann.
    private function verifyLiveCertificate(string $host, string $expectedSerial): array {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            "ssl://{$host}:443", $errno, $errstr, 5,
            STREAM_CLIENT_CONNECT, $context
        );
        if (!$client) {
            return ['ok' => false, 'note' => "Verbindung zu {$host}:443 fehlgeschlagen: {$errstr}"];
        }
        $params = stream_context_get_params($client);
        fclose($client);

        if (empty($params['options']['ssl']['peer_certificate'])) {
            return ['ok' => false, 'note' => 'Kein Zertifikat vom Host erhalten.'];
        }
        $certInfo = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        $liveSerial = strtoupper($certInfo['serialNumberHex'] ?? '');
        $expected = strtoupper($expectedSerial);
        $matches = $liveSerial !== '' && $liveSerial === $expected;

        return [
            'ok' => $matches,
            'note' => $matches
                ? 'Live-Zertifikat stimmt überein.'
                : "Live liefert Serial {$liveSerial}, erwartet {$expected} — nginx evtl. noch nicht neu geladen.",
        ];
    }

    // ---------- Export ----------

    public function exportCertificate(int $id, string $format, string $password): void {
        $row = $this->requireCertRow($id);
        if ($row['status'] !== 'issued' || !$row['cert_pem']) {
            throw new RuntimeException('Zertifikat nicht ausgestellt.');
        }

        $cn = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $row['common_name']);
        $certPem = $row['cert_pem'];
        $caPem = file_get_contents($this->caCert);

        switch ($format) {
            case 'pem':
                $this->sendFile($certPem, "$cn.crt.pem", 'application/x-pem-file');
                break;

            case 'chain':
                $this->sendFile($certPem . "\n" . $caPem, "$cn.chain.pem", 'application/x-pem-file');
                break;

            case 'der':
                $der = $this->pemToDer($certPem);
                $this->sendFile($der, "$cn.crt.der", 'application/x-x509-ca-cert');
                break;

            case 'pfx':
            case 'p12':
                if (!$row['private_key_pem']) {
                    throw new RuntimeException('Kein privater Schlüssel auf Server (gelöscht oder CSR wurde hochgeladen).');
                }
                if ($password === '') {
                    throw new RuntimeException('Passwort für PFX erforderlich.');
                }
                $tmpCert = $this->tmp('pem');
                $tmpKey = $this->tmp('key');
                $tmpChain = $this->tmp('pem');
                $tmpPfx = $this->tmp('pfx');
                try {
                    file_put_contents($tmpCert, $certPem);
                    file_put_contents($tmpKey, $row['private_key_pem']);
                    file_put_contents($tmpChain, $caPem);
                    $this->runOpenssl([
                        'pkcs12', '-export',
                        '-inkey', $tmpKey, '-in', $tmpCert, '-certfile', $tmpChain,
                        '-out', $tmpPfx, '-passout', 'pass:' . $password,
                        '-name', $row['common_name'],
                    ]);
                    $pfx = file_get_contents($tmpPfx);
                } finally {
                    @unlink($tmpCert);
                    @unlink($tmpKey);
                    @unlink($tmpChain);
                    @unlink($tmpPfx);
                }
                $this->sendFile($pfx, "$cn.pfx", 'application/x-pkcs12');
                break;

            case 'key':
                if (!$row['private_key_pem']) {
                    throw new RuntimeException('Kein privater Schlüssel auf Server.');
                }
                $this->sendFile($row['private_key_pem'], "$cn.key.pem", 'application/x-pem-file');
                break;

            default:
                throw new RuntimeException('Unbekanntes Exportformat.');
        }
    }

    // ---------- CRL / OCSP ----------

    public function regenerateCrl(int $crlDays = 30): void {
        if (!$this->caExists()) return;
        $this->syncOcspIndex();

        if (!file_exists($this->crlNumberFile)) {
            file_put_contents($this->crlNumberFile, "1000\n");
        }

        $cnf = $this->tmp('cnf');
        file_put_contents($cnf, implode("\n", [
            '[ ca ]',
            'default_ca = home_ca',
            '',
            '[ home_ca ]',
            'database = ' . $this->indexFile,
            'certificate = ' . $this->caCert,
            'private_key = ' . $this->caKey,
            'default_md = sha256',
            'default_crl_days = ' . $crlDays,
            'crlnumber = ' . $this->crlNumberFile,
            '',
        ]));

        try {
            $this->runOpenssl(['ca', '-config', $cnf, '-gencrl', '-out', $this->crlFile]);
        } finally {
            @unlink($cnf);
        }
    }

    private function syncOcspIndex(): void {
        $stmt = $this->db->query("SELECT serial, cert_pem, status, expires_at, revoked_at
            FROM certificates
            WHERE status IN ('issued', 'revoked') AND serial IS NOT NULL AND cert_pem IS NOT NULL
            ORDER BY id ASC");

        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $expIndex = $this->toIndexDate($row['expires_at']);
            } catch (Throwable $e) {
                continue;
            }
            $dn = $this->dnToSlash($this->subjectOfPem($row['cert_pem']));

            if ($row['status'] === 'revoked' && $row['revoked_at']) {
                $revIndex = $this->isoToIndexDate($row['revoked_at']);
                $lines[] = "R\t{$expIndex}\t{$revIndex}\t{$row['serial']}\tunknown\t{$dn}";
            } else {
                $lines[] = "V\t{$expIndex}\t\t{$row['serial']}\tunknown\t{$dn}";
            }
        }

        // Atomarer Schreibvorgang: der OCSP-Supervisor im Container beobachtet
        // die mtime dieser Datei, um den Responder neu zu starten.
        $tmp = $this->indexFile . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . ($lines ? "\n" : ''));
        rename($tmp, $this->indexFile);
    }

    public function downloadCrl(string $format): void {
        if (!file_exists($this->crlFile)) {
            throw new RuntimeException('Noch keine CRL erzeugt.');
        }
        $pem = file_get_contents($this->crlFile);
        if ($format === 'der') {
            $tmpIn = $this->tmp('pem');
            $tmpOut = $this->tmp('der');
            try {
                file_put_contents($tmpIn, $pem);
                $this->runOpenssl(['crl', '-in', $tmpIn, '-outform', 'DER', '-out', $tmpOut]);
                $der = file_get_contents($tmpOut);
            } finally {
                @unlink($tmpIn);
                @unlink($tmpOut);
            }
            $this->sendFile($der, 'ca.crl.der', 'application/pkix-crl');
        } else {
            $this->sendFile($pem, 'ca.crl.pem', 'application/pkix-crl');
        }
    }

    public function getCrlInfo(): ?array {
        if (!file_exists($this->crlFile)) return null;
        $lastUpdate = $this->cleanOpensslLine($this->runOpenssl(['crl', '-in', $this->crlFile, '-noout', '-lastupdate']), 'lastUpdate=');
        $nextUpdate = $this->cleanOpensslLine($this->runOpenssl(['crl', '-in', $this->crlFile, '-noout', '-nextupdate']), 'nextUpdate=');
        $text = $this->runOpenssl(['crl', '-in', $this->crlFile, '-noout', '-text']);
        return [
            'last_update' => $lastUpdate,
            'next_update' => $nextUpdate,
            'revoked_count' => substr_count($text, 'Serial Number:'),
        ];
    }

    public function ocspResponderReachable(): bool {
        $fp = @fsockopen('127.0.0.1', 2560, $errno, $errstr, 0.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    public function updateDistributionUrls(?string $crlUrl, ?string $ocspUrl): void {
        $stmt = $this->db->prepare("UPDATE ca_config SET crl_url = ?, ocsp_url = ? WHERE id = 1");
        $stmt->execute([$crlUrl !== '' ? $crlUrl : null, $ocspUrl !== '' ? $ocspUrl : null]);
    }

    private function subjectOfPem(string $certPem): string {
        $tmp = $this->tmp('pem');
        try {
            file_put_contents($tmp, $certPem);
            return $this->cleanOpensslLine($this->runOpenssl(['x509', '-in', $tmp, '-noout', '-subject']), 'subject=');
        } finally {
            @unlink($tmp);
        }
    }

    private function dnToSlash(string $subjectLine): string {
        $out = '';
        foreach (explode(',', $subjectLine) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, '=') === false) continue;
            [$k, $v] = array_map('trim', explode('=', $part, 2));
            if ($k === '') continue;
            $out .= '/' . $k . '=' . $v;
        }
        return $out !== '' ? $out : '/CN=unknown';
    }

    private function toIndexDate(string $opensslDate): string {
        $normalized = preg_replace('/\s+/', ' ', trim($opensslDate));
        $dt = DateTime::createFromFormat('M j H:i:s Y \G\M\T', $normalized, new DateTimeZone('UTC'));
        if (!$dt) {
            throw new RuntimeException('Datum konnte nicht geparst werden: ' . $opensslDate);
        }
        return $dt->format('ymdHis') . 'Z';
    }

    private function isoToIndexDate(string $iso): string {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('ymdHis') . 'Z';
    }

    // ---------- Interne Helfer ----------

    public static function normalizeSans(string $raw): array {
        $parts = preg_split('/[,\n\r]+/', $raw);
        $result = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (strpos($p, 'DNS:') === 0 || strpos($p, 'IP:') === 0) {
                $result[] = $p;
                continue;
            }
            $result[] = filter_var($p, FILTER_VALIDATE_IP) ? ('IP:' . $p) : ('DNS:' . $p);
        }
        return $result;
    }

    private function genKeyFile(string $algo, string $param, string $outPath): void {
        if (strtoupper($algo) === 'EC') {
            $this->runOpenssl([
                'genpkey', '-algorithm', 'EC',
                '-pkeyopt', 'ec_paramgen_curve:' . $param,
                '-pkeyopt', 'ec_param_enc:named_curve',
                '-out', $outPath,
            ]);
        } else {
            $this->runOpenssl([
                'genpkey', '-algorithm', 'RSA',
                '-pkeyopt', 'rsa_keygen_bits:' . $param,
                '-out', $outPath,
            ]);
        }
    }

    private function pemToDer(string $pem): string {
        $tmpIn = $this->tmp('pem');
        $tmpOut = $this->tmp('der');
        try {
            file_put_contents($tmpIn, $pem);
            $this->runOpenssl(['x509', '-in', $tmpIn, '-outform', 'DER', '-out', $tmpOut]);
            return file_get_contents($tmpOut);
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    private function tmp(string $ext): string {
        return sys_get_temp_dir() . '/ca_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    private function cleanOpensslLine(string $line, string $prefix): string {
        $line = trim($line);
        if (strpos($line, $prefix) === 0) {
            $line = substr($line, strlen($prefix));
        }
        return trim($line);
    }

    private function sendFile(string $content, string $filename, string $contentType): void {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    private function runOpenssl(array $args, ?string $stdin = null): string {
        $cmd = array_merge(['openssl'], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('openssl konnte nicht gestartet werden.');
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('openssl-Fehler: ' . trim($stderr));
        }
        return $stdout;
    }
}
