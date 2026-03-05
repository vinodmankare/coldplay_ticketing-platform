<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connection(?string $path = null): PDO
    {
        $dbPath = $path ?? getenv('DB_PATH') ?: __DIR__ . '/../storage/app.sqlite';

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
