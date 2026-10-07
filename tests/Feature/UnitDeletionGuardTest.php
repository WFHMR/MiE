<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A unit that a product still prices or stocks something in must not be deletable.
 *
 * Unit declared its dependency in a method called setDepedencies, missing the second "n".
 * NsDependable::delete() finds the declaration with method_exists( $this, 'setDependencies' ),
 * so the name never matched and the guard never ran. Deleting a unit left every
 * products_unit_quantities row pointing at a unit id that no longer existed, which is what
 * makes a product show a blank unit and no price at the till.
 *
 * The declaration itself was already correct, so these tests are about the method being
 * reachable at all, plus the refusal naming the product so the user can act on it.
 */
class UnitDeletionGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The typo is the whole bug, so it gets an assertion of its own. A misspelled declaration
     * is invisible: nothing throws, the guard is simply absent.
     */
    public function test_the_dependency_declaration_is_spelled_the_way_the_trait_looks_it_up(): void
    {
        $this->assertTrue(
            method_exists( Unit::class, 'setDependencies' ),
            'NsDependable looks up setDependencies with method_exists. Any other spelling disables the guard silently.'
        );

        $this->assertFalse(
            method_exists( Unit::class, 'setDepedencies' ),
            'The misspelled method is still present, so the correct one may be a duplicate rather than a rename.'
        );
    }

    public function test_the_declaration_points_at_the_column_that_links_the_two_tables(): void
    {
        $declared = ( new Unit )->setDependencies();

        $this->assertArrayHasKey(
            ProductUnitQuantity::class,
            $declared,
            'A unit is held by products_unit_quantities rows; that is the dependency to check.'
        );

        $indexes = $declared[ ProductUnitQuantity::class ];

        $this->assertSame( 'unit_id', $indexes[ 'foreign_index' ] );
        $this->assertSame( 'id', $indexes[ 'local_index' ] );

        /**
         * The related block is what turns "something depends on this" into "this product
         * depends on this", which is the only form the user can act on.
         */
        $this->assertSame( Product::class, $indexes[ 'related' ][ 'model' ] );
        $this->assertSame( 'product_id', $indexes[ 'related' ][ 'foreign_index' ] );
    }

    public function test_a_unit_held_by_a_product_cannot_be_deleted_and_the_product_is_named(): void
    {
        [ $unit, $product ] = $this->unitHeldByAProduct();

        try {
            $unit->delete();

            $this->fail( 'The unit was deleted while a product still priced something in it.' );
        } catch ( \Throwable $e ) {
            $this->assertStringContainsString(
                $product->name,
                $e->getMessage(),
                'The refusal has to name the product, or the user has nothing to go and fix.'
            );

            /**
             * These are the two placeholders the message falls back to when the lookup finds
             * nothing. Either one means the declaration is wired to the wrong column.
             */
            $this->assertStringNotContainsString( 'Non-existent Item', $e->getMessage() );
            $this->assertStringNotContainsString( 'Undefined Item', $e->getMessage() );
        }

        $this->assertNotNull(
            Unit::find( $unit->id ),
            'The unit is gone even though the delete was refused.'
        );
    }

    public function test_a_unit_nothing_holds_can_still_be_deleted(): void
    {
        /**
         * Positive control. Without it this suite would pass just as happily against a guard
         * that refuses every deletion, which would block the shop from tidying up its units.
         */
        [ $unit, $product ] = $this->unitHeldByAProduct();

        ProductUnitQuantity::where( 'unit_id', $unit->id )->delete();

        $unit->delete();

        $this->assertNull(
            Unit::find( $unit->id ),
            'Nothing holds this unit any more, so deleting it has to be allowed.'
        );
    }

    /**
     * Builds a unit, a product, and the products_unit_quantities row that ties them together.
     *
     * Columns are set explicitly rather than through a factory because several on these tables
     * are NOT NULL without a default, which a factory would not reveal.
     *
     * @return array{0: Unit, 1: Product}
     */
    private function unitHeldByAProduct(): array
    {
        $author = User::first();
        $suffix = strtoupper( substr( uniqid(), -6 ) );

        $group = new UnitGroup;
        $group->name = 'กลุ่มหน่วยทดสอบการลบ ' . $suffix;
        $group->author_id = $author->id;
        $group->save();

        $unit = new Unit;
        $unit->name = 'หน่วยทดสอบการลบ ' . $suffix;
        $unit->identifier = 'test-delete-unit-' . strtolower( $suffix );
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->group_id = $group->id;
        $unit->author_id = $author->id;
        $unit->save();

        $product = new Product;
        $product->name = 'สินค้าทดสอบการลบหน่วย ' . $suffix;
        $product->sku = 'TDU-' . $suffix;
        $product->barcode = 'TDU' . $suffix;
        $product->barcode_type = 'ean13';
        $product->type = 'materialized';
        $product->status = 'available';
        $product->stock_management = 'enabled';
        $product->author_id = $author->id;
        $product->unit_group = $group->id;
        $product->save();

        /**
         * This table carries no author_id. Only quantity is NOT NULL without a default;
         * everything else here is set so the row resembles a real priced unit.
         */
        $quantity = new ProductUnitQuantity;
        $quantity->product_id = $product->id;
        $quantity->unit_id = $unit->id;
        $quantity->quantity = 5;
        $quantity->sale_price = 10;
        $quantity->save();

        return [ $unit, $product ];
    }
}
