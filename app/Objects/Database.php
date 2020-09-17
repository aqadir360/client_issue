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
        $sql = "SELECT t.company_id, t.ftp_path, t.id, max(s.compare_date)
            FROM {$this->adminDb}.import_types t
            LEFT JOIN {$this->adminDb}.import_status s ON s.import_type_id = t.id
            WHERE t.type = :type
            GROUP BY t.id";

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
        $sql = "SELECT j.id as import_job_id, s.id as import_schedule_id, t.id as import_type_id, t.type, t.ftp_path,
                t.company_id, s.daily, s.week_day, s.month_day, s.start_hour, s.start_minute, s.archived_at
                FROM {$this->adminDb}.import_jobs j
                INNER JOIN {$this->adminDb}.import_schedule s ON s.id = j.import_schedule_id
                INNER JOIN {$this->adminDb}.import_types t ON t.id = s.import_type_id
                WHERE j.completed_at is null and j.started_at is null and j.pending_at <= :now
                order by j.pending_at asc";

        return DB::selectOne($sql, [
            'now' => $now,
        ]);
    }

    public function fetchLastRun($importTypeId): int
    {
        $sql = "SELECT compare_date
                FROM {$this->adminDb}.import_status
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

    public function setImportJobInProgess($jobId)
    {
        $sql = "UPDATE {$this->adminDb}.import_jobs SET started_at = NOW() WHERE id = :id";

        return DB::update($sql, [
            'id' => $jobId,
        ]);
    }

    public function fetchIncompleteJobs()
    {
        $sql = "SELECT j.id, s.id as import_schedule_id,
                s.daily, s.week_day, s.month_day, s.start_hour, s.start_minute, s.archived_at
                FROM {$this->adminDb}.import_jobs j
                INNER JOIN {$this->adminDb}.import_schedule s ON s.id = j.import_schedule_id
                WHERE started_at is not null and completed_at is null";
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
        $sql = "INSERT INTO {$this->adminDb}.import_jobs (import_schedule_id, pending_at, created_at) VALUES (:id, :pending, NOW())";

        return DB::update($sql, [
            'id' => $scheduleId,
            'pending' => $date,
        ]);
    }

    public function startImport($importTypeId, $userId = 'cmd')
    {
        $sql = "INSERT INTO {$this->adminDb}.import_status (import_type_id, user_id, created_at) VALUES (:import_type_id, :user_id, NOW())";

        DB::insert($sql, [
            'import_type_id' => $importTypeId,
            'user_id' => $userId,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function completeImport($importId, int $filesProcessed, int $lastRun, string $errorMsg)
    {
        $sql = "UPDATE {$this->adminDb}.import_status
        SET error_message = :msg, files_processed = :files_processed, compare_date = :compare_date,
            completed_at = NOW()
        WHERE id = :id";

        DB::update($sql, [
            'id' => $importId,
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
}
