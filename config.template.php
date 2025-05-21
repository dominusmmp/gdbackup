<?php
// Prevent direct access
defined('GDBPATH') || die('forbidden');

// Template configuration file for MySQL Backup for cPanel
// Copy this file to config.php and fill in the values below
// See readme.md for detailed setup instructions

// Security
$isProductionMode = false; // Set to true for production (logs to error.log), false for debugging (displays errors)
$cronjobKey = ''; // Unique, random string for cronjob/HTTP access (e.g., 'x7k9p2m4q8v5n3j6h')

// General
$root = __DIR__; // Path for temporary backup files (must be writable, e.g., chmod 755)
$backupFilesPrefix = 'DB.Backup'; // Prefix for backup files (e.g., 'DB.Backup.20250101.sql.gz')
$mode = ['gd-stream']; // Backup modes: 'local', 'gd-upload', 'gd-stream', 'tg-upload' (e.g., ['local', 'tg-upload'])
$timezone = 'UTC'; // Server timezone (e.g., 'UTC', 'America/New_York')
$retentionDays = 30; // Days to keep backups on Google Drive or local (0 for unlimited, not supported for Telegram)
$memoryLimit = '1024M'; // Memory limit for PHP (e.g., '512M', '1024M')

// Database
$dbHost = 'localhost:3306'; // Database host (e.g., 'localhost:3306' or 'db.example.com')
$dbUsername = ''; // MySQL username (e.g., 'myuser')
$dbPassword = ''; // MySQL password (e.g., 'mypassword')
$dbNames = ['']; // Database names to back up (e.g., ['mydatabase'])

// Google Drive API for 'gd-upload' and 'gd-stream' modes
$driveRootFolderId = ''; // Google Drive folder ID (optional, leave empty for root; e.g., '1aBcDeFgHiJkLmNoPqRsTuVwXyZ')
$clientId = ''; // OAuth Client ID (e.g., '1234567890-abcdefg.apps.googleusercontent.com')
$clientSecret = ''; // OAuth Client Secret (e.g., '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')
$redirectUri = ''; // URL of auth.php (e.g., 'http://yourdomain.com/auth.php')
$authCode = ''; // Authorization code from auth.php (e.g., '4/0A...')
$encryptionPassword = ''; // Strong password for refresh token encryption (generate at https://www.uuidgenerator.net; e.g., 'StrongPassword123!')
$encryptionKey = ''; // Key for encryption (generate at https://www.uuidgenerator.net; e.g., '550e8400-e29b-41d4-a716-446655440000')

// Telegram API for 'tg-upload' mode
$telegramBotToken = ''; // Telegram Bot API token (e.g., '123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11')
$telegramChatId = ''; // Telegram chat ID for backups (e.g., '1234567890' for private users | '-100xxxxxxxxxx' for channel and supergroups)
$telegramFileSizeLimit = 50000000; // Max file size for Telegram uploads in bytes (default: 50000000 for 50MB, up to 2000000000 for 2GB with premium bot)