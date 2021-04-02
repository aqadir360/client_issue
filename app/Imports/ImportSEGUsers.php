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
//        $fileList = $this->import->downloadFilesByName('SEG_DCP_User_');

        $fileList = glob(storage_path('imports/seg/users/*'));
        $outputHandle = fopen(storage_path('imports/seg/all_users.csv'), 'w');

        foreach ($fileList as $file) {
            if (($handle = fopen($file, "r")) !== false) {
                while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                    fputcsv($outputHandle, $data);
                }

                fclose($handle);
            }
        }

        fclose($outputHandle);
        $this->import->completeImport();
    }

    private function importUsers($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
//                if (!$this->import->recordRow()) {
//                    continue;
//                }

                $first = trim($data[1]);
                $last = trim($data[2]);
                $email = '';
                $username = $this->parseUsername(trim($data[3]));
                $storeId = $this->import->storeNumToStoreId(intval(trim($data[0])));
                if ($storeId === false) {
                    var_dump($data);
                    die();
                    continue;
                }

//                $role = intval(trim($data[5])) === 2 ? 'S_MANAG' : 'USER';
                $role = 'USER';

                $existing = $this->import->db->fetchUserByUsername($username);

                if ($existing) {
                    var_dump($existing);
                    // TODO: Make sure has all stores access
                } else {
                    echo $username . PHP_EOL;
                    $userId = (string)Uuid::uuid1();
                    $result = $this->import->getProxy()->createUser(
                        $this->import->companyId(),
                        $userId,
                        $username,
                        $email,
                        $username,
                        $first,
                        $last,
                        $role,
                        [$storeId]
                    );
                    var_dump($userId);
                    var_dump($storeId);
                    var_dump($result);
                    die();
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
