<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\Database;

interface ImportInterface
{
    public function __construct(Api $api, Database $database);

    public function importUpdates();
}
