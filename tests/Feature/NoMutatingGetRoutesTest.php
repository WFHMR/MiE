<?php

namespace Tests\Feature;

use App\Models\Procurement;
use App\Models\ProductAdjustment;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\User;
use App\Models\WithholdingTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Nothing that changes data answers a GET.
 *
 * Eleven endpoints did. Settling a supplier invoice, executing a stock adjustment, cancelling a withholding
 * certificate, cloning a role, resetting a product, recomputing its prices, refreshing a purchase, triggering
 * a transaction, writing an offsetting ledger entry, spending a one-time permission grant, and rewriting the
 * chart of accounts back to its defaults — each of them was reached by following a link, six of them straight
 * from a row action in a table.
 *
 * A GET is repeated by things nobody chose to repeat: a browser prefetching a link it can see, a bookmark
 * opened weeks later, a url pasted into a chat that unfurls it, a proxy retrying a request that looked slow.
 * The confirm dialogs on those row actions guarded the mouse, not the request.
 *
 * This file pins both halves: the old paths refuse a GET, and the POST that replaced each of them still does
 * the job.
 *
 * The refusals below are asserted as 405, which is what the framework's own handler answers and therefore
 * what runs here. The shop answers the same refusal with a 500 body carrying the same message, because
 * bootstrap/exceptions.php installs a catch-all that gives every framework exception a 500 and only steps
 * aside while APP_DEBUG is on. That difference is not a hole — the request is refused either way and nothing
 * runs — but it cannot be pinned from inside this process: the file is include_once'd, so the production
 * handler cannot be booted a second time in a test. See PENDING-WORK-RESUME.md §40.10.
 */
class NoMutatingGetRoutesTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * The paths that used to change something on a GET, with what each one does.
     *
     * @return array<string, string>
     */
    private function pathsThatMustNotAnswerGet(): array
    {
        return [
            'api/procurements/{procurement}/set-as-paid' => 'settles a supplier invoice',
            'api/procurements/{id}/refresh' => 'rewrites a purchase\'s totals',
            'api/products/adjustments/{adjustment}/execute' => 'writes counted figures onto the shelves',
            'api/products/{identifier}/reset' => 'clears a product\'s stock',
            'api/products/{identifier}/refresh-prices' => 'recomputes a product\'s prices',
            'api/transactions/trigger/{transaction?}' => 'runs a transaction and books it',
            'api/transactions/history/{history}/create-reflection' => 'writes an offsetting ledger entry',
            'api/transactions-accounts/reset-defaults' => 'rewrites the chart of accounts',
            'api/user/access/{access}/use' => 'spends a one-time permission grant',
            'api/users/roles/{role}/clone' => 'creates a role',
            'api/withholding-tax/{withholdingTax}/cancel' => 'cancels a numbered tax certificate',
        ];
    }

    /**
     * Read from the route table rather than by making requests, so the check cannot be satisfied by an
     * endpoint that happens to fail for an unrelated reason.
     */
    public function test_none_of_them_is_registered_as_a_get_route(): void
    {
        $stillGet = [];

        foreach ( Route::getRoutes() as $route ) {
            if ( ! in_array( 'GET', $route->methods(), true ) ) {
                continue;
            }

            $reason = $this->pathsThatMustNotAnswerGet()[ $route->uri() ] ?? null;

            if ( $reason !== null ) {
                $stillGet[] = sprintf( '%s (%s)', $route->uri(), $reason );
            }
        }

        $this->assertSame(
            [],
            $stillGet,
            "These change data and answer a GET, so following a link performs them:\n  " . implode( "\n  ", $stillGet )
        );
    }

    /**
     * Each of them is registered as a POST, so the capability was moved rather than removed.
     */
    public function test_each_of_them_is_registered_as_a_post_route(): void
    {
        $registered = [];

        foreach ( Route::getRoutes() as $route ) {
            if ( in_array( 'POST', $route->methods(), true ) ) {
                $registered[] = $route->uri();
            }
        }

        foreach ( array_keys( $this->pathsThatMustNotAnswerGet() ) as $path ) {
            $this->assertContains(
                $path,
                $registered,
                sprintf( '%s answers neither a GET nor a POST, so the action was lost rather than moved.', $path )
            );
        }
    }

    /**
     * No row action in any table asks for one of these with a GET either. Six of them did, and a row action
     * of type GOTO or GET is what turned "following a link" from a hypothetical into the actual mechanism.
     */
    public function test_no_table_row_action_reaches_them_with_a_get(): void
    {
        $offenders = [];

        foreach ( glob( app_path( 'Crud/*.php' ) ) as $file ) {
            $contents = (string) file_get_contents( $file );

            /**
             * Actions are declared as a run of named arguments, so the type and the url of one action sit
             * within the same call. Matched as a block between one action( and the next.
             */
            foreach ( preg_split( '/\$entry->action\(/', $contents ) as $index => $block ) {
                if ( $index === 0 ) {
                    continue;
                }

                $block = substr( $block, 0, (int) strpos( $block . ');', ');' ) );

                if ( ! preg_match( '/type:\s*\'(GET|GOTO)\'/', $block ) ) {
                    continue;
                }

                foreach ( [ 'set-as-paid', '/refresh', '/execute', '/clone', '/cancel', 'create-reflection', 'trigger/', '/reset' ] as $needle ) {
                    if ( str_contains( $block, $needle ) ) {
                        $offenders[] = basename( $file ) . ' → ' . $needle;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These row actions still reach a data-changing endpoint by following a link:\n  " . implode( "\n  ", $offenders )
        );
    }

    public function test_settling_a_purchase_works_as_a_post_and_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $procurement = Procurement::where( 'payment_status', Procurement::PAYMENT_UNPAID )->first();

        if ( ! $procurement instanceof Procurement ) {
            $this->markTestSkipped( 'The fixture holds no unpaid purchase to settle.' );
        }

        $this->json( 'GET', 'api/procurements/' . $procurement->id . '/set-as-paid' )
            ->assertStatus( 405 );

        $this->assertSame(
            Procurement::PAYMENT_UNPAID,
            $procurement->fresh()->payment_status,
            'A GET settled the invoice.'
        );

        $this->json( 'POST', 'api/procurements/' . $procurement->id . '/set-as-paid' )
            ->assertOk();

        $this->assertSame( Procurement::PAYMENT_PAID, $procurement->fresh()->payment_status );
    }

    public function test_cloning_a_role_works_as_a_post_and_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $role = Role::namespace( 'nexopos.store.cashier' );

        $before = Role::count();

        $this->json( 'GET', 'api/users/roles/' . $role->id . '/clone' )->assertStatus( 405 );

        $this->assertSame( $before, Role::count(), 'A GET cloned the role.' );

        $this->json( 'POST', 'api/users/roles/' . $role->id . '/clone' )->assertOk();

        $this->assertSame( $before + 1, Role::count() );
    }

    public function test_cancelling_a_withholding_certificate_works_as_a_post_and_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $certificate = $this->givenWithholdingCertificate( $admin );

        $this->json( 'GET', 'api/withholding-tax/' . $certificate->id . '/cancel' )->assertStatus( 405 );

        $this->assertSame( 'issued', $certificate->fresh()->status, 'A GET cancelled the certificate.' );

        $this->json( 'POST', 'api/withholding-tax/' . $certificate->id . '/cancel', [
            'reason' => 'ยกเลิกโดยชุดทดสอบ',
        ] )->assertOk();

        $this->assertSame( 'cancelled', $certificate->fresh()->status );
    }

    public function test_resetting_the_chart_of_accounts_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $before = TransactionAccount::count();

        $this->json( 'GET', 'api/transactions-accounts/reset-defaults' )->assertStatus( 405 );

        $this->assertSame( $before, TransactionAccount::count(), 'A GET rewrote the chart of accounts.' );
    }

    public function test_triggering_a_transaction_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $transaction = Transaction::first();

        if ( ! $transaction instanceof Transaction ) {
            $this->markTestSkipped( 'The fixture holds no transaction to trigger.' );
        }

        $this->json( 'GET', 'api/transactions/trigger/' . $transaction->id )->assertStatus( 405 );
    }

    public function test_executing_a_stock_adjustment_refuses_a_get(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $adjustment = ProductAdjustment::first();

        if ( ! $adjustment instanceof ProductAdjustment ) {
            $this->markTestSkipped( 'The fixture holds no stock adjustment.' );
        }

        $this->json( 'GET', 'api/products/adjustments/' . $adjustment->id . '/execute' )->assertStatus( 405 );
    }

    private function givenWithholdingCertificate( User $author ): WithholdingTax
    {
        $certificate = new WithholdingTax;
        $certificate->sequence_scope = (string) now()->year;
        $certificate->sequence_number = (int) ( WithholdingTax::max( 'sequence_number' ) ?? 0 ) + 1;
        $certificate->number = 'WHT-GETGUARD-' . uniqid();
        $certificate->form = 'pnd53';
        $certificate->payee_type = 'juristic';
        $certificate->payee_name = 'ผู้รับเงินสำหรับทดสอบ';
        $certificate->payee_tax_id = '0000000000000';
        $certificate->income_type = 'ค่าบริการ';
        $certificate->base_amount = 1000;
        $certificate->gross_amount = 1000;
        $certificate->rate = 3;
        $certificate->tax_amount = 30;
        $certificate->paid_on = now()->toDateString();
        $certificate->author_id = $author->id;
        $certificate->save();

        return $certificate;
    }
}
