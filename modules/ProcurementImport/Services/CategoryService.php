<?php

namespace Modules\ProcurementImport\Services;

use App\Models\ProductCategory;

/**
 * Category Service - จัดการ Parent-Child Categories
 */
class CategoryService
{
    protected array $cache = [];
    
    /**
     * Category name mapping
     */
    protected array $categoryMap = [
        '01' => 'อาหารและเครื่องดื่ม',
        '02' => 'บรรจุภัณฑ์',
        '03' => 'อุปกรณ์ร้าน',
        '04' => 'ช็อกโกแลตและขนม',
        '05' => 'วัตถุดิบทำเบเกอรี่',
        '06' => 'ของใช้ส่วนตัว',
        '07' => 'เครื่องใช้ไฟฟ้า',
        '08' => 'เครื่องเขียน',
        '09' => 'อื่นๆ',
    ];
    
    /**
     * Sub-category mapping (parent-child)
     */
    protected array $subCategoryMap = [
        '01-05' => 'เยลลี่และวุ้น',
        '01-09' => 'น้ำอัดลม',
        '01-21' => 'เครื่องปรุง',
        '02-01' => 'ถุงพลาสติก',
        '02-05' => 'กล่องและห่อ',
        '03-01' => 'อุปกรณ์ครัว',
        '04-01' => 'ช็อกโกแลต',
        '04-05' => 'ขนมและสแน็ค',
        '05-01' => 'แป้งและน้ำตาล',
        '05-05' => 'สีผสมอาหาร',
    ];
    
    /**
     * Get or create category (parent-child)
     */
    public function getOrCreate(string $parentCode, string $childCode, int $branchId): ProductCategory
    {
        $cacheKey = "{$branchId}_{$parentCode}_{$childCode}";
        
        // Check cache
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Create or get parent
        $parent = $this->getOrCreateParent($parentCode, $branchId);
        
        // Create or get child
        $child = $this->getOrCreateChild($parent, $childCode, $branchId);
        
        // Cache it
        $this->cache[$cacheKey] = $child;
        
        return $child;
    }
    
    /**
     * Create or get parent category
     */
    protected function getOrCreateParent(string $code, int $branchId): ProductCategory
    {
        $cacheKey = "{$branchId}_parent_{$code}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $name = $this->categoryMap[$code] ?? "หมวดหมู่ {$code}";
        
        $parent = ProductCategory::firstOrCreate(
            [
                'code' => $code,
                'parent_id' => null,
                'branch_id' => $branchId,
            ],
            [
                'name' => $name,
                'author' => auth()->id() ?? 1,
            ]
        );
        
        $this->cache[$cacheKey] = $parent;
        
        return $parent;
    }
    
    /**
     * Create or get child category
     */
    protected function getOrCreateChild(ProductCategory $parent, string $code, int $branchId): ProductCategory
    {
        $mapKey = "{$parent->code}-{$code}";
        $name = $this->subCategoryMap[$mapKey] ?? "หมวดหมู่ย่อย {$code}";
        
        return ProductCategory::firstOrCreate(
            [
                'code' => $code,
                'parent_id' => $parent->id,
                'branch_id' => $branchId,
            ],
            [
                'name' => $name,
                'author' => auth()->id() ?? 1,
            ]
        );
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
