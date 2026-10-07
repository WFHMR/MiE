<?php

namespace Modules\ProcurementImport\Services;

use App\Models\Provider;
use Illuminate\Support\Facades\Cache;

/**
 * Supplier Service - จัดการ Supplier/Provider
 */
class SupplierService
{
    protected array $cache = [];
    
    /**
     * Get or create supplier
     * 
     * @param string $code Supplier code (e.g., "SP520002", "00223")
     * @param string|null $name Supplier name (optional)
     * @param int $branchId Branch ID
     * @return Provider
     */
    public function getOrCreate(string $code, ?string $name, int $branchId): Provider
    {
        $cacheKey = "{$branchId}_{$code}";
        
        // Check memory cache
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Parse supplier info
        $supplierData = $this->parseSupplier($code, $name);
        
        // Find or create
        $provider = Provider::firstOrCreate(
            [
                'code' => $supplierData['code'],
                'branch_id' => $branchId,
            ],
            [
                'name' => $supplierData['name'],
                'description' => 'Auto-created from CSV import',
                'email' => null,
                'phone' => null,
                'address' => null,
                'author' => auth()->id() ?? 1,
            ]
        );
        
        // Cache it
        $this->cache[$cacheKey] = $provider;
        
        return $provider;
    }
    
    /**
     * Parse supplier column combinations
     * 
     * Case 1: "SP520002" + "บจก.บทรชิลดส์ ฟูดส์"
     *   → code: "SP520002", name: "บจก.บทรชิลดส์..."
     * 
     * Case 2: "00223" + "" (empty name)
     *   → code: "00223", name: "Supplier 00223"
     */
    protected function parseSupplier(string $code, ?string $name): array
    {
        $code = trim($code);
        $name = trim($name ?? '');
        
        // If name is empty, generate default name
        if (empty($name)) {
            $name = "Supplier {$code}";
        }
        
        return [
            'code' => $code,
            'name' => $name,
        ];
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
    
    /**
     * Get all cached suppliers
     */
    public function getCached(): array
    {
        return $this->cache;
    }
}
