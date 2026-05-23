<?php
// db.php - Conexión PDO a MySQL.
// Las credenciales se buscan en este orden: variables de entorno -> db.local.php
// -> valores de XAMPP. Así el mismo archivo sirve en tu PC y en el hosting.
// getDB() devuelve un singleton: una sola conexión por request.

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = [
            'host' => getenv('DB_HOST') ?: null,
            'name' => getenv('DB_NAME') ?: null,
            'user' => getenv('DB_USER') ?: null,
            'pass' => getenv('DB_PASS'),
        ];
        if (!$cfg['host'] && file_exists(__DIR__ . '/db.local.php')) {
            $cfg = require __DIR__ . '/db.local.php';
        }
        $host = $cfg['host'] ?? null ?: '127.0.0.1';
        $name = $cfg['name'] ?? null ?: 'copago';
        $user = $cfg['user'] ?? null ?: 'root';
        $pass = $cfg['pass'] ?? '';

        $pdo = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
