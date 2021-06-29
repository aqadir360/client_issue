<?php

namespace App\Imports;

use App\Objects\ImportManager;

// Imports SEG user files
class ImportSEGUserUpdates implements ImportInterface
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

                $existing = $this->import->db->fetchUserByUsername(intval($data[3]));
                if (!$existing) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skipped: New User");
                    continue;
                }

                if (isset($data[7]) && trim($data[7]) === 'T') {
                    $result = $this->import->getProxy()->deleteUser($existing->company_id, $existing->user_id);
                    $this->import->recordResponse($result, 'disco');
                    $this->import->writeFileOutput($data, "Success: Deleted User");
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval(trim($data[4])));
                if ($storeId === false) {
                    $this->import->writeFileOutput($data, "Skipped: New Store Not Found");
                    continue;
                }

                if ($this->sameUserStore($existing->user_id, $storeId)) {
                    $this->import->recordStatic();
                    $this->import->writeFileOutput($data, "Skipped: Same User Store");
                    continue;
                }

                $result = $this->import->getProxy()->updateUser(
                    $existing,
                    [$storeId],
                    trim($data[2])
                );

                if ($result) {
                    $this->import->recordMove();
                    $this->import->writeFileOutput($data, "Success: Updated User");
                } else {
                    $this->import->writeFileOutput($data, "Error: Could Not Update User");
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function sameUserStore(string $userId, $storeId): bool
    {
        $userStores = $this->import->db->fetchUserStores($userId);
        foreach ($userStores as $store) {
            if ($store->store_id === $storeId) {
                return true;
            }
        }
        return false;
    }
}
