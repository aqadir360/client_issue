<?php
declare(strict_types=1);

namespace App\Objects;

use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\Session;
use Log;

class Api
{
    private $url;
    private $debugMode;
    private $token;

    public function __construct()
    {
        $this->url = config('scraper.url');
        $this->debugMode = config('scraper.debug_mode') === 'debug';
        $this->token = $this->setCommandApiToken();
    }

    public function fetchProduct($barcode, $companyId, $storeId = null)
    {
        return $this->request(
            'fetch-product',
            [
                'barcode' => $barcode,
                'storeId' => $storeId,
                'companyId' => $companyId,
            ]
        );
    }

    public function fetchAllInventory($companyId, $storeId, $notificationsOnly = false)
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
        string $storeId,
        string $aisle,
        string $section,
        string $deptId,
        string $shelf = ''
    ) {
        return $this->writeRequest(
            'implementation-scan',
            [
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

    public function writeInventoryDisco($inventoryItemId)
    {
        return $this->writeRequest(
            'write-inventory',
            [
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

    public function persistProduct(
        $barcode,
        $description,
        $size,
        $createOnly = true
    ) {
        return $this->writeRequest(
            'persist-product',
            [
                'barcode' => $barcode,
                'name' => $description,
                'size' => $size,
                'createOnly' => $createOnly,
            ]
        );
    }

    public function updateInventoryLocation($itemId, $storeId, $deptId, $aisle, $section, $shelf = '')
    {
        return $this->writeRequest(
            'update-inventory-location',
            [
                'inventoryItemId' => $itemId,
                'storeId' => $storeId,
                'departmentId' => $deptId,
                'aisle' => $aisle,
                'section' => $section,
                'shelf' => $shelf,
            ]
        );
    }

    public function updateInventoryDisco($itemId, $expiration, $prevStatus)
    {
        return $this->writeRequest(
            'update-inventory',
            [
                'inventoryItemId' => $itemId,
                'expiration' => $expiration,
                'prevStatus' => $prevStatus,
                'action' => 'ONSHELF',
                'flag' => 'DISCO',
            ]
        );
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

    public function discontinueProduct($storeId, $productId)
    {
        return $this->writeRequest(
            'discontinue-product',
            [
                'storeId' => $storeId,
                'productId' => $productId,
            ]
        );
    }

    public function discontinueProductByBarcode($storeId, $barcode)
    {
        return $this->writeRequest(
            'discontinue-product',
            [
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

    private function request($service, $data)
    {
        if ($this->token === null) {
            $this->token = $this->getApiToken();
        }

        $url = $this->url . 'api/' . $service;
        $data['createdTimestamp'] = $data['sentTimestamp'] = date('Y-m-d');

        $params = json_encode($data);

        try {
            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);
            curl_close($ch);

            if ($result === false) {
                Log::error('API Error', ['url' => $url, 'msg' => "No Response"]);
                return false;
            }

            return json_decode($result);
        } catch (Exception $e) {
            Log::error('API Error', ['url' => $url, 'msg' => $e->getMessage()]);
        }
    }

    private function setCommandApiToken()
    {
        $token = Session::get('access_token');

        if ($token !== null) {
            return $token;
        }

        $ch = curl_init($this->url . 'oauth/token');
        $params = [
            'grant_type' => 'password',
            'client_id' => config('scraper.client_id'),
            'client_secret' => config('scraper.client_secret'),
            'username' => config('scraper.user'),
            'password' => config('scraper.pass'),
        ];

        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        try {
            $output = json_decode($result);
            Session::put('access_token', $output->access_token);
            Session::save();
            return $output->access_token;
        } catch (Exception $e) {
            return null;
        }
    }

    // Uses logged in user token or gets scraper user token
    private function getApiToken()
    {
        return Session::get('access_token');
    }
}
