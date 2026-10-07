<?php

namespace Tests\Feature;

use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The purchase desk's whole day, walked end to end through the screens and endpoints it actually uses.
 *
 * The document fields, the list and the printable document are each pinned by
 * ProcurementDocumentFieldsTest. What was never pinned is the *journey*: raise a purchase with goods on
 * it, find it in the list, open it, change it, save it again, print it, settle it, and throw it away.
 * Every one of those is a separate endpoint, and a purchase that can be created but not re-saved is no
 * use to a shop.
 *
 * Everything is built here rather than taken from the fixture, because the shop's own catalogue was
 * deleted and re-imported once already and a test that leans on whatever rows happen to exist passes
 * or fails depending on what ran before it.
 */
class ProcurementWorkflowTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private function givenProvider(): Provider
    {
        $provider = new Provider;
        $provider->first_name = 'หจก. ผู้ขายสำหรับชุดทดสอบเส้นทาง';
        $provider->code = 'SP-WF-' . random_int( 10000, 99999 );
        $provider->email = 'wf' . random_int( 10000, 99999 ) . '@test.invalid';
        $provider->author_id = 1;
        $provider->save();

        return $provider;
    }

    /**
     * A product that holds stock on one unit, built the long way because a purchase line needs a unit
     * quantity row to write its figure onto.
     *
     * @return array{product: Product, unit: Unit}
     */
    private function givenProduct(): array
    {
        $group = new UnitGroup;
        $group->name = 'กลุ่มหน่วยทดสอบเส้นทาง ' . random_int( 1000, 9999 );
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = 'ชิ้น';
        $unit->identifier = 'wf-piece-' . random_int( 1000, 9999 );
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->group_id = $group->id;
        $unit->author_id = 1;
        $unit->save();

        $product = new Product;
        $product->name = 'สินค้าทดสอบเส้นทางจัดซื้อ ' . random_int( 1000, 9999 );
        $product->sku = 'WF-' . random_int( 100000, 999999 );
        $product->barcode = (string) random_int( 8600000000000, 8699999999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_on_base_unit = true;
        $product->author_id = 1;
        $product->save();

        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->type = 'product';
        $row->visible = true;
        $row->is_weighable = false;
        $row->low_quantity = 0;
        $row->stock_alert_enabled = false;
        $row->quantity = 0;
        foreach ( [ 'sale_price', 'sale_price_edit', 'sale_price_gross', 'sale_price_net', 'wholesale_price', 'wholesale_price_edit', 'wholesale_price_gross', 'wholesale_price_net' ] as $field ) {
            $row->$field = 20;
        }
        $row->sale_price_tax = 0;
        $row->wholesale_price_tax = 0;
        $row->cogs = 10;
        $row->save();

        return [ 'product' => $product, 'unit' => $unit ];
    }

    /**
     * @param  array<string, mixed>             $general
     * @param  array<int, array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function raisePurchase( Provider $provider, array $products, array $general = [] ): array
    {
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/procurements', [
                'name' => 'ใบรับของเส้นทางทดสอบ ' . random_int( 10000, 99999 ),
                'general' => array_merge( [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => 1,
                ], $general ),
                'products' => $products,
            ] );

        $response->assertOk();
        $response->assertJsonPath( 'status', 'success' );

        return $response->json();
    }

    /**
     * One line of a purchase, shaped the way the screen sends it.
     *
     * The id matters. ns-procurement.vue submits `product.procurement`, which for a row it loaded is
     * the saved line including its id, and saveProducts() uses that id to decide between updating the
     * line and creating another one. A test that leaves it out is testing a request the screen never
     * makes, and would report a duplicate line as an update that silently failed.
     *
     * @return array<string, mixed>
     */
    private function line( int $productId, int $unitId, float $quantity, float $unitPrice, ?int $id = null ): array
    {
        $line = [
            'product_id' => $productId,
            'unit_id' => $unitId,
            'quantity' => $quantity,
            'gross_purchase_price' => $unitPrice,
            'net_purchase_price' => $unitPrice,
            'purchase_price' => $unitPrice,
            'total_purchase_price' => $unitPrice * $quantity,
            'tax_group_id' => 0,
            'tax_type' => 'inclusive',
            'tax_value' => 0,
        ];

        if ( $id !== null ) {
            $line[ 'id' ] = $id;
        }

        return $line;
    }

    public function test_the_create_screen_opens(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/create' )
            ->assertOk();
    }

    /**
     * Raising a purchase with goods on it, carrying every document field, is the first thing the desk
     * does. Saving the lines and saving the paperwork are two different code paths and both have to
     * land in the same record.
     */
    public function test_a_purchase_saves_its_goods_and_its_paperwork_together(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 10, 25 ) ],
            [
                'purchase_order_number' => 'PO-WF-0001',
                'delivery_note_number' => 'DN-WF-0001',
                'delivery_note_date' => '2026-09-18',
                'billing_note_number' => 'BN-WF-0001',
                'billing_note_date' => '2026-09-19',
                'contact_person' => 'คุณสมชาย 081-234-5678',
                'credit_days' => 30,
                'invoice_date' => '2026-09-18',
                'invoice_reference' => 'IV-WF-0001',
            ]
        );

        $procurement = Procurement::with( 'products' )->findOrFail( $result[ 'data' ][ 'procurement' ][ 'id' ] );

        $this->assertSame( 'PO-WF-0001', $procurement->purchase_order_number );
        $this->assertSame( 'DN-WF-0001', $procurement->delivery_note_number );
        $this->assertSame( 'BN-WF-0001', $procurement->billing_note_number );
        $this->assertSame( 'คุณสมชาย 081-234-5678', $procurement->contact_person );
        $this->assertSame( 30, (int) $procurement->credit_days );
        $this->assertStringStartsWith( '2026-09-18', (string) $procurement->delivery_note_date );
        $this->assertStringStartsWith( '2026-09-19', (string) $procurement->billing_note_date );

        /**
         * The due date is worked out from the invoice date plus the credit term, which is the whole
         * point of recording the term: accounts should not have to count days on a calendar.
         */
        $this->assertStringStartsWith( '2026-10-18', (string) $procurement->due_date, 'The due date was not worked out from the invoice date and the credit term.' );

        $this->assertCount( 1, $procurement->products, 'The goods on the purchase were not saved.' );
        $this->assertSame( 10.0, (float) $procurement->products->first()->quantity );
        $this->assertSame( 250.0, (float) $procurement->products->first()->total_purchase_price );
        $this->assertSame( 250.0, (float) $procurement->cost, 'The purchase total does not match the goods on it.' );
        $this->assertSame( 1, (int) $procurement->total_items );
    }

    /**
     * The list is what the desk actually looks at, so it is asked for through the endpoint the page
     * calls rather than by reading the crud class.
     */
    public function test_the_new_purchase_appears_on_the_list_endpoint(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 4, 15 ) ],
            [ 'billing_note_number' => 'BN-WF-LIST', 'purchase_order_number' => 'PO-WF-LIST' ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        /**
         * The crud resource is registered plural, `ns.procurements`, while the form behind the same
         * screen is registered singular, `ns.procurement`. Two registries, two names.
         */
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/crud/ns.procurements?per_page=100' );

        $response->assertOk();

        $rows = collect( $response->json( 'data' ) )->keyBy( 'id' );

        $this->assertArrayHasKey( $id, $rows->all(), 'The purchase just raised is not on the list.' );

        $row = $rows[ $id ];

        $this->assertStringContainsString( 'BN-WF-LIST', (string) $row[ 'billing_note_number' ] );
        $this->assertStringContainsString( 'PO-WF-LIST', (string) $row[ 'purchase_order_number' ] );
        $this->assertStringContainsString( (string) $provider->code, (string) $row[ 'providers_code' ] );
        $this->assertStringContainsString( 'ผู้ขายสำหรับชุดทดสอบเส้นทาง', (string) $row[ 'providers_first_name' ] );
    }

    /**
     * Opening a purchase, changing its paperwork and saving it again. The billing note usually arrives
     * days after the goods, so this is the path the accounts desk takes every time, and a purchase that
     * cannot be re-saved cannot be paid against.
     */
    public function test_a_purchase_can_be_opened_changed_and_saved_again(): void
    {
        $user = $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 5, 20 ) ],
            [ 'delivery_note_number' => 'DN-WF-EDIT', 'invoice_date' => '2026-09-10' ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/edit/' . $id )
            ->assertOk();

        $this->attemptAuthenticate();

        $form = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/forms/ns.procurement/' . $id );

        $form->assertOk();

        $fields = collect( $form->json( 'tabs.general.fields' ) )->keyBy( 'name' );
        $this->assertSame( 'DN-WF-EDIT', $fields[ 'delivery_note_number' ][ 'value' ] ?? null, 'The edit screen did not hand back the saved delivery note.' );

        $lineId = (int) Procurement::with( 'products' )->findOrFail( $id )->products->first()->id;

        /**
         * The billing note turning up later is the change this has to survive. The quantity is raised
         * at the same time, because the two are saved by different parts of the same request.
         */
        $update = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/procurements/' . $id, [
                'name' => 'ใบรับของเส้นทางทดสอบ แก้ไขแล้ว',
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => $user?->id ?? 1,
                    'delivery_note_number' => 'DN-WF-EDIT',
                    'billing_note_number' => 'BN-WF-LATER',
                    'billing_note_date' => '2026-09-25',
                    'credit_days' => 15,
                    'invoice_date' => '2026-09-10',
                ],
                'products' => [ $this->line( $product->id, $unit->id, 8, 20, $lineId ) ],
            ] );

        $update->assertOk();
        $update->assertJsonPath( 'status', 'success' );

        $procurement = Procurement::with( 'products' )->findOrFail( $id );

        $this->assertCount( 1, $procurement->products, 'Saving the purchase again added a second copy of the line instead of updating it.' );
        $this->assertSame( 'ใบรับของเส้นทางทดสอบ แก้ไขแล้ว', $procurement->name );
        $this->assertSame( 'BN-WF-LATER', $procurement->billing_note_number, 'The billing note added on the second save was lost.' );
        $this->assertSame( 15, (int) $procurement->credit_days );
        $this->assertStringStartsWith( '2026-09-25', (string) $procurement->billing_note_date );
        $this->assertStringStartsWith( '2026-09-25', (string) $procurement->due_date, 'The due date was not recalculated from the new credit term.' );
        $this->assertSame( 8.0, (float) $procurement->products->first()->quantity, 'The corrected quantity was not saved.' );
        $this->assertSame( 160.0, (float) $procurement->cost, 'The total was not recalculated after the quantity changed.' );
    }

    /**
     * Taking a line off a purchase and saving has to take it off the record and out of the total.
     *
     * The screen removes a row by dropping it out of its own list and nothing more, so the saved
     * request simply arrives without it. edit() used to update the lines it was given and leave the
     * rest alone, which meant the removed goods came back on the printed document and in the figure
     * accounts was asked to pay. Money, not cosmetics.
     */
    public function test_a_line_taken_off_the_purchase_is_really_gone(): void
    {
        $user = $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $keep, 'unit' => $keepUnit ] = $this->givenProduct();
        [ 'product' => $drop, 'unit' => $dropUnit ] = $this->givenProduct();

        $result = $this->raisePurchase( $provider, [
            $this->line( $keep->id, $keepUnit->id, 2, 100 ),
            $this->line( $drop->id, $dropUnit->id, 3, 100 ),
        ] );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];
        $procurement = Procurement::with( 'products' )->findOrFail( $id );

        $this->assertCount( 2, $procurement->products );
        $this->assertSame( 500.0, (float) $procurement->cost );

        $keptLineId = (int) $procurement->products->firstWhere( 'product_id', $keep->id )->id;

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/procurements/' . $id, [
                'name' => 'ใบรับของ ถอดรายการออกหนึ่งตัว',
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => $user?->id ?? 1,
                ],
                'products' => [ $this->line( $keep->id, $keepUnit->id, 2, 100, $keptLineId ) ],
            ] )
            ->assertOk();

        $procurement = Procurement::with( 'products' )->findOrFail( $id );

        $this->assertCount( 1, $procurement->products, 'The line removed on screen is still on the purchase.' );
        $this->assertSame( $keep->id, (int) $procurement->products->first()->product_id, 'The wrong line was removed.' );
        $this->assertSame( 0, ProcurementProduct::where( 'procurement_id', $id )->where( 'product_id', $drop->id )->count(), 'The removed line is still in the table.' );
        $this->assertSame( 200.0, (float) $procurement->cost, 'The total still includes goods that were taken off the purchase.' );
        $this->assertSame( 1, (int) $procurement->total_items );
    }

    /**
     * A save that fails must not have thrown anything away on its way to failing.
     *
     * Removing the lines the payload dropped and saving the ones it kept are two writes with no
     * transaction around them. saveProducts() throws on a line whose product no longer exists — the
     * state every line of the shop's earliest purchase is in — so if the removals ran first, a save
     * that failed halfway would already have deleted goods that are still on the purchase.
     */
    public function test_a_failed_save_does_not_delete_the_lines_it_was_asked_to_remove(): void
    {
        $user = $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $vanishing, 'unit' => $vanishingUnit ] = $this->givenProduct();
        [ 'product' => $drop, 'unit' => $dropUnit ] = $this->givenProduct();

        $result = $this->raisePurchase( $provider, [
            $this->line( $vanishing->id, $vanishingUnit->id, 2, 100 ),
            $this->line( $drop->id, $dropUnit->id, 3, 100 ),
        ] );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];
        $procurement = Procurement::with( 'products' )->findOrFail( $id );
        $keptLineId = (int) $procurement->products->firstWhere( 'product_id', $vanishing->id )->id;
        $vanishingId = $vanishing->id;

        /**
         * The catalogue loses the product the payload keeps, which is what makes the save fail.
         */
        Product::where( 'id', $vanishingId )->delete();

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/procurements/' . $id, [
                'name' => 'ใบรับของ บันทึกไม่สำเร็จ',
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => $user?->id ?? 1,
                ],
                'products' => [ $this->line( $vanishingId, $vanishingUnit->id, 2, 100, $keptLineId ) ],
            ] );

        $this->assertSame(
            2,
            ProcurementProduct::where( 'procurement_id', $id )->count(),
            'A save that failed had already deleted a line, and nothing rolls it back.'
        );

        $this->assertSame(
            1,
            ProcurementProduct::where( 'procurement_id', $id )->where( 'product_id', $drop->id )->count(),
            'The line the failed save was going to remove is gone even though the save did not go through.'
        );
    }

    /**
     * A caller that never mentions the goods must not have them deleted underneath it. Only an explicit
     * empty list means "the desk removed every line".
     */
    public function test_a_save_that_does_not_mention_the_goods_leaves_them_alone(): void
    {
        $user = $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase( $provider, [ $this->line( $product->id, $unit->id, 7, 10 ) ] );
        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/procurements/' . $id, [
                'name' => 'ใบรับของ แก้แต่หัวเอกสาร',
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => $user?->id ?? 1,
                    'billing_note_number' => 'BN-HEADER-ONLY',
                ],
            ] )
            ->assertOk();

        $procurement = Procurement::with( 'products' )->findOrFail( $id );

        $this->assertCount( 1, $procurement->products, 'A save that said nothing about the goods deleted them.' );
        $this->assertSame( 7.0, (float) $procurement->products->first()->quantity );
        $this->assertSame( 'BN-HEADER-ONLY', $procurement->billing_note_number );
    }

    /**
     * A due date somebody typed is theirs. It must survive a save that would otherwise recalculate it,
     * because a term agreed by phone does not always equal invoice date plus credit days.
     */
    public function test_a_typed_due_date_survives_a_second_save(): void
    {
        $user = $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 2, 10 ) ],
            [ 'invoice_date' => '2026-09-01', 'credit_days' => 30, 'due_date' => '2026-09-20' ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->assertStringStartsWith( '2026-09-20', (string) Procurement::findOrFail( $id )->due_date );

        $lineId = (int) Procurement::with( 'products' )->findOrFail( $id )->products->first()->id;

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/procurements/' . $id, [
                'name' => 'ใบรับของ วันครบกำหนดที่ตกลงไว้',
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_UNPAID,
                    'delivery_status' => Procurement::PENDING,
                    'automatic_approval' => 0,
                    'author_id' => $user?->id ?? 1,
                    'invoice_date' => '2026-09-01',
                    'credit_days' => 30,
                    'due_date' => '2026-09-20',
                ],
                'products' => [ $this->line( $product->id, $unit->id, 2, 10, $lineId ) ],
            ] )
            ->assertOk();

        $this->assertStringStartsWith(
            '2026-09-20',
            (string) Procurement::findOrFail( $id )->due_date,
            'A due date that was agreed and typed in was overwritten by the calculated one.'
        );
    }

    /**
     * The document the receiving desk carries to the shelves, and the one-click print the list offers.
     */
    public function test_the_document_can_be_viewed_and_printed(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 3, 30 ) ],
            [ 'delivery_note_number' => 'DN-WF-PRINT', 'billing_note_number' => 'BN-WF-PRINT' ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $view = $this->actingAs( $admin, 'web' )->get( '/dashboard/procurements/edit/' . $id . '/invoice' );
        $view->assertOk();

        $content = $view->getContent();
        $this->assertStringContainsString( 'DN-WF-PRINT', $content, 'The delivery note number is not on the document.' );
        $this->assertStringContainsString( 'BN-WF-PRINT', $content, 'The billing note number is not on the document.' );
        $this->assertStringContainsString( $product->name, $content, 'The goods are not on the document.' );
        $this->assertStringContainsString( 'ผู้ตรวจรับ (แผนกรับเข้า)', $content, 'The receiving signature block is gone.' );
        $this->assertStringNotContainsString( 'window.print', $content, 'The document prints itself even when it was only opened to be read.' );

        $printed = $this->actingAs( $admin, 'web' )
            ->get( '/dashboard/procurements/edit/' . $id . '/invoice?dash-visibility=disabled&autoprint=true' );

        $printed->assertOk();
        $this->assertStringContainsString( 'window.print', $printed->getContent(), 'The print button opens the document but does not print it.' );
    }

    /**
     * Settling a purchase from the list, and the guard that stops it being settled twice.
     */
    public function test_a_purchase_can_be_settled_once(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase( $provider, [ $this->line( $product->id, $unit->id, 2, 50 ) ] );
        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/procurements/' . $id . '/set-as-paid' )
            ->assertOk();

        $this->assertSame( Procurement::PAYMENT_PAID, Procurement::findOrFail( $id )->payment_status );

        /**
         * Asking again must be refused rather than quietly accepted, or a double payment looks like it
         * went through.
         */
        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/procurements/' . $id . '/set-as-paid' )
            ->assertStatus( 403 );
    }

    /**
     * Receiving the goods has to reach the shelf the till reads, and deleting the purchase has to take
     * that figure back off again. This is the only part of the journey that touches stock.
     */
    public function test_receiving_goods_moves_stock_and_deleting_the_purchase_takes_it_back(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $before = (float) app( ProductService::class )->getQuantity( $product->id, $unit->id );

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 12, 5 ) ],
            [
                'delivery_status' => Procurement::DELIVERED,
                'payment_status' => Procurement::PAYMENT_PAID,
                'automatic_approval' => 1,
            ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->assertSame(
            $before + 12,
            (float) app( ProductService::class )->getQuantity( $product->id, $unit->id ),
            'Receiving the goods did not reach the shelf the till reads.'
        );

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'DELETE', 'api/crud/ns.procurements/' . $id )
            ->assertOk();

        $this->assertNull( Procurement::find( $id ), 'The purchase was not deleted.' );
        $this->assertSame( 0, ProcurementProduct::where( 'procurement_id', $id )->count(), 'The purchase lines were orphaned instead of deleted.' );
        $this->assertSame(
            $before,
            (float) app( ProductService::class )->getQuantity( $product->id, $unit->id ),
            'Deleting a received purchase left its goods on the shelf.'
        );
    }

    /**
     * Once the goods are on the shelf the purchase is locked, because editing it would move stock that
     * has already been counted and possibly sold. The refusal has to come from the server, not from a
     * hidden button.
     */
    public function test_a_stocked_purchase_refuses_to_be_edited(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        [ 'product' => $product, 'unit' => $unit ] = $this->givenProduct();

        $result = $this->raisePurchase(
            $provider,
            [ $this->line( $product->id, $unit->id, 6, 5 ) ],
            [
                'delivery_status' => Procurement::DELIVERED,
                'payment_status' => Procurement::PAYMENT_PAID,
                'automatic_approval' => 1,
            ]
        );

        $id = $result[ 'data' ][ 'procurement' ][ 'id' ];

        $this->assertSame( Procurement::STOCKED, Procurement::findOrFail( $id )->delivery_status, 'Receiving the goods did not mark the purchase as stocked.' );

        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/edit/' . $id )
            ->assertStatus( 403 );
    }
}
