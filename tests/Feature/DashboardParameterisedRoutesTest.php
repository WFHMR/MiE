<?php

namespace Tests\Feature;

use App\Models\BillingNote;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAccountHistory;
use App\Models\CustomerCoupon;
use App\Models\CustomerGroup;
use App\Models\CustomerReward;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\PaymentType;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Register;
use App\Models\RewardSystem;
use App\Models\Role;
use App\Models\ScaleRange;
use App\Models\StockTransfer;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\TaxInvoice;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Models\WithholdingTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Every dashboard page that takes a parameter, opened with a real record behind it.
 *
 * TestOtherGetRoutes covers the dashboard, but it skips any route whose uri holds a {parameter}, which
 * is 57 of them — every edit form, every receipt, every per-record listing. Nothing opened those, and a
 * route that names a controller method which does not exist registers perfectly quietly and only fails
 * when somebody clicks the link. Two such routes were found this way, one of them behind the Edit button
 * on the customer account history list.
 *
 * The point of this file is that a broken page is a failing test rather than a support call. It resolves
 * each parameter to a row that actually exists, so a 404 here means the page is wrong rather than the
 * fixture being empty, and it treats a 500 as a failure with the route named in the message.
 */
class DashboardParameterisedRoutesTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * Parameters that cannot be resolved to a record, with the reason. Anything that ends up skipped
     * without being listed here fails the test, so a new unreachable page cannot be added silently.
     *
     * @var array<string, string>
     */
    private array $unresolvable = [
        'dashboard/crud/download/{hash}' => 'the hash names a file produced by an export that has to have been run first',
        'dashboard/modules/download/{identifier}' => 'downloads a zip of a module, which is a file transfer rather than a page',

        /**
         * These three print a document built from an order, and each already has a suite that builds the
         * order, issues the document and opens the page. Reproducing that here would duplicate the harder
         * half of three files to add nothing.
         */
        'dashboard/orders/tax-invoice/{taxInvoice}' => 'opened by ThaiTaxInvoiceTest and ThaiTaxInvoiceA4FormTest',
        'dashboard/orders/billing-note/{billingNote}' => 'opened by ThaiBillingNoteTest',
        'dashboard/taxes/withholding/certificate/{withholdingTax}' => 'opened by ThaiWithholdingTaxTest',
    ];

    public function test_every_parameterised_dashboard_page_opens(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->givenTheRecordsTheFixtureLacks( $admin );

        $resolved = 0;
        $skipped = [];
        $failures = [];

        foreach ( Route::getRoutes() as $route ) {
            if ( ! in_array( 'GET', $route->methods(), true ) ) {
                continue;
            }

            $uri = $route->uri();

            if ( ! str_contains( $uri, 'dashboard' ) || str_contains( $uri, 'api/' ) ) {
                continue;
            }

            if ( ! preg_match( '/\{\w+\??\}/', $uri ) ) {
                continue;
            }

            if ( isset( $this->unresolvable[ $uri ] ) ) {
                continue;
            }

            $target = $this->substitute( $uri );

            if ( $target === null ) {
                $skipped[] = $uri;

                continue;
            }

            $resolved++;

            /**
             * Requested with the session guard. The web group runs AuthenticateSession, which asks the
             * guard whether the user arrived on a remember cookie, and Sanctum's request guard has no such
             * method — so authenticating the way the api suites do turns every page into a 500 that says
             * nothing about the page.
             */
            $response = $this->actingAs( $admin )->get( '/' . ltrim( $target, '/' ) );

            /**
             * getStatusCode() rather than status(): the barcode route answers with a file, and
             * TestResponse::status() forwards to a Symfony BinaryFileResponse which has no such method.
             */
            $status = $response->getStatusCode();

            /**
             * A 500 is always wrong. A 404 is wrong too here, because the parameter was filled with a
             * row read out of the database a moment earlier.
             */
            if ( $status >= 500 || $status === 404 ) {
                /**
                 * The reason travels with the failure. Without it this says only that one of fifty pages
                 * broke, which leaves the next person to reproduce it by hand.
                 */
                $failures[] = sprintf(
                    '%s -> %s answered %d%s',
                    $uri,
                    $target,
                    $status,
                    $status >= 500 ? ': ' . $this->firstExceptionLine( $response->getContent() ) : ''
                );
            }
        }

        $this->assertSame(
            [],
            $skipped,
            'These pages could not be opened because no record existed to point them at. Either the '
                . 'fixture needs one or the route belongs in $unresolvable with a reason: '
                . implode( ', ', $skipped )
        );

        $this->assertSame(
            [],
            $failures,
            "Dashboard pages that do not open:\n  " . implode( "\n  ", $failures )
        );

        $this->assertGreaterThan(
            40,
            $resolved,
            'Far fewer pages were exercised than this application has, which means the resolver stopped '
                . 'matching rather than the pages being fine.'
        );
    }

    /**
     * The api side of the same gap: the GET endpoints that carry a parameter, which TestOtherGetRoutes skips
     * for the same reason it skips the pages.
     *
     * Every one of them is called now. There used to be eleven that changed something on a GET — settle a
     * supplier invoice, execute a stock adjustment, cancel a withholding certificate, clone a role, reset a
     * product, trigger a transaction, write an offsetting ledger entry, spend a permission grant, rewrite the
     * chart of accounts — and those had to be listed and skipped, because calling them would have done all of
     * that to the fixture. They are POST now, so they are not GET routes at all and the sweep never meets
     * them; the companion test below is what keeps them from coming back.
     */
    public function test_every_parameterised_api_endpoint_that_reads_answers_without_failing(): void
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $admin );

        $this->givenTheRecordsTheFixtureLacks( $admin );

        /**
         * Endpoints that need something to have happened first, with what that is.
         *
         * @var array<string, string>
         */
        $unreachable = [
            'api/marketplace/licenses/{item}' => 'names a licence bought from a marketplace this install is not connected to',
            'api/procurements/preload/{uuid}' => 'the parameter is a cache key written by POST procurements/preload, not a procurement uuid, so it only exists after that call',
        ];

        $resolved = 0;
        $failures = [];
        $skipped = [];

        foreach ( Route::getRoutes() as $route ) {
            if ( ! in_array( 'GET', $route->methods(), true ) ) {
                continue;
            }

            $uri = $route->uri();

            if ( ! str_starts_with( $uri, 'api/' ) || ! preg_match( '/\{\w+\??\}/', $uri ) ) {
                continue;
            }

            if ( isset( $unreachable[ $uri ] ) ) {
                continue;
            }

            $target = $this->substituteApi( $uri );

            if ( $target === null ) {
                $skipped[] = $uri;

                continue;
            }

            $resolved++;

            $response = $this->getJson( '/' . ltrim( $target, '/' ) );

            if ( $response->getStatusCode() >= 500 ) {
                $failures[] = sprintf(
                    '%s -> %s answered %d: %s',
                    $uri,
                    $target,
                    $response->getStatusCode(),
                    $this->firstExceptionLine( (string) $response->getContent() )
                );
            }
        }

        $this->assertSame( [], $skipped, 'Endpoints whose parameters could not be filled: ' . implode( ', ', $skipped ) );

        $this->assertSame( [], $failures, "Api endpoints that fail:\n  " . implode( "\n  ", $failures ) );

        $this->assertGreaterThan( 50, $resolved );
    }

    /**
     * Fills an api uri's parameters. "id" means a different table on almost every one of these, so the
     * path decides which, and only then does the parameter name.
     */
    private function substituteApi( string $uri ): ?string
    {
        /**
         * Endpoints whose parameter is not a record id at all, filled with the values the application
         * itself asks for. Read off the callers in resources/ts rather than guessed: the field and form
         * endpoints name a FieldsService or a SettingsPage, and the settings endpoint names a settings
         * page, so a crud namespace or a product id there produces a 500 that says nothing about the
         * endpoint. The optional trailing segment is left off, which is how the application calls them.
         */
        $byExactUri = [
            'api/fields/{resource}/{identifier?}' => fn () => 'api/fields/ns.login',
            'api/forms/{resource}/{identifier?}' => fn () => 'api/forms/ns.pos-addresses',
            'api/settings/{identifier}' => fn () => 'api/settings/pos',
            'api/modules/{argument?}' => fn () => 'api/modules',
            'api/products/search/using-barcode/{product}' => function () {
                $barcode = ProductUnitQuantity::whereNotNull( 'barcode' )
                    ->where( 'barcode', '!=', '' )
                    ->value( 'barcode' );

                return $barcode === null ? null : 'api/products/search/using-barcode/' . $barcode;
            },

            /**
             * The stock page's own barcode lookup, which needs a branch alongside the code: the operator
             * picks the branch they are counting, so a code without one cannot be answered. The branch of
             * the row itself is used where it carries one, so the lookup is asked a question that has an
             * answer rather than one that is merely well formed.
             */
            'api/inventory/scan/lookup/{barcode}' => function () {
                $row = ProductUnitQuantity::whereNotNull( 'barcode' )
                    ->where( 'barcode', '!=', '' )
                    ->first();

                if ( $row === null ) {
                    return null;
                }

                $branchId = $row->branch_id ?: Branch::value( 'id' );

                return $branchId === null
                    ? null
                    : 'api/inventory/scan/lookup/' . rawurlencode( (string) $row->barcode ) . '?branch_id=' . $branchId;
            },
        ];

        if ( isset( $byExactUri[ $uri ] ) ) {
            return ( $byExactUri[ $uri ] )();
        }

        $byPath = [
            'api/cash-registers' => fn () => Register::value( 'id' ),
            'api/categories' => fn () => ProductCategory::value( 'id' ),
            'api/customers-groups' => fn () => CustomerGroup::value( 'id' ),
            'api/orders' => fn () => Order::value( 'id' ),
            'api/procurements' => fn () => Procurement::value( 'id' ),
            'api/providers' => fn () => Provider::value( 'id' ),
            'api/taxes/groups' => fn () => TaxGroup::value( 'id' ),
            'api/taxes' => fn () => Tax::value( 'id' ),
            'api/transactions-accounts' => fn () => TransactionAccount::value( 'id' ),
            'api/transactions' => fn () => Transaction::value( 'id' ),
            'api/units-groups' => fn () => UnitGroup::value( 'id' ),
            'api/units' => fn () => Unit::value( 'id' ),
            'api/user/access' => fn () => User::value( 'id' ),

            /**
             * The crud endpoints are asked for with namespace ns.products below, so the id that follows is
             * a product's.
             */
            'api/crud' => fn () => Product::value( 'id' ),
        ];

        $byName = [
            'register' => fn () => Register::value( 'id' ),
            'customer' => fn () => Customer::value( 'id' ),
            'namespace' => fn () => 'ns.products',
            'resource' => fn () => 'ns.products',
            'identifier' => fn () => Product::value( 'id' ),
            'argument' => fn () => 'NexoPOS',
            'order' => fn () => Order::value( 'id' ),
            'doc' => fn () => 'receipt',
            'uuid' => fn () => Procurement::whereNotNull( 'uuid' )->value( 'uuid' ),
            'adjustment' => fn () => ProductAdjustment::value( 'id' ),
            'product' => fn () => Product::value( 'id' ),
            'unit' => fn () => Unit::value( 'id' ),
            'procurement' => fn () => Procurement::value( 'id' ),
            'transaction' => fn () => Transaction::value( 'id' ),
            'role' => fn () => Role::value( 'id' ),
            'withholdingTax' => fn () => WithholdingTax::value( 'id' ),
        ];

        if ( preg_match_all( '/\{(\w+)\??\}/', $uri, $matches ) ) {
            foreach ( $matches[ 1 ] as $name ) {
                $resolver = null;

                if ( $name === 'id' ) {
                    foreach ( $byPath as $prefix => $candidate ) {
                        if ( str_starts_with( $uri, $prefix ) ) {
                            $resolver = $candidate;

                            break;
                        }
                    }
                } else {
                    $resolver = $byName[ $name ] ?? null;
                }

                if ( $resolver === null ) {
                    return null;
                }

                $value = $resolver();

                if ( empty( $value ) ) {
                    return null;
                }

                $uri = str_replace( [ '{' . $name . '}', '{' . $name . '?}' ], (string) $value, $uri );
            }
        }

        return $uri;
    }

    /**
     * Pulls the exception class and message out of the error page so a failure reads as a cause rather
     * than as a status code.
     */
    private function firstExceptionLine( string $body ): string
    {
        if ( preg_match( '/<title>(.*?)<\/title>/s', $body, $matches ) ) {
            return trim( html_entity_decode( strip_tags( $matches[ 1 ] ) ) );
        }

        return trim( substr( strip_tags( $body ), 0, 200 ) );
    }

    /**
     * The shared fixture holds no accounting transaction, no withholding tax record and no stock
     * transfer, so the pages that show one had nothing to be pointed at. They are created here rather
     * than skipped, because a page nobody opens is where a fault sits unnoticed — the stock transfer list
     * was exactly that.
     */
    private function givenTheRecordsTheFixtureLacks( User $author ): void
    {
        if ( Transaction::count() === 0 ) {
            $transaction = new Transaction;
            $transaction->name = 'รายการทดสอบการเปิดหน้า';
            $transaction->type = Transaction::TYPE_DIRECT;
            $transaction->value = 100;
            $transaction->active = true;
            $transaction->author_id = $author->id;
            $transaction->account_id = TransactionAccount::value( 'id' );
            $transaction->save();
        }

        if ( WithholdingTax::count() === 0 ) {
            $withholding = new WithholdingTax;
            $withholding->sequence_scope = '2026';
            $withholding->sequence_number = 1;
            $withholding->number = 'WHT-PAGE-TEST-0001';
            $withholding->form = 'pnd53';
            $withholding->payee_type = 'juristic';
            $withholding->payee_name = 'ผู้รับเงินสำหรับทดสอบ';
            $withholding->payee_tax_id = '0000000000000';
            $withholding->income_type = 'ค่าบริการ';
            $withholding->base_amount = 1000;
            $withholding->gross_amount = 1000;
            $withholding->rate = 3;
            $withholding->tax_amount = 30;
            $withholding->paid_on = now()->toDateString();
            $withholding->author_id = $author->id;
            $withholding->save();
        }

        if ( StockTransfer::count() === 0 ) {
            $unitQuantity = ProductUnitQuantity::whereHas( 'product' )->first();

            if ( $unitQuantity instanceof ProductUnitQuantity ) {
                $branches = Branch::orderBy( 'id' )->pluck( 'id' );

                $transfer = new StockTransfer;
                $transfer->product_id = $unitQuantity->product_id;
                $transfer->unit_id = $unitQuantity->unit_id;
                $transfer->from_branch_id = (int) $branches->first();
                $transfer->to_branch_id = (int) ( $branches->count() > 1 ? $branches->get( 1 ) : $branches->first() );
                $transfer->quantity = 1;
                $transfer->status = 'pending';
                $transfer->requested_by = $author->id;
                $transfer->save();
            }
        }
    }

    /**
     * Fills a uri's parameters with the ids of records that exist, or returns null when one of them
     * cannot be satisfied.
     */
    private function substitute( string $uri ): ?string
    {
        /**
         * Matched on the whole uri first. "group" means three different things depending on the page it
         * appears on, and "product" means a procurement line rather than a product on one of them, so
         * the parameter name alone is not enough to identify the record.
         */
        $byUri = [
            'dashboard/customers/groups/edit/{group}' => fn () => CustomerGroup::value( 'id' ),
            'dashboard/taxes/groups/edit/{group}' => fn () => TaxGroup::value( 'id' ),
            'dashboard/units/groups/edit/{group}' => fn () => UnitGroup::value( 'id' ),
            'dashboard/procurements/products/edit/{product}' => fn () => ProcurementProduct::value( 'id' ),
            'dashboard/customers/{customer}/rewards/edit/{reward}' => fn () => CustomerReward::value( 'id' ),
            'dashboard/products/stock-adjustment/{history}' => fn () => ProductAdjustment::value( 'id' ),
            'dashboard/products/barcode/{barcode}' => fn () => ProductUnitQuantity::whereNotNull( 'barcode' )
                ->where( 'barcode', '!=', '' )
                ->value( 'barcode' ),
            'dashboard/settings/{settings}' => fn () => 'general',
        ];

        $byName = [
            'account' => fn () => TransactionAccount::value( 'id' ),
            'transaction' => fn () => Transaction::value( 'id' ),
            'branch' => fn () => Branch::value( 'id' ),
            'register' => fn () => Register::value( 'id' ),
            'coupon' => fn () => Coupon::value( 'id' ),
            'customer' => fn () => Customer::value( 'id' ),
            'customerAccountHistory' => fn () => CustomerAccountHistory::value( 'id' ),
            'customerCoupon' => fn () => CustomerCoupon::value( 'id' ),
            'reward' => fn () => RewardSystem::value( 'id' ),
            'billingNote' => fn () => BillingNote::value( 'id' ),
            'order' => fn () => Order::value( 'id' ),
            'orderPayment' => fn () => OrderPayment::value( 'id' ),
            'paymentType' => fn () => PaymentType::value( 'id' ),
            'refund' => fn () => OrderRefund::value( 'id' ),
            'taxInvoice' => fn () => TaxInvoice::value( 'id' ),
            'procurement' => fn () => Procurement::value( 'id' ),
            'product' => fn () => Product::value( 'id' ),
            'category' => fn () => ProductCategory::value( 'id' ),
            'scaleRange' => fn () => ScaleRange::value( 'id' ),
            'provider' => fn () => Provider::value( 'id' ),
            'tax' => fn () => Tax::value( 'id' ),
            'withholdingTax' => fn () => WithholdingTax::value( 'id' ),
            'unit' => fn () => Unit::value( 'id' ),
            'user' => fn () => User::value( 'id' ),
            'role' => fn () => Role::value( 'id' ),
            'id' => fn () => StockTransfer::value( 'id' ),
        ];

        if ( isset( $byUri[ $uri ] ) ) {
            $value = ( $byUri[ $uri ] )();

            if ( empty( $value ) ) {
                return null;
            }

            /** Only the parameter this entry names is filled here; the rest fall through to $byName. */
            $uri = preg_replace( '/\{' . $this->lastParameter( $uri ) . '\??\}/', (string) $value, $uri, 1 );
        }

        if ( preg_match_all( '/\{(\w+)\??\}/', $uri, $matches ) ) {
            foreach ( $matches[ 1 ] as $name ) {
                if ( ! isset( $byName[ $name ] ) ) {
                    return null;
                }

                $value = ( $byName[ $name ] )();

                if ( empty( $value ) ) {
                    return null;
                }

                $uri = str_replace( [ '{' . $name . '}', '{' . $name . '?}' ], (string) $value, $uri );
            }
        }

        return $uri;
    }

    /**
     * The name of the last parameter in a uri, which is the one the per-uri table above is keyed to.
     */
    private function lastParameter( string $uri ): string
    {
        preg_match_all( '/\{(\w+)\??\}/', $uri, $matches );

        return (string) end( $matches[ 1 ] );
    }
}
