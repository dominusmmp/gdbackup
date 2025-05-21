<?php

declare(strict_types=1);

define('GDBPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('SAPI', php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD']) ? 'http' : 'cli');

if (!file_exists(GDBPATH . 'config.php')) {
    die('Error: config.php is missing. Please create it from config.template.php.');
}

if (!is_readable(GDBPATH . 'config.php')) {
    die('Error: config.php is not readable. Check file permissions (e.g., chmod 600 config.php).');
}

try {
    require_once GDBPATH . 'config.php';
} catch (ParseError $e) {
    die('Error: config.php contains invalid PHP syntax. Please check the file.');
}

require_once GDBPATH . 'src' . DIRECTORY_SEPARATOR . 'Logger.php';
require_once GDBPATH . 'src' . DIRECTORY_SEPARATOR . 'BackupController.php';

try {
    Logger::initialize($isProductionMode ?? true);
    ini_set('memory_limit', $memoryLimit ?? '1024M');

    if (!extension_loaded('curl') || !class_exists('CURLFile')) {
        throw new RuntimeException('The `curl` extension is not loaded. Please enable it in your PHP configuration.');
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The `pdo_mysql` extension is not loaded. Please enable it in your PHP configuration.');
    }

    if (!extension_loaded('openssl')) {
        throw new RuntimeException('The `openssl` extension is not loaded. Please enable it in your PHP configuration.');
    }

    if (!extension_loaded('zlib') || !function_exists('gzencode')) {
        throw new RuntimeException('The `zlib` extension is not loaded. Please enable it in your PHP configuration.');
    }

    if (!extension_loaded('zip') || !class_exists('ZipArchive')) {
        throw new RuntimeException('The `zip` extension is not loaded. Please enable it in your PHP configuration.');
    }

    $controller = new BackupController([
        'isProductionMode' => $isProductionMode ?? true,
        'cronjobKey' => $cronjobKey ?? '',
        'root' => $root ?? __DIR__,
        'backupFilesPrefix' => $backupFilesPrefix ?? 'dbbackup',
        'mode' => $mode ?? ['local'],
        'timezone' => $timezone ?? 'UTC',
        'retentionDays' => $retentionDays ?? 30,
        'dbHost' => $dbHost ?? 'localhost:3306',
        'dbUsername' => $dbUsername ?? '',
        'dbPassword' => $dbPassword ?? '',
        'dbNames' => $dbNames ?? [''],
        'driveRootFolderId' => $driveRootFolderId ?? '',
        'clientId' => $clientId ?? '',
        'clientSecret' => $clientSecret ?? '',
        'redirectUri' => $redirectUri ?? '',
        'authCode' => $authCode ?? '',
        'encryptionPassword' => $encryptionPassword ?? '',
        'encryptionKey' => $encryptionKey ?? '',
        'telegramBotToken' => $telegramBotToken ?? '',
        'telegramChatId' => $telegramChatId ?? '',
        'telegramFileSizeLimit' => $telegramFileSizeLimit ?? 50000000,
    ]);

    $controller->validateAccess();
    $results = $controller->execute();
    $controller->outputResults();
} catch (Throwable $e) {
    Logger::error(sprintf(
        "Fatal error: %s in %s:%d",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    exit(1);
}