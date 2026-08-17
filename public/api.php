<?php
declare(strict_types=1);

session_start();
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/CaManager.php';
require_once __DIR__ . '/../src/NpmClient.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'login':
            $body = readJsonBody();
            Auth::login($body['password'] ?? '')
                ? jsonResponse(['ok' => true])
                : jsonError('Passwort falsch.', 401);
            break;

        case 'logout':
            Auth::logout();
            jsonResponse(['ok' => true]);
            break;

        case 'status':
            $ca = new CaManager();
            jsonResponse([
                'loggedIn' => Auth::isAuthenticated(),
                'caExists' => $ca->caExists(),
            ]);
            break;

        case 'ca_create':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            if ($ca->caExists()) jsonError('CA existiert bereits.', 409);
            $ca->createCa(
                trim((string)($b['cn'] ?? '')),
                trim((string)($b['o'] ?? '')),
                trim((string)($b['c'] ?? '')),
                (int)($b['validity_days'] ?? 3650),
                (string)($b['key_algo'] ?? 'RSA'),
                (string)($b['key_param'] ?? '4096')
            );
            jsonResponse(['ok' => true]);
            break;

        case 'ca_info':
            requireAuth();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            jsonResponse($ca->getCaInfo());
            break;

        case 'ca_download':
            requireAuth();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $ca->downloadCa((string)($_GET['format'] ?? 'pem'));
            break;

        case 'ca_update_urls':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $ca->updateDistributionUrls(
                isset($b['crl_url']) ? trim((string)$b['crl_url']) : null,
                isset($b['ocsp_url']) ? trim((string)$b['ocsp_url']) : null
            );
            jsonResponse(['ok' => true]);
            break;

        // Öffentlich erreichbar, ohne Login: CRL-Distribution-Points in Zertifikaten
        // zeigen hierher, Clients müssen sie ohne Anmeldung abrufen können.
        case 'crl_download':
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $ca->downloadCrl((string)($_GET['format'] ?? 'pem'));
            break;

        case 'csr_generate':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            jsonResponse($ca->generateAndStoreCsr(
                trim((string)($b['cn'] ?? '')),
                (string)($b['sans'] ?? ''),
                (string)($b['key_algo'] ?? 'RSA'),
                (string)($b['key_param'] ?? '2048')
            ));
            break;

        case 'csr_upload':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $id = $ca->storeUploadedCsr((string)($b['csr_pem'] ?? ''));
            jsonResponse(['ok' => true, 'id' => $id]);
            break;

        case 'csr_list':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->listCertificates($_GET['status'] ?? null));
            break;

        case 'csr_get':
            requireAuth();
            $ca = new CaManager();
            $row = $ca->getCertificate((int)($_GET['id'] ?? 0));
            $row ? jsonResponse($row) : jsonError('Nicht gefunden.', 404);
            break;

        case 'csr_approve':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->approveCsr(
                (int)($b['id'] ?? 0),
                (int)($b['validity_days'] ?? 397),
                isset($b['sans']) ? (string)$b['sans'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'csr_approve_all':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            jsonResponse(['results' => $ca->approveAllPending((int)($b['validity_days'] ?? 397))]);
            break;

        case 'csr_reject':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->rejectCsr((int)($b['id'] ?? 0), (string)($b['reason'] ?? ''));
            jsonResponse(['ok' => true]);
            break;

        case 'cert_export':
            requireAuth();
            $ca = new CaManager();
            $ca->exportCertificate(
                (int)($_GET['id'] ?? 0),
                (string)($_GET['format'] ?? 'pem'),
                (string)($_GET['password'] ?? '')
            );
            break;

        case 'cert_key_purge':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->purgePrivateKey((int)($b['id'] ?? 0));
            jsonResponse(['ok' => true]);
            break;

        case 'cert_revoke':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->revokeCertificate((int)($b['id'] ?? 0));
            jsonResponse(['ok' => true]);
            break;

        case 'cert_renew_now':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $id = (int)($b['id'] ?? 0);
            $ca->renewCertificate($id);
            $pushed = false;
            $liveCheck = null;
            $row = $ca->getCertificate($id);
            if ($row && !empty($row['npm_cert_id'])) {
                $pushResult = $ca->pushToNpm($id);
                $pushed = true;
                $liveCheck = $pushResult['live_check'] ?? null;
            }
            jsonResponse(['ok' => true, 'pushed_to_npm' => $pushed, 'live_check' => $liveCheck]);
            break;

        case 'cert_set_renew':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->setAutoRenew(
                (int)($b['id'] ?? 0),
                !empty($b['auto_renew']),
                (int)($b['renew_before_days'] ?? 30),
                isset($b['npm_cert_id']) && $b['npm_cert_id'] !== '' ? (int)$b['npm_cert_id'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'cert_push_npm':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $pushResult = $ca->pushToNpm((int)($b['id'] ?? 0));
            jsonResponse(['ok' => true, 'live_check' => $pushResult['live_check'] ?? null]);
            break;

        case 'cert_renewal_history':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->getRenewalHistory((int)($_GET['id'] ?? 0)));
            break;

        case 'certs_export_all':
            requireAuth();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $ca->exportAllCertificates();
            break;

        case 'npm_settings_get':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->getNpmConfig());
            break;

        case 'npm_settings_save':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->saveNpmConfig(
                trim((string)($b['base_url'] ?? '')),
                trim((string)($b['identity'] ?? '')),
                isset($b['secret']) ? (string)$b['secret'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'npm_test':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->testNpmConnection());
            break;

        case 'npm_list_certificates':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->listNpmCertificates());
            break;

        case 'npm_import_generate':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $items = is_array($b['items'] ?? null) ? $b['items'] : [];
            jsonResponse(['results' => $ca->importFromNpm($items)]);
            break;

        case 'ca_backup':
            requireAuth();
            $ca = new CaManager();
            if (!$ca->caExists()) jsonError('Keine CA vorhanden.', 404);
            $ca->exportBackup();
            break;

        case 'notify_settings_get':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->getNotifyConfig());
            break;

        case 'notify_settings_save':
            requireAuth();
            $b = readJsonBody();
            $ca = new CaManager();
            $ca->saveNotifyConfig(
                isset($b['pushover_token']) ? (string)$b['pushover_token'] : null,
                trim((string)($b['pushover_user'] ?? '')),
                !empty($b['enabled'])
            );
            jsonResponse(['ok' => true]);
            break;

        case 'notify_test':
            requireAuth();
            $ca = new CaManager();
            jsonResponse($ca->testPushover());
            break;

        // Öffentlich, kein Login: für externe Monitoring-Tools (Uptime Kuma etc.)
        case 'health':
            $ca = new CaManager();
            $caOk = $ca->caExists();
            $ocspOk = $caOk && $ca->ocspResponderReachable();
            $crlStale = false;
            if ($caOk) {
                $crlInfo = $ca->getCrlInfo();
                if ($crlInfo && !empty($crlInfo['next_update'])) {
                    $normalized = preg_replace('/\s+/', ' ', trim($crlInfo['next_update']));
                    $dt = DateTime::createFromFormat('M j H:i:s Y \G\M\T', $normalized, new DateTimeZone('UTC'));
                    if ($dt && $dt->getTimestamp() < time()) $crlStale = true;
                }
            }
            $healthy = $caOk && $ocspOk && !$crlStale;
            jsonResponse([
                'status' => $healthy ? 'ok' : 'degraded',
                'ca_exists' => $caOk,
                'ocsp_reachable' => $ocspOk,
                'crl_stale' => $crlStale,
            ], $healthy ? 200 : 503);
            break;

        default:
            jsonError('Unbekannte Aktion.', 404);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
