<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class ProductUsageGuideTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    public function test_help_is_available_on_create_and_edit_without_changing_product_data(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole(), 'web' );
        $product = Product::whereNotNull( 'unit_group' )->firstOrFail();
        $before = $product->getRawOriginal();
        foreach ( ['/dashboard/products/create', '/dashboard/products/edit/' . $product->id] as $url ) {
            $response = $this->get( $url )->assertOk();
            foreach ( ['วิธีใช้งานและแก้ไขข้อมูลสินค้า', '1. ข้อมูลระบุตัวสินค้า', '2. หน่วย', '3. วันหมดอายุ', '4. ภาษี', '5. รูปภาพ', 'การเพิ่มชื่อหน่วยอย่างเดียวไม่ได้เปิดการแปลงอัตโนมัติ'] as $text ) {
                $response->assertSee( $text );
            }
            $html = $response->getContent();
            $this->assertGreaterThan( strpos( $html, '</ns-manage-products>' ), strpos( $html, 'id="product-guide-heading"' ) );
        }
        $this->assertSame( $before, $product->fresh()->getRawOriginal() );
    }
}
