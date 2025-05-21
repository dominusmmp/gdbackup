# MySQL Backup for cPanel

A free, open-source tool to back up MySQL databases from cPanel (or a LAMP server) to local storage, Google Drive, or Telegram. Supports four modes: local storage, zip upload to Google Drive, direct streaming to Google Drive, or zip upload to Telegram. Multiple modes can be used simultaneously for flexible backups. Designed to be easy to use, maintain, and debug, with efficient multi-mode execution and detailed logging.

## Features
- **Backup Modes** (can be combined in `config.php`):
  - `local`: Save backups to the server.
  - `gd-upload`: Save backups locally, zip them, and upload the zip file to Google Drive.
  - `gd-stream`: Stream backups directly to Google Drive without local storage.
  - `tg-upload`: Save backups locally, zip them, and upload the zip file to a Telegram chat.
- Secure cronjob access with a key.
- Automatic deletion of old backups based on retention period (manual cleanup required for Telegram).
- Encrypted Google Drive refresh token stored in `.refresh-token.php` for security.
- Detailed error logging to daily log files for easy debugging.
- **Efficient Multi-Mode Execution**: Database backups are generated once and reused across modes (e.g., local files are reused for `gd-upload` and `tg-upload` to minimize disk I/O).
- **Memory Optimization**: Uses generators and optional chunking for large tables to reduce memory usage.
- **Temporary Folder Cleanup**: Temporary backup folders are deleted after zipping unless `local` mode is used.

## Prerequisites
- **Server Requirements**:
  - PHP 7.4 or higher with `curl`, `pdo_mysql`, `openssl`, `zlib`, and `zip` extensions (checked at runtime).
  - MySQL/MariaDB database(s) accessible via cPanel.
  - Write permissions for the script directory (e.g., `chmod 755 /path/to/gdbackup`).
- **Google Drive API** (for `gd-upload` or `gd-stream` modes):
  - A Google Cloud project with the Drive API enabled.
  - OAuth 2.0 Client ID and Secret from [Google Cloud Console](https://console.cloud.google.com/apis/credentials).
- **Telegram Bot API** (for `tg-upload` mode):
  - A Telegram bot created via [BotFather](https://t.me/BotFather).
  - Bot API token and a chat ID (e.g., a channel or group).
- **cPanel Access**:
  - MySQL database credentials (username, password, database names).
  - Cronjob setup for automated backups.

## Installation
1. **Clone or Download**:
   - Clone the repository: `git clone https://github.com/dominusmmp/gdbackup.git`
   - Or download and extract the ZIP file to your cPanel File Manager (e.g., `/home/username/gdbackup`).

2. **Set Permissions**:
   - Ensure the script directory is writable: `chmod 755 /path/to/gdbackup`.
   - Protect sensitive files (`config.php`, `.refresh-token.php`, `error.log`) by placing them outside the web root or using `.htaccess`:
     ```apache
     <FilesMatch "^(config\.php|\.refresh-token\.php|error.*\.log)$">
         Deny from all
     </FilesMatch>
     ```

3. **Install Dependencies**:
   - No external libraries are required; all dependencies are included in the `src` directory.

## Configuration
The project includes a template configuration file, `config.template.php`, which you should copy and rename to `config.php` before customizing. **Do not edit `config.template.php` directly**, as it serves as a reference for the required settings. For Google Drive API authentication (for `gd-upload` or `gd-stream` modes), use `auth.html` to obtain the authorization code.

1. **Create `config.php`**:
   - Copy `config.template.php` to `config.php`:
     ```bash
     cp config.template.php config.php
     ```
   - Open `config.php` in a text editor and fill in the required values as described below.
   - Ensure `config.php` is protected (e.g., `chmod 600 config.php`) and placed outside the web root or secured via `.htaccess`.
   - **Validate Configuration**: Before running the script, verify all fields in `config.php` are correctly filled to avoid runtime errors.

### Security Configuration
- **`$isProductionMode`** (`config.php`):
  - Set to `true` for production (logs errors to `error.log.Y-m-d.log`).
  - Set to `false` for debugging (displays errors in the cli/browser).
- **`$cronjobKey`** (`config.php`):
  - Generate a unique, random string (e.g., via a password manager).
  - Example: `'x7k9p2m4q8v5n3j6h'`.

### General Configuration
- **`$root`** (`config.php`):
  - Path to store temporary backup files (default: `__DIR__`).
  - Ensure it’s writable (e.g., `chmod 755 /path/to/gdbackup`).
- **`$backupFilesPrefix`** (`config.php`):
  - Prefix for backup files (e.g., `prefix.20250101.sql.gz`).
- **`$mode`** (`config.php`):
  - Array of modes: `['local']`, `['gd-upload', 'tg-upload']`, etc.
  - Valid modes: `'local'`, `'gd-upload'`, `'gd-stream'`, `'tg-upload'`.
  - Example: `['local', 'gd-upload', 'tg-upload']` backs up to local disk, Google Drive, and Telegram.
- **`$timezone`** (`config.php`):
  - Set to your server’s timezone (e.g., `'UTC'`, `'America/New_York'`).
  - See [PHP Timezones](https://www.php.net/manual/en/timezones.php).
- **`$retentionDays`** (`config.php`):
  - Days to keep backups on local or Google Drive (e.g., `30`).
  - Set to `0` for unlimited retention.
  - For `tg-upload`, manual cleanup is required due to Telegram API limitations.
- **`$memoryLimit`** (`config.php`):
  - PHP memory limit (e.g., `'512M'`, `'1024M'`).
  - Choose based on database size (e.g., `512M` for small databases, `2048M` for large ones).
  - Must be in a valid format (e.g., `512M`, `1G`); invalid formats may cause errors.

### Database Configuration
- **`$dbHost`** (`config.php`):
  - Database host (e.g., `'localhost:3306'` or `'db.example.com'`).
  - Find in cPanel’s MySQL Databases.
- **`$dbUsername`** (`config.php`):
  - MySQL username (e.g., `'myuser'`).
- **`$dbPassword`** (`config.php`):
  - MySQL password (e.g., `'mypassword'`).
- **`$dbNames`** (`config.php`):
  - Array of database names (e.g., `['mydatabase']`).
  - Note: Table definitions are cleaned (e.g., `AUTO_INCREMENT`, `ENGINE`, `CHARSET`, `COLLATE` are removed) for simpler backups. Verify compatibility during restoration.

### Google Drive API Configuration (for `gd-upload` or `gd-stream` modes)
1. **Create OAuth Credentials**:
   - Go to [Google Cloud Console](https://console.cloud.google.com/apis/credentials).
   - Enable the Google Drive API.
   - Create an OAuth 2.0 Client ID (select “Web application”).
   - Add the redirect URI (e.g., `http://yourdomain.com/auth.html` or `https://dominusmmp.github.io/gdbackup/auth.html`) under “Authorized redirect URIs”.
   - Copy the Client ID and Client Secret.

2. **Obtain `$authCode`**:
   - Use `auth.html` to generate the authorization code:
     - **Option 1: Local Server**: Use `auth.html` on your local web server (e.g., `http://localhost/auth.html`).
     - **Option 2: Host Online**: Upload `auth.html` to your web server (e.g., `http://yourdomain.com/auth.html`).
     - **Option 3: Project GitHub Pages**: Use the project’s GitHub Pages version: `https://dominusmmp.github.io/gdbackup/auth.html`.
     - Enter your Client ID, verify the redirect URI, and click “Generate Auth URL”.
     - Authenticate with Google, and you’ll be redirected to `auth.html` with your `authCode` displayed.
     - Copy the `authCode` and paste it into `$authCode` in `config.php`.
       ```php
       $authCode = '4/0A...';
       ```

3. **Configure `config.php`**:
   - **`$driveRootFolderId`**:
     - ID of the Google Drive folder for backups (optional; leave empty for root).
     - Find in the URL: `https://drive.google.com/drive/folders/YourFolderID`.
     - Example: `'1aBcDeFgHiJkLmNoPqRsTuVwXyZ'`.
   - **`$clientId`**:
     - OAuth Client ID (e.g., `'1234567890-abcdefg.apps.googleusercontent.com'`).
   - **`$clientSecret`**:
     - OAuth Client Secret (e.g., `'GOCSPX-abcdefg1234567890'`).
   - **`$redirectUri`**:
     - URL of `auth.html` (e.g., `http://yourdomain.com/auth.html` or `https://dominusmmp.github.io/gdbackup/auth.html`).
   - **`$authCode`**:
     - Authorization code from step 2 (e.g., `'4/0A...'`).
   - **`$encryptionPassword`**:
     - Strong password for refresh token encryption (e.g., `'StrongPassword123!'`).
   - **`$encryptionKey`**:
     - Key/UUID for encryption (generate at [uuidgenerator.net](https://www.uuidgenerator.net)).
     - Example: `'550e8400-e29b-41d4-a716-446655440000'`.
   - **Note**: The refresh token is stored in `.refresh-token.php` with the format `<?php defined('GDBPATH') || die('forbidden'); // [timestamp] $encryptedToken`. Protect this file (`chmod 600`) and back it up. If lost or corrupted, regenerate `$authCode` via `auth.html`.

### Telegram API Configuration (for `tg-upload` mode)
1. **Create a Telegram Bot**:
   - Open Telegram and start a chat with [BotFather](https://t.me/BotFather).
   - Send `/newbot`, follow the prompts to name your bot, and receive a Bot API token (e.g., `123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`).
   - Copy the token for use in `config.php`.

2. **Set Up a Chat**:
   - Choose a chat where the bot will send backups. The bot must have permission to send messages and upload files in the chosen chat. Supported chat types:
     - **Private Chat**: Use your personal chat with the bot. Start a conversation with the bot to initialize it.
     - **Group/Supergroup**: Add the bot to a group or supergroup and grant it administrator privileges (to send files).
     - **Channel**: Add the bot to a public or private channel as an administrator.
   - **Obtain the Chat ID**:
     - **Option 1: Use a Bot**: Send a message in the target chat (private, group, or channel), then use a bot like [GetIDs Bot](https://t.me/getidsbot) to retrieve the chat ID.
       - For private chats: You’ll get a positive integer (e.g., `123456789`).
       - For public channels/groups/supergroups: You’ll get a negative integer (e.g., `-123456789` or `-100123456789`).
       - For channels: You’ll get the channel username (e.g., `@MyBackupChannel`) or a negative integer (e.g., `-100123456789`).
     - **Option 2: Channel Username**: For public channels, use the channel’s username (e.g., `@MyBackupChannel`).
     - **Option 3: Test with Bot**: Send a message to the bot or chat, then use the Telegram API to retrieve updates:
       - Make an API call: `https://api.telegram.org/bot<YourBotToken>/getUpdates`.
       - Look for the `chat` object in the response to find the `id` or `username`.

3. **Configure `config.php`**:
   - **`$telegramBotToken`**:
     - Paste the Bot API token from BotFather (e.g., `'123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11'`).
   - **`$telegramChatId`**:
     - Paste the chat ID or username based on the chat type:
       - Private chat: Positive integer (e.g., `'123456789'`).
       - Private channel/group/supergroup: Negative integer (e.g., `'-123456789'` or `'-100123456789'`).
       - Public channel: Username (e.g., `'@MyBackupChannel'`) or negative integer (e.g., `'-100123456789'`).
   - **`$telegramFileSizeLimit`**:
     - Maximum file size for uploads in bytes (default: `50000000` for 50MB).
     - For premium Telegram bots, set up to `2000000000` (2GB).
     - Example: `2000000000`.

   Example configuration in `config.php`:
   ```php
   $telegramBotToken = '123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
   $telegramChatId = '@MyBackupChannel'; // Or '123456789' for private chat, '-100123456789' for group/channel
   $telegramFileSizeLimit = 50000000;
   ```

## Usage
### Manual Backup
- Run via HTTP: `http://yourdomain.com/run.php?key=your-cronjob-key`
- Run via CLI: `php /path/to/run.php your-cronjob-key`

### Automated Backup (Cronjob)
1. In cPanel, go to “Cron Jobs”.
2. Add a new cronjob:
   ```
   /usr/local/bin/php /path/to/run.php your-cronjob-key
   ```
3. Set the schedule (e.g., daily at midnight).

### Backup Output
- **Local Mode**: Backups are saved to `$root/prefix/` as zipped files (e.g., `prefix.20250101.zip`).
- **gd-upload/gd-stream Modes**: Backups are uploaded to Google Drive under the specified folder, with subfolders by date and time.
- **tg-upload Mode**: Backups are uploaded as zip files to the specified Telegram chat.
- Multiple modes produce outputs for each destination (e.g., local file path, Google Drive URL, Telegram message ID).
- Temporary folders are deleted after zipping unless `local` mode is used.
- Check `error.log.Y-m-d.log` in the `.logs` directory for issues.

## Database Restoration
The `restore.php` script restores a MySQL database from a `.sql` or `.sql.gz` backup file created by `run.php`. It supports both hardcoded configuration and interactive CLI prompts.

### Manual Restoration
1. **Edit `restore.php`** (optional):
   - Open `restore.php` and set the following variables at the top:
     ```php
     $dbHost = 'localhost:3306'; // Database host
     $dbUsername = 'myuser'; // Database username
     $dbPassword = 'mypassword'; // Database password
     $dbName = 'mydatabase'; // Database to restore
     $backupFile = '/path/to/backup.sql.gz'; // Path to .sql or .sql.gz file
     ```
   - If left empty, the script will prompt for these values when run via CLI.

2. **Run the Script**:
   - **Via CLI** (recommended):
     ```bash
     php /path/to/restore.php
     ```
     - If variables are not set, follow the interactive prompts to enter `dbHost`, `dbUsername`, `dbPassword`, `dbName`, and `backupFile`.
   - **Via HTTP**:
     - Access `http://yourdomain.com/restore.php` (requires variables to be set in the script).
     - Note: HTTP mode is less secure; use CLI for production environments.

3. **Output**:
   - On success: Displays “Successfully restored database 'dbname' from backupFile”.
   - On failure: Displays an error message (e.g., invalid file, database connection failure).
   - Temporary decompressed files (for `.sql.gz`) are automatically deleted.

### Restoration Notes
- **File Format**: The backup file must be a `.sql` or `.sql.gz` file generated by this project.
- **Database**: The target database must exist before restoration.
- **Permissions**: Ensure the script has read access to the backup file (`chmod 644` or higher).
- **Foreign Keys**: The script disables foreign key checks during restoration to avoid constraint errors.
- **Debugging**: Errors are displayed in the output.

## cPanel Tips
- **Cronjob PATH**: If the cronjob fails, specify the PHP binary path explicitly (e.g., `/usr/local/bin/php`).
- **Path Restrictions**: On shared hosting, ensure `$root` is within your home directory (e.g., `/home/username/gdbackup`).
- **File Permissions**: Use `chmod 755` for the script directory and `chmod 600` for `config.php`, `.refresh-token.php`, and `./logs/*`.
- **Large Databases**: Increase `$memoryLimit` (e.g., `2048M`) for large databases to avoid memory errors.

## Troubleshooting
- **Error: “Access denied!”**
  - Verify `$cronjobKey` matches in `config.php` and your cronjob/URL.
- **Error: “Missing required config values”**
  - Ensure all fields in `config.php` are filled, especially for selected modes (e.g., Google Drive or Telegram configs).
- **Error: “Invalid mode”**
  - Check that `$mode` in `config.php` contains only valid modes (`local`, `gd-upload`, `gd-stream`, `tg-upload`).
- **Error: “The `curl`, `pdo_mysql`, `openssl`, `zlib`, or `zip` extension is not loaded”**
  - Enable the missing PHP extension in cPanel’s PHP configuration or your server’s PHP configuration.
  - Contact your hosting provider to enable the missing PHP extension in cPanel’s PHP configuration.
- **Google Drive Authentication Fails**
  - Verify `$clientId`, `$clientSecret`, and `$redirectUri` in `config.php`.
  - Ensure the redirect URI in Google Cloud Console matches `$redirectUri` in `config.php`.
  - If `.refresh-token.php` is corrupted or deleted, regenerate `$authCode` via `auth.html`.
  - Check the browser console (F12) for errors when using `auth.html`.
- **Google Drive API Quota Errors**
  - The script includes rate limiting to avoid quotas, but you may hit Google Drive API limits with frequent backups. Check [Google Cloud Console](https://console.cloud.google.com/apis) for quota details or increase limits.
- **Telegram Upload Fails**
  - Verify `$telegramBotToken` and `$telegramChatId` in `config.php`.
  - Ensure the bot is an administrator in the chat.
  - Check file size against `$telegramFileSizeLimit` (default 50MB, 2GB for premium bots).
- **Permission Issues**
  - Ensure the script directory is writable (`chmod 755`).
  - Secure `config.php`, `.refresh-token.php`, and `./logs/*` (`chmod 600`).
- **Memory Errors**
  - Increase `$memoryLimit` in `config.php` (e.g., `2048M`) for large databases.
- **Debugging**
  - Set `$isProductionMode = false` to display errors.
  - Check `.logs/error.log.Y-m-d.log` for detailed logs, including timestamps and error levels.
  - For `auth.html` issues, open the browser console (F12) to view JavaScript errors.

## Project Structure
```
🗂️ gdbackup/
├── 📁 .logs/
│   └── error.log.Y-m-d.log
├── 📁 src/
│   ├── BackupController.php
│   ├── EncryptionHelper.php
│   ├── GoogleDriveAPI.php
│   ├── Logger.php
│   ├── MySQLBackupAPI.php
│   ├── TelegramAPI.php
│   └── ZipHelper.php
├── .gitignore
├── .htaccess
├── .refresh-token.php
├── auth.html
├── config.php
├── config.template.php
├── readme.md
├── restore.php
└── run.php
```

## Acknowledgments
- Built for cPanel users to simplify MySQL backups.
- Enhanced with assistance from [Grok](https://grok.com).
- Inspired by the need for free, reliable backup solutions.

## License
Licensed under the MIT License. See [LICENSE](LICENSE) for details.