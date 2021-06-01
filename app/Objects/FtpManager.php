<?php

namespace App\Objects;

use Archive7z\Archive7z;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use Log;
use ZipArchive;

class FtpManager
{
    private $ftp;
    private $ftpPath;
    private $newDate = 0;
    private $compareDate;

    public $modifiedFiles = [];

    public function __construct(?string $ftpPath, ?int $compareDate)
    {
        $this->ftpPath = $ftpPath;
        if ($ftpPath !== null) {
            $this->ftp = Storage::disk('sftp');
        }
        $this->compareDate = 1619560804;
    }

    // Reads most recently modified date from local txt file
    // Pulls list of files modified since that date from FTP
    public function getRecentlyModifiedFiles(): array
    {
        if (empty($this->modifiedFiles)) {
            $this->setRecentlyModified();
        }

        return $this->modifiedFiles;
    }

    public function getNewDate(): int
    {
        return $this->newDate;
    }

    // Returns the path of the most recently modified file from FTP
    public function getMostRecentFile(): ?string
    {
        $lastModified = 0;
        $mostRecentFile = null;

        $files = $this->ftp->files($this->ftpPath);
        foreach ($files as $file) {
            $fileLastModified = $this->ftp->lastModified($file);
            if (($lastModified < $fileLastModified)) {
                $lastModified = $fileLastModified;
                $mostRecentFile = $file;
            }
        }

        return $mostRecentFile;
    }

    // Writes from FTP to local storage/imports/
    public function downloadFile(string $file)
    {
        echo "Downloading $file" . PHP_EOL;
        try {
            if (!file_exists(storage_path('imports/' . basename($file)))) {
                Storage::disk('imports')->put(basename($file), $this->ftp->get($file));
            }
            return storage_path('imports/' . basename($file));
        } catch (FileNotFoundException $e) {
            Log::error($e);
        }
        return false;
    }

    public function unzipFile(string $zipFile, $unzipPath)
    {
        $zip = new ZipArchive();
        $zipSuccess = $zip->open($zipFile);

        if ($zipSuccess === true) {
            $path = storage_path("imports/$unzipPath/");
            if (!file_exists($path)) {
                mkdir($path);
            }

            $zip->extractTo($path);
            $zip->close();

            unlink($zipFile);
            return true;
        }

        Log::error("Unzip Error $zipSuccess in $zipFile");
        return false;
    }

    public function unzipSevenZipFile(string $zipFile, $unzipPath)
    {
        $path = storage_path("imports/$unzipPath/");
        if (!file_exists($path)) {
            mkdir($path);
        }

        $zip = new Archive7z($zipFile);
        if (!$zip->isValid()) {
            return false;
        }

        $zip->setOutputDirectory($path);
        $zip->extract();
        unlink($zipFile);
        return true;
    }

    // Populates list of files modified since last date
    private function setRecentlyModified()
    {
        $ftpFiles = $this->ftp->files($this->ftpPath);

        foreach ($ftpFiles as $file) {
            $lastModified = $this->ftp->lastModified($file);
            if ($this->isBefore($lastModified)) {
                $this->setNewDate($lastModified);
                $this->modifiedFiles[] = $file;
            }
        }
    }

    private function isBefore($lastModified): bool
    {
        return ($lastModified > $this->compareDate);
    }

    private function setNewDate($lastModified)
    {
        if ($lastModified > $this->newDate) {
            $this->newDate = $lastModified;
        }
    }
}
