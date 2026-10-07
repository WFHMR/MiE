<?php
namespace Tests\Feature;
use App\Models\Product;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
class ProductFormApiAvailabilityTest extends TestCase
{
    use WithAuthentication;
    public function test_product_create_and_edit_api_return_form_for_admin(): void
    {
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $product = Product::whereNotNull('unit_group')->firstOrFail();
        foreach (['/api/crud/ns.products/form-config', '/api/crud/ns.products/form-config/'.$product->id] as $url) {
            $this->getJson($url)->assertOk()->assertJsonStructure(['form'=>['main', 'variations']]);
        }
    }
}
