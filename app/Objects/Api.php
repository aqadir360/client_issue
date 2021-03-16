<?php
declare(strict_types=1);

namespace App\Objects;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Log;

class Api
{
    private $url;
    private $adminToken = null;
    private $token = null;
    private $debugMode;

    public function __construct()
    {
        $this->url = config('scraper.url');
        $this->debugMode = config('scraper.debug_mode') === 'debug';
    }

    public function setAdminToken(string $token)
    {
        $this->adminToken = $token;
    }

    public function fetchAllInventory(string $companyId, string $storeId, bool $notificationsOnly = false)
    {
        return $this->request(
            'fetch-all-inventory',
            [
                'companyId' => $companyId,
                'storeId' => $storeId,
                'notificationsOnly' => $notificationsOnly,
            ]
        );
    }

    public function implementationScan(
        Product $product,
        string $companyId,
        string $storeId,
        string $aisle,
        string $section,
        string $deptId,
        string $shelf = ''
    ) {
        return $this->writeRequest(
            'implementation-scan',
            [
                'companyId' => $companyId,
                'barcode' => $product->barcode,
                'name' => $product->description,
                'size' => $product->size,
                'storeId' => $storeId,
                'departmentId' => $deptId,
                'aisle' => $aisle,
                'section' => $section,
                'shelf' => $shelf,
                'pulled' => 0,
                'outOfStock' => false,
            ]
        );
    }

    public function writeInventoryDisco(string $companyId, string $inventoryItemId)
    {
        return $this->writeRequest(
            'write-inventory',
            [
                'companyId' => $companyId,
                'inventoryItemId' => $inventoryItemId,
                'productId' => null,
                'locationId' => null,
                'departmentId' => null,
                'expiration' => null,
                'closeDated' => null,
                'expiring' => null,
                'setDates' => false,
                'status' => null,
                'flag' => 'DISCO',
                'clearFlag' => false,
                'count' => null,
                'markdown' => null,
            ]
        );
    }

    public function writeInventoryExpiration(string $companyId, string $inventoryItemId, string $expirationDate)
    {
        return $this->writeRequest(
            'write-inventory',
            [
                'companyId' => $companyId,
                'inventoryItemId' => $inventoryItemId,
                'productId' => null,
                'locationId' => null,
                'departmentId' => null,
                'expiration' => $expirationDate,
                'closeDated' => null,
                'expiring' => null,
                'setDates' => true,
                'status' => null,
                'flag' => null,
                'clearFlag' => true,
                'count' => null,
                'markdown' => null,
            ]
        );
    }

    public function persistProduct(
        $companyId,
        $barcode,
        $description,
        $size,
        $createOnly = true
    ) {
        return $this->writeRequest(
            'persist-product',
            [
                'companyId' => $companyId,
                'barcode' => $barcode,
                'description' => $description,
                'size' => $size,
                'createOnly' => $createOnly,
            ]
        );
    }

    public function createUser(
        $companyId,
        $userId,
        $username,
        $email,
        $password,
        $first,
        $last,
        $role,
        $stores
    ) {
        return $this->writeRequest(
            'persist-user',
            [
                'companyId' => $companyId,
                'userId' => $userId,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'firstName' => $first,
                'lastName' => $last,
                'role' => $role,
                'stores' => $stores,
                'timezoneSetting' => 'America/Chicago',
                'dateChecker' => true,
                'requireReset' => true,
                'implementationScan' => 0,
                'resetScan' => 0,
                'overlayScan' => 0,
                'title' => '',
            ]
        );
    }

    public function updateInventoryLocation($companyId, $itemId, $storeId, $deptId, $aisle, $section, $shelf = '')
    {
        return $this->writeRequest(
            'update-inventory-location',
            [
                'companyId' => $companyId,
                'inventoryItemId' => $itemId,
                'storeId' => $storeId,
                'departmentId' => $deptId,
                'aisle' => $aisle,
                'section' => $section,
                'shelf' => $shelf,
            ]
        );
    }

    public function copyMetrics(string $companyId)
    {
        return $this->writeRequest('copy-metrics', [
            'company_id' => $companyId,
        ]);
    }

    public function createVendor(
        $barcode,
        $vendor,
        $companyId
    ) {
        return $this->writeRequest(
            'create-product-vendor',
            [
                'barcode' => $barcode,
                'vendor' => $vendor,
                'companyId' => $companyId,
            ]
        );
    }

    public function discontinueProduct($companyId, $storeId, $productId)
    {
        return $this->writeRequest(
            'discontinue-product',
            [
                'companyId' => $companyId,
                'storeId' => $storeId,
                'productId' => $productId,
            ]
        );
    }

    public function discontinueProductByBarcode($companyId, $storeId, $barcode)
    {
        return $this->writeRequest(
            'discontinue-product',
            [
                'companyId' => $companyId,
                'storeId' => $storeId,
                'barcode' => $barcode,
            ]
        );
    }

    public function triggerUpdateCounts(string $companyId)
    {
        $this->writeRequest(
            'trigger-calc-store-counts',
            [
                'companyId' => $companyId,
            ]
        );
    }

    public function validResponse($response): bool
    {
        return $response && ($response->status === 'ACCEPTED' || $response->status === 'FOUND');
    }

    private function writeRequest($service, $data)
    {
        if ($this->debugMode) {
            return new class {
                public $status = 'ACCEPTED';
            };
        } else {
            return $this->request($service, $data);
        }
    }

    private function request($service, $data, $timestamps = null)
    {
        if ($this->token === null) {
            $this->setToken();
        }

        $url = $this->url . 'api/' . $service;
        if ($timestamps == null) {
            $data['createdTimestamp'] = $data['sentTimestamp'] = date('Y-m-d');
        } else {
            $data['createdTimestamp'] = $data['sentTimestamp'] = $timestamps;
        }

        // Must be set for the API to use the correct database
        $data['admin_token'] = $this->adminToken;

        try {
            $response = Http::asJson()->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->post($url, $data);

            if ($response->status() == 200) {
                return json_decode($response->body());
            } else {
                Log::error("API request $service");
            }
        } catch (\Exception $e) {
            Log::error("API request $service");
            Log::error($e);
        }

        return null;
    }

    private function setToken()
    {
        $params = [
            'grant_type' => 'password',
            'client_id' => config('scraper.client_id'),
            'client_secret' => config('scraper.client_secret'),
            'username' => config('scraper.user'),
            'password' => config('scraper.pass'),
        ];

        $response = Http::withoutRedirecting()->post(config('scraper.url') . "oauth/token", $params);

        try {
            if ($response->status() == 200) {
                $body = json_decode($response->body());
                $this->token = $body->access_token;
            } else {
                Log::error("API token request failed");
                Log::error(json_encode($response));
                die();
            }
        } catch (\Exception $e) {
            Log::error("API token request failed");
            Log::error($e);
            die();
        }
    }
}
