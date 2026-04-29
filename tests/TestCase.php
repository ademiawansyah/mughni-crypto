<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $databaseConfig = $this->testing_database_config();

        $this->ensureTestingDatabaseExists($databaseConfig);

        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = 'pgsql';
        $_SERVER['DB_CONNECTION'] = 'pgsql';
        $_ENV['DB_HOST'] = $databaseConfig['host'];
        $_SERVER['DB_HOST'] = $databaseConfig['host'];
        $_ENV['DB_PORT'] = $databaseConfig['port'];
        $_SERVER['DB_PORT'] = $databaseConfig['port'];
        $_ENV['DB_DATABASE'] = $databaseConfig['database'];
        $_SERVER['DB_DATABASE'] = $databaseConfig['database'];
        $_ENV['DB_USERNAME'] = $databaseConfig['username'];
        $_SERVER['DB_USERNAME'] = $databaseConfig['username'];
        $_ENV['DB_PASSWORD'] = $databaseConfig['password'];
        $_SERVER['DB_PASSWORD'] = $databaseConfig['password'];
        $_ENV['DB_URL'] = '';
        $_SERVER['DB_URL'] = '';

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.host', $databaseConfig['host']);
        $app['config']->set('database.connections.pgsql.port', $databaseConfig['port']);
        $app['config']->set('database.connections.pgsql.database', $databaseConfig['database']);
        $app['config']->set('database.connections.pgsql.username', $databaseConfig['username']);
        $app['config']->set('database.connections.pgsql.password', $databaseConfig['password']);
        $app['db']->purge();

        return $app;
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function testing_database_config(): array
    {
        return [
            'host' => (string) (getenv('DB_HOST') ?: 'postgres'),
            'port' => (string) (getenv('DB_PORT') ?: '5432'),
            'database' => (string) (getenv('DB_TEST_DATABASE') ?: 'mughni_crypto_test'),
            'username' => (string) (getenv('DB_USERNAME') ?: 'devuser'),
            'password' => (string) (getenv('DB_PASSWORD') ?: 'devpassword'),
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $databaseConfig
     */
    private function ensureTestingDatabaseExists(array $databaseConfig): void
    {
        $connection = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $databaseConfig['host'], $databaseConfig['port']),
            $databaseConfig['username'],
            $databaseConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $statement = $connection->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
        $statement->execute(['database' => $databaseConfig['database']]);

        if ($statement->fetchColumn()) {
            return;
        }

        $connection->exec(sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $databaseConfig['database'])));
    }
}
