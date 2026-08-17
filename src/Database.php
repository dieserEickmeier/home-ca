<?php

class Database {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            $dataDir = getenv('DATA_DIR') ?: '/app/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0700, true);
            }
            $dbFile = $dataDir . '/ca.db';
            self::$pdo = new PDO('sqlite:' . $dbFile);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::initSchema(self::$pdo);
        }
        return self::$pdo;
    }

    private static function initSchema(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ca_config (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            subject_cn TEXT,
            subject_o TEXT,
            subject_c TEXT,
            key_algo TEXT,
            key_param TEXT,
            validity_days INTEGER,
            created_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            serial TEXT,
            common_name TEXT,
            sans TEXT,
            key_algo TEXT,
            key_param TEXT,
            status TEXT DEFAULT 'pending',
            source TEXT,
            csr_pem TEXT,
            cert_pem TEXT,
            private_key_pem TEXT,
            key_downloaded INTEGER DEFAULT 0,
            reject_reason TEXT,
            validity_days INTEGER DEFAULT 397,
            created_at TEXT,
            issued_at TEXT,
            expires_at TEXT,
            revoked_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS npm_config (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            base_url TEXT,
            identity TEXT,
            secret TEXT,
            updated_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS notify_config (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            pushover_token TEXT,
            pushover_user TEXT,
            enabled INTEGER DEFAULT 0,
            updated_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            certificate_id INTEGER NOT NULL,
            old_serial TEXT,
            new_serial TEXT,
            renewed_at TEXT,
            pushed_to_npm INTEGER DEFAULT 0,
            live_check_ok INTEGER,
            live_check_note TEXT
        )");

        self::migrate($pdo);
    }

    private static function migrate(PDO $pdo): void {
        $caCols = array_column($pdo->query("PRAGMA table_info(ca_config)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['crl_url' => 'TEXT', 'ocsp_url' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $caCols, true)) {
                $pdo->exec("ALTER TABLE ca_config ADD COLUMN $col $type");
            }
        }

        $certCols = array_column($pdo->query("PRAGMA table_info(certificates)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ([
            'auto_renew' => 'INTEGER DEFAULT 0',
            'renew_before_days' => 'INTEGER DEFAULT 30',
            'npm_cert_id' => 'INTEGER',
            'last_renewed_at' => 'TEXT',
        ] as $col => $type) {
            if (!in_array($col, $certCols, true)) {
                $pdo->exec("ALTER TABLE certificates ADD COLUMN $col $type");
            }
        }
    }
}
