<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 *
 */

// defined("ABSPATH") or die("Hey, it\"s forbidden!");

/**
 * --------------------------------------------------------
 * ------------------- General Config ---------------------
 * --------------------------------------------------------
 */

/** Absolute Home Directory */
$homedir = getcwd() . "/";

/** Name Prefix For Database Files */
$dbprefix = "dbbackup";

/** Save Database As File Before Upload Or Upload Directly To Google Drive */
$local_save_mode = false;

/** Delete Backups Older Than "x Days" From Google Drive Root Folder */
$delete_time = 60;

/**
 * --------------------------------------------------------
 * ------------------- Database Config --------------------
 * --------------------------------------------------------
 */
 
/** MySQL Host */
$dbhost = "localhost:3306";

/** MySQL Username */
$dbuser = "test_user";

/** MySQL Password */
$dbpass = '0000';

/** MySQL Database Names */
$dbnames = ["test_db"];

/**
 * --------------------------------------------------------
 * -------------- Google Drive API Config -----------------
 * --------------------------------------------------------
 */

/** Password to Encrypt and Decrypt Google API Refresh Token in Json File */
$refresh_token_protection_password = "";

/** Key to Encrypt and Decrypt Google API Refresh Token in Json File */
$refresh_token_protection_key = "";

/**
 * Google API Client ID
 * @todo Should Be Obtain From:
 * @link https://console.cloud.google.com/apis/credentials
 */
$google_client_id = "";

/**
 * Google API Client Secret
 * @todo Should Be Obtain From:
 * @link https://console.cloud.google.com/apis/credentials
 */
$google_client_secret = "";

/**
 * Google API Authorized Redirect URI
 * @todo Should Be Obtain From:
 * @link https://console.cloud.google.com/apis/credentials
 */
$google_redirect_uri = "http://localhost/auth_code.php";

/**
 * Google API Authentication Code
 * Only Needed If You Don"t Have The Refresh Token
 * @todo After Filling Config File Values, You Can Get Authentication Code By Loading "auth_code.php" File In Your Browser.
 */
$google_auth_code = "";

/**
 * Google Drive Root Folder ID
 * @todo To Obtain, Simply Open The Google Drive Folder You Want In Any Browser And Get The Folder ID From The Url
 */
$gdrive_root_id = "";
