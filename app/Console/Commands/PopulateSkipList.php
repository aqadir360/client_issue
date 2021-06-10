<?php

namespace App\Console\Commands;

use App\Objects\Database;
use Illuminate\Console\Command;

// Fills in product descriptions for skip list
class PopulateSkipList extends Command
{
    protected $signature = 'dcp:skip_list';
    protected $description = 'Fills in product descriptions for skip list';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $database = new Database();

        $skipList = $database->fetchSkipListItems();
        foreach ($skipList as $item) {
            $description = $database->fetchProductDescription($item->barcode);
            $database->updateSkipItem($item->id, $description);
        }
    }
}
