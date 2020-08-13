<?php

namespace App\Imports;

use App\Objects\ImportManager;

interface ImportInterface
{
    public function __construct(ImportManager $importManager);

    public function importUpdates();
}
