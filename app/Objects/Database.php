<?php
declare(strict_types=1);

namespace App\Objects;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class Database
{
    private $dbName;

    public function setDbName(string $dbName)
    {
        $this->dbName = $dbName;
    }

    public function setProxyLoginToken($companyId): string
    {
        $adminToken = (string)Uuid::uuid1();
        $sql = 'insert into login_attempts (username, ip_address, version, user_agent, status, created_at)
            values (:username, :ip_address, :version, :user_agent, :status, NOW())';
        DB::insert($sql, [
                'username' => config('scraper.user'),
                'ip_address' => '',
                'version' => 'Imports',
                'user_agent' => '',
                'status' => 1,
            ]
        );
        $loginId = $this->lastInsertId();
        $sql = 'insert into login_success (user_id, login_id, company_id, token, created_at)
            values (:user_id, :login_id, :company_id, :token, NOW())';
        DB::insert($sql, [
            'user_id' => 'c75daa99-b3b0-a108-0be3-5987be65087b',
            'login_id' => $loginId,
            'company_id' => $companyId,
            'token' => $adminToken,
        ]);
        return $adminToken;
    }

    public function fetchStores(string $companyId)
    {
        $sql = "SELECT store_id, store_num FROM #t#.stores WHERE company_id = :company_id";

        return $this->fetchFromCompanyDb($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchDepartments(string $companyId)
    {
        $sql = "SELECT department_id, department, category, skip
            FROM import_department_mapping
            WHERE company_id = :company_id ";

        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchSkipItems(string $companyId)
    {
        $sql = "SELECT barcode FROM import_skip_list WHERE company_id = :company_id ";

        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchProductByBarcode(string $barcode)
    {
        $sql = "SELECT * FROM products WHERE barcode = :barcode";

        $result = DB::select($sql, [
            'barcode' => $barcode,
        ]);

        if (count($result) > 0) {
            return $result[0];
        }

        return false;
    }

    public function fetchProductInventory(string $productId, string $storeId)
    {
        $sql = "SELECT * FROM #t#.inventory_items i
             INNER JOIN #t#.locations l on l.location_id = i.location_id
             WHERE i.product_id = :product_id AND l.store_id = :store_id
             AND i.disco = 0 AND l.markdown_department_id IS NULL";

        return $this->fetchFromCompanyDb($sql, [
            'product_id' => $productId,
            'store_id' => $storeId,
        ]);
    }

    public function hasDiscoInventory(string $productId, string $storeId)
    {
        $params = [
            'product_id' => $productId,
            'store_id' => $storeId,
        ];

        $sql = "SELECT * FROM #t#.inventory_items i
             INNER JOIN #t#.locations l on l.location_id = i.location_id
             WHERE i.product_id = :product_id AND l.store_id = :store_id AND i.disco = 1";
        $result = $this->fetchOneFromCompanyDb($sql, $params);
        if (!empty($result)) {
            return true;
        }

        $sql = "SELECT * FROM #t#.disco_items i
             WHERE i.product_id = :product_id AND i.store_id = :store_id";
        $result = $this->fetchOneFromCompanyDb($sql, $params);
        return !empty($result);
    }

    public function fetchRaleysSkus()
    {
        $sql = "SELECT sku_num, barcode, is_primary FROM #t#.raleys_products";
        return $this->fetchFromCompanyDb($sql, []);
    }

    public function insertRaleysSku($sku, $barcode)
    {
        try {
            $sql = "INSERT INTO #t#.raleys_products (sku_num, barcode) VALUES (:sku_num, :barcode)";
            DB::connection('db_companies')->insert(
                $this->companyPdoConvert($sql, $this->dbName), [
                    'sku_num' => $sku,
                    'barcode' => $barcode,
                ]
            );
        } catch (\Exception $e) {
            Log::error($e);
        }
    }

    public function fetchSegSkus()
    {
        $sql = "SELECT sku, barcode FROM #t#.seg_products";
        return $this->fetchFromCompanyDb($sql, []);
    }

    public function fetchSegReclaimSkus()
    {
        $sql = "SELECT sku FROM #t#.seg_reclaim";
        return $this->fetchFromCompanyDb($sql, []);
    }

    public function insertSegSku($sku, $inputBarcode, $barcode)
    {
        try {
            $sql = "INSERT INTO #t#.seg_products (sku, input_barcode, barcode)
                VALUES (:sku, :input_barcode, :barcode)";
            DB::connection('db_companies')->insert(
                $this->companyPdoConvert($sql, $this->dbName), [
                    'sku' => $sku,
                    'input_barcode' => $inputBarcode,
                    'barcode' => $barcode,
                ]
            );
        } catch (\Exception $e) {
            Log::error($e);
        }
    }

    public function insertSkipItem(string $companyId, string $barcode)
    {
        $sql = "INSERT INTO import_skip_list (company_id, barcode, created_at) VALUES (:company_id, :barcode, NOW())";

        try {
            DB::insert($sql, [
                'company_id' => $companyId,
                'barcode' => $barcode,
            ]);
        } catch (\Exception $e) {
            Log::error($e);
        }
    }

    public function fetchImportByType(string $type)
    {
        $sql = "SELECT t.company_id, c.db_name, t.ftp_path, t.id as type_id
            FROM import_types t
            INNER JOIN companies c on c.company_id = t.company_id
            WHERE t.type = :type";

        return DB::selectOne($sql, [
            'type' => $type,
        ]);
    }

    public function fetchImportCompanies()
    {
        $sql = "SELECT distinct company_id FROM import_schedule";
        return DB::selectOne($sql, []);
    }

    public function fetchCurrentImport()
    {
        $sql = "SELECT id FROM import_status WHERE completed_at is null";
        return DB::selectOne($sql, []);
    }

    public function fetchNextUpcomingImport(string $now)
    {
        $sql = "SELECT j.id as import_job_id, t.id as import_type_id, t.type, t.ftp_path,
                s.company_id, s.id as import_schedule_id, c.db_name
                FROM import_jobs j
                INNER JOIN import_schedule s ON s.id = j.import_schedule_id
                INNER JOIN import_types t ON t.id = s.import_type_id
                INNER JOIN companies c ON c.company_id = s.company_id
                WHERE j.completed_at is null and j.started_at is null and j.pending_at <= :now
                order by j.pending_at asc";

        return DB::selectOne($sql, [
            'now' => $now,
        ]);
    }

    public function fetchImportSchedule($scheduleId)
    {
        $sql = "SELECT * FROM import_schedule WHERE id = :id AND archived_at IS NULL";

        return DB::selectOne($sql, [
            'id' => $scheduleId,
        ]);
    }

    public function archiveSchedule($scheduleId)
    {
        $sql = "UPDATE import_schedule SET archived_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $scheduleId,
        ]);
    }

    public function fetchLastRun($importTypeId): int
    {
        $sql = "SELECT compare_date
                FROM import_status
                WHERE import_type_id = :import_type_id
                order by compare_date desc";

        $result = DB::selectOne($sql, [
            'import_type_id' => $importTypeId,
        ]);

        if (empty($result)) {
            return 0;
        }

        return intval($result->compare_date);
    }

    public function setImportJobInProgress($jobId)
    {
        $sql = "UPDATE import_jobs SET started_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $jobId,
        ]);
    }

    public function setImportJobComplete($jobId)
    {
        $sql = "UPDATE import_jobs SET completed_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $jobId,
        ]);
    }

    public function insertNewJob($scheduleId, $date)
    {
        $sql = "INSERT INTO import_jobs (import_schedule_id, pending_at, created_at) VALUES (:id, :pending, NOW())";

        DB::update($sql, [
            'id' => $scheduleId,
            'pending' => $date,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function startImport($importTypeId, $importJobId)
    {
        $sql = "INSERT INTO import_status (import_type_id, import_job_id, created_at)
            VALUES (:import_type_id, :import_job_id, NOW())";

        DB::insert($sql, [
            'import_type_id' => $importTypeId,
            'import_job_id' => $importJobId,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function completeImport($importStatusId, int $filesProcessed, int $lastRun, string $errorMsg)
    {
        var_dump("complete import");
        var_dump($importStatusId);
        $sql = "UPDATE import_status
        SET error_message = :msg, files_processed = :files_processed, compare_date = :compare_date, completed_at = NOW()
        WHERE id = :id";

        DB::insert($sql, [
            'id' => $importStatusId,
            'msg' => $errorMsg,
            'files_processed' => $filesProcessed,
            'compare_date' => $lastRun,
        ]);
    }

    public function fetchExistingMetric(string $storeId, string $productId)
    {
        $sql = "SELECT cost, retail, movement FROM #t#.metrics
            WHERE store_id = :store_id AND product_id = :product_id";

        return $this->fetchFromCompanyDb($sql, [
            'store_id' => $storeId,
            'product_id' => $productId,
        ]);
    }

    public function updateMetric(string $storeId, string $productId, int $cost, int $retail, int $movement)
    {
        $sql = "UPDATE #t#.metrics
        SET retail = :retail, cost = :cost, movement = :movement, updated_at = NOW()
        WHERE store_id = :store_id AND product_id = :product_id";

        return DB::connection('db_companies')->update(
            $this->companyPdoConvert($sql, $this->dbName), [
                'store_id' => $storeId,
                'product_id' => $productId,
                'cost' => $cost,
                'retail' => $retail,
                'movement' => $movement,
            ]
        );
    }

    //    public function insertProduct(string $productId, string $barcode, string $name, string $size)
    //    {
    //        $sql = "INSERT INTO {$this->db}.products
    //        (product_id, barcode, description, size, created_at, updated_at)
    //        VALUES (:product_id, :barcode, :description, :size, NOW(), NOW())";
    //        return DB::insert($sql, [
    //            'product_id' => $productId,
    //            'barcode' => $barcode,
    //            'description' => $name,
    //            'size' => $size,
    //        ]);
    //    }

    public function insertMetric(string $storeId, string $productId, int $cost, int $retail, int $movement)
    {
        $sql = "INSERT INTO #t#.metrics
        (store_id, product_id, retail, cost, movement, created_at, updated_at)
        VALUES (:store_id, :product_id, :retail, :cost, :movement, NOW(), NOW())";

        return DB::connection('db_companies')->insert(
            $this->companyPdoConvert($sql, $this->dbName), [
                'store_id' => $storeId,
                'product_id' => $productId,
                'cost' => $cost,
                'retail' => $retail,
                'movement' => $movement,
            ]
        );
    }

    public function fetchSkipListItems()
    {
        $sql = "SELECT l.id, l.barcode FROM import_skip_list l
            WHERE l.description is null and l.updated_at is null";

        return DB::select($sql);
    }

    public function updateSkipItem($id, $description)
    {
        $sql = "UPDATE import_skip_list SET description = :description, updated_at = NOW() WHERE id = :id";

        DB::update($sql, [
            'description' => $description,
            'id' => $id,
        ]);
    }

    public function fetchProductDescription(string $barcode)
    {
        $sql = "SELECT p.description FROM products p WHERE p.barcode LIKE :barcode";
        $result = DB::selectOne($sql, [
            'barcode' => '%' . $barcode . '%',
        ]);
        return $result ? $result->description : '';
    }

    public function fetchUserByUsername(string $username)
    {
        $sql = "SELECT u.* FROM users u WHERE u.username = :username";

        return DB::selectOne($sql, [
            'username' => $username,
        ]);
    }

    public function insertResultsRow($importStatusId, string $filename)
    {
        $sql = "INSERT INTO import_results (import_status_id, filename, created_at)
            VALUES (:import_status_id, :filename, NOW())";

        DB::insert($sql, [
            'import_status_id' => $importStatusId,
            'filename' => $filename,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function updateOverlayResultsRow($id, $total, $updated, $skipped, $output)
    {
        $sql = "UPDATE import_results
                SET completed_at = NOW(), total = :total, adds = :adds, skipped = :skipped, output = :output
                WHERE id = :id";

        DB::update($sql, [
            'id' => $id,
            'output' => $output,
            'skipped' => $skipped,
            'adds' => $updated,
            'total' => $total,
        ]);
    }

    public function fetchClosestDate(
        string $productId,
        string $companyId,
        array $copyFromStores,
        string $orderDirection,
        string $maxDate
    ) {
        $sql = "select i.expiration_date from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where i.product_id = :product_id and s.company_id = :company_id and i.expiration_date < :max_date
            and i.expiration_date > NOW() and i.expiration_date is not null and i.flag is null and i.disco = 0";

        if (!empty($copyFromStores)) {
            $sql .= " and s.store_id IN (" . $this->getListParams($copyFromStores) . ") ";
        }

        $sql .= " order by i.expiration_date $orderDirection ";

        return $this->fetchOneFromCompanyDb($sql, [
            'product_id' => $productId,
            'company_id' => $companyId,
            'max_date' => $maxDate,
        ]);
    }

    public function fetchNewCompanyProducts(string $companyId)
    {
        $sql = "select distinct p.product_id from #t#.products p
            inner join #t#.inventory_items i on i.product_id = p.product_id
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where i.flag = 'NEW' and s.company_id = :company_id and p.no_expiration = 0";

        return $this->fetchFromCompanyDb($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchNewCompanyInventory(string $productId, string $companyId, array $excludeStores, array $excludeDepts)
    {
        $sql = "select i.inventory_item_id from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where i.flag = 'NEW' and s.company_id = :company_id and i.product_id = :product_id
            and i.disco = 0 and l.markdown_department_id is null ";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        var_dump($sql);

        return $this->fetchFromCompanyDb($sql, [
            'company_id' => $companyId,
            'product_id' => $productId,
        ]);
    }

    public function getOosInventory(string $companyId, array $excludeStores, array $excludeDepts)
    {
        $sql = "select i.inventory_item_id, i.close_dated_date, i.product_id, i.close_dated_date, s.store_id
            from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where s.company_id = :company_id and i.disco = 0 and i.flag = 'OOS'";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        return $this->fetchFromCompanyDb($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function getOosInventoryCount(string $companyId, array $excludeStores, array $excludeDepts): int
    {
        $sql = "select count(i.inventory_item_id) as count from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where s.company_id = :company_id and i.disco = 0 and i.flag = 'OOS'";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        return $this->fetchOneFromCompanyDb($sql, [
            'company_id' => $companyId,
        ])->count;
    }

    public function fetchCustomImportSettings($scheduleId)
    {
        $sql = "select s.type, s.value from import_settings s
            where s.import_schedule_id = :import_schedule_id";

        return DB::select($sql, [
            'import_schedule_id' => $scheduleId,
        ]);
    }

    // Converts an array of strings to a sql list
    private function getListParams(array $elements)
    {
        $list = "";

        if (!empty($elements)) {
            foreach ($elements as $item) {
                $list .= "'" . $item . "',";
            }

            $list = substr($list, 0, -1);
        }

        return $list;
    }

    private function lastInsertId()
    {
        return DB::getPdo()->lastInsertId();
    }

    private function fetchFromCompanyDb($sql, $params)
    {
        return DB::connection('db_companies')->select(
            $this->companyPdoConvert($sql, $this->dbName),
            $params
        );
    }

    private function fetchOneFromCompanyDb($sql, $params)
    {
        return DB::connection('db_companies')->selectOne(
            $this->companyPdoConvert($sql, $this->dbName),
            $params
        );
    }

    private function companyPdoConvert(string $sql, string $dbName)
    {
        return str_replace("#t#", '`' . $dbName . '`', $sql);
    }
}
