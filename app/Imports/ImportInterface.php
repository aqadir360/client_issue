<?php

namespace App\Imports;

use App\Objects\Api;

interface ImportInterface
{
    public function __construct(Api $api);

    public function importUpdates();
}
