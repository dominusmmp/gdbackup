<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Logger.php';

/**
 * Handles MySQL database backups to file or raw data, with optional compression.
 */
class MySQLBackupAPI
{
    private const COMMENT_SEPARATOR_START = "--\n--";
    private const COMMENT_SEPARATOR_END = "\n--";

    private ?PDO $db = null;
    private array $connectionData;
    private array $tables = [];
    private string $backupData = '';
    private string $timezone;
    private array $excludedTables;
    private int $chunkSize;

    /**
     * Initializes the MySQL backup API.
     *
     * @param string $host Database host (e.g., 'localhost:3306').
     * @param string $username Database username.
     * @param string $password Database password (can be empty).
     * @param string $dbName Database name.
     * @param string $timezone Server timezone (e.g., 'UTC').
     * @param array $options PDO options (default includes UTF8MB4 and exception mode).
     * @param array $excludedTables Tables to exclude from backup.
     * @param int $chunkSize Rows to fetch per chunk (0 for no chunking).
     * @throws InvalidArgumentException If required arguments are invalid.
     */
    public function __construct(
        string $host,
        string $username,
        string $password,
        string $dbName,
        string $timezone = 'UTC',
        array $options = [],
        array $excludedTables = [],
        int $chunkSize = 0
    ) {
        $missingArgs = array_filter([
            empty($host) ? 'host' : null,
            empty($username) ? 'username' : null,
            empty($dbName) ? 'dbName' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(sprintf('[MySQLBackupAPI] Missing required arguments: %s', implode(', ', $missingArgs)));
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
            throw new InvalidArgumentException('[MySQLBackupAPI] Invalid database name: only alphanumeric and underscores allowed.');
        }

        if ($chunkSize < 0) {
            throw new InvalidArgumentException('[MySQLBackupAPI] Chunk size must be non-negative.');
        }

        $this->connectionData = [
            'dsn' => "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
            'username' => $username,
            'password' => $password,
            'options' => [
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES UTF8MB4',
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
                ...$options,
            ],
        ];

        $this->timezone = $timezone;
        $this->excludedTables = array_filter($excludedTables, 'is_string');
        $this->chunkSize = $chunkSize;
    }

    /**
     * Establishes a PDO connection to the database.
     *
     * @throws PDOException If connection fails.
     */
    private function connect(): void
    {
        if ($this->db instanceof PDO) {
            return;
        }

        try {
            $this->db = new PDO(
                $this->connectionData['dsn'],
                $this->connectionData['username'],
                $this->connectionData['password'],
                $this->connectionData['options']
            );
        } catch (PDOException $e) {
            throw new PDOException("[MySQLBackupAPI::connect] Failed to connect to database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Closes the PDO connection.
     */
    private function disconnect(): void
    {
        $this->db = null;
    }

    /**
     * Fetches the CREATE TABLE statement for a table.
     *
     * @param string $tableName Table name.
     * @return string CREATE TABLE statement.
     * @throws PDOException If query fails.
     */
    private function fetchTableColumns(string $tableName): string
    {
        try {
            $stmt = $this->db->query("SHOW CREATE TABLE `$tableName`");
            $tableColumns = $stmt->fetchColumn(1);

            // Clean up table definition
            $tableColumns = preg_replace([
                '/AUTO_INCREMENT=\d+/',
                '/\sENGINE=[^\s]+/',
                '/\sDEFAULT\sCHARSET=[^\s]+/',
                '/\sCOLLATE=[^\s]+/',
                // Replace zero-date defaults for DATETIME, DATE, TIMESTAMP
                '/(DATETIME|DATE|TIMESTAMP)\s+NOT\s+NULL\s+DEFAULT\s+\'0000-00-00( 00:00:00)?\'/i',
                '/(DATETIME|DATE|TIMESTAMP)\s+NULL\s+DEFAULT\s+\'0000-00-00( 00:00:00)?\'/i',
            ], [
                '',
                '',
                '',
                '',
                '$1 NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '$1 NULL DEFAULT NULL',
            ], $tableColumns);

            return $tableColumns;
        } catch (PDOException $e) {
            throw new PDOException("[MySQLBackupAPI::fetchTableColumns] Failed to fetch columns for table `$tableName`: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Fetches table data as a generator to reduce memory usage.
     *
     * @param string $tableName Table name.
     * @return Generator<string> Yields INSERT statements.
     * @throws PDOException If query fails.
     */
    private function fetchTableData(string $tableName): Generator
    {
        try {
            if ($this->chunkSize > 0) {
                $offset = 0;
                while (true) {
                    $stmt = $this->db->query("SELECT * FROM `$tableName` LIMIT $offset, {$this->chunkSize}");
                    $stmt->setFetchMode(PDO::FETCH_NUM);
                    $rows = $stmt->fetchAll();

                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $row = array_map(fn($value) => $this->db->quote((string) ($value ?? '')), $row);
                        yield sprintf("INSERT INTO `%s` VALUES (%s);", $tableName, implode(', ', $row));
                    }

                    $offset += $this->chunkSize;
                }
            } else {
                $stmt = $this->db->query("SELECT * FROM `$tableName`");
                $stmt->setFetchMode(PDO::FETCH_NUM);

                while ($row = $stmt->fetch()) {
                    $row = array_map(fn($value) => $this->db->quote((string) ($value ?? '')), $row);
                    yield sprintf("INSERT INTO `%s` VALUES (%s);", $tableName, implode(', ', $row));
                }
            }
        } catch (PDOException $e) {
            throw new PDOException("[MySQLBackupAPI::fetchTableData] Failed to fetch data for table `$tableName`: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Fetches all database tables and their metadata.
     *
     * @throws PDOException If query fails.
     */
    private function fetchDatabaseTables(): void
    {
        try {
            $stmt = $this->db->query('SHOW TABLES');
            $tables = array_column(iterator_to_array($stmt), 0);

            $this->tables = [];
            foreach ($tables as $tableName) {
                if (in_array($tableName, $this->excludedTables, true)) {
                    continue;
                }

                $this->tables[] = [
                    'name' => $tableName,
                    'columns' => $this->fetchTableColumns($tableName),
                    'data' => $this->fetchTableData($tableName),
                ];
            }
        } catch (PDOException $e) {
            throw new PDOException("[MySQLBackupAPI::fetchDatabaseTables] Failed to fetch database tables: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generates the backup SQL string.
     */
    private function generateBackupString(): void
    {
        date_default_timezone_set($this->timezone);

        $dataArray = [
            sprintf(
                '%s BACKUP DATE (%s): %s%s BACKUP TIME (%s): %s%s',
                self::COMMENT_SEPARATOR_START,
                $this->timezone,
                date('Y-m-d'),
                self::COMMENT_SEPARATOR_END,
                $this->timezone,
                date('H:i:s'),
                self::COMMENT_SEPARATOR_END
            ),
            sprintf(
                '%s DATABASE: `%s`%s',
                self::COMMENT_SEPARATOR_START,
                $this->db->query('SELECT DATABASE()')->fetchColumn(),
                self::COMMENT_SEPARATOR_END
            ),
        ];

        foreach ($this->tables as $table) {
            $dataArray[] = sprintf(
                '%s --------------------------------------------------------%s',
                self::COMMENT_SEPARATOR_START,
                self::COMMENT_SEPARATOR_END
            );

            $dataArray[] = sprintf(
                '%s TABLE STRUCTURE FOR TABLE `%s`%s',
                self::COMMENT_SEPARATOR_START,
                $table['name'],
                self::COMMENT_SEPARATOR_END
            );

            $dataArray[] = $table['columns'] . ';';

            $hasData = false;
            $inserts = [];
            foreach ($table['data'] as $insert) {
                $hasData = true;
                $inserts[] = $insert;
            }

            if ($hasData) {
                $dataArray[] = sprintf(
                    '%s INSERTING DATA INTO TABLE `%s`%s',
                    self::COMMENT_SEPARATOR_START,
                    $table['name'],
                    self::COMMENT_SEPARATOR_END
                );

                $dataArray[] = implode(PHP_EOL, $inserts);
            }
        }

        $dataArray[] = sprintf('%s THE END%s', self::COMMENT_SEPARATOR_START, self::COMMENT_SEPARATOR_END);
        $this->backupData = implode("\n\n", $dataArray);
    }

    /**
     * Saves the backup to a file.
     *
     * @param string $fileName Base name for the backup file (without extension).
     * @param string $path Directory path for the backup file.
     * @param bool $compress Whether to compress the output with gzip.
     * @return string Path to the saved backup file.
     * @throws InvalidArgumentException If arguments are invalid.
     * @throws RuntimeException If file operations fail.
     */
    private function saveBackup(string $fileName, string $path, bool $compress = true): string
    {
        if (empty($fileName) || empty($path)) {
            throw new InvalidArgumentException(sprintf('[MySQLBackupAPI::saveBackup] Missing required arguments: %s', implode(', ', array_filter([
                empty($fileName) ? 'fileName' : null,
                empty($path) ? 'path' : null,
            ]))));
        }

        // Sanitize fileName
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
        if (empty($fileName)) {
            throw new InvalidArgumentException('[MySQLBackupAPI::saveBackup] Invalid fileName after sanitization.');
        }

        $realpath = realpath($path);
        if ($realpath === false) {
            throw new RuntimeException("[MySQLBackupAPI::saveBackup] Directory does not exist: $path");
        }

        if (!is_dir($realpath)) {
            throw new RuntimeException("[MySQLBackupAPI::saveBackup] Path is not a directory: $realpath");
        }

        if (!is_writable($realpath)) {
            throw new RuntimeException("[MySQLBackupAPI::saveBackup] Directory is not writable: $realpath");
        }

        $filePath = sprintf('%s%s%s.sql%s', $realpath, DIRECTORY_SEPARATOR, $fileName, $compress ? '.gz' : '');
        $fileData = $compress ? gzencode($this->backupData, 9) : $this->backupData;

        if (file_put_contents($filePath, $fileData) === false) {
            throw new RuntimeException("[MySQLBackupAPI::saveBackup] Failed to write backup file: $filePath");
        }

        if (!chmod($filePath, 0600)) {
            Logger::warning("[MySQLBackupAPI::saveBackup] Failed to set permissions on backup file: $filePath");
        }

        return $filePath;
    }

    /**
     * Backs up the MySQL database.
     *
     * @param string $type Backup type: 'file' (save to file) or 'raw' (return data).
     * @param bool $compress Whether to compress the output with gzip.
     * @param string|null $fileName Base name for the backup file (required for 'file' type).
     * @param string|null $path Directory path for the backup file (required for 'file' type).
     * @return string Path to the backup file (for 'file') or raw/compressed data (for 'raw').
     * @throws InvalidArgumentException If arguments are invalid.
     * @throws PDOException If database operations fail.
     * @throws RuntimeException If file operations fail.
     */
    public function backup(string $type, bool $compress = true, ?string $fileName = null, ?string $path = null): string
    {
        if (!in_array($type, ['file', 'raw'], true)) {
            throw new InvalidArgumentException("[MySQLBackupAPI::backup] Invalid backup type: $type. Must be 'file' or 'raw'.");
        }

        $missingArgs = array_filter([
            $type === 'file' && empty($fileName) ? 'fileName' : null,
            $type === 'file' && empty($path) ? 'path' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(sprintf('[MySQLBackupAPI::backup] Missing required arguments: %s', implode(', ', $missingArgs)));
        }

        if ($compress && !function_exists('gzencode')) {
            throw new RuntimeException('[MySQLBackupAPI::backup] zlib extension is required for compression.');
        }

        try {
            $this->connect();
            $this->fetchDatabaseTables();
            $this->generateBackupString();
        } finally {
            $this->disconnect();
        }

        if ($type === 'file') {
            return $this->saveBackup($fileName, $path, $compress);
        }

        return $compress ? gzencode($this->backupData, 9) : $this->backupData;
    }
}