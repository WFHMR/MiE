<?php

namespace Modules\ProcurementImport\Services;

use App\Models\Unit;
use App\Models\UnitGroup;

/**
 * Unit Service - จัดการ Multi-Unit (Pack, Piece)
 */
class UnitService
{
    protected array $cache = [];
    
    /**
     * Create unit group with base and pack units
     * 
     * @param string $productName Product name for naming
     * @param string $packInfo Pack info from CSV (e.g., "12", "50 กรัม*25")
     * @param string $saleUnit Sale unit name
     * @param string $stockUnit Stock unit name
     * @param int $branchId Branch ID
     * @return array ['group' => UnitGroup, 'base_unit' => Unit, 'pack_unit' => Unit|null]
     */
    public function createUnitGroup(
        string $productName,
        string $packInfo,
        string $saleUnit,
        string $stockUnit,
        int $branchId
    ): array {
        // Parse pack quantity
        $packQuantity = $this->parsePackQuantity($packInfo);
        
        // Create unit group
        $unitGroup = UnitGroup::create([
            'name' => "UG-" . substr($productName, 0, 30),
            'branch_id' => $branchId,
            'author' => auth()->id() ?? 1,
        ]);
        
        // Create base unit (piece/ชิ้น)
        $baseUnit = Unit::create([
            'name' => $stockUnit ?: 'ชิ้น',
            'identifier' => 'piece',
            'value' => 1,
            'base_unit' => true,
            'group_id' => $unitGroup->id,
            'branch_id' => $branchId,
            'author' => auth()->id() ?? 1,
        ]);
        
        // Create pack unit if pack quantity exists
        $packUnit = null;
        if ($packQuantity > 1) {
            $packUnit = Unit::create([
                'name' => $saleUnit ?: 'แพ็ค',
                'identifier' => 'pack',
                'value' => $packQuantity,
                'base_unit' => false,
                'group_id' => $unitGroup->id,
                'branch_id' => $branchId,
                'author' => auth()->id() ?? 1,
            ]);
        }
        
        return [
            'group' => $unitGroup,
            'base_unit' => $baseUnit,
            'pack_unit' => $packUnit,
        ];
    }
    
    /**
     * Parse pack quantity from pack info
     * 
     * Examples:
     * "12" → 12
     * "50 กรัม*25" → 25
     * "10 ใบ" → 10
     * "คละเบอร์ แบบ" → 1
     */
    protected function parsePackQuantity(string $packInfo): int
    {
        $packInfo = trim($packInfo);
        
        if (empty($packInfo)) {
            return 1;
        }
        
        // Try to find numbers
        if (preg_match('/\d+/', $packInfo, $matches)) {
            // If contains "*", get the number after it
            if (strpos($packInfo, '*') !== false) {
                $parts = explode('*', $packInfo);
                return (int) trim($parts[1] ?? 1);
            }
            
            // Otherwise, get the first number
            return (int) $matches[0];
        }
        
        return 1;
    }
    
    /**
     * Get or create default unit group for simple products
     */
    public function getOrCreateDefaultUnitGroup(string $unitName, int $branchId): array
    {
        $cacheKey = "{$branchId}_{$unitName}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Find existing or create new
        $unitGroup = UnitGroup::firstOrCreate(
            [
                'name' => "UG-{$unitName}",
                'branch_id' => $branchId,
            ],
            [
                'author' => auth()->id() ?? 1,
            ]
        );
        
        $unit = Unit::firstOrCreate(
            [
                'name' => $unitName,
                'group_id' => $unitGroup->id,
                'branch_id' => $branchId,
            ],
            [
                'identifier' => strtolower($unitName),
                'value' => 1,
                'base_unit' => true,
                'author' => auth()->id() ?? 1,
            ]
        );
        
        $result = [
            'group' => $unitGroup,
            'base_unit' => $unit,
            'pack_unit' => null,
        ];
        
        $this->cache[$cacheKey] = $result;
        
        return $result;
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
