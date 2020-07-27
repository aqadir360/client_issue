<?php

namespace App\Objects;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use Log;
use ZipArchive;

class FtpManager
{
    private $path;
    private $dateFilePath;
    private $ftp;
    private $ftpPath;
    private $newDate = 0;
    private $compareDate;

    public $modifiedFiles = [];

    public function __construct(string $ftpPath, string $dateFilePath = '/last.txt')
    {
//        $this->path = storage_path('imports');
        $this->ftpPath = $ftpPath;
//        $this->dateFilePath = storage_path($this->path . $dateFilePath);
        $this->ftp = Storage::disk('sftp');
    }

    // Reads most recently modified date from local txt file
    // Pulls list of files modified since that date from FTP
    public function getRecentlyModifiedFiles(): array
    {
        $this->readLastDate();
        $this->setRecentlyModified();
        return $this->modifiedFiles;
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

                $zip->extractTo($path);
                $zip->close();

                unlink($zipFile);
                return true;
            }
        }

        Log::error("Unzip Error $zipFile");
        return false;
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

    // Sets last date to one week ago if not found
    private function readLastDate()
    {
//        if (file_exists($this->dateFilePath)) {
//            $this->compareDate = intval(file_get_contents($this->dateFilePath));
//        } else {
        $this->compareDate = 0;
//        }
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

    public function writeLastDate()
    {
//        if ($this->newDate > 0) {
//            $handle = fopen($this->dateFilePath, 'w');
//            fwrite($handle, $this->newDate);
//            fclose($handle);
//        }
    }
}
