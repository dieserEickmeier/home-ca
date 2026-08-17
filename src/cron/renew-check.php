#!/usr/bin/env php
<?php
// Läuft periodisch per busybox-crond (siehe entrypoint.sh).
// Kein Web-Zugriff auf dieses Skript - liegt bewusst unter src/, nicht public/.

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../CaManager.php';
require_once __DIR__ . '/../NpmClient.php';

$ca = new CaManager();
if (!$ca->caExists()) {
    echo gmdate('c') . " keine CA vorhanden, ueberspringe.\n";
    exit(0);
}

$results = $ca->processAutoRenewals();
if (empty($results)) {
    echo gmdate('c') . " nichts faellig.\n";
    exit(0);
}

foreach ($results as $r) {
    $line = gmdate('c') . " #{$r['id']} ({$r['common_name']}): ";
    if (!empty($r['error'])) {
        $line .= "FEHLER - " . $r['error'];
    } else {
        $line .= "erneuert";
        if (!empty($r['pushed_to_npm'])) {
            $line .= " + nach NPM gepusht";
            if (array_key_exists('live_check_ok', $r)) {
                $line .= $r['live_check_ok'] ? " (Live-Check OK)" : " (Live-Check FEHLGESCHLAGEN)";
            }
        }
    }
    echo $line . "\n";
}
