<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Register;
use App\Models\User;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every sale has to land in the branch that made it.
 *
 * The branch was read from a session key that is only ever filled when multi store mode is switched
 * on. It is off here, so the code fell through to a hardcoded 1 and every sale at every branch was
 * recorded against the main branch. The owner reads per branch takings to decide what to stock and
 * where, so a sale filed under the wrong branch is worse than a missing one.
 *
 * The till is the anchor: a register belongs to one branch and does not move between them.
 */
class OrderBranchAttributionTest extends TestCase
{
    use DatabaseTransactions;

    private OrdersService $ordersService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ordersService = app( OrdersService::class );
    }

    public function test_a_sale_is_filed_under_the_branch_that_owns_the_till(): void
    {
        $branchId = $this->makeBranch( 'สาขาทดสอบหนึ่ง' );
        $registerId = $this->makeRegister( $branchId );

        $this->assertSame(
            $branchId,
            $this->resolve( [ 'register_id' => $registerId ] ),
            'The till decides, because it belongs to one branch and never moves.'
        );
    }

    public function test_an_explicitly_supplied_branch_wins(): void
    {
        $tillBranch = $this->makeBranch( 'สาขาของลิ้นชัก' );
        $askedBranch = $this->makeBranch( 'สาขาที่ระบุมา' );
        $registerId = $this->makeRegister( $tillBranch );

        $this->assertSame(
            $askedBranch,
            $this->resolve( [ 'register_id' => $registerId, 'branch_id' => $askedBranch ] ),
            'An API caller or an import that names a branch means it.'
        );
    }

    /**
     * Reversed deliberately.
     *
     * This used to assert that the branch picker beat the till. That is what let a sale rung up on
     * a till bolted to one branch be filed under another, because the picker is a screen control
     * with no authorisation behind it and the session it writes outlives the click. A register
     * belongs to one branch and does not travel, so it is the more reliable witness. Every branch
     * added widens the gap between the two answers, which is why this was changed rather than left.
     */
    public function test_the_till_wins_over_the_session_choice(): void
    {
        $tillBranch = $this->makeBranch( 'สาขาของลิ้นชักสอง' );
        $sessionBranch = $this->makeBranch( 'สาขาที่เลือกไว้' );
        $registerId = $this->makeRegister( $tillBranch );

        session( [ 'selected_branch_id' => $sessionBranch ] );

        $resolved = $this->resolve( [ 'register_id' => $registerId ] );

        session()->forget( 'selected_branch_id' );

        $this->assertSame(
            $tillBranch,
            $resolved,
            'A sale rung up on this till belongs to this till branch, whatever the picker says.'
        );
    }

    /**
     * With no till behind it — a back office order, an API call — the picker is the best answer
     * available and still has to be honoured.
     */
    public function test_the_session_choice_is_used_when_there_is_no_till(): void
    {
        $sessionBranch = $this->makeBranch( 'สาขาที่เลือกไว้ไม่มีลิ้นชัก' );

        session( [ 'selected_branch_id' => $sessionBranch ] );

        $resolved = $this->resolve( [] );

        session()->forget( 'selected_branch_id' );

        $this->assertSame( $sessionBranch, $resolved );
    }

    /**
     * A till whose branch was never filled in cannot answer, so the cashier's own branch does.
     */
    public function test_the_cashiers_branch_is_used_when_the_till_says_nothing(): void
    {
        $branchId = $this->makeBranch( 'สาขาของพนักงาน' );
        $registerId = $this->makeRegister( null );

        $user = User::first();
        $originalBranch = $user->branch_id;

        $user->branch_id = $branchId;
        $user->save();

        Auth::login( $user );

        $resolved = $this->resolve( [ 'register_id' => $registerId ] );

        Auth::logout();
        $user->branch_id = $originalBranch;
        $user->save();

        $this->assertSame( $branchId, $resolved );
    }

    /**
     * Seeders and imports run with no till and nobody signed in. Those belong to the main branch,
     * found by its flag rather than by assuming it is numbered one.
     */
    public function test_a_sale_with_no_till_and_no_user_goes_to_the_main_branch(): void
    {
        $this->clearMainBranchFlag();

        $mainBranchId = $this->makeBranch( 'สาขาหลักทดสอบ', isMain: true );

        Auth::logout();

        $this->assertSame( $mainBranchId, $this->resolve( [] ) );
    }

    /**
     * The behaviour that was wrong: with no session, no till and no user branch, the branch used to
     * be the literal number one whatever the data said. Renumbering the main branch proves the flag
     * is read rather than the number assumed.
     */
    public function test_the_main_branch_is_found_by_its_flag_not_by_its_number(): void
    {
        $this->clearMainBranchFlag();

        /**
         * A filler goes first so the main branch cannot be the one numbered one. A resolver that
         * assumed the number would answer with the filler and fail this.
         */
        $this->makeBranch( 'สาขาที่ไม่ใช่สาขาหลัก' );

        $newMainId = $this->makeBranch( 'สาขาหลักใหม่', isMain: true );

        $this->assertGreaterThan( 1, $newMainId );

        Auth::logout();

        $this->assertSame( $newMainId, $this->resolve( [] ) );
    }

    /**
     * The fixture may or may not carry a main branch, so the flag is cleared first to make either
     * starting point behave the same. DatabaseTransactions rolls this back.
     */
    private function clearMainBranchFlag(): void
    {
        DB::table( 'nexopos_branches' )->where( 'is_main', 1 )->update( [ 'is_main' => 0 ] );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolve( array $fields ): int
    {
        $order = new Order;
        $order->register_id = $fields[ 'register_id' ] ?? null;

        $method = new \ReflectionMethod( OrdersService::class, 'resolveOrderBranch' );

        return $method->invoke( $this->ordersService, $fields, $order );
    }

    private function makeBranch( string $name, bool $isMain = false ): int
    {
        return (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'T' . uniqid(),
            'name' => $name,
            'is_main' => $isMain,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    private function makeRegister( ?int $branchId ): int
    {
        return (int) DB::table( 'nexopos_registers' )->insertGetId( [
            'name' => 'จุดคิดเงินทดสอบ ' . uniqid(),
            'branch_id' => $branchId,
            'status' => Register::STATUS_CLOSED,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }
}
