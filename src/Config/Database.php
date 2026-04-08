<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Connexion PDO directe à Supabase PostgreSQL.
 * Non utilisée actuellement — toutes les opérations passent par l'API REST Supabase.
 * Conservée pour d'éventuelles requêtes complexes futures (agrégations, transactions).
 * Nécessite DB_HOST / DB_USER / DB_PASSWORD renseignés dans .env.
 */

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }

        return self::$instance;
    }

    private static function connect(): PDO
    {
        $host     = $_ENV['DB_HOST']     ?? throw new RuntimeException('DB_HOST non défini');
        $port     = $_ENV['DB_PORT']     ?? '5432';
        $dbname   = $_ENV['DB_NAME']     ?? 'postgres';
        $user     = $_ENV['DB_USER']     ?? throw new RuntimeException('DB_USER non défini');
        $password = $_ENV['DB_PASSWORD'] ?? throw new RuntimeException('DB_PASSWORD non défini');

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            $host, $port, $dbname
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Connexion à la base de données impossible : ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    private function __construct() {}
    private function __clone() {}
}
