
# MySQL Database Auto Backup To Google Drive With PHP PDO

PHP script to backup your MySQL databeses to Google Drive for free and in easy steps.
To make the script run Automatically you need to set cronjobs.



## Features

- Fully free and runable on any server or hosting that supports PHP +7
- Save backups to Google Drive
- Option to limit backup days (E.g. last x days)
- Option to stream encoded database data directly to Google Drive
- Auto database compression to save space



## Requirements

- PHP +7
- PHP extensions: PDO | PDO_MYSQL | Zip | Zlib
- Cronjob to run the script automatically



## Deployment

### 1. Configuration
To deploy this project first you will need to have your database authentication parameters
and also get the needed authentication parameters from your Google Cloud console
and then add them to the `config.inc.php` file.

#### General Config

`$homedir`
- This defines the absolute path to the project directory.
  It's better to not touch the value of this one
  but if the project is not working because of it
  you can manually insert your project directory path.

`$dbprefix`
- It's just to know what you are uploading in your Google Drive.
  name it anything you want

`$local_save_mode`
- If you set this parameter to `true`
  all databases in the list will first save as separated files locally,
  then they will zip together as a compressed zip file
  and then the zip file will upload to your Google Drive folder.
- If you set it to `false` then each database data will be
  encoded, compressed and streamed directly to your Google Drive
  and saved in a separate folder.
  (I personally prefer this one since there's no need to save any file locally
  and also this method has a better compression rate and will save more space)

`$delete_time`
- Days to keep database backups. All backups older than `x` days that you will set
  for this parameter will be automatically deleted from your Google Drive folder

#### Database Config

`$dbhost`
- Database host name E.g. `localhost` or `127.0.0.1` or with port `localhost:3306`

`$dbuser`
- Your database username

`$dbpass`
- Your database password

`$dbnames`
- List of databases you want to backup E.g `["db_name"]` or `["db_1_name", "db_2_name"]`

#### Google Drive API Config

`$refresh_token_protection_password` \
`$refresh_token_protection_key`
- Encryption password and key to secure your refresh token
  because it'll be save in a json file

`$google_client_id` \
`$google_client_secret`
- Google API Client authentication parameters. You can get them from
  https://console.cloud.google.com/apis/credentials
  after creating a Google Drive project at your Google Cloud console

`$google_redirect_uri`
- Path to file `auth_code.php` which you need to get the next parameter value.
  You also have to set this in your Google Drive project at
  https://console.cloud.google.com/apis/credentials

`$google_auth_code`
- Really annoying to get this parameter value from Google
  but you can easily get it with the file `auth_code.php`.
  Just edit the file `auth_code.php` and insert your `$google_client_id`
  and then load it in your browser and follow the steps.
  Note that you need to get this just once or when you don't have the refresh token.
  If your project at the Google Cloud console is not in testing mode you need to get it weekly.

`$gdrive_root_id`
- Google Drive folder id which you want backup files to upload. It's easy to get it,
  just open the Google Drive folder you want
  and you can see your folder id in your browser's address bar.\
  E.g. https://drive.google.com/drive/folders/2DSutgFGfkfgDjhfQJaLDFdfsAsdrsGdu1F2e \
  In the link above, folder id is `2DSutgFGfkfgDjhfQJaLDFdfsAsdrsGdu1F2e`

### 2. Running The Script

#### Manual Run

To run the script manually, load the file `gdrive_autobackup.php` in your browser.
Note that the `$production_mode` value should be `false`
if you're running the script manually.

#### Cronjob

To run the script with Cronjob there are some steps should be done:

- Set `$production_mode` value in file `gdrive_autobackup.php` to `true`
- For the security reasons and to avoid running script from everywhere,
  you should set `$cronjob_key` value in file `gdrive_autobackup.php`.

  E.g `c6ONXu9VIq6ysdTBrKh06aRpdoRlA3jV` \
  Cronjob link will be: \
  `path-to-script-directly/gdrive_autobackup.php?cron=c6ONXu9VIq6ysdTBrKh06aRpdoRlA3jV` \
  If you're running the script on a cpanel or linux hosting,
  the command will be: \
  `/usr/local/bin/php path-to-script-directly/gdrive_autobackup.php cron=c6ONXu9VIq6ysdTBrKh06aRpdoRlA3jV` \
  or \
  `/usr/local/bin/php /home/username/public_html/gdrive_autobackup.php cron=c6ONXu9VIq6ysdTBrKh06aRpdoRlA3jV`

  You can set the Cronjob time as you want, daily, hourly, or even every minute.



## Feedback

If you have any feedback or question, please reach out to me at dominusmmp@gmail.com



## License

[MIT](https://choosealicense.com/licenses/mit/)