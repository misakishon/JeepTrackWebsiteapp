<?php

namespace App\Console\Commands;

use App\Services\TransportSql;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class TransportImport extends Command
{
    protected $signature = 'transport:import {--connector=jdbc : jdbc uses the supplied JAR; pdo uses PHP} {--check : Verify connection without importing}';

    protected $description = 'Import the supplied MySQL schema into an empty database, without dropping existing tables';

    public function handle(TransportSql $sql): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Set DB_CONNECTION=mysql in .env first.');

            return self::FAILURE;
        }
        $cfg = config('database.connections.mysql');
        $payload = ['host' => $cfg['host'], 'port' => (string) $cfg['port'], 'database' => $cfg['database'], 'username' => $cfg['username'], 'password' => $cfg['password'] ?? '', 'action' => $this->option('check') ? 'check' : 'import'];
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $payload['database'])) {
            $this->error('Database name may contain only letters, numbers, and underscores.');

            return self::FAILURE;
        }
        try {
            $source = $this->option('check') ? '' : $sql->prepare(file_get_contents(database_path('transport_system.sql')));
            if ($this->option('connector') === 'jdbc') {
                $jar = config('transport.jdbc_jar');
                if (! is_file($jar)) {
                    throw new \RuntimeException('The configured Connector/J JAR was not found.');
                }
                // Secrets and SQL travel on stdin, never command-line arguments or logs.
                $input = implode("\n", array_map(fn ($value) => base64_encode((string) $value), array_values($payload)))."\n".base64_encode($source)."\n";
                $process = new Process([config('transport.java_binary'), '-cp', $jar, base_path('tools/mysql/TransportConnector.java')], base_path());
                $process->setInput($input)->setTimeout(90)->run();
                if (! $process->isSuccessful()) {
                    $this->error(trim($process->getErrorOutput()) ?: 'JDBC connection/import failed. Check the MySQL service and .env credentials.');

                    return self::FAILURE;
                }
                $this->info(trim($process->getOutput()));
            } elseif ($this->option('connector') === 'pdo') {
                $pdo = new \PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", $cfg['username'], $cfg['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                if ($this->option('check')) {
                    $pdo->exec('USE `'.$cfg['database'].'`');
                    $this->info('PDO connection verified. MySQL '.$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));

                    return self::SUCCESS;
                }
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$cfg['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec('USE `'.$cfg['database'].'`');
                if ($pdo->query('SHOW TABLES')->fetch()) {
                    throw new \RuntimeException('Import refused: the destination database already contains tables. No existing tables were changed.');
                }
                foreach ($sql->statements($source) as $statement) {
                    $pdo->exec($statement);
                }
                $this->info('Imported the supplied schema through PDO. Sample passwords were hashed before import.');
            } else {
                throw new \RuntimeException('Connector must be jdbc or pdo.');
            }
            DB::purge('mysql');
            foreach (['Users', 'Drivers', 'Vehicles', 'Routes', 'Stops', 'Trips', 'GPS_Locations', 'Passengers', 'Feedback'] as $table) {
                $this->line($table.': '.DB::table($table)->count());
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Do not print connection strings, credentials, SQL, or seed password hashes.
            $message = $e instanceof \PDOException || $e instanceof QueryException
                ? 'MySQL connection/import failed. Check that the server is running and .env database credentials are correct.' : $e->getMessage();
            $this->error($message);

            return self::FAILURE;
        }
    }
}
