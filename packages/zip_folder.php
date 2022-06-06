<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 * 
 */

/**
 * Zip Folder
 * @example
 * $ziped_folder = zip_folder(array(
 * "folder_path" => "",
 * "file_name" => "",
 * "delete_folder" => false
 * ));
 */
function zip_folder($args)
{
    // Initialize Empty Error List
    $error = array();
    if (!isset($args["folder_path"])) {
        $error[] = "Parameter folder_path is missing!";
    }

    if (!isset($args["file_name"])) {
        $error[] = "Parameter file_name is missing!";
    }

    if (count($error) > 0) {
        foreach ($this->error as $err) {echo $err . nl2br("\n\n");}
        die;
    }

    // Initialize Parameters
    $folder_path = (substr($args["folder_path"], -1) != "/") ? $args["folder_path"] : substr($args["folder_path"], 0, -1);
    $file_name = $args["file_name"];
    $delete_folder = $args["delete_folder"];

    // Initialize Archive Object
    $zip_dir = dirname($folder_path);
    $zip_file_path = $zip_dir . "/" . $file_name . ".zip";
    $zip = new ZipArchive();
    $zip->open($zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // Initialize Empty Delete List
    $files_to_delete = array();

    // Create Recursive Directory Iterator
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder_path),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        // Skip Directories
        if (!$file->isDir()) {
            // Get Eeal And Relative Path To Current File
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($folder_path) + 1);

            // Add Current File To Archive
            $zip->addFile($file_path, $relative_path);

            // Add Current File To Delete List
            $files_to_delete[] = $file_path;
        }
    }

    //  Create Zip Archive By Closing Archive Object
    $zip->close();

    if ($delete_folder) {
        // Delete All Files In Delete List
        foreach ($files_to_delete as $file) {
            unlink($file);
        }

        // Delete Root Folder
        rmdir($folder_path);
    }

    return $zip_file_path;
}
