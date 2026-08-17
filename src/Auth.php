<?php

class Auth {
    public static function login(string $password): bool {
        $expected = getenv('ADMIN_PASSWORD') ?: 'changeme';
        if ($password !== '' && hash_equals($expected, $password)) {
            $_SESSION['auth'] = true;
            return true;
        }
        return false;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function isAuthenticated(): bool {
        return !empty($_SESSION['auth']);
    }
}
