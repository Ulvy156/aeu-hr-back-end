<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Process;

class BackupService
{
    public function generateDatabaseDump(): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $path = storage_path('app/backups/db-backup-'.now()->format('Y-m-d_His').'.sql');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $command = match ($connection) {
            'pgsql' => $this->buildPgDumpCommand($config, $path),
            'mysql' => $this->buildMysqlDumpCommand($config, $path),
            default => throw ApiException::badRequest("Unsupported database connection: {$connection}"),
        };

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            @unlink($path);
            throw ApiException::badRequest('Database backup failed: '.$result->errorOutput());
        }

        return $path;
    }

    protected function buildPgDumpCommand(array $config, string $path): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '5432';
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'] ?? '';

        $env = $password !== '' ? 'PGPASSWORD='.escapeshellarg($password).' ' : '';

        return sprintf(
            '%spg_dump -h %s -p %s -U %s -F p -f %s %s',
            $env,
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            escapeshellarg($path),
            escapeshellarg($database),
        );
    }

    protected function buildMysqlDumpCommand(array $config, string $path): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '3306';
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'] ?? '';

        $passwordArg = $password !== '' ? ' -p'.escapeshellarg($password) : '';

        return sprintf(
            'mysqldump -h %s -P %s -u %s%s %s > %s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            $passwordArg,
            escapeshellarg($database),
            escapeshellarg($path),
        );
    }
}
