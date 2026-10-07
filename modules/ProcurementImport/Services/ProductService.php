<?php

namespace Modules\ProcurementImport\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitGroup;

/**
 * Product Service - จัดการสินค้า
 */
class ProductService
{
    /**
     * Create or update product
     */
    public function createOrUpdate(array $data, int $branchId): Product
    {
        $product = Product::updateOrCreate(
            [
                'sku' => $data['sku'],
                'branch_id' => $branchId,
            ],
            [
                'name' => $data['name'],
                'barcode' => $data['barcode'] ?? null,
                'category_id' => $data['category_id'],
                'unit_group_id' => $data['unit_group_id'],
                'type' => 'product',
                'status' => 'available',
                'stock_management' => 'enabled',
                'author' => auth()->id() ?? 1,
            ]
        );
        
        return $product;
    }
    
    /**
     * Check if product exists by SKU
     */
    public function existsBySku(string $sku, int $branchId): bool
    {
        return Product::where('sku', $sku)
            ->where('branch_id', $branchId)
            ->exists();
    }
    
    /**
     * Get product by SKU
     */
    public function getBySku(string $sku, int $branchId): ?Product
    {
        return Product::where('sku', $sku)
            ->where('branch_id', $branchId)
            ->first();
    }
}
