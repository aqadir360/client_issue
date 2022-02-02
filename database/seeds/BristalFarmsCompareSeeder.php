<?php

use Illuminate\Database\Seeder;

class BristalFarmsCompareSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        INSERT INTO `import_types` (`id`, `company_id`, `type`, `name`, `description`, `ftp_path`, `created_at`) VALUES (NULL, '9a22701b-d7ae-aaac-1194-d583167f0ba4', 'bristol_farms_compare', 'Bristole Farms Inventory Update', 'Updates Inventory by comparison', 'imports/bristolfarms', '2022-02-02 18:20:31')

    }
}
