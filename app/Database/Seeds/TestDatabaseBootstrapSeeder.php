<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Database;

class TestDatabaseBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        if (ENVIRONMENT !== 'testing') {
            throw new \RuntimeException('TestDatabaseBootstrapSeeder can only run in the testing environment.');
        }

        $config = config('Database');
        $group  = $config->tests;
        $dbName = $group['database'];

        if ($dbName === '') {
            throw new \RuntimeException('Refusing to reset a database with an empty name.');
        }

        // Note: this project intentionally reuses the same database name
        // ("ospos") for both the "default" and "tests" connection groups
        // (see .env / .env.example / phpunit.xml.dist), so the name alone
        // can't distinguish a real DB from a test one. The ENVIRONMENT check
        // above and hardcoding to $config->tests (never $config->default)
        // are what actually keep this from ever touching a real database.

        $serverConn = Database::connect([
            'hostname' => $group['hostname'],
            'username' => $group['username'],
            'password' => $group['password'],
            'DBDriver' => $group['DBDriver'],
            'database' => null,
            'charset'  => $group['charset'] ?? 'utf8mb4',
            'DBCollat' => $group['DBCollat'] ?? 'utf8mb4_general_ci',
        ], false);

        $serverConn->query("DROP DATABASE IF EXISTS `{$dbName}`");
        $serverConn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
    }
}
