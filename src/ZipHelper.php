<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Logger.php';

/**
 * Provides static utilities for zipping folders.
 */
class ZipHelper
{
    /**
     * Zips a folder and optionally deletes the source directory.
     *
     * @param string $sourcePath Path to the folder to zip.
     * @param string $fileName Name of the output zip file (without .zip extension).
     * @param string $filePath Directory where the zip file will be saved.
     * @param bool $deleteSource If true, deletes the source folder after zipping.
     * @return string Path to the created zip file.
     * @throws InvalidArgumentException If required arguments are missing or empty.
     * @throws RuntimeException If zipping or file operations fail.
     */
    public static function zipFolder(
        string $sourcePath,
        string $fileName,
        string $filePath,
        bool $deleteSource = false
    ): string {
        $missingArgs = array_filter([
            empty($sourcePath) ? 'sourcePath' : null,
            empty($fileName) ? 'fileName' : null,
            empty($filePath) ? 'filePath' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(sprintf(
                '[ZipHelper::zipFolder] Missing required arguments: %s',
                implode(', ', $missingArgs)
            ));
        }

        $validSourcePath = realpath($sourcePath);
        if ($validSourcePath === false) {
            throw new RuntimeException("[ZipHelper::zipFolder] Source path does not exist: $sourcePath");
        }

        $validFilePath = realpath($filePath);
        if ($validFilePath === false || !is_writable($validFilePath)) {
            throw new RuntimeException("[ZipHelper::zipFolder] File path does not exist or is not writable: $filePath");
        }

        $zipFile = $validFilePath . DIRECTORY_SEPARATOR . (str_ends_with(strtolower($fileName), '.zip') ? $fileName : "$fileName.zip");

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("[ZipHelper::zipFolder] Failed to create zip file: $zipFile. " . $zip->getStatusString());
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($validSourcePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $sourcePathLength = strlen($validSourcePath) + 1;
        foreach ($files as $file) {
            if ($file->isFile()) {
                $fileRealPath = $file->getRealPath();
                $relativePath = substr($fileRealPath, $sourcePathLength);

                if (!$zip->addFile($fileRealPath, $relativePath)) {
                    $zip->close();
                    unlink($zipFile);
                    throw new RuntimeException("[ZipHelper::zipFolder] Failed to add file to zip: $fileRealPath");
                }
            }
        }

        if (!$zip->close()) {
            unlink($zipFile);
            throw new RuntimeException("[ZipHelper::zipFolder] Failed to close zip file: $zipFile. " . $zip->getStatusString());
        }

        if ($deleteSource) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($validSourcePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileInfo) {
                $path = $fileInfo->getRealPath();
                $todo = $fileInfo->isDir() ? 'rmdir' : 'unlink';
                if (!$todo($path)) {
                    throw new RuntimeException("[ZipHelper::zipFolder] Failed to delete $path");
                }
            }

            if (!rmdir($validSourcePath)) {
                throw new RuntimeException("[ZipHelper::zipFolder] Failed to delete source folder: $validSourcePath");
            }

            Logger::info("[ZipHelper::zipFolder] Zip file created and deleted source folder: $validSourcePath");
        }

        Logger::info("[ZipHelper::zipFolder] Created zip file: $zipFile");
        return $zipFile;
    }
}