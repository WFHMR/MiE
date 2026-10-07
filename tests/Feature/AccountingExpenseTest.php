<?php

namespace Tests\Feature;

use App\Fields\ReccurringTransactionFields;
use App\Fields\ScheduledTransactionFields;
use App\Jobs\DetectScheduledTransactionsJob;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\TransactionHistory;
use App\Services\DateService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class AccountingExpenseTest extends TestCase
{
    use WithAuthentication, WithFaker;

    /**
     * Every expense booked here triggers a reflection, and the reflection reads
     * ns_accounting_default_paid_expense_offset_account. When that option does not resolve to an
     * account the service throws "The offset account is not found." and the expense is never booked.
     *
     * A shop gets that option written for it the moment the chart of accounts is generated. This
     * pipeline builds its accounts from the schema instead and never calls that routine, so the
     * option row was simply absent and all six tests in this file died on it. The shop's own
     * database has the option set and pointing at a real account, so this was a missing fixture
     * rather than a fault in the service.
     *
     * The option is pointed at an existing account rather than regenerated, because regenerating
     * truncates nexopos_transactions_accounts and the tests that share this database afterwards
     * hold account ids of their own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->givenAPaidExpenseOffsetAccount();
    }

    /**
     * Leaves the option alone when it already resolves, so a configured database is never rewritten.
     */
    private function givenAPaidExpenseOffsetAccount(): void
    {
        $configured = ns()->option->get( 'ns_accounting_default_paid_expense_offset_account' );

        if ( TransactionAccount::find( $configured ) instanceof TransactionAccount ) {
            return;
        }

        /**
         * An asset account is what the generator picks, so it is preferred here. The category only
         * decides the wording, not whether the reflection can be written, hence the fallback.
         */
        $account = TransactionAccount::where( 'category_identifier', 'assets' )->first()
            ?: TransactionAccount::first();

        $this->assertInstanceOf(
            TransactionAccount::class,
            $account,
            'The chart of accounts is empty, so no expense could ever be offset.'
        );

        ns()->option->set( 'ns_accounting_default_paid_expense_offset_account', $account->id );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_start_of_month()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Rent',
            'occurrence' => Transaction::OCCURRENCE_START_OF_MONTH,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_scheduled_transaction()
    {
        $this->attemptAuthenticate();

        $date = app()->make( DateService::class );

        $transaction = $this->createTransaction( [
            'name' => 'Scheduled Transaction',
            'occurrence' => null,
            'active' => true,
            'recurring' => false,
            'type' => ScheduledTransactionFields::getIdentifier(),
            'scheduled_date' => $date->copy()->addMinutes( 2 )->toDateTimeString(),
        ] );

        $this->executeScheduledTransaction( $transaction );
    }

    private function executeScheduledTransaction( $transaction )
    {
        $scheduledCarbon = Carbon::parse( $transaction->scheduled_date );

        ns()->date->setDateTimeFrom( $scheduledCarbon );

        DetectScheduledTransactionsJob::dispatchSync();

        $cashFlow = TransactionHistory::where( 'transaction_id', $transaction->id )->first();

        $this->assertTrue( $cashFlow instanceof TransactionHistory, 'No cash flow record were saved after the scheduled transaction.' );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_specific_day()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Rent',
            'occurrence' => Transaction::OCCURRENCE_SPECIFIC_DAY,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_end_of_month()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Delivery',
            'occurrence' => Transaction::OCCURRENCE_END_OF_MONTH,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_middle_of_month()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Reccurring Middle Of Month',
            'occurrence' => Transaction::OCCURRENCE_MIDDLE_OF_MONTH,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_x_after_month_starts()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Reccurring On Month Starts',
            'occurrence' => Transaction::OCCURRENCE_X_AFTER_MONTH_STARTS,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_reccurring_transaction_on_x_before_month_ends()
    {
        $this->attemptAuthenticate();

        $transaction = $this->createTransaction( [
            'name' => 'Reccurring On Month Ends',
            'occurrence' => Transaction::OCCURRENCE_X_BEFORE_MONTH_ENDS,
        ] );

        $this->executeReccuringTransaction( $transaction );
    }

    private function createTransaction( $config )
    {
        /**
         * We don't want to execute multiple transactions
         * during this test.
         */
        Transaction::truncate();

        $occurrence = $config[ 'occurrence' ] ?? Transaction::OCCURRENCE_START_OF_MONTH;

        $range = match ( $occurrence ) {
            Transaction::OCCURRENCE_START_OF_MONTH,
            Transaction::OCCURRENCE_END_OF_MONTH,
            Transaction::OCCURRENCE_MIDDLE_OF_MONTH => 1,
            Transaction::OCCURRENCE_SPECIFIC_DAY => 28,
            Transaction::OCCURRENCE_X_BEFORE_MONTH_ENDS,
            Transaction::OCCURRENCE_X_AFTER_MONTH_STARTS => 10,
            default => 1,
        };

        $response = $this->json( 'POST', '/api/transactions', [
            'name' => $config[ 'name' ] ?? $this->faker->paragraph( 2 ),
            'active' => true,
            'account_id' => TransactionAccount::get( 'id' )->random()->id,
            'description' => $this->faker->paragraph( 5 ),
            'value' => $this->faker->randomNumber( 2 ),
            'recurring' => $config[ 'recurring' ] ?? true,
            'type' => $config[ 'type' ] ?? ReccurringTransactionFields::getIdentifier(),
            'group_id' => $config[ 'group_id' ] ?? null,
            'occurrence' => $occurrence,
            'scheduled_date' => $config[ 'scheduled_date'] ?? null,
            'occurrence_value' => $this->faker->numberBetween( 1, $range ),
        ] );

        $response->assertJsonPath( 'status', 'success' );
        $responseJson = $response->json();
        $transaction = $responseJson[ 'data' ][ 'transaction' ];

        return Transaction::find( $transaction[ 'id' ] );
    }

    private function executeReccuringTransaction( $transaction )
    {
        $currentDay = now()->startOfMonth();

        /**
         * @var TransactionService
         */
        $transactionService = app()->make( TransactionService::class );

        while ( ! $currentDay->isLastOfMonth() ) {
            $result = $transactionService->handleRecurringTransactions( $currentDay );

            switch ( $transaction->occurrence ) {
                case Transaction::OCCURRENCE_START_OF_MONTH:
                    if ( (int) $currentDay->day === 1 ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered at the first day of the month.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
                case Transaction::OCCURRENCE_END_OF_MONTH:
                    if ( $currentDay->isLastOfMonth() ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered at the last day of the month.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
                case Transaction::OCCURRENCE_MIDDLE_OF_MONTH:
                    if ( (int) $currentDay->day === 15 ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered at the middle of the month.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
                case Transaction::OCCURRENCE_SPECIFIC_DAY:
                    if ( (int) $currentDay->day === (int) $transaction->occurrence_value ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered at a specific day of the month.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
                case Transaction::OCCURRENCE_X_AFTER_MONTH_STARTS:
                    if ( (int) $currentDay->copy()->startOfMonth()->addDays( $transaction->occurrence_value )->isSameDay( $currentDay ) ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered x days after the month started.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
                case Transaction::OCCURRENCE_X_BEFORE_MONTH_ENDS:
                    if ( (int) $currentDay->copy()->endOfMonth()->subDays( $transaction->occurrence_value )->isSameDay( $currentDay ) ) {
                        $this->assertTrue(
                            $result[ 'data' ][0][ 'status' ] === 'success',
                            'The transaction hasn\'t been triggered x days before the month ended.'
                        );

                        $resultTransactionId = (int) $result[ 'data' ][0][ 'data' ][ 'histories' ]->first()->transaction_id ?? 0;

                        $this->assertTrue(
                            $resultTransactionId === (int) $transaction->id,
                            'The transaction id is not matching the one that executed.'
                        );
                    }
                    break;
            }

            $currentDay->addDay();
        }
    }
}
