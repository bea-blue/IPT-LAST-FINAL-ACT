<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Creates the single PDO connection used by the data-access classes.
 * Prepared statements are real (not emulated) so values are always bound
 * separately from the SQL text.
 */
final class Database
{
    public static function connect(array $config): PDO
    {
        $pdo = new PDO($config['dsn'], $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Read and write TIMESTAMP values as UTC.
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $pdo;
    }
}
