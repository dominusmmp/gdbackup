<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 *
 */

/**
 * @todo Set Production Mode true
 * If You're Going To Put This Script On Your Host With Public Privileges And Use Cronjobs To Run It
 * Otherwise Keep It false.
 */
$production_mode = false;

/**
 * @todo Set This Cronjob Key To Secure Your Script Running From Anywhere
 * @example Final Link To Set As Cronjob Should Be As The Links Below:
 * Without Secure Key: path_to_this_file
 * With Secure Key: path_to_this_file?cron=your_cronjob_key
 * With Secure Key Cronjob: /usr/local/bin/php path_to_this_file cron=your_cronjob_key
 */
$cronjob_key = "";

/** Securing Script On Production Mode */
if ($production_mode) {
    // Turn Off Error Display
    ini_set("display_errors", 0);

    if (!empty($cronjob_key)) {
        // Check Cronjob Key To Secure The Script Running From Anywhere
        $file_url = $_SERVER["argv"];
        $url_params = !empty($file_url[1]) ? $file_url[1] : $file_url[0];
        $cron_key = null;
        if (!empty($url_params)) {
            parse_str($url_params, $params);
            $cron_key = (!empty($params["cron"])) ? $params["cron"] : false;
        }

        if (empty($cron_key) || ($cron_key != $cronjob_key)) {
            defined("ABSPATH") or die("Hey, it's forbidden!");
        }
    }
} else {
    ini_set("display_errors", 1);
}

/** PHP Config */
ini_set("memory_limit", "1024M");
date_default_timezone_set("UTC");

/** Require Packages */
require_once __DIR__ . "/packages/db_backup.php";
require_once __DIR__ . "/packages/zip_folder.php";
require_once __DIR__ . "/packages/gdrive_api_v3/gdrive_api_v3.php";

/** Include Config File */
include_once __DIR__ . "/config.inc.php";

/** Unique Identifiers */
$uid = date("Y-m-d_H-i-s");
$uidd = date("Y-m-d");
$uidt = date("H-i-s");
$uidr = date("Y-m-d\TH:i:s", strtotime("-" . intval(abs($delete_time)) . " day"));

/** Database Unique Local Folder Path */
$dbdir = $homedir . $dbprefix . "_" . $uid;

/** Initialize Google Drive API */
$gdrive = new GDriveAPIv3(array(
    "client_id" => $google_client_id,
    "client_secret" => $google_client_secret,
    "redirect_uri" => $google_redirect_uri,
    "auth_code" => $google_auth_code,
    "refresh_token_password" => $refresh_token_protection_password,
    "refresh_token_key" => $refresh_token_protection_key,
));

/** Initialize Upload Results Variable */
$upload_results = [];

/** Initialize Upload Main Folder ID */
$folder_id_main = $gdrive->create_folder(array(
    "root_id" => $gdrive_root_id,
    "folder_name" => $dbprefix,
));

/** Backup Databases And Save Them To Local Folder Or Stream Their Data To Google Drive */
foreach ($dbnames as $dbname) {
    // Initialize Class DatabaseBackup Object
    $db_backup = new DatabaseBackup(array(
        "host" => $dbhost,
        "user" => $dbuser,
        "pass" => $dbpass,
        "dbname" => $dbname,
    ));

    if ($local_save_mode) {
        // Save Each Database Backup As A Local File
        if (!is_dir($dbdir)) {mkdir($dbdir, 0755);}

        $sql_data = $db_backup->backup(array(
            "path" => $dbdir,
            "filename" => $dbname . "_" . $uid,
            "compression" => false,
            "get_data" => false,
        ));
    } else {
        // Get And Stream Each Database Backup Directly To Google Drive
        $sql_data = $db_backup->backup(array(
            "compression" => true,
            "get_data" => true,
        ));

        $folder_id_day = $gdrive->create_folder(array(
            "root_id" => $folder_id_main["result"],
            "folder_name" => $uidd,
        ));

        $folder_id_time = $gdrive->create_folder(array(
            "root_id" => $folder_id_day["result"],
            "folder_name" => $uidt,
        ));

        $upload_results[] = $gdrive->upload_file(array(
            "data_content" => $sql_data,
            "file_name" => $dbname . "_" . $uid . ".sql.gz",
            "root_id" => $folder_id_time["result"],
            "ignore_existed" => true,
        ));
    }
}

/** If Backups Saved As Local Files, Zip, Uploead, And Delete Them All Together */
if ($local_save_mode) {
    // Zip And Delete Local Backups Folder
    $zipped_folder = zip_folder(array(
        "folder_path" => $dbdir,
        "file_name" => $dbprefix . "_" . $uid,
        "delete_folder" => true,
    ));

    // Upload Databases To Google Drive
    $folder_id_day = $gdrive->create_folder(array(
        "root_id" => $folder_id_main["result"],
        "folder_name" => $uidd,
    ));

    $upload_results[] = $gdrive->upload_file(array(
        "path_to_file" => $zipped_folder,
        "file_name" => $dbprefix . "_" . $uid,
        "root_id" => $folder_id_day["result"],
        "ignore_existed" => true,
    ));

    // Delete Databases Backup Zipped File
    unlink($zipped_folder);
}

/** Search & Delete Backups Older Than n Days */
$search_results = $gdrive->search(array(
    "root_id" => $folder_id_main["result"],
    "dateBefore" => $uidr,
));
if (empty($search_results["error"]) && is_array($search_results["result"])) {
    $delete_results = $gdrive->delete($search_results["result"]);
} else {
    array(
        "error" => 1,
        "message" => "No file to delete or failed to delete files!",
    );
}

/** Echo Results In Browser If You Run The Script Manually In Browser And If Production Mode Is false */
if ($production_mode) {
    echo "Hey, it's forbidden!";
} else {
    $i = 0;
    echo "Upload Results:" . nl2br("\n");
    foreach ($upload_results as $upload_result) {
        if (empty($upload_result["error"])) {
            $backup_gdrive_link = "https://drive.google.com/file/d/" . $upload_result["result"];
            echo "File #" . $i . " url: " . $backup_gdrive_link . nl2br("\n");
        } else {
            echo $upload_result["message"] . nl2br("\n");
        }
    }

    $delete_errors = [];
    echo nl2br("\n\n") . "Delete Results:" . nl2br("\n");
    if (!empty($delete_results)) {
        foreach ($delete_results as $delete_result) {
            if ($delete_result["error"] == 1) {
                $delete_errors[] = $delete_result["message"];
                echo $delete_result["message"] . nl2br("\n");
            }
        }
    }
    if (empty($delete_errors)) {
        echo "Files Deleted Succussfully!";
    }
}
