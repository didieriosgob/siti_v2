<?php

function ensureUserProfileColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users") as $col) {
        $existing[$col['Field']] = true;
    }

    if (!isset($existing['full_name'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN full_name VARCHAR(140) NULL AFTER username");
    }

    if (!isset($existing['is_social_service'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_social_service TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    }

    $done = true;
}

function userRoleLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Administrador',
        'user'  => 'Operativo',
        default => 'Consulta',
    };
}
