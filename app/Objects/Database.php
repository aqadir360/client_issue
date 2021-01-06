<?php
declare(strict_types=1);

namespace App\Objects;

use Illuminate\Support\Facades\DB;

class Database
{
    private $db;
    private $adminDb;

    public function __construct()
    {
        $conn = config('database.connections.' . config('database.default'));

        $this->db = $conn['database'];
        $this->adminDb = $conn['admin_database'];
    }

    public function fetchStores(string $companyId)
    {
        $sql = "SELECT store_id, store_num FROM {$this->db}.stores WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchDepartments(string $companyId)
    {
        $sql = "SELECT department_id, department, category, skip FROM {$this->adminDb}.import_department_mapping WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchSkipItems(string $companyId)
    {
        $sql = "SELECT barcode FROM {$this->adminDb}.import_skip_list WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchProductByBarcode(string $barcode)
    {
        $sql = "SELECT * FROM {$this->db}.products WHERE barcode = :barcode";
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
        $sql = "SELECT * FROM {$this->db}.inventory_items i
             INNER JOIN {$this->db}.locations l on l.location_id = i.location_id
             WHERE i.product_id = :product_id AND l.store_id = :store_id
             AND i.disco = 0 AND l.markdown_department_id IS NULL";
        return DB::select($sql, [
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

        $sql = "SELECT * FROM {$this->db}.inventory_items i
             INNER JOIN {$this->db}.locations l on l.location_id = i.location_id
             WHERE i.product_id = :product_id AND l.store_id = :store_id AND i.disco = 1";
        $result = DB::selectOne($sql, $params);
        if (!empty($result)) {
            return true;
        }

        $sql = "SELECT * FROM {$this->db}.disco_items i
             WHERE i.product_id = :product_id AND i.store_id = :store_id";
        $result = DB::selectOne($sql, $params);
        return !empty($result);
    }

    public function fetchRaleysSkus()
    {
        $sql = "SELECT sku_num, barcode, is_primary FROM {$this->db}.raleys_products";
        return DB::select($sql, []);
    }

    public function insertRaleysSku($sku, $barcode)
    {
        try {
            $sql = "INSERT INTO {$this->db}.raleys_products (sku_num, barcode) VALUES (:sku_num, :barcode)";
            DB::insert($sql, [
                'sku_num' => $sku,
                'barcode' => $barcode,
            ]);
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function fetchSegSkus()
    {
        $sql = "SELECT sku, barcode FROM {$this->db}.seg_products";
        return DB::select($sql, []);
    }

    public function insertSegSku($sku, $barcode)
    {
        try {
            $sql = "INSERT INTO {$this->db}.seg_products (sku, barcode) VALUES (:sku, :barcode)";
            DB::insert($sql, [
                'sku' => $sku,
                'barcode' => $barcode,
            ]);
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function insertSkipItem(string $companyId, string $barcode)
    {
        $sql = "INSERT INTO {$this->adminDb}.import_skip_list (company_id, barcode, created_at) VALUES (:company_id, :barcode, NOW())";

        try {
            DB::insert($sql, [
                'company_id' => $companyId,
                'barcode' => $barcode,
            ]);
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function fetchImportByType(string $type)
    {
        $sql = "SELECT t.company_id, t.ftp_path, t.id, s.id as schedule_id
            FROM {$this->adminDb}.import_types t
            LEFT JOIN {$this->adminDb}.import_schedule s ON s.import_type_id = t.id
            WHERE t.type = :type";

        return DB::selectOne($sql, [
            'type' => $type,
        ]);
    }

    public function fetchCurrentImport()
    {
        $sql = "SELECT r.id FROM {$this->adminDb}.import_results r WHERE r.completed_at is null";
        return DB::selectOne($sql, []);
    }

    public function fetchNextUpcomingImport(string $now)
    {
        $sql = "SELECT j.id as import_job_id, t.id as import_type_id, t.type, t.ftp_path,
                s.company_id, s.id as import_schedule_id
                FROM {$this->adminDb}.import_jobs j
                INNER JOIN {$this->adminDb}.import_schedule s ON s.id = j.import_schedule_id
                INNER JOIN {$this->adminDb}.import_types t ON t.id = s.import_type_id
                WHERE j.completed_at is null and j.started_at is null and j.pending_at <= :now
                order by j.pending_at asc";

        return DB::selectOne($sql, [
            'now' => $now,
        ]);
    }

    public function fetchImportSchedule($scheduleId)
    {
        $sql = "SELECT * FROM {$this->adminDb}.import_schedule WHERE id = :id AND archived_at IS NULL";

        return DB::selectOne($sql, [
            'id' => $scheduleId,
        ]);
    }

    public function archiveSchedule($scheduleId)
    {
        $sql = "UPDATE {$this->adminDb}.import_schedule SET archived_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $scheduleId,
        ]);
    }

    public function fetchLastRun($importScheduleId): int
    {
        $sql = "SELECT compare_date
                FROM {$this->adminDb}.import_status
                WHERE import_schedule_id = :import_schedule_id
                order by compare_date desc";

        $result = DB::selectOne($sql, [
            'import_schedule_id' => $importScheduleId,
        ]);

        if (empty($result)) {
            return 0;
        }

        return intval($result->compare_date);
    }

    public function setImportJobInProgess($jobId)
    {
        $sql = "UPDATE {$this->adminDb}.import_jobs SET started_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $jobId,
        ]);
    }

    public function fetchIncompleteJobs()
    {
        $sql = "SELECT j.id, j.import_schedule_id
                FROM {$this->adminDb}.import_jobs j
                INNER JOIN {$this->adminDb}.import_schedule s ON s.id = j.import_schedule_id
                WHERE j.started_at is not null and j.completed_at is null";
        return DB::select($sql, []);
    }

    public function setImportJobComplete($jobId)
    {
        $sql = "UPDATE {$this->adminDb}.import_jobs SET completed_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $jobId,
        ]);
    }

    public function insertNewJob($scheduleId, $date)
    {
        $db = $this->adminDb;
        $sql = "INSERT INTO $db.import_jobs (import_schedule_id, pending_at, created_at) VALUES (:id, :pending, NOW())";

        return DB::update($sql, [
            'id' => $scheduleId,
            'pending' => $date,
        ]);
    }

    public function startImport($importScheduleId)
    {
        $sql = "INSERT INTO {$this->adminDb}.import_status (import_schedule_id, created_at) VALUES (:import_schedule_id, NOW())";

        DB::insert($sql, [
            'import_schedule_id' => $importScheduleId,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function completeImport($importStatusId, int $filesProcessed, int $lastRun, string $errorMsg)
    {
        $sql = "UPDATE {$this->adminDb}.import_status
        SET error_message = :msg, files_processed = :files_processed, compare_date = :compare_date, completed_at = NOW()
        WHERE id = :id";

        DB::update($sql, [
            'id' => $importStatusId,
            'msg' => $errorMsg,
            'files_processed' => $filesProcessed,
            'compare_date' => $lastRun,
        ]);
    }

    public function cancelRunningImports()
    {
        $sql = "UPDATE {$this->adminDb}.import_status SET error_message = :msg, completed_at = NOW()
                WHERE completed_at is null";

        DB::update($sql, [
            'msg' => "Cancelled",
        ]);

        $sql = "UPDATE {$this->adminDb}.import_results SET completed_at = NOW()
                WHERE completed_at is null";
        DB::update($sql, []);
    }

    public function fetchExistingMetric(string $storeId, string $productId)
    {
        $sql = "SELECT cost, retail, movement FROM {$this->db}.metrics
        WHERE store_id = :store_id AND product_id = :product_id";
        return DB::select($sql, [
            'store_id' => $storeId,
            'product_id' => $productId,
        ]);
    }

    public function updateMetric(string $storeId, string $productId, int $cost, int $retail, int $movement)
    {
        $sql = "UPDATE {$this->db}.metrics
        SET retail = :retail, cost = :cost, movement = :movement, updated_at = NOW()
        WHERE store_id = :store_id AND product_id = :product_id";
        return DB::update($sql, [
            'store_id' => $storeId,
            'product_id' => $productId,
            'cost' => $cost,
            'retail' => $retail,
            'movement' => $movement,
        ]);
    }

    public function insertMetric(string $storeId, string $productId, int $cost, int $retail, int $movement)
    {
        $sql = "INSERT INTO {$this->db}.metrics
        (store_id, product_id, retail, cost, movement, created_at, updated_at)
        VALUES (:store_id, :product_id, :retail, :cost, :movement, NOW(), NOW())";
        return DB::insert($sql, [
            'store_id' => $storeId,
            'product_id' => $productId,
            'cost' => $cost,
            'retail' => $retail,
            'movement' => $movement,
        ]);
    }

    public function fetchSkipListItems()
    {
        $sql = "SELECT l.id, l.barcode FROM {$this->adminDb}.import_skip_list l
            WHERE l.description is null and l.updated_at is null";
        return DB::select($sql);
    }

    public function updateSkipItem($id, $description)
    {
        $sql = "UPDATE {$this->adminDb}.import_skip_list SET description = :description, updated_at = NOW() WHERE id = :id";
        DB::update($sql, [
            'description' => $description,
            'id' => $id,
        ]);
    }

    public function fetchProductDescription(string $barcode)
    {
        $sql = "SELECT p.description FROM {$this->db}.products p WHERE p.barcode LIKE '%$barcode%'";
        $result = DB::selectOne($sql);
        return $result ? $result->description : '';
    }

    public function insertResultsRow($importStatusId, string $filename)
    {
        $sql = "INSERT INTO dcp2admin.import_results (import_status_id, filename, created_at) VALUES (:import_status_id, :filename, NOW())";

        DB::insert($sql, [
            'import_status_id' => $importStatusId,
            'filename' => $filename,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function updateOverlayResultsRow($id, $total, $updated, $skipped, $output)
    {
        $sql = "UPDATE dcp2admin.import_results
                SET completed_at = NOW(), total = :total, adds = :adds, skipped = :skipped, output = :output WHERE id = :id";

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
        $sql = "select i.expiration_date from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on l.store_id = s.store_id
            where i.product_id = :product_id and s.company_id = :company_id and i.expiration_date < :max_date
            and i.expiration_date > NOW() and i.expiration_date is not null and i.flag is null and i.disco = 0";

        if (!empty($copyFromStores)) {
            $sql .= " and s.store_id IN (" . $this->getListParams($copyFromStores) . ") ";
        }

        $sql .= " order by i.expiration_date $orderDirection ";

        return DB::selectOne($sql, [
            'product_id' => $productId,
            'company_id' => $companyId,
            'max_date' => $maxDate,
        ]);
    }

    public function fetchNewCompanyProducts(string $companyId)
    {
        $sql = "select p.product_id from products p
            inner join inventory_items i on i.product_id = p.product_id
            inner join locations l on l.location_id = i.location_id
            inner join stores s on l.store_id = s.store_id
            where i.flag = 'NEW' and s.company_id = :company_id and p.no_expiration = 0
            group by p.product_id";

        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchNewCompanyInventory(string $productId, string $companyId, array $excludeStores, array $excludeDepts)
    {
        $sql = "select i.inventory_item_id from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on l.store_id = s.store_id
            where i.flag = 'NEW' and s.company_id = :company_id and i.product_id = :product_id
            and i.disco = 0 and l.markdown_department_id is null ";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        return DB::select($sql, [
            'company_id' => $companyId,
            'product_id' => $productId,
        ]);
    }

    public function getOosInventory(string $companyId, array $excludeStores, array $excludeDepts)
    {
        $sql = "select i.inventory_item_id, i.close_dated_date, i.product_id, i.close_dated_date, s.store_id from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on l.store_id = s.store_id
            where s.company_id = :company_id and i.disco = 0 and i.flag = 'OOS'";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function getOosInventoryCount(string $companyId, array $excludeStores, array $excludeDepts): int
    {
        $sql = "select count(i.inventory_item_id) as count from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on l.store_id = s.store_id
            where s.company_id = :company_id and i.disco = 0 and i.flag = 'OOS'";

        if (!empty($excludeStores)) {
            $sql .= " and s.store_id NOT IN (" . $this->getListParams($excludeStores) . ") ";
        }

        if (!empty($excludeDepts)) {
            $sql .= " and i.department_id NOT IN (" . $this->getListParams($excludeDepts) . ") ";
        }

        return DB::selectOne($sql, [
            'company_id' => $companyId,
        ])->count;
    }

    public function fetchCustomImportSettings(string $key, string $companyId)
    {
        $sql = "select s.type, s.value from {$this->adminDb}.import_settings s
            inner join {$this->adminDb}.import_schedule i on i.id = s.import_schedule_id
            inner join {$this->adminDb}.import_types t on t.id = i.import_type_id
            where t.type = :key and i.company_id = :company_id";

        return DB::select($sql, [
            'company_id' => $companyId,
            'key' => $key,
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
}
