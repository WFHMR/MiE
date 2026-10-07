<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\BillingNote;
use App\Models\BillingNoteSequence;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\ThaiBahtTextService;
use App\Services\ThaiBillingNoteService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers the provisional billing note (ใบแจ้งหนี้/วางบิลค่าสินค้า ชั่วคราว).
 *
 * The document collects a credit customer's outstanding deliveries onto one sheet, which
 * their accounts desk checks against its own ledger before scheduling payment. It carries
 * no tax and creates no liability — the tax invoices it lists already did that — so what
 * is under test here is not tax correctness but the things that cost real money when they
 * go wrong: a delivery asked for twice, a customer billed for another customer's goods, a
 * settled order billed again, and figures that move after the customer has signed for them.
 *
 * The layout assertions are transcribed from หจก. รุ่งเรืองแกรนด์ note 69080001, so they
 * are a specification of the shop's existing stationery rather than a guess.
 */
class ThaiBillingNoteTest extends TestCase
{
    use DatabaseTransactions;

    private ThaiBillingNoteService $notes;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notes = app()->make( ThaiBillingNoteService::class );
        $this->admin = Role::namespace( 'admin' )?->users()->first() ?? User::first();

        /**
         * Saving an order line writes a stock history row, whose author_id is NOT NULL and
         * is taken from the signed in user. A clerk raising a billing note is always signed
         * in, so authenticating here matches how this runs rather than working around it.
         */
        $this->actingAs( $this->admin );

        ns()->option->set( 'ns_currency_precision', 2 );
        ns()->option->set( 'ns_thai_billing_note_prefix', 'BN' );
        ns()->option->set( 'ns_thai_billing_note_start_number', 0 );
        ns()->option->set( 'ns_thai_tax_registered_name', 'ห้างหุ้นส่วนจำกัด รุ่งเรืองแกรนด์' );
        ns()->option->set( 'ns_thai_tax_registered_address', '31/62 หมู่ที่ 7 ตำบลจันทนิมิต อำเภอเมือง จังหวัดจันทบุรี 22000' );
        ns()->option->set( 'ns_store_phone', '039-324078' );
        ns()->option->set( 'ns_store_fax', '039-324079' );

        $this->customer = $this->givenCreditCustomer();
    }

    public function test_a_note_lists_the_deliveries_it_asks_for_and_numbers_itself(): void
    {
        $first = $this->givenUnpaidOrder( total: 12300.0 );
        $second = $this->givenUnpaidOrder( total: 7000.0 );

        $note = $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $first->id, $second->id ],
            authorId: $this->admin->id,
        );

        $this->assertInstanceOf( BillingNote::class, $note );
        $this->assertSame( BillingNote::STATUS_ISSUED, $note->status );
        $this->assertMatchesRegularExpression( '/^BN-\d{4}-\d{6}$/', $note->number );
        $this->assertCount( 2, $note->lines );

        /**
         * 12,300 + 7,000 is the 19,300 the shop's own note 69080001 shows, which is the
         * figure the customer's desk ticks off.
         */
        $this->assertEqualsWithDelta( 19300.0, (float) $note->total_billed, 0.01 );
        $this->assertEqualsWithDelta( 0.0, (float) $note->total_deducted, 0.01 );
        $this->assertEqualsWithDelta( 19300.0, (float) $note->total_net, 0.01 );
    }

    /**
     * The lines are documents, not products. One row is one delivery the customer has to
     * find in their own records, so it needs the reference, the date, the term and the
     * amount — and nothing else.
     */
    public function test_each_line_carries_what_the_accounts_desk_matches_on(): void
    {
        $order = $this->givenUnpaidOrder( total: 4500.0 );

        $note = $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $order->id ],
            authorId: $this->admin->id,
        );

        $line = $note->lines[ 0 ];

        $this->assertSame( $order->id, $line[ 'order_id' ] );
        $this->assertSame( $order->code, $line[ 'document_number' ] );
        $this->assertSame( 30, $line[ 'payment_term_days' ] );
        $this->assertNotNull( $line[ 'document_date' ] );
        $this->assertEqualsWithDelta( 4500.0, (float) $line[ 'amount' ], 0.01 );
    }

    public function test_numbers_are_sequential_and_are_not_reused_after_a_cancellation(): void
    {
        $first = $this->notes->issue( $this->customer, [ $this->givenUnpaidOrder()->id ], authorId: $this->admin->id );
        $second = $this->notes->issue( $this->customer, [ $this->givenUnpaidOrder()->id ], authorId: $this->admin->id );

        $this->assertSame( 1, $first->sequence_number );
        $this->assertSame( 2, $second->sequence_number );

        /**
         * Cancelling releases the deliveries to be billed again but must not free the
         * number. Two notes sharing a number is how a payment gets matched to the wrong
         * set of deliveries.
         */
        $this->notes->cancel( $second, 'ลูกค้าขอให้แยกใบวางบิล' );

        $third = $this->notes->issue( $this->customer, [ $this->givenUnpaidOrder()->id ], authorId: $this->admin->id );

        $this->assertSame( 3, $third->sequence_number );
        $this->assertSame( 3, BillingNoteSequence::where( 'scope', $this->notes->currentScope() )->firstOrFail()->last_number );
    }

    public function test_a_settled_order_cannot_be_billed(): void
    {
        $order = $this->givenUnpaidOrder();
        $order->payment_status = Order::PAYMENT_PAID;
        $order->save();

        $this->expectException( NotAllowedException::class );

        /**
         * Asking for money already received invites the customer to pay twice and then
         * spend a week reconciling it back out.
         */
        $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );
    }

    public function test_another_customers_delivery_cannot_be_put_on_the_note(): void
    {
        $other = $this->givenCreditCustomer();
        $foreign = $this->givenUnpaidOrder( customer: $other );

        $this->expectException( NotAllowedException::class );

        /**
         * A billing note is left at one accounts desk. Mixing customers would show each of
         * them what the other bought.
         */
        $this->notes->issue( $this->customer, [ $foreign->id ], authorId: $this->admin->id );
    }

    public function test_a_delivery_already_on_an_outstanding_note_is_refused(): void
    {
        $order = $this->givenUnpaidOrder();

        $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );

        $this->expectException( NotAllowedException::class );

        $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );
    }

    /**
     * A note that was raised in error has to be withdrawable, and withdrawing it has to
     * put the deliveries back in the pool — otherwise a mistake permanently strands goods
     * the shop can never ask for.
     */
    public function test_cancelling_releases_the_deliveries_to_be_billed_again(): void
    {
        $order = $this->givenUnpaidOrder();

        $note = $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );

        $this->assertEmpty( $this->notes->billableOrders( $this->customer )->pluck( 'id' )->intersect( [ $order->id ] ) );

        $this->notes->cancel( $note, 'วางบิลผิดลูกค้า' );

        $this->assertContains( $order->id, $this->notes->billableOrders( $this->customer )->pluck( 'id' ) );

        $reissued = $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );

        $this->assertSame( $order->id, $reissued->lines[ 0 ][ 'order_id' ] );
    }

    public function test_cancelling_requires_a_reason_and_keeps_the_document(): void
    {
        $note = $this->notes->issue( $this->customer, [ $this->givenUnpaidOrder()->id ], authorId: $this->admin->id );

        try {
            $this->notes->cancel( $note, '   ' );
            $this->fail( 'A cancellation without a reason must be refused.' );
        } catch ( NotAllowedException ) {
            // expected
        }

        $cancelled = $this->notes->cancel( $note, 'ออกเลขซ้ำ' );

        $this->assertSame( BillingNote::STATUS_CANCELLED, $cancelled->status );
        $this->assertNotNull( $cancelled->cancelled_at );
        $this->assertNotNull( BillingNote::find( $note->id ), 'A cancelled note is kept, never deleted.' );

        $this->expectException( NotAllowedException::class );
        $this->notes->cancel( $cancelled->refresh(), 'ยกเลิกซ้ำ' );
    }

    /**
     * หักตามหมายเหตุ is what the customer's desk strikes off during the check. A deduction
     * larger than the sum being asked for would print a negative amount to collect, which
     * is a credit note — a different document with different consequences.
     */
    public function test_a_deduction_cannot_exceed_what_is_being_asked_for(): void
    {
        $order = $this->givenUnpaidOrder( total: 5000.0 );

        $note = $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $order->id ],
            attributes: [ 'deduction' => 500.0, 'notes' => 'ส่งขาด 2 ถุง' ],
            authorId: $this->admin->id,
        );

        $this->assertEqualsWithDelta( 5000.0, (float) $note->total_billed, 0.01 );
        $this->assertEqualsWithDelta( 500.0, (float) $note->total_deducted, 0.01 );
        $this->assertEqualsWithDelta( 4500.0, (float) $note->total_net, 0.01 );
        $this->assertSame( 'ส่งขาด 2 ถุง', $note->notes );

        $this->expectException( NotAllowedException::class );

        $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $this->givenUnpaidOrder( total: 1000.0 )->id ],
            attributes: [ 'deduction' => 1500.0 ],
            authorId: $this->admin->id,
        );
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException( NotAllowedException::class );

        $this->notes->issue( $this->customer, [], authorId: $this->admin->id );
    }

    /**
     * The customer is holding a signed copy. Renaming them, moving the shop or paying the
     * order off must not change what that copy says, because the payment is reconciled
     * against it weeks later.
     */
    public function test_the_document_keeps_its_own_copy_of_what_it_printed(): void
    {
        $order = $this->givenUnpaidOrder( total: 8000.0 );

        $note = $this->notes->issue( $this->customer, [ $order->id ], authorId: $this->admin->id );

        $originalCustomerName = $note->customer_name;
        $originalSeller = $note->seller_name;

        $this->customer->first_name = 'ชื่อใหม่หลังเปลี่ยน';
        $this->customer->last_name = '';
        $this->customer->save();

        ns()->option->set( 'ns_thai_tax_registered_name', 'ชื่อร้านใหม่' );

        $order->total = 1.0;
        $order->save();

        $reloaded = BillingNote::findOrFail( $note->id );

        $this->assertSame( $originalCustomerName, $reloaded->customer_name );
        $this->assertSame( $originalSeller, $reloaded->seller_name );
        $this->assertEqualsWithDelta( 8000.0, (float) $reloaded->total_billed, 0.01 );
        $this->assertEqualsWithDelta( 8000.0, (float) $reloaded->lines[ 0 ][ 'amount' ], 0.01 );
    }

    public function test_the_billable_list_offers_only_unsettled_deliveries(): void
    {
        $outstanding = $this->givenUnpaidOrder();

        $settled = $this->givenUnpaidOrder();
        $settled->payment_status = Order::PAYMENT_PAID;
        $settled->save();

        $billable = $this->notes->billableOrders( $this->customer )->pluck( 'id' );

        $this->assertContains( $outstanding->id, $billable );
        $this->assertNotContains( $settled->id, $billable );
    }

    public function test_the_printed_sheet_carries_the_fields_the_paper_form_has(): void
    {
        $note = $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $this->givenUnpaidOrder( total: 19300.0 )->id ],
            authorId: $this->admin->id,
        );

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/billing-note/' . $note->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        // The title, with the qualifier that says this document settles nothing on its own.
        $response->assertSee( 'ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว)', false );
        $response->assertSee( 'ขอวางบิลค่าสินค้าตามรายการดังต่อไปนี้', false );

        // A4 geometry, so it lines up with the shop's stationery.
        $response->assertSee( 'size: A4 portrait', false );

        // The document's own number and the customer it is addressed to.
        $response->assertSee( $note->number, false );

        /**
         * The one assertion here that lets Laravel escape what it looks for. Every other value on
         * this sheet is a literal we wrote, so raw matching is what we want. The customer name is
         * not: it comes from the fixture, Blade prints it through {{ }}, and a name such as
         * "Harmon O'Hara" reaches the page as "Harmon O&#039;Hara". Matching it raw passed only
         * for as long as the generated name happened to hold no apostrophe.
         */
        $response->assertSee( $note->customer_name );
        $response->assertSee( 'ห้างหุ้นส่วนจำกัด รุ่งเรืองแกรนด์', false );
        $response->assertSee( '039-324078', false );

        // The columns the desk reads by position.
        $response->assertSee( 'ใบส่งสินค้าเลขที่', false );
        $response->assertSee( 'ลงวันที่', false );
        $response->assertSee( 'กำหนดชำระ (วัน)', false );
        $response->assertSee( 'ครบกำหนดวันที่', false );

        // The three totals, in the order they are ticked off.
        $response->assertSee( 'จำนวนเงินที่วางบิลรวม', false );
        $response->assertSee( 'หักตามหมายเหตุรวม', false );
        $response->assertSee( 'จำนวนเงินรับวางบิลสุทธิ', false );

        // Three signatures: the runner, the desk that accepted it, the officer who pays it.
        $response->assertSee( 'ผู้วางบิล', false );
        $response->assertSee( 'ผู้รับวางบิล', false );
        $response->assertSee( 'ฝ่ายการเงิน/บัญชี', false );
    }

    /**
     * A figure can have a digit added to it after the fact, a sentence cannot. The written
     * amount states the net, because that is what the customer is accepting for payment
     * rather than what was originally asked for.
     */
    public function test_the_sheet_writes_the_net_amount_out_in_thai(): void
    {
        $note = $this->notes->issue(
            customer: $this->customer,
            orderIds: [ $this->givenUnpaidOrder( total: 19300.0 )->id ],
            authorId: $this->admin->id,
        );

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/billing-note/' . $note->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        $expected = app()->make( ThaiBahtTextService::class )->convert( $note->total_net );

        $this->assertSame( 'หนึ่งหมื่นเก้าพันสามร้อยบาทถ้วน', $expected );
        $response->assertSee( $expected, false );
    }

    /**
     * A credit customer, since a note over cash sales would have nothing outstanding to
     * ask for. The group carries the term, which is where the printed "กำหนดชำระ (วัน)"
     * comes from.
     *
     * The name is pinned rather than left to the factory's faker. Two reasons: the printed sheet is
     * asserted against this name, and a random one made that assertion pass or fail depending on
     * whether the generated name happened to contain a character Blade escapes. The ampersand keeps
     * that path covered on every run, and shop names of this shape are what the trade actually uses.
     */
    private function givenCreditCustomer(): Customer
    {
        $group = CustomerGroup::first();

        if ( ! $group instanceof CustomerGroup ) {
            $group = CustomerGroup::factory()->create();
        }

        $group->payment_term_days = 30;
        $group->save();

        return Customer::factory()->create( [
            'group_id' => $group->id,
            'first_name' => 'หจก. เอ แอนด์ บี ซัพพลาย',
            'last_name' => '(สาขา 1) & หุ้นส่วน',
        ] );
    }

    /**
     * An unpaid order with one line, built here rather than looked up so the amounts on
     * the note are the ones the assertion needs.
     */
    private function givenUnpaidOrder( float $total = 1000.0, ?Customer $customer = null ): Order
    {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $order = new Order;
        $order->code = 'BN-TEST-' . uniqid();
        $order->type = 'delivery';
        $order->payment_status = Order::PAYMENT_UNPAID;
        $order->customer_id = ( $customer ?? $this->customer )->id;
        $order->author_id = $this->admin->id;
        $order->subtotal = $total;
        $order->total = $total;
        $order->tendered = 0;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->final_payment_date = ns()->date->getNow()->addDays( 30 )->toDateTimeString();
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
            'unit_price' => $total,
            'total_price' => $total,
            'status' => 'sold',
            'vat_status' => ThaiVatService::STATUS_STANDARD,
            'vat_rate' => 7,
        ] );

        $order->products()->save( $orderProduct );

        return $order->fresh( 'products' );
    }
}
