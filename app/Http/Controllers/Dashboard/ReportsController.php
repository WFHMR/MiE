<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\DashboardController;
use App\Jobs\ComputeYearlyReportJob;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\WithholdingTax;
use App\Services\DailyReconciliationService;
use App\Services\DateService;
use App\Services\OrdersService;
use App\Services\ProductService;
use App\Services\ReportService;
use App\Services\ThaiWithholdingTaxService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;

class ReportsController extends DashboardController
{
    public function __construct(
        protected OrdersService $ordersService,
        protected ReportService $reportService,
        protected DateService $dateService
    ) {
        // ...
    }

    public function dailyReconciliation( Request $request )
    {
        ns()->restrict( 'nexopos.reports.sales' );
        $request->validate( ['date' => 'nullable|date_format:Y-m-d'] );
        $date = $request->input( 'date' ) ?: now()->toDateString();
        $user = Auth::user();
        $branchId = (int) app( ProductService::class )->branchIdForCurrentUser();
        abort_if( $branchId <= 0, 422, 'กรุณาเลือกสาขาก่อน' );
        if ( ! $user->hasRoles( ['admin'] ) && $user->branch_id && (int) $user->branch_id !== $branchId ) {
            abort( 403 );
        }
        $report = app( DailyReconciliationService::class )->report( $date, $branchId );

        return view( 'pages.dashboard.reports.daily-reconciliation', compact( 'report', 'date' ) )
            ->with( 'title', 'กระทบยอดบิลรายวัน' )->with( 'description', 'ตรวจทุกบิล รวมบิลค้างชำระ คืนสินค้า พัก และยกเลิก' );
    }

    public function salesReport()
    {
        return View::make( 'pages.dashboard.reports.sales-report', [
            'title' => __( 'Sales Report' ),
            'description' => __( 'Provides an overview over the sales during a specific period' ),
        ] );
    }

    public function salesProgress()
    {
        return View::make( 'pages.dashboard.reports.best-products-report', [
            'title' => __( 'Sales Progress' ),
            'description' => __( 'Provides an overview over the best products sold during a specific period.' ),
        ] );
    }

    public function soldStock()
    {
        return View::make( 'pages.dashboard.reports.sold-stock-report', [
            'title' => __( 'Sold Stock' ),
            'description' => __( 'Provides an overview over the sold stock during a specific period.' ),
        ] );
    }

    public function stockReport()
    {
        return View::make( 'pages.dashboard.reports.low-stock-report', [
            'title' => __( 'Stock Report' ),
            'description' => __( 'Provides an overview of the products stock.' ),
        ] );
    }

    public function profit()
    {
        return View::make( 'pages.dashboard.reports.profit-report', [
            'title' => __( 'Profit Report' ),
            'description' => __( 'Provides an overview of the provide of the products sold.' ),
        ] );
    }

    public function transactionsReport()
    {
        return View::make( 'pages.dashboard.reports.transactions', [
            'title' => __( 'Transactions Report' ),
            'description' => __( 'Provides an overview on the activity for a specific period.' ),
        ] );
    }

    public function stockCombinedReport()
    {
        return View::make( 'pages.dashboard.reports.stock-combined', [
            'title' => __( 'Combined Report' ),
            'description' => __( 'Provides a combined report for every transactions on products.' ),
        ] );
    }

    /**
     * get sales based on a specific time range
     *
     * @return array
     */
    public function getSaleReport( Request $request )
    {
        return $this->reportService
            ->getSaleReport(
                $request->input( 'startDate' ),
                $request->input( 'endDate' ),
                $request->input( 'type' ),
                $request->input( 'user_id' ),
                $request->input( 'categories_id' ),
            );
    }

    /**
     * get sold stock on a specific time range
     *
     * @return array
     */
    public function getSoldStockReport( Request $request )
    {
        $orders = $this->ordersService
            ->getSoldStock(
                startDate: $request->input( 'startDate' ),
                endDate: $request->input( 'endDate' ),
                categories: $request->input( 'categories' ),
                units: $request->input( 'units' ),
                providers: $request->input( 'providers' ) ?? []
            );

        /**
         * Supplier names fetched once for the ids the rows came back with, rather than a lookup per row.
         * Both columns feed this: a supplier may be named only as some product's default and never
         * appear on a purchase batch at all.
         */
        $providerNames = Provider::whereIn( 'id', $orders->pluck( 'provider_id' )
            ->merge( $orders->pluck( 'catalogue_provider_id' ) )
            ->filter()
            ->unique() )
            ->get()
            ->mapWithKeys( fn ( Provider $provider ) => [
                $provider->id => trim( $provider->first_name . ' ' . ( $provider->last_name ?? '' ) ),
            ] );

        /**
         * `mode` and `unit_price` used to be in this list and both were always wrong.
         *
         * getSoldStock() used to build its select list and then reassign the variable, rebuilding it
         * from the group columns plus the four SUM()s — which dropped `mode` and `unit_price` before the
         * query was ever run. Reading them off the result gave `mode` => null on every row, and summing
         * `unit_price` gave 0.00 on every row. Neither the sold stock screen nor the profit screen
         * displays either one, so nothing showed the zeros; they were simply served to anybody reading
         * the endpoint.
         *
         * Summing a unit price across a group would be meaningless anyway — a price per unit added
         * together over several sales is not a quantity of anything. So they are gone rather than
         * patched, and what remains is the four figures the query genuinely aggregates.
         */
        return collect( $orders )->mapToGroups( function ( $product ) {
            $provider = $this->resolveSoldStockProvider( $product );

            /**
             * Keyed by supplier and by where that supplier came from. A product bought from two
             * suppliers stays as two rows, and — less obviously — two lines that resolve to the same
             * supplier by different routes also stay apart. Merging those would produce one row carrying
             * both a confirmed purchase and an assumption under a single label, and the label would be
             * wrong for part of the money in it.
             */
            return [
                $product->product_id . '-' . $product->unit_id . '-'
                    . ( $provider[ 'id' ] ?? 'none' ) . '-' . $provider[ 'source' ] => $product,
            ];
        } )->map( function ( $groups ) use ( $providerNames ) {
            $provider = $this->resolveSoldStockProvider( $groups->first() );
            $providerId = $provider[ 'id' ];

            return [
                'name' => $groups->first()->name,
                'unit_name' => $groups->first()->unit_name,

                /**
                 * Said plainly rather than left blank. A blank cell reads as a missing supplier name;
                 * this says nothing is known about where these goods were bought.
                 */
                'provider_name' => $providerId === null
                    ? __( 'ไม่ระบุซัพพลายเออร์' )
                    : ( $providerNames[ $providerId ] ?? __( 'ซัพพลายเออร์ที่ถูกลบแล้ว' ) ),
                'provider_id' => $providerId,

                /**
                 * Shipped alongside the name, not folded into it. Whoever is closing the books needs to
                 * know which rows they can defend with a purchase document and which rows rest on the
                 * product's default supplier, and that difference cannot be recovered from the name.
                 */
                'provider_source' => $provider[ 'source' ],
                'provider_source_label' => $this->soldStockProviderSourceLabel( $provider[ 'source' ] ),
                'quantity' => $groups->sum( 'quantity' ),
                'total_price' => $groups->sum( 'total_price' ),
                'tax_value' => $groups->sum( 'tax_value' ),
            ];
        } )->values();
    }

    /**
     * Decides which of the two suppliers on a sold line to report, and says which one it chose.
     *
     * The batch wins whenever there is one, because it is evidence: goods arrived, an invoice was
     * settled, a provider was named on it. The product's default is consulted only in its absence, and
     * never silently — the source travels with the answer so the caller cannot present an assumption as
     * a fact. Neither present means neither is claimed.
     *
     * @return array{id: int|null, source: string}
     */
    private function resolveSoldStockProvider( object $line ): array
    {
        if ( $line->provider_id !== null ) {
            return [ 'id' => (int) $line->provider_id, 'source' => 'procurement' ];
        }

        if ( $line->catalogue_provider_id !== null ) {
            return [ 'id' => (int) $line->catalogue_provider_id, 'source' => 'catalogue' ];
        }

        return [ 'id' => null, 'source' => 'none' ];
    }

    /**
     * Wording aimed at the person reconciling the accounts rather than at a developer, which is why it
     * names the document each answer can be traced back to.
     */
    private function soldStockProviderSourceLabel( string $source ): string
    {
        return match ( $source ) {
            'procurement' => __( 'จากใบสั่งซื้อ' ),
            'catalogue' => __( 'จากทะเบียนสินค้า' ),
            default => __( 'ไม่มีข้อมูล' ),
        };
    }

    public function getAccountSummaryReport( Request $request )
    {
        return $this->reportService->getAccountSummaryReport(
            $request->input( 'startDate' ),
            $request->input( 'endDate' ),
        );
    }

    /**
     * get sold stock on a specific time range
     *
     *
     * @todo review
     *
     * @return array
     */
    public function getProfit( Request $request )
    {
        $orders = $this->ordersService
            ->getSoldStock(
                startDate: $request->input( 'startDate' ),
                endDate: $request->input( 'endDate' ),
                categories: $request->input( 'categories' ),
                units: $request->input( 'units' )
            );

        return $orders;
    }

    public function getAnnualReport( Request $request )
    {
        return $this->reportService->getYearReportFor( $request->input( 'year' ) );
    }

    public function annualReport( Request $request )
    {
        return View::make( 'pages.dashboard.reports.annual-report', [
            'title' => __( 'Annual Report' ),
            'description' => __( 'Provides an overview over the sales during a specific period' ),
        ] );
    }

    public function salesByPaymentTypes( Request $request )
    {
        return View::make( 'pages.dashboard.reports.payment-types', [
            'title' => __( 'ยอดขายแยกช่องทางการชำระเงิน' ),
            'description' => __( 'ยอดเงินที่รับเข้าแยกตามช่องทาง พร้อมรายการเลขอ้างอิงของช่องทางที่เงินเข้าภายหลัง (พร้อมเพย์ โอนธนาคาร บัตรสวัสดิการแห่งรัฐ) สำหรับกระทบยอดกับใบแจ้งยอด' ),
        ] );
    }

    public function vatReturnReport()
    {
        return View::make( 'pages.dashboard.reports.vat-return', [
            'title' => __( 'รายงานภาษีขาย (ภ.พ.30)' ),
            'description' => __( 'สรุปยอดขายและภาษีขายรายเดือน แยกตามสถานะภาษี เพื่อใช้ยื่นแบบ ภ.พ.30' ),
        ] );
    }

    public function getVatReturn( Request $request )
    {
        return $this->reportService->getVatReturnReport(
            $request->input( 'startDate' ),
            $request->input( 'endDate' ),
        );
    }

    public function getPaymentTypes( Request $request )
    {
        return $this->ordersService->getPaymentTypesReport(
            $request->input( 'startDate' ),
            $request->input( 'endDate' ),
        );
    }

    /**
     * The two delivery note registers the shop prints at the end of a day.
     *
     * Separate routes rather than one page with a toggle, because they are two sheets in
     * the shop's filing and staff reach for them by name. They share one view and one
     * service call: the columns and the totals are identical and only the set of orders
     * differs, so a copy of either would drift from the other.
     */
    public function deliveryNotesCashReport()
    {
        return View::make( 'pages.dashboard.reports.delivery-notes', [
            'title' => __( 'รายงานการออกใบส่งสินค้า/บิลเงินสด' ),
            'description' => __( 'ทะเบียนใบส่งสินค้าที่ชำระเงินครบแล้วในช่วงวันที่เลือก สำหรับกระทบยอดกับเงินที่รับเข้าในวันนั้น' ),
            'registerType' => ReportService::DELIVERY_NOTES_CASH,
        ] );
    }

    public function deliveryNotesConsignmentReport()
    {
        return View::make( 'pages.dashboard.reports.delivery-notes', [
            'title' => __( 'รายงานการออกใบส่งสินค้า/ใบฝากสินค้าชั่วคราว' ),
            'description' => __( 'ทะเบียนใบส่งสินค้าที่ส่งของออกไปแล้วแต่ยังไม่ได้รับเงินครบ สำหรับกระทบยอดกับสมุดลูกหนี้' ),
            'registerType' => ReportService::DELIVERY_NOTES_CONSIGNMENT,
        ] );
    }

    public function getDeliveryNotes( Request $request )
    {
        return $this->reportService->getDeliveryNoteIssuanceReport(
            $request->input( 'startDate' ),
            $request->input( 'endDate' ),
            (string) $request->input( 'type', ReportService::DELIVERY_NOTES_CASH ),
        );
    }

    public function receivablesReport()
    {
        return View::make( 'pages.dashboard.reports.receivables', [
            'title' => __( 'รายงานลูกหนี้การค้า (อายุหนี้)' ),
            'description' => __( 'ยอดค้างชำระแยกตามอายุหนี้และตามลูกค้า สำหรับติดตามเก็บเงินจากบิลที่ขายเป็นเครดิต' ),
        ] );
    }

    /**
     * The ageing is measured from a date the user picks rather than always from now, so a
     * shop can reproduce the position it reported at a month end.
     */
    public function getReceivables( Request $request )
    {
        return $this->ordersService->getReceivablesAging(
            $request->input( 'asOf' ),
        );
    }

    public function withholdingTaxReport()
    {
        return View::make( 'pages.dashboard.reports.withholding-tax', [
            'title' => __( 'รายงานภาษีหัก ณ ที่จ่าย (ภ.ง.ด.3/53)' ),
            'description' => __( 'สรุปภาษีที่หักไว้รายเดือน แยกตามผู้รับเงินและประเภทเงินได้ สำหรับกรอกแบบ ภ.ง.ด.3 (บุคคลธรรมดา) และ ภ.ง.ด.53 (นิติบุคคล)' ),
        ] );
    }

    /**
     * Both monthly returns for one period.
     *
     * Returned together because a shop that paid both an individual and a company in the
     * same month owes two separate filings, and seeing only one of them is how the other
     * gets missed.
     */
    public function getWithholdingTax( Request $request )
    {
        $service = app()->make( ThaiWithholdingTaxService::class );

        return [
            'pnd3' => $service->getPndSummary(
                WithholdingTax::FORM_PND3,
                $request->input( 'startDate' ),
                $request->input( 'endDate' ),
            ),
            'pnd53' => $service->getPndSummary(
                WithholdingTax::FORM_PND53,
                $request->input( 'startDate' ),
                $request->input( 'endDate' ),
            ),
        ];
    }

    public function computeReport( Request $request, $type )
    {
        if ( $type === 'yearly' ) {
            ComputeYearlyReportJob::dispatch( $request->input( 'year' ) );

            return [
                'stauts' => 'success',
                'message' => __( 'The report will be computed for the current year.' ),
            ];
        }

        throw new Exception( __( 'Unknown report to refresh.' ) );
    }

    public function getProductsReport( Request $request )
    {
        return $this->reportService->getProductSalesDiff(
            $request->input( 'startDate' ),
            $request->input( 'endDate' ),
            $request->input( 'sort' )
        );
    }

    public function getMyReport()
    {
        return $this->reportService->getCashierDashboard( Auth::id() );
    }

    public function getLowStock( Request $request )
    {
        return $this->reportService->getLowStockProducts(
            categories: $request->input( 'categories' ),
            units: $request->input( 'units' )
        );
    }

    public function getStockReport( Request $request )
    {
        return $this->reportService->getStockReport(
            categories: $request->input( 'categories' ),
            units: $request->input( 'units' )
        );
    }

    public function showCustomerStatement()
    {
        return View::make( 'pages.dashboard.reports.customers-statement', [
            'title' => __( 'Customers Statement' ),
            'description' => __( 'Display the complete customer statement.' ),
        ] );
    }

    public function getCustomerStatement( Customer $customer, Request $request )
    {
        return $this->reportService->getCustomerStatement(
            customer: $customer,
            rangeStarts: $request->input( 'rangeStarts' ),
            rangeEnds: $request->input( 'rangeEnds' )
        );
    }

    public function getProductHistoryCombined( Request $request )
    {
        return $this->reportService->getCombinedProductHistory(
            Carbon::parse( $request->input( 'date' ) )->format( 'Y-m-d' ),
            $request->input( 'categories' ),
            $request->input( 'units' )
        );
    }

    public function computeCombinedReport( Request $request )
    {
        return $this->reportService->computeCombinedReport( $request->input( 'date' ) );
    }
}
