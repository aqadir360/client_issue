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
        // TODO: set this from config
        $this->db = 'DateCheckPro';
        $this->adminDb = 'dcp2admin';
    }

    public function fetchStores(string $companyId)
    {
        $sql = "SELECT store_id, store_num FROM " . $this->db . ".stores WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchDepartments(string $companyId)
    {
        $sql = "SELECT department_id, department, category, skip FROM " . $this->adminDb . ".import_department_mapping WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchDcpDepartments(string $companyId)
    {
        $sql = "SELECT department_id, name, display_name FROM " . $this->db . ".departments WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchSkipItems(string $companyId)
    {
        $sql = "SELECT barcode FROM " . $this->adminDb . ".import_skip_list WHERE company_id = :company_id ";
        return DB::select($sql, [
            'company_id' => $companyId,
        ]);
    }

    public function fetchProductByBarcode(string $barcode)
    {
        $sql = "SELECT * FROM " . $this->db . ".products WHERE barcode = :barcode";
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
        $sql = "SELECT * FROM " . $this->db . ".inventory_items i
             INNER JOIN " . $this->db . ".locations l on l.location_id = i.location_id
             WHERE i.product_id = :product_id AND l.store_id = :store_id AND i.disco = 0";
        return DB::select($sql, [
            'product_id' => $productId,
            'store_id' => $storeId,
        ]);
    }

    public function fetchRaleysSkus()
    {
        $sql = "SELECT sku_num, barcode, is_primary FROM " . $this->db . ".raleys_products";
        return DB::select($sql, []);
    }

    public function insertSkipItem(string $companyId, string $barcode)
    {
        $sql = "INSERT INTO " . $this->adminDb . ".import_skip_list (company_id, barcode, created_at) VALUES (:company_id, :barcode, NOW())";

        try {
            DB::insert($sql, [
                'company_id' => $companyId,
                'barcode' => $barcode,
            ]);
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function startImport(string $companyId, string $userId = 'cmd')
    {
        $sql = "INSERT INTO " . $this->adminDb . ".import_status (company_id, user_id, created_at) VALUES (:company_id, :user_id, NOW())";

        DB::insert($sql, [
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        return DB::getPdo()->lastInsertId();
    }

    public function completeImport($id)
    {
        $sql = "UPDATE " . $this->adminDb . ".import_status SET completed_at = NOW() WHERE id = :id";

        DB::update($sql, [
            'id' => $id,
        ]);
    }

    public function fetchExistingMetric(string $storeId, string $productId)
    {
        $sql = "SELECT cost, retail, movement FROM " . $this->db . ".metrics
        WHERE store_id = :store_id AND product_id = :product_id";
        return DB::select($sql, [
            'store_id' => $storeId,
            'product_id' => $productId,
        ]);
    }

    public function updateMetric(string $storeId, string $productId, int $cost, int $retail, int $movement)
    {
        $sql = "UPDATE " . $this->db . ".metrics
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
        $sql = "INSERT INTO " . $this->db . ".metrics
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
}
