<?php

namespace Tests\Feature;

use App\Fields\CashRegisterClosingFields;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Register;
use App\Models\RegisterHistory;
use App\Models\User;
use App\Services\CashRegistersService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The shift closing document (ใบปิดกะ) a till prints when the drawer is counted.
 *
 * Two things have to hold or the document is worse than none. The cash it says the drawer should
 * hold must be the cash that actually passed through the drawer, or the person counting is sent
 * looking for a discrepancy the report invented. And a till belongs to one branch, so one branch
 * must not be able to read another's takings.
 *
 * Every figure here is built from register history and order rows inserted directly. That is
 * deliberate: driving the whole order service would test the order service, and the arithmetic
 * under test is the summary's own.
 */
class ShiftClosingSummaryTest extends TestCase
{
    use WithAuthentication;

    private CashRegistersService $service;

    /** @var list<array{0: string, 1: int}> */
    private array $createdRows = [];

    private ?User $actor = null;

    private ?int $userBranchBefore = null;

    private ?int $userRegisterBefore = null;

    public function test_cash_sales_after_change_are_distinct_from_manual_cash_in(): void
    {
        $register = $this->openRegister( 2500 );
        $cashOrder = $this->makeOrder( $register, total: 145 );
        $cashOrder->change = 355;
        $cashOrder->save();
        $this->makeCashPayment( $cashOrder, 500 );

        $transferOrder = $this->makeOrder( $register, total: 524 );
        $paymentId = (int) DB::table( 'nexopos_orders_payments' )->insertGetId( [
            'order_id' => $transferOrder->id, 'identifier' => 'bank-payment',
            'value' => 524, 'author_id' => $this->actor()->id,
            'created_at' => now(), 'updated_at' => now(),
        ] );
        $this->createdRows[] = [ 'nexopos_orders_payments', $paymentId ];
        $this->service->closeRegister( $register->refresh(), 3169, 'ทดสอบยอดตามภาพลูกค้า' );
        $report = $this->service->getZReport( $register->refresh() );

        $this->assertSame( 0.0, (float) $report->totalCashIn );
        $this->assertSame( 2645.0, (float) $report->cashOnHand );
        $this->assertSame( 524.0, (float) $report->cashDifference );
        $this->assertSame( 669.0, (float) $report->netCashToHandIn );

        $html = view( 'pages.dashboard.orders.templates.z-report', (array) $report )->render();
        $this->assertMatchesRegularExpression( '/เงินสดจากการขายหลังทอน<\/td>\s*<td[^>]*><\/td>\s*<td[^>]*>145\.00<\/td>/u', $html );
        $this->assertMatchesRegularExpression( '/เติมเงินสดระหว่างกะ<\/td>\s*<td[^>]*>0<\/td>\s*<td[^>]*>0\.00<\/td>/u', $html );
        $this->assertStringContainsString( 'ไม่รวมยอดโอน/พร้อมเพย์/บัตร', $html );

        $fields = app( CashRegisterClosingFields::class )->get();
        $description = collect( $fields )->firstWhere( 'name', 'description' );
        $this->assertStringContainsString( 'เงินสดที่นับได้ในลิ้นชัก', $description['description'] );
        $this->assertStringContainsString( 'ไม่รวมยอดโอน', $description['description'] );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app()->make( CashRegistersService::class );
    }

    protected function tearDown(): void
    {
        if ( $this->actor instanceof User ) {
            DB::table( 'nexopos_users' )->where( 'id', $this->actor->id )->update( [
                'branch_id' => $this->userBranchBefore,
                'assigned_register_id' => $this->userRegisterBefore,
            ] );
        }

        /**
         * History rows are written by the service rather than by this test, so they are cleared by
         * the till they belong to. Left behind they would join the next shift's figures.
         */
        $registerIds = collect( $this->createdRows )
            ->filter( fn ( array $row ): bool => $row[ 0 ] === 'nexopos_registers' )
            ->map( fn ( array $row ): int => $row[ 1 ] );

        if ( $registerIds->isNotEmpty() ) {
            DB::table( 'nexopos_registers_history' )->whereIn( 'register_id', $registerIds )->delete();
        }

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        parent::tearDown();
    }

    /**
     * The fault that made the document unusable on any till that handled cash by hand.
     *
     * Expected cash was opening + cash takings - change, and ignored cash added to the float and
     * cash removed for the bank entirely. A till opened with 1,000, topped up by 500 and relieved of
     * 300 was reported as holding 1,000 when it held 1,200, so the close showed a 200 surplus that
     * did not exist.
     */
    public function test_expected_cash_counts_the_money_moved_by_hand(): void
    {
        $register = $this->openRegister( 1000 );

        $this->service->cashIng( $register->refresh(), 500, 'เพิ่มเงินทอน' );
        $this->service->cashOut( $register->refresh(), 300, 'นำส่งธนาคาร' );

        $report = $this->service->getZReport( $register->refresh() );

        $this->assertSame( 500.0, (float) $report->totalCashIn, 'cash added during the shift was not reported' );
        $this->assertSame( 300.0, (float) $report->totalCashOut, 'cash removed during the shift was not reported' );
        $html = view( 'pages.dashboard.orders.templates.z-report', (array) $report )->render();
        $this->assertMatchesRegularExpression( '/เติมเงินสดระหว่างกะ<\/td>\s*<td[^>]*>1<\/td>\s*<td[^>]*>500\.00<\/td>/u', $html );

        $this->assertSame(
            1200.0,
            (float) $report->cashOnHand,
            'The cash the drawer should hold ignores money added to or taken from the float, so the'
                . ' person counting it is shown a shortage or a surplus that never happened.'
        );
    }

    public function test_the_difference_is_what_was_counted_against_what_was_expected(): void
    {
        $register = $this->openRegister( 1000 );

        $this->service->cashIng( $register->refresh(), 200, 'เพิ่มเงินทอน' );

        /** Counted 20 short of the 1,200 the drawer should hold. */
        $this->service->closeRegister( $register->refresh(), 1180, 'ปิดกะ' );

        $report = $this->service->getZReport( $register->refresh() );

        $this->assertSame( 1200.0, (float) $report->cashOnHand );
        $this->assertSame( 1180.0, (float) $report->countedCash );
        $this->assertSame(
            -20.0,
            (float) $report->cashDifference,
            'The difference is not the counted cash less the expected cash, so a short drawer cannot be read off the document.'
        );
    }

    /**
     * The shop's own slip, reproduced.
     *
     * Float 2,500, cash takings 8,184, nothing moved by hand, counted 10,704. Their system prints
     * 10,684 in the drawer, 10,704 counted and 8,204 to hand in. These are the figures the person
     * receiving the money checks, so they are pinned to the paper the shop already uses.
     */
    public function test_the_handover_figures_match_the_slip_the_shop_already_uses(): void
    {
        $register = $this->openRegister( 2500 );

        $product = $this->makeProductInItsOwnCategory();
        $order = $this->makeOrder( $register, total: 8184 );

        $this->makeOrderProduct( $order, $product, quantity: 1, total: 8184 );
        $this->makeCashPayment( $order, 8184 );

        $this->service->closeRegister( $register->refresh(), 10704, 'ปิดกะ' );

        $report = $this->service->getZReport( $register->refresh() );

        $this->assertSame( 8184.0, (float) $report->totalCashPayment, 'cash takings' );
        $this->assertSame( 10684.0, (float) $report->cashOnHand, 'คงเหลือเงินในลิ้นชัก' );
        $this->assertSame( 10704.0, (float) $report->countedCash, 'ยอดเงินนับได้จริง' );
        $this->assertSame( 2500.0, (float) $report->floatCarriedForward, 'เงินทอนส่งต่อไป' );

        $this->assertSame(
            8204.0,
            (float) $report->netCashToHandIn,
            'ยอดเงินนำส่งสุทธิ is wrong. This is the figure handed over with the cash, so the person'
                . ' receiving it counts against this number and nothing else.'
        );

        $this->assertSame( 20.0, (float) $report->cashDifference, 'the drawer was 20 over' );
    }

    /**
     * Every channel the shop accepts appears, whether or not the shift used it, and each carries a
     * count. A channel that vanishes on a quiet shift leaves the person checking the slip unable to
     * tell "none taken" from "line missing".
     */
    public function test_every_payment_channel_is_listed_with_its_count(): void
    {
        $register = $this->openRegister( 0 );

        $product = $this->makeProductInItsOwnCategory();
        $order = $this->makeOrder( $register, total: 300 );

        $this->makeOrderProduct( $order, $product, quantity: 1, total: 300 );
        $this->makeCashPayment( $order, 100 );
        $this->makeCashPayment( $order, 200 );

        $report = $this->service->getZReport( $register->refresh() );

        $channels = collect( $report->payments );

        $activeChannels = DB::table( 'nexopos_payments_types' )->where( 'active', true )->count();

        $this->assertGreaterThanOrEqual(
            $activeChannels,
            $channels->count(),
            'Not every channel the shop accepts was listed, so a quiet channel silently disappears from the slip.'
        );

        $cash = $channels->firstWhere( 'identifier', OrderPayment::PAYMENT_CASH );

        $this->assertNotNull( $cash, 'the cash channel was missing from the slip' );

        $this->assertSame(
            2,
            $cash->times,
            'The count beside the amount is wrong. Two payments totalling 300 must read as 2, or a'
                . ' wrong figure cannot be traced back to the transactions behind it.'
        );

        $this->assertSame( 300.0, (float) $cash->total_amount );

        /** A channel with no takings still gets a line, at zero. */
        $unused = $channels->firstWhere( 'identifier', 'welfare-card-payment' );

        if ( $unused !== null ) {
            $this->assertSame( 0, $unused->times );
            $this->assertSame( 0.0, (float) $unused->total_amount );
        }
    }

    /** A till closed twice in one day produces shift 1 and shift 2, not two copies of shift 1. */
    public function test_each_shift_of_the_day_is_numbered(): void
    {
        $register = $this->openRegister( 100 );

        $this->service->closeRegister( $register->refresh(), 100, 'ปิดกะเช้า' );

        $first = $this->service->getZReport( $register->refresh() );

        $this->assertSame( 1, $first->shiftNumber, 'the first shift of the day was not numbered 1' );

        $this->service->openRegister( $register->refresh(), 100, 'เปิดกะบ่าย' );
        $this->service->closeRegister( $register->refresh(), 100, 'ปิดกะบ่าย' );

        $second = $this->service->getZReport( $register->refresh() );

        $this->assertSame(
            2,
            $second->shiftNumber,
            'The second shift of the day is numbered 1 as well, so its slip is indistinguishable from'
                . ' a reprint of the first.'
        );
    }

    /**
     * The duration is built with intdiv() and %, which take integers, while Carbon hands back a
     * float — so every shift closing logged two "implicit conversion loses precision" deprecations.
     * It must also read forwards: the caller passes the closing time before the opening one, and an
     * unsigned difference would print a nine hour shift as "-9:-48".
     */
    public function test_the_shift_duration_reads_forwards_and_logs_no_deprecation(): void
    {
        $register = $this->openRegister( 0 );

        /** Two hours and five minutes before now, with seconds on it so the float is not whole. */
        DB::table( 'nexopos_registers_history' )
            ->where( 'register_id', $register->id )
            ->where( 'action', RegisterHistory::ACTION_OPENING )
            ->update( [ 'created_at' => now()->subMinutes( 125 )->subSeconds( 10 ) ] );

        $deprecations = [];

        set_error_handler(
            function ( int $severity, string $message ) use ( &$deprecations ): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED
        );

        try {
            $report = $this->service->getZReport( $register->refresh() );
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            [],
            $deprecations,
            'Producing the shift closing document raised a deprecation: ' . implode( ' | ', $deprecations )
        );

        $this->assertSame(
            '2:05',
            $report->sessionDuration,
            'The shift duration is wrong or signed. A shift that ran two hours and five minutes must'
                . ' read as 2:05, not as a negative.'
        );
    }

    public function test_a_shift_still_running_reports_no_difference(): void
    {
        $register = $this->openRegister( 1000 );

        $report = $this->service->getZReport( $register->refresh() );

        $this->assertNull(
            $report->countedCash,
            'An open shift reported a counted figure, although nothing has been counted yet.'
        );

        $this->assertNull(
            $report->cashDifference,
            'An open shift reported a difference. It would read as the whole float being missing'
                . ' rather than as a shift that has not been closed.'
        );

        $this->assertNull(
            $report->netCashToHandIn,
            'An open shift reported an amount to hand in, although nothing has been counted yet.'
        );
    }

    /**
     * The category totals were reset on every line, because the check read the category name while
     * the write used the category id, so nothing was ever found and each category ended up
     * reporting only the quantity of its last item.
     */
    public function test_category_quantities_add_up_across_the_whole_shift(): void
    {
        $register = $this->openRegister( 0 );

        $product = $this->makeProductInItsOwnCategory();

        $order = $this->makeOrder( $register );

        $this->makeOrderProduct( $order, $product, quantity: 3, total: 30 );
        $this->makeOrderProduct( $order, $product, quantity: 4, total: 40 );

        $report = $this->service->getZReport( $register->refresh() );

        $categories = collect( $report->categories );

        $this->assertSame(
            7.0,
            (float) $categories->sum( 'quantity' ),
            'The category quantities do not add up over the shift. Each line overwrote the last, so'
                . ' the document under-reports everything a category sold.'
        );

        $this->assertSame(
            70.0,
            (float) collect( $report->products )->sum( 'total_price' ),
            'the product totals did not accumulate over the shift'
        );
    }

    public function test_a_till_that_was_never_opened_is_refused_in_words(): void
    {
        $register = Register::findOrFail( $this->makeRegister( null ) );

        $this->expectExceptionMessageMatches( '/ยังไม่มีการเปิดกะ/' );

        $this->service->getZReport( $register );
    }

    /**
     * Route model binding hands over whatever id is in the address bar, so without re-resolving the
     * till a cashier could read another branch's takings by editing the URL.
     */
    public function test_another_branchs_till_cannot_be_read(): void
    {
        $ours = $this->makeBranch( 'สาขาของเรา' );
        $theirs = $this->makeBranch( 'สาขาอื่น' );

        $ourRegister = $this->makeRegister( $ours );
        $theirRegister = $this->makeRegister( $theirs );

        $this->pinActorToBranch( $ours );

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/cash-registers/z-report/' . $theirRegister )
            ->assertNotFound();

        /**
         * Our own till is still reachable — a scoping rule that locks everyone out would pass the
         * assertion above while making the feature useless.
         */
        $this->service->openRegister( Register::findOrFail( $ourRegister ), 100, 'เปิดกะทดสอบ' );

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/cash-registers/z-report/' . $ourRegister )
            ->assertOk();
    }

    /**
     * A cashier bound to one till may read that till and no other, even within their own branch,
     * which is the same rule that decides which drawer they may open.
     */
    public function test_a_cashier_bound_to_one_till_cannot_read_the_next_counter(): void
    {
        $branch = $this->makeBranch( 'สาขาเดียวกัน' );

        $mine = $this->makeRegister( $branch );
        $nextCounter = $this->makeRegister( $branch );

        $this->pinActorToBranch( $branch, $mine );

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/cash-registers/z-report/' . $nextCounter )
            ->assertNotFound();
    }

    public function test_the_owner_can_read_any_branchs_till(): void
    {
        $branch = $this->makeBranch( 'สาขาที่เจ้าของดูแล' );
        $register = $this->makeRegister( $branch );

        /** The owner has no branch of their own, which is how the administrator is stored. */
        $this->pinActorToBranch( null );

        /** Opening records who did it, so the shift needs an authenticated author. */
        $this->actingAs( $this->actor(), 'web' );

        $this->service->openRegister( Register::findOrFail( $register ), 100, 'เปิดกะทดสอบ' );

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/cash-registers/z-report/' . $register )
            ->assertOk();
    }

    /**
     * The slip stops at the money.
     *
     * It used to end with two more blocks, one listing sales per product category and one listing every
     * product the shift sold, and the shop asked for both to be taken off: the document is handed over
     * with the cash, and the person receiving it is checking one figure against the notes in their hand.
     *
     * Pinned from the rendered document rather than from the template text, because a block can come
     * back by being re-added anywhere on the page. What the slip must still carry is asserted alongside,
     * so a change that empties the whole document cannot pass this.
     *
     * The shift here actually sells something, in a category of its own, and is then closed. A slip
     * from an empty shift would have had nothing to list in either block and would have passed whether
     * or not they were removed, and the handover figure only prints once the drawer has been counted.
     */
    public function test_the_slip_no_longer_lists_categories_or_every_product(): void
    {
        $branch = $this->makeBranch( 'สาขาบิลปิดกะ' );
        $registerId = $this->makeRegister( $branch );

        $this->pinActorToBranch( $branch, $registerId );

        $this->actingAs( $this->actor(), 'web' );

        $register = Register::findOrFail( $registerId );

        $this->service->openRegister( $register, 100, 'เปิดกะทดสอบ' );

        $product = $this->makeProductInItsOwnCategory();
        $order = $this->makeOrder( $register, total: 250 );

        $this->makeOrderProduct( $order, $product, quantity: 2, total: 250 );
        $this->makeCashPayment( $order, 250 );

        $this->service->closeRegister( $register->refresh(), 350, 'ปิดกะทดสอบ' );

        $slip = $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/cash-registers/z-report/' . $registerId );

        $slip->assertOk();

        $document = $slip->getContent();

        foreach ( [ 'ยอดขายแยกตามหมวดสินค้า', 'ภาพรวมสินค้า' ] as $removed ) {
            $this->assertStringNotContainsString(
                $removed,
                $document,
                sprintf( 'The "%s" block is back on the shift closing slip; the shop asked for it to be off.', $removed )
            );
        }

        /**
         * What the slip is for. Asserted in the same test so that removing the two blocks cannot be
         * mistaken for permission to shorten the document further.
         */
        foreach ( [ 'ยอดเงินนำส่งสุทธิ', 'พิมพ์เมื่อ', 'ผู้ส่งเงิน', 'ผู้รับเงิน' ] as $kept ) {
            $this->assertStringContainsString(
                $kept,
                $document,
                sprintf( 'The slip no longer carries "%s", which the shop signs and hands over.', $kept )
            );
        }
    }

    /**
     * The arithmetic behind the two removed blocks stays, and stays correct.
     *
     * Deleting it would have deleted the cover for two faults that were fixed in it — category
     * quantities that reset on every line, and product totals that never accumulated — for no gain,
     * since taking a block off a printed page does not require the figures to stop existing. They are
     * there for any report that wants them.
     */
    public function test_the_figures_behind_the_removed_blocks_are_still_available(): void
    {
        $register = $this->openRegister( 100 );
        $report = $this->service->getZReport( $register->refresh() );

        $this->assertTrue( property_exists( $report, 'categories' ), 'getZReport no longer returns the per-category figures.' );
        $this->assertTrue( property_exists( $report, 'products' ), 'getZReport no longer returns the per-product figures.' );
    }

    /**
     * Closing a till costs the same number of reads whether it sold one thing or forty.
     *
     * The per-category and per-product figures walk every line and read `$item->product->category`.
     * Only `products` was eager loaded, so both of those hops fell to lazy loading and cost two
     * queries per line sold — the slip was quietly charging the shop a round trip for every item in
     * the shift, and still is charging it even though the slip no longer prints those figures.
     *
     * Written as a comparison rather than against a fixed number, because what is wrong with an N+1
     * is not the count itself but that the count follows the data. A shift with four lines in four
     * categories must read the products table the same number of times as a shift with one.
     */
    public function test_closing_a_till_does_not_query_once_per_line_sold(): void
    {
        $oneLine = $this->countProductReadsWhileClosing( lines: 1 );
        $fourLines = $this->countProductReadsWhileClosing( lines: 4 );

        $this->assertGreaterThan(
            0,
            $oneLine,
            'The measurement read nothing at all, so it cannot tell a fixed cost from one that grows.'
        );

        $this->assertSame(
            $oneLine,
            $fourLines,
            sprintf(
                'Closing a till reads the products tables %d times for one line sold and %d times for four,'
                    . ' so the cost still follows the number of items in the shift. Eager load'
                    . ' products.product.category on the orders query in getZReport().',
                $oneLine,
                $fourLines
            )
        );
    }

    /**
     * Builds a shift with the given number of lines, each its own product in its own category, and
     * counts the reads against the products and product categories tables that getZReport() makes.
     *
     * Distinct categories on purpose: lines sharing one category could be answered from the
     * identity map and would hide the per-line loading this is looking for.
     */
    private function countProductReadsWhileClosing( int $lines ): int
    {
        $register = $this->openRegister( 0 );
        $order = $this->makeOrder( $register, total: $lines * 100 );

        for ( $line = 0; $line < $lines; $line++ ) {
            $this->makeOrderProduct( $order, $this->makeProductInItsOwnCategory(), quantity: 1, total: 100 );
        }

        $this->makeCashPayment( $order, $lines * 100 );

        $reads = 0;

        DB::listen( function ( $query ) use ( &$reads ): void {
            if ( preg_match( '/from "\w*nexopos_products(_categories)?"/', $query->sql ) === 1 ) {
                $reads++;
            }
        } );

        $this->service->getZReport( $register->refresh() );

        return $reads;
    }

    private function openRegister( float $float ): Register
    {
        $register = Register::findOrFail( $this->makeRegister( null ) );

        $this->actingAs( $this->actor(), 'web' );

        $this->service->openRegister( $register, $float, 'เปิดกะทดสอบ' );

        return $register;
    }

    private function makeBranch( string $name ): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'SHIFT' . random_int( 100000, 999999 ),
            'name' => $name,
            'is_active' => true,
            'is_main' => false,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_branches', $id ];

        return $id;
    }

    private function makeRegister( ?int $branchId ): int
    {
        $id = (int) DB::table( 'nexopos_registers' )->insertGetId( [
            'name' => 'จุดคิดเงินปิดกะ ' . random_int( 100000, 999999 ),
            'branch_id' => $branchId,
            'status' => Register::STATUS_CLOSED,
            'balance' => 0,
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_registers', $id ];

        return $id;
    }

    /**
     * Its own product, category, unit and sellable row.
     *
     * Borrowing a product that happened to be in the database made this test pass on its own and
     * fail in the suite, because the products it was borrowing are created by a later suite. A test
     * that depends on another test's leftovers is not testing anything reliably.
     */
    private function makeProductInItsOwnCategory(): Product
    {
        $categoryId = (int) DB::table( 'nexopos_products_categories' )->insertGetId( [
            'name' => 'หมวดปิดกะ ' . random_int( 100000, 999999 ),
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_products_categories', $categoryId ];

        $unitGroupId = (int) DB::table( 'nexopos_units_groups' )->insertGetId( [
            'name' => 'กลุ่มหน่วยปิดกะ ' . random_int( 100000, 999999 ),
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_units_groups', $unitGroupId ];

        $unitId = (int) DB::table( 'nexopos_units' )->insertGetId( [
            'name' => 'ชิ้นปิดกะ',
            'identifier' => 'shift-piece-' . random_int( 100000, 999999 ),
            'group_id' => $unitGroupId,
            'value' => 1,
            'base_unit' => true,
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_units', $unitId ];

        $suffix = random_int( 100000, 999999 );

        $productId = (int) DB::table( 'nexopos_products' )->insertGetId( [
            'name' => 'สินค้าปิดกะ ' . $suffix,
            'barcode' => '88' . $suffix . '01',
            'barcode_type' => 'ean13',
            'sku' => 'shift-' . $suffix,
            'category_id' => $categoryId,
            'unit_group' => $unitGroupId,
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_products', $productId ];

        $unitQuantityId = (int) DB::table( 'nexopos_products_unit_quantities' )->insertGetId( [
            'product_id' => $productId,
            'unit_id' => $unitId,
            'quantity' => 100,
            'sale_price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $unitQuantityId ];

        return Product::findOrFail( $productId );
    }

    private function makeCashPayment( Order $order, float $value ): void
    {
        $id = (int) DB::table( 'nexopos_orders_payments' )->insertGetId( [
            'order_id' => $order->id,
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => $value,
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_orders_payments', $id ];
    }

    private function makeOrder( Register $register, float $total = 70 ): Order
    {
        $id = (int) DB::table( 'nexopos_orders' )->insertGetId( [
            'code' => 'SHIFT-' . random_int( 100000, 999999 ),
            'register_id' => $register->id,
            'type' => 'takeaway',
            'payment_status' => Order::PAYMENT_PAID,
            'author_id' => $this->actor()->id,
            /**
             * Customers are rows of nexopos_users in this schema, so the acting user stands in as
             * the buyer. The summary never reads the customer, it only needs the column filled.
             */
            'customer_id' => $this->actor()->id,
            'subtotal' => $total,
            'total' => $total,
            'discount' => 0,
            'shipping' => 0,
            'tax_value' => 0,
            'change' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_orders', $id ];

        return Order::findOrFail( $id );
    }

    private function makeOrderProduct( Order $order, Product $product, float $quantity, float $total ): void
    {
        $unitQuantity = $product->unit_quantities()->firstOrFail();

        $id = (int) DB::table( 'nexopos_orders_products' )->insertGetId( [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'unit_id' => $unitQuantity->unit_id,
            'unit_quantity_id' => $unitQuantity->id,
            'product_category_id' => $product->category_id,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $total / $quantity,
            'total_price' => $total,
            'tax_value' => 0,
            'discount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_orders_products', $id ];
    }

    private function pinActorToBranch( ?int $branchId, ?int $registerId = null ): void
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [
            'branch_id' => $branchId,
            'assigned_register_id' => $registerId,
        ] );

        /**
         * The in-memory model is what actingAs hands to the guard, and forCurrentBranch() reads the
         * branch off it. Without refreshing, every request below would still be scoped by the branch
         * the user had before this line and the test would prove nothing.
         */
        $actor->refresh();

        Auth::forgetGuards();
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
            $this->userBranchBefore = $this->actor->branch_id;
            $this->userRegisterBefore = $this->actor->assigned_register_id;
        }

        return $this->actor;
    }
}
