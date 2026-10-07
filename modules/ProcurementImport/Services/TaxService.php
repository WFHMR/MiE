<?php

namespace Modules\ProcurementImport\Services;

/**
 * Tax Service - คำนวณ VAT แบบ Reverse
 * Thailand VAT = 7%
 */
class TaxService
{
    protected float $vatRate = 0.07; // 7%
    
    /**
     * Reverse calculate VAT from sale price (inc VAT)
     * 
     * Sale Price (Inc VAT) = 1,130.00 บาท
     * → Net Price = 1,130.00 / 1.07 = 1,056.07 บาท
     * → VAT Amount = 73.93 บาท
     * 
     * @param float $salePriceIncVAT ราคาขายรวม VAT
     * @return array
     */
    public function reverseCalculateVAT(float $salePriceIncVAT): array
    {
        $netPrice = $salePriceIncVAT / (1 + $this->vatRate);
        $vatAmount = $salePriceIncVAT - $netPrice;
        
        return [
            'sale_price' => round($netPrice, 2),              // ราคาขายไม่รวม VAT
            'sale_price_inc_vat' => $salePriceIncVAT,         // ราคาขายรวม VAT
            'vat_amount' => round($vatAmount, 2),             // จำนวน VAT
            'vat_rate' => $this->vatRate * 100,               // 7%
        ];
    }
    
    /**
     * Calculate VAT from net price
     * 
     * Net Price = 1,000 บาท
     * → VAT Amount = 1,000 × 0.07 = 70 บาท
     * → Sale Price (Inc VAT) = 1,070 บาท
     */
    public function calculateVAT(float $netPrice): array
    {
        $vatAmount = $netPrice * $this->vatRate;
        $salePriceIncVAT = $netPrice + $vatAmount;
        
        return [
            'sale_price' => round($netPrice, 2),
            'sale_price_inc_vat' => round($salePriceIncVAT, 2),
            'vat_amount' => round($vatAmount, 2),
            'vat_rate' => $this->vatRate * 100,
        ];
    }
    
    /**
     * Set custom VAT rate
     */
    public function setVatRate(float $rate): self
    {
        $this->vatRate = $rate;
        return $this;
    }
}
