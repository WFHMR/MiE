<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Updating a product through the API.
 *
 * This file sat in tests/Feature without being listed in phpunit.xml, so it had never run. Three things
 * were wrong with it, all of which only show once it does run:
 *
 * 1. It asked for unit group 2 by number. No fixture guarantees that id exists, and in this one it does
 *    not, so UnitGroup::find( 2 ) returned null and the test died reading ->units on nothing.
 *
 * 2. It picked its unit with ->units->random()->first(). random() already returns one unit; calling
 *    first() on that model forwards to a fresh query and returns the first unit in the whole table,
 *    which need not belong to the group being sent. The request then described a unit from one group
 *    under the id of another.
 *
 * 3. It carried no isolation trait, so it renamed a real product in the shared fixture to "Sample
 *    Product" and stamped barcode "quassas" onto it permanently. Anything running afterwards that read
 *    that product saw the test's data.
 *
 * 4. It asked for category 1 by number, the same mistake as the unit group above. The fixture's lowest
 *    category id is 4, so ProductService::update() rejected the request with "The category to which the
 *    product is attached doesn't exists or has been deleted" and the test could only ever return 500.
 *    The category is now looked up the way WithProductTest looks it up — a sub-category or a root-level
 *    one, never a parent that holds children.
 */
class UpdateProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_product()
    {
        Sanctum::actingAs(
            Role::namespace( 'admin' )->users->first(),
            [ '*' ]
        );

        $product = Product::firstOrFail();

        /** A group that actually holds units, found rather than assumed. */
        $unitGroup = UnitGroup::whereHas( 'units' )->firstOrFail();

        $unit = Unit::where( 'group_id', $unitGroup->id )->firstOrFail();

        /** A category products may actually be attached to, found rather than assumed. */
        $category = ProductCategory::where( 'parent_id', '>', 0 )
            ->orWhere( 'parent_id', null )
            ->firstOrFail();

        $response = $this
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', '/api/products/' . $product->id, [
                'name' => 'Sample Product',
                'variations' => [
                    [
                        '$primary' => true,
                        'expiracy' => [
                            'expires' => 0,
                            'on_expiration' => 'prevent_sales',
                        ],
                        'identification' => [
                            /**
                             * The original value was "quassas" with type ean13. EAN13 carries digits
                             * only, so the barcode writer refused it and the request could never have
                             * succeeded.
                             *
                             * Digits alone are not enough either: the save path appends a variation
                             * suffix, producing "885000012345-1", and EAN13 rejects the hyphen. So EAN13
                             * cannot be used through this path at all — noted in PENDING-WORK-RESUME.md
                             * §16 as its own question, since whether a variation barcode should be an
                             * EAN13 is a product decision and not one to settle inside a test.
                             *
                             * code128 is what every importer in this codebase writes, and it accepts the
                             * suffix, so the test can exercise the update rather than the barcode format.
                             */
                            'barcode' => '885000012345',
                            'barcode_type' => 'code128',
                            'category_id' => $category->id,
                            'description' => __( 'Created via tests' ),
                            'product_type' => 'product',
                            'sku' => 'sample-sku',
                            'status' => 'available',
                            'stock_management' => 'enabled',

                            /**
                             * Required by the request and absent from this file, because the field was
                             * added after it was written and nothing ran it to notice.
                             */
                            'type' => Product::TYPE_MATERIALIZED,
                        ],
                        'images' => [],
                        'taxes' => [
                            'tax_group_id' => TaxGroup::firstOrFail()->id,
                            'tax_type' => 'exclusive',
                        ],
                        'units' => [
                            'selling_group' => [
                                [
                                    'sale_price' => 10,
                                    'sale_price_edit' => 10,
                                    'wholesale_price' => 9.55,
                                    'wholesale_price_edit' => 9.55,
                                    'unit_id' => $unit->id,
                                ],
                            ],
                            'unit_group' => $unitGroup->id,
                        ],
                    ],
                ],
            ] );

        $response->assertStatus( 200 );

        $this->assertSame(
            'Sample Product',
            (string) $product->fresh()->name,
            'The update reported success without changing the product.'
        );
    }
}
