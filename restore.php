<?php

declare(strict_types=1);

// Configuration (edit these or leave empty to prompt interactively in CLI)
$dbHost = 'localhost:3306'; // e.g., 'localhost:3306'
$dbUsername = ''; // e.g., 'myuser'
$dbPassword = ''; // e.g., 'mypassword'
$dbName = ''; // e.g., 'mydatabase'
$backupFile = ''; // e.g., '/path/to/backup.sql.gz' or '/path/to/backup.sql'

class RestoreController
{
    private string $dbHost;
    private string $dbUsername;
    private string $dbPassword;
    private string $dbName;
    private string $backupFile;

    public function __construct(string $dbHost, string $dbUsername, string $dbPassword, string $dbName, string $backupFile)
    {
        $this->dbHost = $dbHost;
        $this->dbUsername = $dbUsername;
        $this->dbPassword = $dbPassword;
        $this->dbName = $dbName;
        $this->backupFile = $backupFile;
        $this->validateInputs();
    }

    private function validateInputs(): void
    {
        if (empty($this->dbHost) || empty($this->dbUsername) || empty($this->dbName) || empty($this->backupFile)) {
            throw new InvalidArgumentException('Missing required inputs: ' . implode(', ', array_filter([
                empty($this->dbHost) ? 'dbHost' : null,
                empty($this->dbUsername) ? 'dbUsername' : null,
                empty($this->dbName) ? 'dbName' : null,
                empty($this->backupFile) ? 'backupFile' : null,
            ])));
        }

        if (!file_exists($this->backupFile) || !is_readable($this->backupFile)) {
            throw new InvalidArgumentException("Backup file does not exist or is not readable: $this->backupFile");
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->dbName)) {
            throw new InvalidArgumentException('Invalid database name: only alphanumeric and underscores allowed.');
        }

        if (!str_ends_with(strtolower($this->backupFile), '.sql') && !str_ends_with(strtolower($this->backupFile), '.sql.gz')) {
            throw new InvalidArgumentException("Unsupported file format: $this->backupFile. Must be .sql or .sql.gz.");
        }
    }

    private function decompressGzip(string $gzFilePath): string
    {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('zlib extension is required for .sql.gz files.');
        }

        $sqlFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_' . uniqid() . '.sql';
        $data = file_get_contents($gzFilePath);
        if ($data === false) {
            throw new RuntimeException("Failed to read gzip file: $gzFilePath");
        }

        $decompressed = gzdecode($data);
        if ($decompressed === false) {
            throw new RuntimeException("Failed to decompress gzip file: $gzFilePath");
        }

        if (file_put_contents($sqlFilePath, $decompressed) === false) {
            throw new RuntimeException("Failed to write temporary SQL file: $sqlFilePath");
        }

        return $sqlFilePath;
    }

    private function restoreDatabase(string $sqlFilePath): void
    {
        try {
            $pdo = new PDO(
                "mysql:host=$this->dbHost;dbname=$this->dbName;charset=utf8mb4",
                $this->dbUsername,
                $this->dbPassword,
                [
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES UTF8MB4',
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 30,
                ]
            );

            $sql = file_get_contents($sqlFilePath);
            if ($sql === false) {
                throw new RuntimeException("Failed to read SQL file: $sqlFilePath");
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec($sql);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            echo "Successfully restored database '$this->dbName' from $sqlFilePath\n";
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to restore database '$this->dbName': {$e->getMessage()}");
        }
    }

    public function execute(): void
    {
        $sqlFilePath = $this->backupFile;
        $tempFile = null;

        try {
            if (str_ends_with(strtolower($this->backupFile), '.sql.gz')) {
                $sqlFilePath = $this->decompressGzip($this->backupFile);
                $tempFile = $sqlFilePath;
            }

            $this->restoreDatabase($sqlFilePath);
        } finally {
            if ($tempFile && file_exists($tempFile) && !unlink($tempFile)) {
                echo "Warning: Failed to delete temporary SQL file: $tempFile\n";
            }
        }
    }
}

try {
    if (empty($dbHost) || empty($dbUsername) || empty($dbName) || empty($backupFile)) {
        if (php_sapi_name() !== 'cli') {
            die('Error: Please set variables in the script or run via CLI for interactive prompts.');
        }

        echo "Enter database host (e.g., localhost:3306): ";
        $dbHost = trim(fgets(STDIN));
        echo "Enter database username: ";
        $dbUsername = trim(fgets(STDIN));
        echo "Enter database password (leave empty for none): ";
        $dbPassword = trim(fgets(STDIN));
        echo "Enter database name: ";
        $dbName = trim(fgets(STDIN));
        echo "Enter backup file path (e.g., /path/to/backup.sql.gz): ";
        $backupFile = trim(fgets(STDIN));
    }

    $controller = new RestoreController($dbHost, $dbUsername, $dbPassword, $dbName, $backupFile);
    $controller->execute();
} catch (Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}