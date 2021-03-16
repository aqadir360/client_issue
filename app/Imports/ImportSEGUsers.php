<?php

namespace App\Imports;

use App\Objects\ImportManager;
use Ramsey\Uuid\Uuid;

// Imports SEG user files
class ImportSEGUsers implements ImportInterface
{
    private $path;

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->path = storage_path('imports/seg/');

        if (!file_exists($this->path)) {
            mkdir($this->path);
        }
    }

    public function importUpdates()
    {
        $fileList = $this->import->downloadFilesByName('SEG_DCP_User_');

        foreach ($fileList as $file) {
            $this->importUsers($file);
        }

        $this->import->completeImport();
    }

    private function importUsers($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    continue;
                }

                $first = trim($data[0]);
                $last = trim($data[1]);
                $email = trim($data[2]);
                $username = $this->parseUsername(trim($data[3]));
                $storeId = $this->import->storeNumToStoreId(intval(trim($data[4])));
                if ($storeId === false) {
                    continue;
                }

                $role = intval(trim($data[5])) === 2 ? 'S_MANAG' : 'USER';

                $existing = $this->import->db->fetchUserByUsername($username);

                if ($existing) {
                    // TODO: Make sure has all stores access
                } else {
                    $result = $this->import->getProxy()->createUser(
                        $this->import->companyId(),
                        (string)Uuid::uuid1(),
                        $username,
                        $email,
                        $username,
                        $first,
                        $last,
                        $role,
                        [$storeId]
                    );
                    $this->import->recordResponse($result, 'add');
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseUsername(string $username)
    {
        if (strlen($username) > 6) {
            return substr($username, -6);
        }
        return $username;
    }
}
