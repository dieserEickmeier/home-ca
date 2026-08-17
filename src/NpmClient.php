<?php

class NpmClient {
    public static function getToken(string $baseUrl, string $identity, string $secret): string {
        $ch = curl_init(rtrim($baseUrl, '/') . '/api/tokens');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['identity' => $identity, 'secret' => $secret]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('NPM nicht erreichbar: ' . $err);
        }
        $data = json_decode($resp, true);
        if ($code >= 300 || !isset($data['token'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException('NPM-Login fehlgeschlagen: ' . $msg);
        }
        return $data['token'];
    }

    public static function listCertificates(string $baseUrl, string $token): array {
        $ch = curl_init(rtrim($baseUrl, '/') . '/api/nginx/certificates');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 300) {
            throw new RuntimeException('NPM-Zertifikatsliste konnte nicht geladen werden (HTTP ' . $code . ').');
        }
        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }

    public static function listProxyHosts(string $baseUrl, string $token): array {
        $ch = curl_init(rtrim($baseUrl, '/') . '/api/nginx/proxy-hosts');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 300) {
            throw new RuntimeException('NPM-Proxy-Host-Liste konnte nicht geladen werden (HTTP ' . $code . ').');
        }
        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }

    // Speichert einen Proxy-Host unverändert erneut, um bei NPM eine
    // Config-Regenerierung + nginx-Reload zu erzwingen. Der reine
    // Zertifikats-Upload-Endpunkt löst das bei NPM nicht zuverlässig aus,
    // der normale Host-Save-Pfad dagegen schon.
    public static function touchProxyHost(string $baseUrl, string $token, int $hostId, array $host): void {
        $blocklist = ['id', 'created_on', 'modified_on', 'owner_user_id', 'certificate'];
        $payload = array_diff_key($host, array_flip($blocklist));

        $ch = curl_init(rtrim($baseUrl, '/') . "/api/nginx/proxy-hosts/{$hostId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('NPM Proxy-Host-Reload fehlgeschlagen: ' . $err);
        }
        if ($code >= 300) {
            $data = json_decode($resp, true);
            $msg = $data['error']['message'] ?? $resp;
            throw new RuntimeException('NPM Proxy-Host-Reload fehlgeschlagen (HTTP ' . $code . '): ' . $msg);
        }
    }

    public static function uploadCertificate(string $baseUrl, string $token, int $certId, string $certPem, string $keyPem, ?string $chainPem = null): void {
        $tmpCert = tempnam(sys_get_temp_dir(), 'npm_cert_');
        $tmpKey = tempnam(sys_get_temp_dir(), 'npm_key_');
        $tmpChain = null;
        file_put_contents($tmpCert, $certPem);
        file_put_contents($tmpKey, $keyPem);

        $fields = [
            'certificate' => new CURLFile($tmpCert, 'application/x-pem-file', 'certificate.pem'),
            'certificate_key' => new CURLFile($tmpKey, 'application/x-pem-file', 'certificate_key.pem'),
        ];
        if ($chainPem) {
            $tmpChain = tempnam(sys_get_temp_dir(), 'npm_chain_');
            file_put_contents($tmpChain, $chainPem);
            $fields['intermediate_certificate'] = new CURLFile($tmpChain, 'application/x-pem-file', 'chain.pem');
        }

        try {
            $ch = curl_init(rtrim($baseUrl, '/') . "/api/nginx/certificates/{$certId}/upload");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
            ]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                throw new RuntimeException('NPM-Upload fehlgeschlagen: ' . $err);
            }
            if ($code >= 300) {
                $data = json_decode($resp, true);
                $msg = $data['error']['message'] ?? $resp;
                throw new RuntimeException('NPM-Upload fehlgeschlagen (HTTP ' . $code . '): ' . $msg);
            }
        } finally {
            @unlink($tmpCert);
            @unlink($tmpKey);
            if ($tmpChain) @unlink($tmpChain);
        }
    }
}
