<?php

namespace Modules\ProcurementImport\Services;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;

/**
 * Stock Service - จัดการ Stock ตาม Branch
 */
class StockService
{
    /**
     * Create or update stock for product
     */
    public function createOrUpdateStock(
        Product $product,
        Unit $unit,
        float $quantity,
        float $costPrice,
        float $salePrice,
        ?string $barcode,
        int $branchId
    ): ProductUnitQuantity {
        return ProductUnitQuantity::updateOrCreate(
            [
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'branch_id' => $branchId,
            ],
            [
                'quantity' => $quantity,
                'low_quantity' => 5,
                'sale_price' => $salePrice,
                'wholesale_price' => $salePrice * 0.9,
                'preview_url' => null,
                'barcode' => $barcode,
            ]
        );
    }
    
    /**
     * Get stock by product and branch
     */
    public function getStock(int $productId, int $branchId): ?ProductUnitQuantity
    {
        return ProductUnitQuantity::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();
    }
    
    /**
     * Update stock quantity
     */
    public function updateQuantity(ProductUnitQuantity $stock, float $quantity): bool
    {
        return $stock->update(['quantity' => $quantity]);
    }
}
