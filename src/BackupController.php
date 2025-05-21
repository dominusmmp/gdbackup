<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

/**
 * Main backup controller class for managing MySQL database backups.
 */
class BackupController
{
    private const VALID_MODES = ['local', 'gd-upload', 'gd-stream', 'tg-upload'];

    private array $config;
    private bool $isProductionMode;
    private ?GoogleDriveAPI $driveAPI = null;
    private ?TelegramAPI $telegramAPI = null;
    private string $backupPath = GDBPATH . 'backups' . DIRECTORY_SEPARATOR;
    private array $backupResults = [];

    /**
     * Initializes the backup controller with configuration and setup.
     *
     * @param array $config Configuration array from config.php
     * @throws RuntimeException If initialization fails
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->isProductionMode = $config['isProductionMode'] ?? true;

        if (!in_array($config['timezone'], DateTimeZone::listIdentifiers())) {
            throw new InvalidArgumentException('[BackupController::validateConfig] Invalid timezone: ' . $this->config['timezone']);
        }

        date_default_timezone_set($config['timezone'] ?? 'UTC');

        $this->validateConfig();
        $this->initializeDependencies();
    }

    /**
     * Validates configuration values.
     *
     * @throws InvalidArgumentException If required config values are missing or invalid
     */
    private function validateConfig(): void
    {
        $required = [
            'root',
            'backupFilesPrefix',
            'mode',
            'retentionDays',
            'dbHost',
            'dbUsername',
            'dbPassword',
            'dbNames',
        ];

        $missing = array_filter($required, fn($key) => empty($this->config[$key]));

        if ($missing) {
            throw new InvalidArgumentException(sprintf('[BackupController::validateConfig] Missing required config values: %s', implode(', ', $missing)));
        }

        if (!is_array($this->config['mode']) || empty($this->config['mode'])) {
            throw new InvalidArgumentException('[BackupController::validateConfig] mode must be a non-empty array of valid modes: ' . implode(', ', self::VALID_MODES));
        }

        foreach ($this->config['mode'] as $mode) {
            if (!in_array($mode, self::VALID_MODES, true)) {
                throw new InvalidArgumentException(sprintf('[BackupController::validateConfig] Invalid mode: %s. Must be one of: %s', $mode, implode(', ', self::VALID_MODES)));
            }
        }

        if (!is_array($this->config['dbNames']) || !array_filter($this->config['dbNames'], 'is_string')) {
            throw new InvalidArgumentException('[BackupController::validateConfig] dbNames must be a non-empty array of strings');
        }

        if (array_intersect($this->config['mode'], ['gd-upload', 'gd-stream'])) {
            $gdRequired = ['clientId', 'clientSecret', 'redirectUri', 'authCode', 'encryptionPassword', 'encryptionKey'];
            $gdMissing = array_filter($gdRequired, fn($key) => empty($this->config[$key]));

            if ($gdMissing) {
                throw new InvalidArgumentException(sprintf('[BackupController::validateConfig] Missing Google Drive config values for gd-upload/gd-stream: %s', implode(', ', $gdMissing)));
            }
        }

        if (in_array('tg-upload', $this->config['mode'], true)) {
            $tgRequired = ['telegramBotToken', 'telegramChatId', 'telegramFileSizeLimit'];
            $tgMissing = array_filter($tgRequired, fn($key) => empty($this->config[$key]));

            if ($tgMissing) {
                throw new InvalidArgumentException(sprintf('[BackupController::validateConfig] Missing Telegram config values for tg-upload: %s', implode(', ', $tgMissing)));
            }

            if (!is_int($this->config['telegramFileSizeLimit']) || $this->config['telegramFileSizeLimit'] < 1 || $this->config['telegramFileSizeLimit'] > 2000000000) {
                throw new InvalidArgumentException('[BackupController::validateConfig] telegramFileSizeLimit must be an integer between 1 and 2000000000 bytes.');
            }
        }
    }

    /**
     * Initializes dependencies.
     *
     * @throws RuntimeException If dependency initialization fails
     */
    private function initializeDependencies(): void
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'Logger.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'MySQLBackupAPI.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoogleDriveAPI.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'TelegramAPI.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZipHelper.php';

        if (array_intersect($this->config['mode'], ['gd-upload', 'gd-stream'])) {
            $this->driveAPI = new GoogleDriveAPI(
                $this->config['clientId'],
                $this->config['clientSecret'],
                $this->config['redirectUri'],
                $this->config['authCode'],
                $this->config['encryptionPassword'],
                $this->config['encryptionKey']
            );
        }

        if (in_array('tg-upload', $this->config['mode'], true)) {
            $this->telegramAPI = new TelegramAPI(
                $this->config['telegramBotToken'],
                $this->config['telegramChatId'],
                $this->config['telegramFileSizeLimit']
            );
        }
    }

    /**
     * Validates the cronjob key for secure access.
     *
     * @throws RuntimeException If access is denied
     */
    public function validateAccess(): void
    {
        if (!$this->isProductionMode || empty($this->config['cronjobKey'])) {
            return;
        }

        $key = SAPI === 'cli' ? ($_SERVER['argv'][1] ?? null) : ($_GET['key'] ?? null);

        if (empty($key) || $key !== $this->config['cronjobKey']) {
            Logger::error('[BackupController::validateAccess] Access denied: Invalid cronjob key from IP: ' . $_SERVER['REMOTE_ADDR']);
            die('forbidden');
        }
    }

    /**
     * Executes the backup process for all specified modes.
     *
     * @return array Backup results
     * @throws RuntimeException If backup fails
     */
    public function execute(): array
    {
        $tuid = uniqid(date('Y-m-d\TH.i.s.T'));

        $currentDate = date('Y-m-d');
        $currentTime = date('H.i.s');

        $retentionTime = ($this->config['retentionDays'] >= 1)
            ? date('Y-m-d\TH:i:s', strtotime('-' . (int) $this->config['retentionDays'] . ' day'))
            : false;

        $this->backupPath = $this->config['root'] . DIRECTORY_SEPARATOR . $this->config['backupFilesPrefix'] . DIRECTORY_SEPARATOR;
        $uniqueBackupPath = $this->backupPath . $tuid;

        if (array_intersect($this->config['mode'], ['local', 'gd-upload', 'tg-upload']) && !file_exists($uniqueBackupPath)) {
            if (!mkdir($uniqueBackupPath, 0755, true)) {
                throw new RuntimeException("[BackupController::execute] Failed to create folder: $uniqueBackupPath");
            }
        }

        $baseFolderId = null;
        if (array_intersect($this->config['mode'], ['gd-upload', 'gd-stream'])) {
            $baseFolderId = $this->driveAPI->createFolder($this->config['backupFilesPrefix'], $this->config['driveRootFolderId']);
        }

        foreach ($this->config['dbNames'] as $dbName) {
            Logger::info("[BackupController::execute] Starting backup for database: $dbName");
            $dbAPI = new MySQLBackupAPI(
                $this->config['dbHost'],
                $this->config['dbUsername'],
                $this->config['dbPassword'],
                $dbName,
                $this->config['timezone'],
                [],
                [],
                1000
            );

            if (in_array('gd-stream', $this->config['mode'], true)) {
                $data = $dbAPI->backup('raw', true);

                $daySubFolderId = $this->driveAPI->createFolder($currentDate, $baseFolderId);

                $timeSubFolderId = $this->driveAPI->createFolder($currentTime, $daySubFolderId);

                $fileId = $this->driveAPI->uploadFile("{$dbName}.{$tuid}.sql.gz", null, $data, $timeSubFolderId);

                $this->backupResults[] = ['db' => $dbName, 'fileId' => $fileId, 'mode' => 'gd-stream'];

                Logger::info("[BackupController::execute] Uploaded backup for $dbName to Google Drive: $fileId");
            }

            if (array_intersect($this->config['mode'], ['local', 'gd-upload', 'tg-upload'])) {
                $backupFile = $dbAPI->backup('file', true, "{$dbName}.{$tuid}", $uniqueBackupPath);

                Logger::info("[BackupController::execute] Prepared backup file for $dbName: $backupFile");
            }
        }

        if (array_intersect($this->config['mode'], ['local', 'gd-upload', 'tg-upload'])) {
            $zipFileName = "{$this->config['backupFilesPrefix']}.{$tuid}";

            $zipFile = ZipHelper::zipFolder($uniqueBackupPath, $zipFileName, dirname($uniqueBackupPath), true);

            if (!chmod($zipFile, 0600)) {
                Logger::warning("[BackupController::execute] Failed to set permissions on zip file: $zipFile");
            }

            foreach ($this->config['mode'] as $mode) {
                if ($mode === 'local') {
                    $this->backupResults[] = ['db' => implode(', ', $this->config['dbNames']), 'filePath' => $zipFile, 'mode' => 'local'];
                    Logger::info("[BackupController::execute] Saved local backup zip: $zipFile");
                } elseif ($mode === 'gd-upload') {
                    $daySubFolderId = $this->driveAPI->createFolder($currentDate, $baseFolderId);

                    $fileId = $this->driveAPI->uploadFile("{$zipFileName}.zip", $zipFile, null, $daySubFolderId);

                    $this->backupResults[] = ['db' => implode(', ', $this->config['dbNames']), 'fileId' => $fileId, 'mode' => 'gd-upload'];

                    Logger::info("[BackupController::execute] Uploaded zipped backup to Google Drive: $fileId");
                } elseif ($mode === 'tg-upload') {
                    $messageId = $this->telegramAPI->uploadFile("{$zipFileName}.zip", $zipFile);

                    $this->backupResults[] = ['db' => implode(', ', $this->config['dbNames']), 'messageId' => $messageId, 'mode' => 'tg-upload'];

                    Logger::info("[BackupController::execute] Uploaded zipped backup to Telegram: $messageId");
                }
            }

            if (!in_array('local', $this->config['mode'], true) && !unlink($zipFile)) {
                Logger::warning("[BackupController::execute] Failed to delete local zip file: $zipFile");
            }
        }

        if ($retentionTime) {
            if (array_intersect($this->config['mode'], ['gd-upload', 'gd-stream'])) {
                $oldFiles = $this->driveAPI->search($baseFolderId, null, null, null, $retentionTime);

                if ($oldFiles) {
                    $this->driveAPI->delete($oldFiles);
                    Logger::info("[BackupController::execute] Deleted " . count($oldFiles) . " old backups from Google Drive");
                }
            }

            if (in_array('local', $this->config['mode'], true)) {
                $oldFiles = glob(dirname($uniqueBackupPath) . DIRECTORY_SEPARATOR . "*.zip");

                foreach ($oldFiles as $file) {
                    if (is_file($file) && filemtime($file) < strtotime($retentionTime)) {
                        if (!unlink($file)) {
                            Logger::warning("[BackupController::execute] Failed to delete old local backup file: $file");
                        } else {
                            Logger::info("[BackupController::execute] Deleted old local backup file: $file");
                        }
                    }
                }
            }
        }

        return $this->backupResults;
    }

    /**
     * Outputs backup results.
     */
    public function outputResults(): void
    {
        if (SAPI === 'cli') {
            foreach ($this->backupResults as $result) {
                if ($result['mode'] === 'local') {
                    Logger::info("[BackupController::outputResults] Backup for '{$result['db']}': {$result['filePath']} (local)");
                } elseif ($result['mode'] === 'gd-upload' || $result['mode'] === 'gd-stream') {
                    Logger::info("[BackupController::outputResults] Backup for '{$result['db']}': https://drive.google.com/file/d/{$result['fileId']} ({$result['mode']})");
                } elseif ($result['mode'] === 'tg-upload') {
                    Logger::info("[BackupController::outputResults] Backup for '{$result['db']}': Telegram message ID {$result['messageId']} (tg-upload)");
                }
            }
            return;
        }

        if ($this->isProductionMode) {
            die('forbidden');
        }

        echo "<pre>\nBackup Results:\n";
        foreach ($this->backupResults as $result) {
            if ($result['mode'] === 'local') {
                echo "File path for '{$result['db']}': {$result['filePath']} (local)\n";
            } elseif ($result['mode'] === 'gd-upload' || $result['mode'] === 'gd-stream') {
                echo "File URL for '{$result['db']}': https://drive.google.com/file/d/{$result['fileId']} ({$result['mode']})\n";
            } elseif ($result['mode'] === 'tg-upload') {
                echo "Telegram message ID for '{$result['db']}': {$result['messageId']} (tg-upload)\n";
            }
        }
        echo "</pre>\n";
    }

    public function __destruct()
    {
        if (!in_array('local', $this->config['mode'], true) && is_dir($this->backupPath)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->backupPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileInfo) {
                $path = $fileInfo->getRealPath();
                $todo = $fileInfo->isDir() ? 'rmdir' : 'unlink';
                if (!$todo($path)) {
                    throw new RuntimeException("[BackupController::__destruct] Failed to clean up file: $path" . PHP_EOL);
                }
            }

            if (!rmdir($this->backupPath)) {
                throw new RuntimeException("[BackupController::__destruct] Failed to clean up backup folder: $this->backupPath" . PHP_EOL);
            }

            Logger::info("[BackupController::__destruct] Successfully cleaned up backup folder: $this->backupPath" . PHP_EOL);
        }
    }
}