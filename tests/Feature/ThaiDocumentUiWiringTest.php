<?php

namespace Tests\Feature;

use App\Classes\Hook;
use App\Crud\BillingNoteCrud;
use App\Crud\OrderCrud;
use App\Models\BillingNote;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use App\Services\ThaiBillingNoteService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers the wiring that makes the Thai documents reachable from the dashboard.
 *
 * The services and the templates are tested elsewhere. What is under test here is the seam
 * between files, because that is what actually breaks: a row action whose identifier the
 * footer dispatcher no longer matches, a Blade tag naming a Vue component nobody registered,
 * a menu entry pointing at a route that was renamed. Each of those leaves a feature that
 * exists in the codebase and cannot be reached by a user, and none of them is a syntax error.
 */
class ThaiDocumentUiWiringTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::namespace( 'admin' )?->users()->first() ?? User::first();

        /**
         * Saving an order line writes a stock history row whose author_id is NOT NULL and
         * comes from the signed in user.
         */
        $this->actingAs( $this->admin );

        ns()->option->set( 'ns_thai_vat_registered', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', '0223554000076' );
        ns()->option->set( 'ns_thai_tax_registered_name', 'ห้างหุ้นส่วนจำกัด รุ่งเรืองแกรนด์' );
        ns()->option->set( 'ns_thai_tax_branch_code', ThaiVatService::HEAD_OFFICE_BRANCH_CODE );
        ns()->option->set( 'ns_thai_billing_note_prefix', 'BN' );
    }

    /**
     * A tax invoice is issued from a sale's row actions, and that is the only place in the
     * application it can be issued from. If the action stops being emitted the document
     * becomes unreachable while every service test still passes.
     */
    public function test_a_sale_offers_the_tax_invoice_action(): void
    {
        $order = $this->givenOrder( Order::PAYMENT_PAID );

        $actions = $this->orderRowActions( $order );

        $this->assertArrayHasKey( 'ns.order-tax-invoice', $actions );
        $this->assertSame( 'POPUP', $actions[ 'ns.order-tax-invoice' ][ 'type' ] );
        $this->assertSame(
            [ 'nexopos.create.tax-invoices' ],
            $actions[ 'ns.order-tax-invoice' ][ 'permissions' ],
            'The action has to stay gated on issuing, not on reading.'
        );
    }

    /**
     * A POPUP action does nothing on its own: it emits an event that a subscriber in the
     * CRUD footer has to recognise by identifier. The two live in different files and in
     * different languages, so a rename on either side is silent — the menu item appears and
     * clicking it does nothing at all.
     */
    public function test_the_footer_dispatcher_recognises_the_tax_invoice_action(): void
    {
        $footer = File::get( resource_path( 'views/pages/dashboard/orders/footer.blade.php' ) );

        $this->assertStringContainsString( 'ns.order-tax-invoice', $footer );
        $this->assertStringContainsString( 'nsOrderTaxInvoice', $footer );

        /**
         * And the component that name refers to has to be registered on the popup bundle,
         * which is built separately from the page bundle.
         */
        $popups = File::get( resource_path( 'ts/popups.ts' ) );

        $this->assertStringContainsString( 'nsOrderTaxInvoice', $popups );
        $this->assertStringContainsString( 'ns-order-tax-invoice-popup.vue', $popups );
    }

    public function test_the_billing_note_register_recognises_its_cancel_action(): void
    {
        $footer = File::get( resource_path( 'views/pages/dashboard/orders/billing-notes-footer.blade.php' ) );

        $this->assertStringContainsString( 'ns.billing-note-cancel', $footer );
    }

    /**
     * The register is resolved by identifier through a hook, not by class name, so a
     * mismatched IDENTIFIER produces a 404 on a page that otherwise looks correct.
     */
    public function test_the_billing_note_register_resolves_and_lists_its_columns(): void
    {
        $this->assertSame( BillingNoteCrud::class, Hook::filter( 'ns-crud-resource', BillingNoteCrud::IDENTIFIER ) );

        $config = ( new BillingNoteCrud )->getCrudConfig();

        foreach ( [ 'number', 'issued_at', 'customer_name', 'total_billed', 'total_deducted', 'total_net', 'status' ] as $column ) {
            $this->assertArrayHasKey( $column, $config[ 'columns' ] );
        }

        /**
         * documents_count is counted out of the frozen snapshot rather than selected, so it
         * must not be sortable — the CRUD would build an ORDER BY against a column that does
         * not exist on the table.
         */
        $this->assertFalse( $config[ 'columns' ][ 'documents_count' ][ 'sort' ] );

        /**
         * There is no form behind this register. An edit or put link would put a button on
         * the table leading to a page that cannot exist.
         */
        $this->assertArrayNotHasKey( 'edit', $config[ 'links' ] );
        $this->assertArrayNotHasKey( 'put', $config[ 'links' ] );
        $this->assertStringEndsWith( '/dashboard/orders/billing-notes/create', $config[ 'links' ][ 'create' ] );
    }

    public function test_an_issued_note_offers_printing_and_withdrawal(): void
    {
        $note = $this->givenBillingNote();

        $actions = $this->billingNoteRowActions( $note );

        $this->assertArrayHasKey( 'print', $actions );
        $this->assertSame( 'TAB', $actions[ 'print' ][ 'type' ] );
        $this->assertStringContainsString( 'autoprint=true', $actions[ 'print' ][ 'url' ] );

        $this->assertArrayHasKey( 'ns.billing-note-cancel', $actions );
        $this->assertSame( 'POPUP', $actions[ 'ns.billing-note-cancel' ][ 'type' ] );
        $this->assertSame( [ 'nexopos.cancel.billing-notes' ], $actions[ 'ns.billing-note-cancel' ][ 'permissions' ] );
    }

    /**
     * Once withdrawn there is nothing left to withdraw, and offering it again invites a
     * second cancellation that the service would reject with an error the clerk cannot act on.
     */
    public function test_a_cancelled_note_no_longer_offers_withdrawal(): void
    {
        $note = $this->givenBillingNote();

        app()->make( ThaiBillingNoteService::class )->cancel( $note, 'ทดสอบ' );

        $actions = $this->billingNoteRowActions( $note->refresh() );

        $this->assertArrayHasKey( 'print', $actions, 'A withdrawn note still has to be reprintable for the file.' );
        $this->assertArrayNotHasKey( 'ns.billing-note-cancel', $actions );
    }

    /**
     * The row count comes from the document's own snapshot, so it keeps reporting what was
     * printed even after one of the listed orders is gone.
     */
    public function test_the_register_counts_the_deliveries_from_the_frozen_snapshot(): void
    {
        $note = $this->givenBillingNote( 2 );

        $entry = $this->billingNoteEntry( $note );

        $this->assertSame( 2, $entry->documents_count );
        $this->assertSame( 'ใช้งาน', $entry->status );
    }

    /**
     * Both pages exist and each renders its own component. A Blade naming a component that
     * was never registered renders an empty page with no error anywhere.
     */
    public function test_both_billing_note_pages_render_their_components(): void
    {
        $create = $this->actingAs( $this->admin )->get( '/dashboard/orders/billing-notes/create' );

        $create->assertStatus( 200 );
        $create->assertSee( 'ns-billing-note-issue', false );
        $create->assertSee( 'dashboard-body', false );

        $register = $this->actingAs( $this->admin )->get( '/dashboard/orders/billing-notes' );

        $register->assertStatus( 200 );
        $register->assertSee( 'ns.billing-notes', false );
        $register->assertSee( 'dashboard-body', false );
    }

    public function test_the_issuing_page_component_is_registered_on_the_page_bundle(): void
    {
        $appInit = File::get( resource_path( 'ts/app-init.ts' ) );

        $this->assertStringContainsString( 'ns-billing-note-issue.vue', $appInit );
        $this->assertStringContainsString( 'nsBillingNoteIssue', $appInit );
    }

    /**
     * Every document has to be reachable from the sidebar, and each href has to resolve to a
     * registered route. A menu entry pointing at a renamed path is a 404 the developer never
     * sees because the page they were testing was opened by typing the URL.
     */
    public function test_the_sidebar_reaches_every_document(): void
    {
        $menus = app()->make( MenuService::class )->getMenus();

        $this->assertArrayHasKey( 'orders', $menus, 'The sales documents section carries the only route to a sale row.' );

        $children = $menus[ 'orders' ][ 'childrens' ];

        foreach ( [ 'orders-list', 'billing-notes-create', 'billing-notes-list' ] as $identifier ) {
            $this->assertArrayHasKey( $identifier, $children );

            $path = parse_url( $children[ $identifier ][ 'href' ], PHP_URL_PATH );

            $response = $this->actingAs( $this->admin )->get( $path );

            $this->assertSame(
                200,
                $response->status(),
                sprintf( 'The sidebar entry "%s" points at %s, which does not render.', $identifier, $path )
            );
        }

        $reports = $menus[ 'reports' ][ 'childrens' ];

        $this->assertArrayHasKey( 'delivery-notes-cash-report', $reports );
        $this->assertArrayHasKey( 'delivery-notes-consignment-report', $reports );
    }

    /**
     * MenusFilter hangs the instalments entry off $menus[ 'orders' ][ 'childrens' ]. No such
     * section existed before this work, so the injection had been silently doing nothing for
     * as long as the filter had been there — the shop could enable credit sales and never get
     * the menu that manages them. Asserted here because the section is what makes it work.
     */
    public function test_enabling_credit_sales_adds_the_instalments_entry_to_the_section(): void
    {
        ns()->option->set( 'ns_orders_allow_unpaid', 'yes' );

        $children = app()->make( MenuService::class )->getMenus()[ 'orders' ][ 'childrens' ];

        $this->assertArrayHasKey( 'instalments', $children );

        $path = parse_url( $children[ 'instalments' ][ 'href' ], PHP_URL_PATH );

        $this->actingAs( $this->admin )->get( $path )->assertStatus( 200 );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orderRowActions( Order $order ): array
    {
        $crud = new OrderCrud;

        $entry = collect( $crud->getEntries()[ 'data' ] )->first( fn ( $row ) => (int) $row->id === $order->id );

        $this->assertNotNull( $entry, 'The order under test has to appear in the orders list.' );

        return $entry->{'$actions'};
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function billingNoteRowActions( BillingNote $note ): array
    {
        return $this->billingNoteEntry( $note )->{'$actions'};
    }

    private function billingNoteEntry( BillingNote $note )
    {
        $entry = collect( ( new BillingNoteCrud )->getEntries()[ 'data' ] )
            ->first( fn ( $row ) => (int) $row->id === $note->id );

        $this->assertNotNull( $entry, 'The note under test has to appear in the register.' );

        return $entry;
    }

    private function givenBillingNote( int $deliveries = 1 ): BillingNote
    {
        $customer = $this->givenCreditCustomer();

        $orderIds = collect( range( 1, $deliveries ) )
            ->map( fn () => $this->givenOrder( Order::PAYMENT_UNPAID, $customer )->id )
            ->all();

        return app()->make( ThaiBillingNoteService::class )->issue(
            customer: $customer,
            orderIds: $orderIds,
            authorId: $this->admin->id,
        );
    }

    private function givenCreditCustomer(): Customer
    {
        $group = CustomerGroup::first() ?: CustomerGroup::factory()->create();

        $group->payment_term_days = 15;
        $group->save();

        return Customer::factory()->create( [ 'group_id' => $group->id ] );
    }

    private function givenOrder( string $paymentStatus, ?Customer $customer = null ): Order
    {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $order = new Order;
        $order->code = 'UI-' . uniqid();
        $order->type = 'delivery';
        $order->payment_status = $paymentStatus;
        $order->customer_id = ( $customer ?? $this->givenCreditCustomer() )->id;
        $order->author_id = $this->admin->id;
        $order->subtotal = 1070.0;
        $order->total = 1070.0;
        $order->tendered = $paymentStatus === Order::PAYMENT_PAID ? 1070.0 : 0;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->final_payment_date = ns()->date->getNow()->addDays( 15 )->toDateTimeString();
        $order->save();

        /**
         * NsModel declares no $fillable, so every model is totally guarded and mass
         * assignment is refused. forceFill is how a line gets built outside OrdersService.
         */
        $orderProduct = new OrderProduct;
        $orderProduct->forceFill( [
            'name' => $unitQuantity->product->name,
            'product_id' => $unitQuantity->product_id,
            'unit_id' => $unitQuantity->unit_id,
            'unit_quantity_id' => $unitQuantity->id,
            'product_category_id' => $unitQuantity->product->category_id ?? 0,
            'unit_name' => 'ชิ้น',
            'quantity' => 1,
            'unit_price' => 1070.0,
            'total_price' => 1070.0,
            'status' => 'sold',
            'vat_status' => ThaiVatService::STATUS_STANDARD,
            'vat_rate' => 7,
        ] );

        $order->products()->save( $orderProduct );

        return $order->fresh( 'products' );
    }
}
