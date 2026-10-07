<?php

namespace App\Services;

use App\Classes\Currency;
use App\Classes\Hook;
use App\Jobs\EnsureCombinedProductHistoryExistsJob;
use App\Models\ActiveTransactionHistory;
use App\Models\Customer;
use App\Models\CustomerAccountHistory;
use App\Models\DashboardDay;
use App\Models\DashboardMonth;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductHistory;
use App\Models\ProductHistoryCombined;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxInvoice;
use App\Models\TransactionAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\NsCommissions\Models\EarnedCommission;
use stdClass;

class ReportService
{
    private string $dayStarts;

    private string $dayEnds;

    public function __construct(
        protected DateService $dateService,
        protected ProductService $productService,
    ) {
        // ...
    }

    public function refreshFromDashboardDay( DashboardDay $todayReport )
    {
        $previousReport = DashboardDay::forLastRecentDay( $todayReport );

        /**
         * when the method is used without defining
         * the dayStarts and dayEnds, this method
         * create these values.
         */
        $this->defineDate( $todayReport );

        $this->computeUnpaidOrdersCount( $previousReport, $todayReport );
        $this->computeUnpaidOrders( $previousReport, $todayReport );
        $this->computePaidOrders( $previousReport, $todayReport );
        $this->computePaidOrdersCount( $previousReport, $todayReport );
        $this->computeOrdersTaxes( $previousReport, $todayReport );
        $this->computePartiallyPaidOrders( $previousReport, $todayReport );
        $this->computePartiallyPaidOrdersCount( $previousReport, $todayReport );
        $this->computeDiscounts( $previousReport, $todayReport );
        $this->computeIncome( $previousReport, $todayReport );
    }

    private function defineDate( DashboardDay $dashboardDay )
    {
        $this->dayStarts = empty( $this->dayStarts ) ? ( Carbon::parse( $dashboardDay->range_starts )->startOfDay()->toDateTimeString() ) : $this->dayStarts;
        $this->dayEnds = empty( $this->dayEnds ) ? ( Carbon::parse( $dashboardDay->range_ends )->endOfDay()->toDateTimeString() ) : $this->dayEnds;
    }

    /**
     * Will compute the report for the current day
     */
    public function computeDayReport( ?string $dateStart = null, ?string $dateEnd = null ): DashboardDay
    {
        $this->dayStarts = $dateStart ?: $this->dateService->copy()->startOfDay()->toDateTimeString();
        $this->dayEnds = $dateEnd ?: $this->dateService->copy()->endOfDay()->toDateTimeString();

        $todayReport = DashboardDay::firstOrCreate( [
            'range_starts' => $this->dayStarts,
            'range_ends' => $this->dayEnds,
            'day_of_year' => Carbon::parse( $this->dayStarts )->dayOfYear,
        ] );

        $this->refreshFromDashboardDay( $todayReport );

        $todayReport->save();

        return $todayReport;
    }

    public function computeDashboardMonth( Carbon $todayCarbon ): DashboardMonth
    {
        if ( $todayCarbon === null ) {
            $todayCarbon = $this->dateService->copy()->now();
        }

        $monthStarts = $todayCarbon->startOfMonth()->toDateTimeString();
        $monthEnds = $todayCarbon->endOfMonth()->toDateTimeString();

        $entries = DashboardDay::from( $monthStarts )
            ->to( $monthEnds );

        $dashboardMonth = DashboardMonth::from( $monthStarts )
            ->to( $monthEnds )
            ->first();

        if ( ! $dashboardMonth instanceof DashboardMonth ) {
            $dashboardMonth = new DashboardMonth;
            $dashboardMonth->range_starts = $monthStarts;
            $dashboardMonth->range_ends = $monthEnds;
            $dashboardMonth->month_of_year = $todayCarbon->month;
            $dashboardMonth->save();
        }

        $dashboardMonth->month_unpaid_orders = $entries->sum( 'day_unpaid_orders' );
        $dashboardMonth->month_unpaid_orders_count = $entries->sum( 'day_unpaid_orders_count' );
        $dashboardMonth->month_paid_orders = $entries->sum( 'day_paid_orders' );
        $dashboardMonth->month_paid_orders_count = $entries->sum( 'day_paid_orders_count' );
        $dashboardMonth->month_partially_paid_orders = $entries->sum( 'day_partially_paid_orders' );
        $dashboardMonth->month_partially_paid_orders_count = $entries->sum( 'day_partially_paid_orders_count' );
        $dashboardMonth->month_income = $entries->sum( 'day_income' );
        $dashboardMonth->month_discounts = $entries->sum( 'day_discounts' );
        $dashboardMonth->month_taxes = $entries->sum( 'day_taxes' );
        $dashboardMonth->month_wasted_goods_count = $entries->sum( 'day_wasted_goods_count' );
        $dashboardMonth->month_wasted_goods = $entries->sum( 'day_wasted_goods' );
        $dashboardMonth->month_expenses = $entries->sum( 'day_expenses' );

        foreach ( [
            'total_unpaid_orders',
            'total_unpaid_orders_count',
            'total_paid_orders',
            'total_paid_orders_count',
            'total_partially_paid_orders',
            'total_partially_paid_orders_count',
            'total_income',
            'total_discounts',
            'total_taxes',
            'total_wasted_goods_count',
            'total_wasted_goods',
            'total_expenses',
        ] as $field ) {
            $dashboardMonth->$field = $entries->get()->last()->$field ?? 0;
        }

        $dashboardMonth->save();

        return $dashboardMonth;
    }

    public function computeOrdersTaxes( DashboardDay $previousReport, DashboardDay $todayReport ): void
    {
        $timeRangeTaxes = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'paid' )
            ->sum( 'tax_value' );

        $todayReport->day_taxes = $timeRangeTaxes;
        $todayReport->total_taxes = ( $todayReport->total_taxes ?? 0 ) + $timeRangeTaxes;
    }

    /**
     * will update wasted goods report
     *
     * @return void
     */
    public function handleStockAdjustment( ProductHistory $history ): array
    {
        if ( in_array( $history->operation_type, [
            ProductHistory::ACTION_DEFECTIVE,
            ProductHistory::ACTION_LOST,
            ProductHistory::ACTION_DELETED,
            ProductHistory::ACTION_REMOVED,
        ] ) ) {
            $currentDay = DashboardDay::forToday();

            if ( $currentDay instanceof DashboardDay ) {
                $yesterDay = DashboardDay::forLastRecentDay( $currentDay );
                $currentDay->day_wasted_goods_count += $history->quantity;
                $currentDay->day_wasted_goods += $history->total_price;
                $currentDay->total_wasted_goods_count = ( $yesterDay->total_wasted_goods_count ?? 0 ) + $currentDay->day_wasted_goods_count;
                $currentDay->total_wasted_goods = ( $yesterDay->total_wasted_goods ?? 0 ) + $currentDay->day_wasted_goods;
                $currentDay->save();

                return [
                    'status' => 'success',
                    'message' => __( 'The dashboard report has been updated.' ),
                ];
            }

            $message = __( 'A stock operation has recently been detected, however RGBF wasn\'t able to update the report accordingly. This occurs if the daily dashboard reference hasn\'t been created.' );

            /**
             * @todo make sure outgoing link takes to relevant article
             *
             * @var NotificationService
             */
            $notification = app()->make( NotificationService::class );
            $notification->create(
                title: __( 'Untracked Stock Operation' ),
                description: $message,
                url: '#',
            )->dispatchForGroup(
                [ Role::namespace( 'admin' ) ]
            );

            return [
                'status' => 'error',
                'message' => $message,
            ];
        }

        return [
            'status' => 'error',
            'message' => __( 'Unsupported action' ),
        ];
    }

    public function computeIncome( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalIncome = ActiveTransactionHistory::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->operation( ActiveTransactionHistory::OPERATION_CREDIT )
            ->sum( 'value' );

        $totalExpenses = ActiveTransactionHistory::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->operation( ActiveTransactionHistory::OPERATION_DEBIT )
            ->sum( 'value' );

        $todayReport->day_expenses = $totalExpenses;
        $todayReport->day_income = $totalIncome;
        $todayReport->total_income = ( $previousReport->total_income ?? 0 ) + $todayReport->day_income;
        $todayReport->total_expenses = ( $previousReport->total_expenses ?? 0 ) + $todayReport->day_expenses;
    }

    /**
     * specifically compute
     * the unpaid orders count
     *
     * @return void
     */
    private function computeUnpaidOrdersCount( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalUnpaidOrders = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'unpaid' )
            ->count();

        $todayReport->day_unpaid_orders_count = $totalUnpaidOrders;
        $todayReport->total_unpaid_orders_count = ( $previousReport->total_unpaid_orders_count ?? 0 ) + $totalUnpaidOrders;
    }

    /**
     * specifically compute
     * the unpaid orders amount
     *
     * @return void
     */
    private function computeUnpaidOrders( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalUnpaidOrders = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'unpaid' )
            ->sum( 'total' );

        $todayReport->day_unpaid_orders = $totalUnpaidOrders;
        $todayReport->total_unpaid_orders = ( $previousReport->total_unpaid_orders ?? 0 ) + $totalUnpaidOrders;
    }

    /**
     * specifically compute
     * the aid orders amount
     *
     * @return void
     */
    private function computePaidOrders( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalPaid = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'paid' )
            ->sum( 'total' );

        $todayReport->day_paid_orders = $totalPaid;
        $todayReport->total_paid_orders = ( $previousReport->total_paid_orders ?? 0 ) + $totalPaid;
    }

    /**
     * specifically compute
     * the piad orders count
     *
     * @return void
     */
    private function computePaidOrdersCount( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalPaidOrders = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'paid' )
            ->count();

        $todayReport->day_paid_orders_count = $totalPaidOrders;
        $todayReport->total_paid_orders_count = ( $previousReport->total_paid_orders_count ?? 0 ) + $totalPaidOrders;
    }

    /**
     * specifically compute
     * the aid orders amount
     *
     * @return void
     */
    private function computePartiallyPaidOrders( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalPaid = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'partially_paid' )
            ->sum( 'total' );

        $todayReport->day_partially_paid_orders = $totalPaid;
        $todayReport->total_partially_paid_orders = ( $previousReport->total_partially_paid_orders ?? 0 ) + $totalPaid;
    }

    /**
     * specifically compute
     * the piad orders count
     *
     * @return void
     */
    private function computePartiallyPaidOrdersCount( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalPartiallyPaidOrdersCount = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'partially_paid' )
            ->count();

        $todayReport->day_partially_paid_orders_count = $totalPartiallyPaidOrdersCount;
        $todayReport->total_partially_paid_orders_count = ( $previousReport->total_partially_paid_orders_count ?? 0 ) + $totalPartiallyPaidOrdersCount;
    }

    private function computeDiscounts( DashboardDay $previousReport, DashboardDay $todayReport )
    {
        $totalDiscount = Order::from( $this->dayStarts )
            ->to( $this->dayEnds )
            ->paymentStatus( 'paid' )
            ->sum( 'discount' );

        $todayReport->day_discounts = $totalDiscount;
        $todayReport->total_discounts = ( $previousReport->total_discounts ?? 0 ) + $totalDiscount;
    }

    public function notifyIncorrectDashboardReport(): array
    {
        $message = __( 'A stock operation has recently been detected, however RGBF wasn\'t able to update the report accordingly. This occurs if the daily dashboard reference hasn\'t been created.' );

        ns()->notification->create(
            title: __( 'Untracked Stock Operation' ),
            description: $message,
            url: '#',
        )->dispatchForGroup(
            [ Role::namespace( 'admin' ) ]
        );

        return [
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * get from a specific date
     */
    public function getFromTimeRange( string $startDate, string $endDate ): Collection
    {
        return DashboardDay::from( $startDate )
            ->to( $endDate )
            ->get();
    }

    /**
     * This return the year report
     *
     * @return array $reports
     */
    public function getYearReportFor( int $year )
    {
        $date = $this->dateService->now();
        $date->year = $year >= 2019 && $year <= 2099 ? $year : 2020; // validate the date
        $startOfYear = $date->startOfYear()->copy();
        $endOfYear = $date->endOfYear()->copy();

        $reports = [];

        do {
            $currentMonth = $startOfYear->copy();

            $monthReport = DashboardMonth::from( $currentMonth->startOfMonth()->toDateTimeString() )
                ->to( $currentMonth->endOfMonth()->toDateTimeString() )
                ->first();

            if ( ! $monthReport instanceof DashboardMonth ) {
                $monthReport = $this->computeDashboardMonth( $currentMonth );
            }

            $reports[ (int) $currentMonth->format( 'm' ) ] = $monthReport;

            $startOfYear->addMonth();
        } while ( ! $startOfYear->isSameMonth( $endOfYear->copy()->addMonth() ) );

        return $reports;
    }

    /**
     * Will return the products report
     */
    public function getProductSalesDiff( string $startDate, string $endDate, string $sort ): array
    {
        $startDate = Carbon::parse( $startDate );
        $endDate = Carbon::parse( $endDate );
        $diffInDays = Carbon::parse( $startDate )->diffInDays( $endDate );

        if ( $diffInDays > 0 ) {
            // check if it's the start and end of the month
            $isStartOfMonth = Carbon::parse( $startDate )->startOfMonth()->isSameDay( $startDate );
            $isEndOfMonth = Carbon::parse( $endDate )->endOfMonth()->isSameDay( $endDate );

            if (
                $isStartOfMonth && $isEndOfMonth
            ) {
                $startCycle = Carbon::parse( $startDate )->subMonth()->startOfMonth();
                $endCycle = Carbon::parse( $endDate )->subDay()->subMonth()->endOfMonth();
            } else {
                $startCycle = Carbon::parse( $startDate )->subDays( $diffInDays + 1 );
                $endCycle = Carbon::parse( $endDate )->subDays( $diffInDays + 1 );
            }

            $previousDates = [
                'previous' => [
                    'startDate' => $startCycle->toDateTimeString(),
                    'endDate' => $endCycle->toDateTimeString(),
                ],
                'current' => [
                    'startDate' => $startDate->toDateTimeString(),
                    'endDate' => $endDate->toDateTimeString(),
                ],
            ];

            return $this->getBestRecords( $previousDates, $sort );
        } else {
            $startCycle = Carbon::parse( $startDate )->subDay();
            $endCycle = Carbon::parse( $endDate )->subDay();

            $previousDates = [
                'previous' => [
                    'startDate' => $startCycle->toDateTimeString(),
                    'endDate' => $endCycle->toDateTimeString(),
                ],
                'current' => [
                    'startDate' => $startDate->toDateTimeString(),
                    'endDate' => $endDate->toDateTimeString(),
                ],
            ];

            return $this->getBestRecords( $previousDates, $sort );
        }
    }

    /**
     * Will detect whether an increase
     * or decrease exists between an old and new value
     *
     * @param  int $old
     * @param  int $new
     * @return int
     */
    private function getDiff( $old, $new )
    {
        if ( $old > $new ) {
            return $this->computeDiff( $old, $new, 'decrease' );
        } else {
            return $this->computeDiff( $old, $new, 'increase' );
        }
    }

    /**
     * Will compute the difference between two numbers
     *
     * @param  int    $old
     * @param  int    $new
     * @param  string $operation
     * @return int
     */
    private function computeDiff( $old, $new, $operation )
    {
        if ( $new == 0 ) {
            return 100;
        } else {
            $change = ( ( $old - $new ) / $new ) * 100;

            return $operation === 'increase' ? abs( $change ) : $change;
        }
    }

    /**
     * Will proceed the request to the
     * database that returns the products report
     */
    private function getBestRecords( array $previousDates, string $sort ): array
    {
        $orderProductTable = Hook::filter( 'ns-model-table', 'nexopos_orders_products' );
        $orderTable = Hook::filter( 'ns-model-table', 'nexopos_orders' );

        switch ( $sort ) {
            case 'using_quantity_asc':
                $sorting = [
                    'column' => 'quantity',
                    'direction' => 'asc',
                ];
                break;
            case 'using_quantity_desc':
                $sorting = [
                    'column' => 'quantity',
                    'direction' => 'desc',
                ];
                break;
            case 'using_sales_asc':
                $sorting = [
                    'column' => 'total_price',
                    'direction' => 'asc',
                ];
                break;
            case 'using_sales_desc':
                $sorting = [
                    'column' => 'total_price',
                    'direction' => 'desc',
                ];
                break;
            case 'using_name_asc':
                $sorting = [
                    'column' => 'name',
                    'direction' => 'asc',
                ];
                break;
            case 'using_name_desc':
                $sorting = [
                    'column' => 'name',
                    'direction' => 'desc',
                ];
                break;
            default:
                $sorting = [
                    'column' => 'total_price',
                    'direction' => 'desc',
                ];
                break;
        }

        foreach ( $previousDates as $key => $report ) {
            $previousDates[ $key ][ 'products' ] = DB::table( $orderProductTable )
                ->select( [
                    $orderProductTable . '.unit_name as unit_name',
                    $orderProductTable . '.product_id as product_id',
                    $orderProductTable . '.name as name',
                    DB::raw( 'SUM( quantity ) as quantity' ),
                    DB::raw( 'SUM( total_price ) as total_price' ),
                    DB::raw( 'SUM( ' . env( 'DB_PREFIX' ) . $orderProductTable . '.tax_value ) as tax_value' ),
                ] )
                ->groupBy(
                    $orderProductTable . '.unit_name',
                    $orderProductTable . '.product_id',
                    $orderProductTable . '.name'
                )
                ->orderBy( $sorting[ 'column' ], $sorting[ 'direction' ] )
                ->join( $orderTable, $orderTable . '.id', '=', $orderProductTable . '.order_id' )
                ->where( $orderTable . '.created_at', '>=', $report[ 'startDate' ] )
                ->where( $orderTable . '.created_at', '<=', $report[ 'endDate' ] )
                ->whereIn( $orderTable . '.payment_status', [ Order::PAYMENT_PAID ] )
                ->get()
                ->map( function ( $product ) {
                    $product->difference = 0;

                    return $product;
                } );
        }

        foreach ( $previousDates[ 'current' ][ 'products' ] as $id => &$product ) {
            $default = new stdClass;
            $default->total_price = 0;
            $default->quantity = 0;

            $oldProduct = collect( $previousDates[ 'previous' ][ 'products' ] )->filter( function ( $previousProduct ) use ( $product ) {
                return $previousProduct->product_id === $product->product_id;
            } )->first() ?: $default;

            $product->old_total_price = $oldProduct->total_price;
            $product->old_quantity = $oldProduct->quantity ?? 0;
            $product->difference = $oldProduct->total_price > 0 ? $this->getDiff(
                $oldProduct->total_price,
                $product->total_price
            ) : 100;

            $product->evolution = $product->quantity > $oldProduct->quantity ? 'progress' : 'regress';
        }

        $previousDates[ 'current' ][ 'total_price' ] = collect( $previousDates[ 'current' ][ 'products' ] )
            ->map( fn( $product ) => $product->total_price )
            ->sum();

        $previousDates[ 'previous' ][ 'total_price' ] = collect( $previousDates[ 'previous' ][ 'products' ] )
            ->map( fn( $product ) => $product->total_price )
            ->sum();

        return $previousDates;
    }

    /**
     * Will return a report based
     * on the requested type.
     */
    public function getSaleReport( string $start, string $end, string $type, $user_id = null, $categories_id = null )
    {
        switch ( $type ) {
            case 'products_report':
                return $this->getProductsReports(
                    start: $start,
                    end: $end,
                    user_id: $user_id,
                    categories_id: $categories_id
                );
                break;
            case 'categories_report':
            case 'categories_summary':
                return $this->getCategoryReports(
                    start: $start,
                    end: $end,
                    user_id: $user_id,
                    categories_id: $categories_id
                );
                break;
        }
    }

    private function getSalesSummary( Collection $orders ): array
    {
        $allSales = $orders->map( function ( $order ) {
            $productTaxes = $order->products()->sum( 'tax_value' );
            $totalPurchasePrice = $order->products()->sum( 'total_purchase_price' );

            return [
                'subtotal' => $order->subtotal,
                'product_taxes' => $productTaxes,
                'sales_discounts' => $order->discount,
                'sales_taxes' => $order->tax_value,
                'shipping' => $order->shipping,
                'total' => $order->total,
                'received' => min( (float) $order->total, max( 0, (float) $order->tendered ) ),
                'outstanding' => max( 0, (float) $order->total - (float) $order->tendered ),
                'total_purchase_price' => $totalPurchasePrice,

                /**
                 * The tax is taken off once. It used to be subtracted twice — once as the order's tax
                 * and once as the sum of its lines' taxes — which are the same money: the order tax is
                 * derived from the lines in every product VAT mode, and comes from the order tax group
                 * in the flat modes, where the lines carry none. On this shop's own figures that turned
                 * a profit of 248.48 into a loss of 226.50, and a report that says a profitable day lost
                 * money is worse than no report.
                 *
                 * The order's own tax_value is the figure charged to the customer, so it is the one
                 * used. product_taxes stays in the response as a breakdown for display.
                 */
                'profit' => ns()->currency->define( $order->total )
                    ->subtractBy( $totalPurchasePrice )
                    ->subtractBy( $order->tax_value )
                    ->toFloat(),
            ];
        } );

        return [
            'sales_discounts' => Currency::define( $allSales->sum( 'sales_discounts' ) )->toFloat(),
            'product_taxes' => Currency::define( $allSales->sum( 'product_taxes' ) )->toFloat(),
            'sales_taxes' => Currency::define( $allSales->sum( 'sales_taxes' ) )->toFloat(),
            'subtotal' => Currency::define( $allSales->sum( 'subtotal' ) )->toFloat(),
            'shipping' => Currency::define( $allSales->sum( 'shipping' ) )->toFloat(),
            'profit' => Currency::define( $allSales->sum( 'profit' ) )->toFloat(),
            'total_purchase_price' => Currency::define( $allSales->sum( 'total_purchase_price' ) )->toFloat(),
            'total' => Currency::define( $allSales->sum( 'total' ) )->toFloat(),
            'received' => Currency::define( $allSales->sum( 'received' ) )->toFloat(),
            'outstanding' => Currency::define( $allSales->sum( 'outstanding' ) )->toFloat(),
        ];
    }

    /**
     * @todo add support for category filter
     */
    public function getProductsReports( string $start, string $end, $user_id = null, $categories_id = null ): array
    {
        $request = Order::paymentStatusIn( [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED, Order::PAYMENT_PARTIALLY, Order::PAYMENT_UNPAID, Order::PAYMENT_DUE, Order::PAYMENT_PARTIALLY_DUE ] )
            ->from( $start )
            ->to( $end );
        $branchId = app( ProductService::class )->branchIdForCurrentUser();
        if ( $branchId !== null ) {
            $request->where( fn ( $query ) => $query->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' ) );
        }

        if ( ! empty( $user_id ) ) {
            $request = $request->where( 'author_id', $user_id );
        }

        if ( ! empty( $categories_id ) ) {
            /**
             * Will only pull orders that has products which
             * belongs to the categories id provided
             */
            $request = $request->whereHas( 'products', function ( $query ) use ( $categories_id ) {
                $query->whereIn( 'product_category_id', $categories_id );
            } );

            /**
             * Will only pull products that belongs to the categories id provided.
             */
            $request = $request->with( [
                'products' => function ( $query ) use ( $categories_id ) {
                    $query->whereIn( 'product_category_id', $categories_id );
                },
            ] );
        }

        $orders = $request->get();
        $summary = $this->getSalesSummary( $orders );
        $products = $orders->map( fn( $order ) => $order->products )->flatten();
        $productsIds = $products->map( fn( $product ) => $product->product_id )->unique();

        return [
            'result' => $productsIds->map( function ( $id ) use ( $products ) {
                $product = $products->where( 'product_id', $id )->first();
                $filtredProdcuts = $products->where( 'product_id', $id )->all();

                $summable = [ 'quantity', 'discount', 'wholesale_tax_value', 'sale_tax_value', 'tax_value', 'total_price_net', 'total_price', 'total_price_gross', 'total_purchase_price' ];
                foreach ( $summable as $key ) {
                    $product->$key = collect( $filtredProdcuts )->sum( $key );
                }

                return $product;
            } )->values(),
            'summary' => $summary,
        ];
    }

    public function getCategoryReports( $start, $end, $orderAttribute = 'name', $orderDirection = 'desc', $user_id = null, $categories_id = [] )
    {
        $request = Order::paymentStatusIn( [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED, Order::PAYMENT_PARTIALLY, Order::PAYMENT_UNPAID, Order::PAYMENT_DUE, Order::PAYMENT_PARTIALLY_DUE ] )
            ->from( $start )
            ->to( $end );
        $branchId = app( ProductService::class )->branchIdForCurrentUser();
        if ( $branchId !== null ) {
            $request->where( fn ( $query ) => $query->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' ) );
        }

        $request->with( 'products' );

        if ( ! empty( $user_id ) ) {
            $request = $request->where( 'author_id', $user_id );
        }

        if ( ! empty( $categories_id ) ) {
            /**
             * Will only pull orders that has products which
             * belongs to the categories id provided
             */
            $request = $request->whereHas( 'products', function ( $query ) use ( $categories_id ) {
                $query->whereIn( 'product_category_id', $categories_id );
            } );

            /**
             * Will only pull products that belongs to the categories id provided.
             */
            $request = $request->with( [
                'products' => function ( $query ) use ( $categories_id ) {
                    $query->whereIn( 'product_category_id', $categories_id );
                },
            ] );
        }

        $orders = $request->get();

        /**
         * We'll pull the sales
         * summary
         */
        $summary = $this->getSalesSummary( $orders );

        $products = $orders->map( fn( $order ) => $order->products )->flatten();
        $category_ids = $orders->map( fn( $order ) => $order->products->map( fn( $product ) => $product->product_category_id ) );

        $unitIds = $category_ids->flatten()->unique()->toArray();

        /**
         * We'll get all category that are listed
         * on the product sold
         */
        $categories = ProductCategory::whereIn( 'id', $unitIds )
            ->orderBy( $orderAttribute, $orderDirection )
            ->get();

        /**
         * Merges the lines of one category and totals them.
         *
         * Extracted so the categories that still exist and the ones that no longer do are summarised by
         * the same rules, rather than the latter being left out of the table entirely.
         */
        $summariseCategory = function ( array $base, $rawProducts ): array {
            $mergedProducts = [];

            /**
             * this will merge similar products
             * to summarize them.
             */
            collect( $rawProducts )->values()->each( function ( $product ) use ( &$mergedProducts ) {
                if ( isset( $mergedProducts[ $product->product_id ] ) ) {
                    $mergedProducts[ $product->product_id ][ 'quantity' ] += $product->quantity;
                    $mergedProducts[ $product->product_id ][ 'tax_value' ] += $product->tax_value;
                    $mergedProducts[ $product->product_id ][ 'discount' ] += $product->discount;
                    $mergedProducts[ $product->product_id ][ 'total_price' ] += $product->total_price;
                    $mergedProducts[ $product->product_id ][ 'total_purchase_price' ] += $product->total_purchase_price;
                } else {
                    $mergedProducts[ $product->product_id ] = array_merge( $product->toArray(), [
                        'quantity' => $product->quantity,
                        'tax_value' => $product->tax_value,
                        'discount' => $product->discount,
                        'total_price' => $product->total_price,
                        'total_purchase_price' => $product->total_purchase_price,
                        'name' => $product->name,
                        'product_id' => $product->product_id,
                        'unit_id' => $product->unit_id,
                    ] );
                }
            } );

            $base[ 'products' ] = array_values( $mergedProducts );
            $base[ 'total_tax_value' ] = collect( $mergedProducts )->sum( 'tax_value' );
            $base[ 'total_price' ] = collect( $mergedProducts )->sum( 'total_price' );
            $base[ 'total_discount' ] = collect( $mergedProducts )->sum( 'discount' );
            $base[ 'total_sold_items' ] = collect( $mergedProducts )->sum( 'quantity' );
            $base[ 'total_purchase_price' ] = collect( $mergedProducts )->sum( 'total_purchase_price' );

            return $base;
        };

        /**
         * That will sum all the total prices
         */
        $result = $categories->map( function ( $category ) use ( $products, $summariseCategory ) {
            return $summariseCategory(
                [...$category->toArray()],
                $products->where( 'product_category_id', $category->id )->all()
            );
        } );

        /**
         * Sales whose category no longer exists are gathered into rows of their own.
         *
         * The table used to be built only from categories still present in the catalogue, so deleting a
         * category quietly removed every past sale of its products from this screen while those sales
         * kept counting in the summary above it. On this shop's own figures that hid 6,022 of 7,260.50
         * taken — 83% of the period — behind a table that looked complete. A report whose detail does
         * not add up to its own total is worse than no report, because nothing says which of the two to
         * believe.
         *
         * Skipped when the user has filtered to particular categories, since rows outside that filter
         * were not asked for.
         */
        if ( empty( $categories_id ) ) {
            $knownIds = $categories->pluck( 'id' )->map( fn( $id ) => (int) $id )->all();

            $orphanIds = collect( $unitIds )
                ->map( fn( $id ) => (int) $id )
                ->unique()
                ->reject( fn( $id ) => in_array( $id, $knownIds, true ) )
                ->values();

            foreach ( $orphanIds as $orphanId ) {
                $orphanProducts = $products->where( 'product_category_id', $orphanId )->all();

                if ( empty( $orphanProducts ) ) {
                    continue;
                }

                /**
                 * The former id is kept in the label so a shop can trace which grouping these sales used
                 * to belong to, rather than being told only that they are unclassified.
                 */
                $result->push( $summariseCategory( [
                    'id' => $orphanId,
                    'name' => $orphanId > 0
                        ? sprintf( __( 'ไม่ระบุหมวดหมู่ (รหัสเดิม %s)' ), $orphanId )
                        : __( 'ไม่ระบุหมวดหมู่' ),
                ], $orphanProducts ) );
            }
        }

        return compact( 'result', 'summary' );
    }

    /**
     * Will returns the details for a specific cashier
     */
    public function getCashierDashboard( int $cashier, ?string $startDate = null, ?string $endDate = null ): array
    {
        $cacheKey = 'cashier-report-' . $cashier;

        if ( ! empty( request()->query( 'refresh' ) ) ) {
            Cache::forget( $cacheKey );
        }

        return Cache::remember( $cacheKey, now()->addDay( 1 ), function () use ( $startDate, $cashier, $endDate ) {
            $startDate = $startDate === null ? ns()->date->getNow()->startOfDay()->toDateTimeString() : $startDate;
            $endDate = $endDate === null ? ns()->date->getNow()->endOfDay()->toDateTimeString() : $endDate;

            $totalSales = Order::paid()
                ->where( 'author_id', $cashier )
                ->count();

            $todaySales = Order::paid()
                ->where( 'created_at', '>=', $startDate )
                ->where( 'created_at', '<=', $endDate )
                ->where( 'author_id', $cashier )
                ->count();

            $totalSalesAmount = Order::paid()
                ->where( 'author_id', $cashier )
                ->sum( 'total' );

            $todaySalesAmount = Order::paid()
                ->where( 'created_at', '>=', $startDate )
                ->where( 'created_at', '<=', $endDate )
                ->where( 'author_id', $cashier )
                ->sum( 'total' );

            $totalRefundsAmount = Order::refunded()
                ->where( 'author_id', $cashier )
                ->sum( 'total' );

            $todayRefunds = Order::refunded()
                ->where( 'created_at', '>=', $startDate )
                ->where( 'created_at', '<=', $endDate )
                ->where( 'author_id', $cashier )
                ->sum( 'total' );

            $totalCustomers = Customer::where( 'author_id', $cashier )
                ->count();

            $todayCustomers = Customer::where( 'created_at', '>=', $startDate )
                ->where( 'created_at', '<=', $endDate )
                ->where( 'author_id', $cashier )
                ->count();

            /**
             * This will compute the cashier
             * commissions and displays on his dashboard.
             */
            $module = app()->make( ModulesService::class );
            $config = [];

            if ( $module->getIfEnabled( 'NsCommissions' ) ) {
                $config = [
                    'today_commissions' => EarnedCommission::for( Auth::id() )
                        ->where( 'created_at', '>=', ns()->date->getNow()->copy()->startOfDay()->toDateTimeString() )
                        ->where( 'created_at', '<=', ns()->date->getNow()->copy()->endOfDay()->toDateTimeString() )
                        ->sum( 'value' ),
                    'total_commissions' => EarnedCommission::for( Auth::id() )
                        ->sum( 'value' ),
                ];
            }

            return array_merge( [
                [
                    'key' => 'created_at',
                    'value' => ns()->date->getFormatted( Auth::user()->created_at ),
                    'label' => __( 'Member Since' ),
                ], [
                    'key' => 'total_sales_count',
                    'value' => $totalSales,
                    'label' => __( 'Total Orders' ),
                    'today' => [
                        'key' => 'today_sales_count',
                        'value' => $todaySales,
                        'label' => __( 'Today\'s Orders' ),
                    ],
                ], [
                    'key' => 'total_sales_amount',
                    'value' => ns()->currency->define( $totalSalesAmount )->format(),
                    'label' => __( 'Total Sales' ),
                    'today' => [
                        'key' => 'today_sales_amount',
                        'value' => ns()->currency->define( $todaySalesAmount )->format(),
                        'label' => __( 'Today\'s Sales' ),
                    ],
                ], [
                    'key' => 'total_refunds_amount',
                    'value' => ns()->currency->define( $totalRefundsAmount )->format(),
                    'label' => __( 'Total Refunds' ),
                    'today' => [
                        'key' => 'today_refunds_amount',
                        'value' => ns()->currency->define( $todayRefunds )->format(),
                        'label' => __( 'Today\'s Refunds' ),
                    ],
                ], [
                    'key' => 'total_customers',
                    'value' => $totalCustomers,
                    'label' => __( 'Total Customers' ),
                    'today' => [
                        'key' => 'today_customers',
                        'value' => $todayCustomers,
                        'label' => __( 'Today\'s Customers' ),
                    ],
                ],
            ], $config );
        } );
    }

    /**
     * @param  int   $year
     * @return array $response
     */
    public function computeYearReport( $year )
    {
        $date = ns()->date->copy();
        $date->year = $year;
        $startOfYear = $date->copy()->startOfYear();
        $endOfYear = $date->copy()->endOfYear();

        while ( ! $startOfYear->isSameMonth( $endOfYear ) ) {
            $this->computeDashboardMonth( $startOfYear->copy() );
            $startOfYear->addMonth();
        }

        return [
            'status' => 'success',
            'message' => __( 'The report has been computed successfully.' ),
        ];
    }

    /**
     * The stock report, for one branch at a time.
     *
     * It used to load every branch's rows for every product and the page added them together in its
     * footer, so the figure the owner read was a company total presented as if it were a shelf: a
     * product held 40 in one branch and 60 in another reported 100, and no row said which shelf
     * either belonged to. Ordering stock from that number over-buys by however many branches there
     * are.
     *
     * One branch at a time is the only reading that can be acted on, and it is the same rule the
     * products list follows, so the two pages cannot disagree. The branch comes from the header
     * picker for the owner and from their own branch for everyone else.
     *
     * Rows with no branch are kept: a single store install leaves branch_id null on every row and
     * scoping them out would report the whole catalogue as empty.
     */
    public function getStockReport( array $categories, array $units )
    {
        $branchId = app( ProductService::class )->branchIdForCurrentUser();

        $query = Product::notGrouped()
            ->withStockEnabled()
            ->with( [ 'unit_quantities' => function ( $query ) use ( $units, $branchId ) {
                if ( $branchId !== null ) {
                    $query->where(
                        fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                    );
                }

                if ( ! empty( $units ) ) {
                    $query->whereIn( 'unit_id', $units );
                } else {
                    return false;
                }
            }, 'unit_quantities.unit' ] );

        if ( ! empty( $categories ) ) {
            $query->whereIn( 'category_id', $categories );
        }

        return $query->paginate( 50 );
    }

    /**
     * Will retrun products having low stock
     *
     * @return Collection $products
     */
    /**
     * Products running low, for one branch at a time.
     *
     * Unscoped, the same product appeared once per branch that was low with nothing to say which,
     * and the page's footer added the shortfalls together. Restocking off that list sent goods to
     * whichever branch happened to be read first.
     *
     * Branchless rows are kept, for the same reason as the stock report above.
     */
    public function getLowStockProducts( array $categories, array $units )
    {
        $branchId = app( ProductService::class )->branchIdForCurrentUser();

        return ProductUnitQuantity::query()
            ->where( 'stock_alert_enabled', 1 )
            ->whereRaw( 'low_quantity > quantity' )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where(
                    fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                )
            )
            ->with( [
                'product',
                'unit' => function ( $query ) use ( $units ) {
                    if ( ! empty( $units ) ) {
                        $query->whereIn( 'id', $units );
                    }
                },
            ] )
            ->whereHas( 'unit', function ( $query ) use ( $units ) {
                if ( ! empty( $units ) ) {
                    $query->whereIn( 'id', $units );
                } else {
                    return false;
                }
            } )
            ->whereHas( 'product', function ( $query ) use ( $categories ) {
                if ( ! empty( $categories ) ) {
                    $query->whereIn( 'category_id', $categories );
                }

                return false;
            } )
            ->get();
    }

    public function recomputeTransactions( Carbon $fromDate, Carbon $toDate ): void
    {
        ActiveTransactionHistory::truncate();
        DashboardDay::truncate();
        DashboardMonth::truncate();

        $startDateString = $fromDate->startOfDay()->toDateTimeString();
        $endDateString = $toDate->endOfDay()->toDateTimeString();

        /**
         * @var TransactionService
         */
        $transactionService = app()->make( TransactionService::class );

        $transactionService->recomputeCashFlow(
            $startDateString,
            $endDateString
        );

        $days = ns()->date->getDaysInBetween( $fromDate, $toDate );

        foreach ( $days as $day ) {
            $this->computeDayReport(
                $day->startOfDay()->toDateTimeString(),
                $day->endOfDay()->toDateTimeString()
            );

            $this->computeDashboardMonth( $day );
        }
    }

    /**
     * Will return the actual customer statement
     *
     * @return array
     */
    public function getCustomerStatement( Customer $customer, $rangeStarts = null, $rangeEnds = null )
    {
        $rangeStarts = Carbon::parse( $rangeStarts )->toDateTimeString();
        $rangeEnds = Carbon::parse( $rangeEnds )->toDateTimeString();

        return [
            'purchases_amount' => $customer->purchases_amount,
            'owed_amount' => $customer->owed_amount,
            'account_amount' => $customer->account_amount,
            'total_orders' => $customer->orders()->count(),
            'credit_limit_amount' => $customer->credit_limit_amount,
            'orders' => Order::where( 'customer_id', $customer->id )
                ->paymentStatusIn( [ Order::PAYMENT_PAID, Order::PAYMENT_UNPAID, Order::PAYMENT_REFUNDED, Order::PAYMENT_PARTIALLY ] )
                ->where( 'created_at', '>=', $rangeStarts )
                ->where( 'created_at', '<=', $rangeEnds )
                ->get(),
            'wallet_transactions' => CustomerAccountHistory::where( 'customer_id', $customer->id )
                ->where( 'created_at', '>=', $rangeStarts )
                ->where( 'created_at', '<=', $rangeEnds )
                ->get(),
        ];
    }

    public function combineProductHistory( ProductHistory $productHistory )
    {
        $currentDetailedHistory = $this->prepareProductHistoryCombinedHistory( $productHistory );
        $this->saveProductHistoryCombined( $currentDetailedHistory, $productHistory );
        $currentDetailedHistory->save();
    }

    /**
     * Will compute the product history combined for the whole day
     */
    public function computeProductHistoryCombinedForWholeDay( ProductHistory $productHistory ): ProductHistoryCombined
    {
        $startOfDay = Carbon::parse( $productHistory->created_at )->startOfDay();
        $endOfDay = Carbon::parse( $productHistory->created_at )->endOfDay();

        $initialQuantity = 0;

        $previousProductHistory = ProductHistoryCombined::where( 'date', '<', $startOfDay->toDateTimeString() )
            ->where( 'product_id', $productHistory->product_id )
            ->where( 'unit_id', $productHistory->unit_id )
            ->orderBy( 'date', 'desc' )
            ->first();

        if ( $previousProductHistory ) {
            $initialQuantity = $previousProductHistory->final_quantity;
        }

        $addedQuantity = ProductHistory::where( 'operation_type', ProductHistory::ACTION_ADDED )
            ->where( 'product_id', $productHistory->product_id )
            ->where( 'unit_id', $productHistory->unit_id )
            ->where( 'created_at', '>=', $startOfDay->toDateTimeString() )
            ->where( 'created_at', '<=', $endOfDay->toDateTimeString() )
            ->sum( 'quantity' );

        $defectiveQuantity = ProductHistory::where( 'operation_type', ProductHistory::ACTION_DEFECTIVE )
            ->where( 'product_id', $productHistory->product_id )
            ->where( 'unit_id', $productHistory->unit_id )
            ->where( 'created_at', '>=', $startOfDay->toDateTimeString() )
            ->where( 'created_at', '<=', $endOfDay->toDateTimeString() )
            ->sum( 'quantity' );

        $soldQuantity = ProductHistory::where( 'operation_type', ProductHistory::ACTION_SOLD )
            ->where( 'product_id', $productHistory->product_id )
            ->where( 'unit_id', $productHistory->unit_id )
            ->where( 'created_at', '>=', $startOfDay->toDateTimeString() )
            ->where( 'created_at', '<=', $endOfDay->toDateTimeString() )
            ->sum( 'quantity' );

        $finalQuantity = $initialQuantity + $addedQuantity - $defectiveQuantity - $soldQuantity;

        $productHistoryCombined = ProductHistoryCombined::where( 'date', $startOfDay->format( 'Y-m-d' ) )
            ->where( 'product_id', $productHistory->product_id )
            ->where( 'unit_id', $productHistory->unit_id )
            ->firstOrNew();

        $productHistoryCombined->final_quantity = $finalQuantity;
        $productHistoryCombined->initial_quantity = $initialQuantity;
        $productHistoryCombined->procured_quantity = $addedQuantity;
        $productHistoryCombined->defective_quantity = $defectiveQuantity;
        $productHistoryCombined->sold_quantity = $soldQuantity;
        $productHistoryCombined->product_id = $productHistory->product_id;
        $productHistoryCombined->unit_id = $productHistory->unit_id;
        $productHistoryCombined->name = $productHistory->product->name;
        $productHistoryCombined->date = $startOfDay->format( 'Y-m-d' );
        $productHistoryCombined->save();

        return $productHistoryCombined;
    }

    /**
     * Will prepare the product history combined
     */
    public function prepareProductHistoryCombinedHistory( ProductHistory $productHistory ): ProductHistoryCombined
    {
        $formatedDate = $this->dateService->now()->format( 'Y-m-d' );
        $currentDetailedHistory = ProductHistoryCombined::where( 'date', $formatedDate )
            ->where( 'unit_id', $productHistory->unit_id )
            ->where( 'product_id', $productHistory->product_id )
            ->first();

        /**
         * if this is not set, the we're probably doing this for the
         * first time of the day, so we need to pull the current quantity of the product
         */
        if ( ! $currentDetailedHistory instanceof ProductHistoryCombined ) {
            $currentDetailedHistory = new ProductHistoryCombined;
            $currentDetailedHistory->date = $formatedDate;
            $currentDetailedHistory->name = $productHistory->product->name;
            $currentDetailedHistory->initial_quantity = $productHistory->before_quantity ?? 0;
            $currentDetailedHistory->procured_quantity = 0;
            $currentDetailedHistory->sold_quantity = 0;
            $currentDetailedHistory->defective_quantity = 0;
            $currentDetailedHistory->final_quantity = 0;
            $currentDetailedHistory->product_id = $productHistory->product_id;
            $currentDetailedHistory->unit_id = $productHistory->unit_id;
        }

        return $currentDetailedHistory;
    }

    /**
     * Will save the product history combined
     */
    public function saveProductHistoryCombined( ProductHistoryCombined &$currentDetailedHistory, ProductHistory $productHistory ): ProductHistoryCombined
    {
        if ( $productHistory->operation_type === ProductHistory::ACTION_ADDED ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_DELETED ) {
            $currentDetailedHistory->defective_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_STOCKED ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_LOST ) {
            $currentDetailedHistory->defective_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_REMOVED ) {
            $currentDetailedHistory->defective_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_SOLD ) {
            $currentDetailedHistory->sold_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_ADJUSTMENT_RETURN ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_CONVERT_IN ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_RETURNED ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_TRANSFER_IN ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_TRANSFER_CANCELED ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        } elseif ( $productHistory->operation_type === ProductHistory::ACTION_TRANSFER_REJECTED ) {
            $currentDetailedHistory->procured_quantity += $productHistory->quantity;
        }

        $currentDetailedHistory->final_quantity = ns()->currency->define( $currentDetailedHistory->initial_quantity )
            ->additionateBy( $currentDetailedHistory->procured_quantity )
            ->subtractBy( $currentDetailedHistory->sold_quantity )
            ->subtractBy( $currentDetailedHistory->defective_quantity )
            ->toFloat();

        return $currentDetailedHistory;
    }

    public function getCombinedProductHistory( string $date, array $categories, array $units ): SupportCollection
    {
        $request = DB::query()->select( [
            'nexopos_products_unit_quantities.*',
            'nexopos_products.category_id as product_category_id',
            'nexopos_units.name as unit_name',
            'nexopos_products_histories_combined.date as history_date',
            'nexopos_products_histories_combined.initial_quantity as history_initial_quantity',
            'nexopos_products_histories_combined.procured_quantity as history_procured_quantity',
            'nexopos_products_histories_combined.defective_quantity as history_defective_quantity',
            'nexopos_products_histories_combined.sold_quantity as history_sold_quantity',
            'nexopos_products_histories_combined.final_quantity as history_final_quantity',
            'nexopos_products_histories_combined.unit_id as history_unit_id',
            'nexopos_products_histories_combined.product_id as history_product_id',
            'nexopos_products_histories_combined.name as history_name',
        ] )->from( 'nexopos_products_unit_quantities' )
            ->rightJoin( 'nexopos_products', 'nexopos_products.id', '=', 'nexopos_products_unit_quantities.product_id' )
            ->rightJoin( 'nexopos_products_histories_combined', function ( $join ) use ( $date ) {
                $join->on( 'nexopos_products_histories_combined.product_id', '=', 'nexopos_products_unit_quantities.product_id' );
                $join->on( 'nexopos_products_histories_combined.unit_id', '=', 'nexopos_products_unit_quantities.unit_id' );
                $join->where( 'nexopos_products_histories_combined.date', $date );
            } )
            ->rightJoin( 'nexopos_units', 'nexopos_units.id', '=', 'nexopos_products_histories_combined.unit_id' );

        /**
         * Will only pull products that belongs to the units id provided.
         */
        if ( ! empty( $units ) ) {
            $request->whereIn( 'nexopos_products_histories_combined.unit_id', $units );
        }

        if ( ! empty( $categories ) ) {
            $request->whereIn( 'nexopos_products.category_id', $categories );
        }

        $request->where( 'nexopos_products_histories_combined.date', Carbon::parse( $date )->format( 'Y-m-d' ) );

        return $request->get();
    }

    /**
     * Only trigger the job for combined products.
     */
    public function computeCombinedReport( ?string $date ): array
    {
        EnsureCombinedProductHistoryExistsJob::dispatch( $date );

        return [
            'status' => 'success',
            'message' => __( 'The report will be generated. Try loading the report within few minutes.' ),
        ];
    }

    public function getAccountSummaryReport( ?string $startDate = null, ?string $endDate = null ): array
    {
        $startDate = $startDate === null ? ns()->date->getNow()->startOfMonth()->toDateTimeString() : $startDate;
        $endDate = $endDate === null ? ns()->date->getNow()->endOfMonth()->toDateTimeString() : $endDate;
        $accounts = collect( config( 'accounting.accounts' ) )->map( function ( $account, $name ) use ( $startDate, $endDate ) {
            $transactionAccount = TransactionAccount::where( 'category_identifier', $name )->with( [ 'histories' => function ( $query ) use ( $startDate, $endDate ) {
                $query->where( 'created_at', '>=', $startDate )->where( 'created_at', '<=', $endDate );
            }] )->get();

            $transactions = $transactionAccount->map( function ( $account ) {
                return [
                    'name' => $account->name,
                    'debits' => $account->histories->where( 'operation', 'debit' )->sum( 'value' ),
                    'credits' => $account->histories->where( 'operation', 'credit' )->sum( 'value' ),
                ];
            } );

            return [
                'transactions' => $transactions,
                'name' => $account[ 'label' ],
                'debits' => $transactions->sum( 'debits' ),
                'credits' => $transactions->sum( 'credits' ),
            ];
        } );

        return [
            'accounts' => $accounts,
            'debits' => $accounts->sum( 'debits' ),
            'credits' => $accounts->sum( 'credits' ),
            'profit' => ns()->currency->define( $accounts->sum( 'credits' ) )->subtractBy( $accounts->sum( 'debits' ) )->toFloat(),
        ];
    }

    /**
     * The monthly Thai VAT return figures (ภ.พ.30).
     *
     * The return separates sales three ways, and the distinction is not cosmetic:
     *
     *  - standard rated  taxable supplies carrying output tax
     *  - zero rated      taxable supplies at 0% (exports) — inside the VAT system
     *  - exempt          outside the VAT system entirely, Revenue Code Section 81
     *
     * Zero rated and exempt both produce no tax, so a report that only looked at tax
     * amounts would merge them and misstate the return. The split therefore comes from
     * the vat_status frozen on each order line at the moment of sale.
     *
     * Aggregated in SQL on purpose. getSalesSummary() runs two queries per order inside
     * a map, which is tolerable for a day and not for a month of a busy shop.
     *
     * @return array{ period: array, summary: array, output_tax: float, total_sales: float, invoices: array, notes: array }
     */
    public function getVatReturnReport( ?string $startDate = null, ?string $endDate = null ): array
    {
        $startDate = $startDate ?: ns()->date->getNow()->startOfMonth()->toDateTimeString();
        $endDate = $endDate ?: ns()->date->getNow()->endOfMonth()->toDateTimeString();

        /**
         * @var ThaiVatService
         */
        $vatService = app()->make( ThaiVatService::class );

        $ordersTable = Hook::filter( 'ns-model-table', 'nexopos_orders' );
        $productsTable = Hook::filter( 'ns-model-table', 'nexopos_orders_products' );

        /**
         * A raw fragment bypasses the grammar, so the connection's table prefix is not
         * applied to it and the qualified name has to be built by hand. Everything that
         * goes through the builder (select, where, groupBy) is prefixed automatically and
         * is left unqualified here.
         */
        $prefixedProductsTable = Helper::getPrefixedTableName( $productsTable );

        /**
         * Only paid sales belong on the return, and refunded lines do not: an
         * nexopos_orders_products row keeps a status of "sold" or "refunded", and a
         * refunded line was reversed so it produced no output tax.
         */
        $rows = DB::table( $productsTable )
            ->join( $ordersTable, $productsTable . '.order_id', '=', $ordersTable . '.id' )
            ->where( $ordersTable . '.payment_status', Order::PAYMENT_PAID )
            ->where( $ordersTable . '.created_at', '>=', $startDate )
            ->where( $ordersTable . '.created_at', '<=', $endDate )
            ->where( $productsTable . '.status', '!=', 'refunded' )
            ->groupBy( $productsTable . '.vat_status' )
            ->select( $productsTable . '.vat_status as vat_status' )
            ->selectRaw( 'SUM(' . $prefixedProductsTable . '.total_price) as gross' )
            /**
             * "lines" is a reserved word in MySQL and MariaDB, so the alias has to
             * be quoted or the statement fails to parse.
             */
            ->selectRaw( 'COUNT(*) as `lines`' )
            ->get();

        $buckets = [
            ThaiVatService::STATUS_STANDARD => [ 'gross' => 0.0, 'lines' => 0 ],
            ThaiVatService::STATUS_ZERO_RATED => [ 'gross' => 0.0, 'lines' => 0 ],
            ThaiVatService::STATUS_EXEMPT => [ 'gross' => 0.0, 'lines' => 0 ],
        ];

        foreach ( $rows as $row ) {
            /**
             * Rows written before the column existed, or by a module that did not set it,
             * fall back to the shop default rather than being dropped from the return.
             */
            $status = $vatService->normalizeStatus( $row->vat_status );

            $buckets[ $status ][ 'gross' ] += (float) $row->gross;
            $buckets[ $status ][ 'lines' ] += (int) $row->lines;
        }

        /**
         * The rate that applied during the period. Read at the end of the period because
         * a decree change mid-month would otherwise be missed; a shop that spans a rate
         * change in one return has to file the two periods separately anyway.
         */
        $rate = $vatService->rateOn( $endDate );

        $standardGross = $buckets[ ThaiVatService::STATUS_STANDARD ][ 'gross' ];
        $standardNet = $vatService->netFromGross( $standardGross, $rate );
        $outputTax = $vatService->vatFromGross( $standardGross, $rate );

        $zeroRated = ns()->currency->define( $buckets[ ThaiVatService::STATUS_ZERO_RATED ][ 'gross' ] )->toFloat();
        $exempt = ns()->currency->define( $buckets[ ThaiVatService::STATUS_EXEMPT ][ 'gross' ] )->toFloat();

        $invoices = DB::table( Hook::filter( 'ns-model-table', 'nexopos_tax_invoices' ) )
            ->where( 'issued_at', '>=', $startDate )
            ->where( 'issued_at', '<=', $endDate )
            ->groupBy( 'status' )
            ->selectRaw( 'status, COUNT(*) as total' )
            ->pluck( 'total', 'status' )
            ->toArray();

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'rate' => $rate,
            ],

            /**
             * Shaped like every other report in the app: { label, total } rows so the Vue
             * table can render it without knowing what a PP30 is.
             */
            'summary' => [
                [
                    'label' => __( 'ยอดขายที่เสีย VAT (ไม่รวมภาษี)' ),
                    'total' => $standardNet,
                ],
                [
                    'label' => sprintf( __( 'ภาษีขาย %s%%' ), rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) ),
                    'total' => $outputTax,
                ],
                [
                    'label' => __( 'ยอดขายอัตราร้อยละ 0 (ส่งออก)' ),
                    'total' => $zeroRated,
                ],
                [
                    'label' => __( 'ยอดขายที่ยกเว้น VAT (มาตรา 81)' ),
                    'total' => $exempt,
                ],
            ],
            'output_tax' => $outputTax,
            'standard_net' => $standardNet,
            'standard_gross' => ns()->currency->define( $standardGross )->toFloat(),
            'zero_rated' => $zeroRated,
            'exempt' => $exempt,
            'total_sales' => ns()->currency->define( $standardNet + $zeroRated + $exempt )->toFloat(),
            'invoices' => [
                'issued' => (int) ( $invoices[ 'issued' ] ?? 0 ),
                'cancelled' => (int) ( $invoices[ 'cancelled' ] ?? 0 ),
            ],

            /**
             * Stated in the payload rather than left for the accountant to discover. The
             * return has an input tax side that this system cannot fill: purchase VAT is
             * not recorded anywhere in the procurement data, so the figure has to come
             * from the purchase tax invoices the shop received.
             */
            'notes' => [
                __( 'ภาษีซื้อไม่ได้บันทึกอยู่ในระบบนี้ ต้องกรอกจากใบกำกับภาษีซื้อที่ได้รับมาเอง ตัวเลขในรายงานนี้เป็นฝั่งภาษีขายเท่านั้น' ),
                __( 'ยอดที่แสดงตัดรายการที่คืนสินค้าออกแล้ว และนับเฉพาะบิลที่ชำระเงินครบ' ),
                __( 'ต้องยื่น ภ.พ.30 ภายในวันที่ 15 ของเดือนถัดไป' ),
            ],
        ];
    }

    /**
     * Delivery notes settled at the counter — the shop's "บิลเงินสด" register.
     */
    const DELIVERY_NOTES_CASH = 'cash';

    /**
     * Delivery notes where the goods were released but the money has not arrived — the
     * shop's "ใบฝากสินค้าชั่วคราว" register.
     */
    const DELIVERY_NOTES_CONSIGNMENT = 'consignment';

    /**
     * The register of delivery notes issued over a period.
     *
     * This is the shop's daily control sheet, not an accounting statement. It is printed
     * at the end of the day, the totals are checked against the till and the credit book,
     * and it is filed. Two variants exist on paper — บิลเงินสด and ใบฝากสินค้าชั่วคราว —
     * with identical columns and totals, so they are one report with a filter rather than
     * two implementations that would drift apart the first time a column changed.
     *
     * The two registers separate money already collected from goods released against a
     * promise, which is the distinction the owner is checking when they read it:
     *
     *  - cash         the sale was settled, so the amount should be in the till
     *  - consignment  the goods left, so the amount should be in the credit book
     *
     * Held, voided and refunded orders are excluded from both. A voided order's delivery
     * note was withdrawn, and listing it would make the register disagree with the till.
     *
     * Three queries regardless of how many rows come back: the orders, the VAT split of
     * their lines, and the tax invoice numbers. Reading the split per order inside a loop
     * is what makes a month of a busy shop time out.
     *
     * @return array{ period: array, type: string, title: string, rows: array, summary: array, totals: array, notes: array }
     */
    public function getDeliveryNoteIssuanceReport(
        ?string $startDate = null,
        ?string $endDate = null,
        string $type = self::DELIVERY_NOTES_CASH,
    ): array {
        $type = $type === self::DELIVERY_NOTES_CONSIGNMENT
            ? self::DELIVERY_NOTES_CONSIGNMENT
            : self::DELIVERY_NOTES_CASH;

        $startDate = $startDate ?: ns()->date->getNow()->startOfDay()->toDateTimeString();
        $endDate = $endDate ?: ns()->date->getNow()->endOfDay()->toDateTimeString();

        /**
         * @var ThaiVatService
         */
        $vatService = app()->make( ThaiVatService::class );

        $ordersTable = Hook::filter( 'ns-model-table', 'nexopos_orders' );
        $customersTable = Hook::filter( 'ns-model-table', 'nexopos_users' );
        $groupsTable = Hook::filter( 'ns-model-table', 'nexopos_customers_groups' );

        $statuses = $type === self::DELIVERY_NOTES_CASH
            ? [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED ]
            : [ Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIALLY, Order::PAYMENT_DUE, Order::PAYMENT_PARTIALLY_DUE ];

        $branchId = app( ProductService::class )->branchIdForCurrentUser();
        $orders = DB::table( $ordersTable )
            ->when( $branchId !== null, fn ( $query ) => $query->where( fn ( $scoped ) => $scoped->where( $ordersTable . '.branch_id', $branchId )->orWhereNull( $ordersTable . '.branch_id' ) ) )
            ->leftJoin( $customersTable, $ordersTable . '.customer_id', '=', $customersTable . '.id' )
            ->leftJoin( $groupsTable, $customersTable . '.group_id', '=', $groupsTable . '.id' )
            ->whereIn( $ordersTable . '.payment_status', $statuses )
            ->where( $ordersTable . '.created_at', '>=', $startDate )
            ->where( $ordersTable . '.created_at', '<=', $endDate )
            ->orderBy( $ordersTable . '.created_at' )
            ->orderBy( $ordersTable . '.id' )
            ->select( [
                $ordersTable . '.id as id',
                $ordersTable . '.code as code',
                $ordersTable . '.created_at as created_at',
                $ordersTable . '.total as total',
                $ordersTable . '.payment_status as payment_status',
                $ordersTable . '.customer_id as customer_id',
                $customersTable . '.first_name as first_name',
                $customersTable . '.last_name as last_name',
                $groupsTable . '.payment_term_days as payment_term_days',
            ] )
            ->get();

        if ( $orders->isEmpty() ) {
            return $this->emptyDeliveryNoteRegister( $type, $startDate, $endDate );
        }

        $orderIds = $orders->pluck( 'id' )->all();

        $documents = $this->deliveryNoteDocuments( $orderIds );
        $vatByOrder = $this->deliveryNoteVatSplit( $orderIds, $vatService );

        $rows = [];
        $totalNet = 0.0;
        $totalTax = 0.0;
        $totalGross = 0.0;

        foreach ( $orders as $order ) {
            $document = $documents[ $order->id ] ?? null;

            /**
             * Once a tax invoice has been issued, its own frozen figures are the ones that
             * belong here. The customer is holding that document and an auditor will read
             * it, so a register that recomputed the split could disagree with it by a
             * satang — the invoice rounds each line, an aggregate rounds each group — and a
             * control sheet that does not tie out to the documents it lists is worse than
             * no control sheet. Orders not yet invoiced fall back to the computed split,
             * which is all there is for them.
             */
            $split = $document !== null && $order->payment_status !== Order::PAYMENT_PARTIALLY_REFUNDED
                ? [ 'net' => $document[ 'net' ], 'tax' => $document[ 'tax' ] ]
                : ( $vatByOrder[ $order->id ] ?? [ 'net' => (float) $order->total, 'tax' => 0.0 ] );

            $net = ns()->currency->define( $split[ 'net' ] )->toFloat();
            $tax = ns()->currency->define( $split[ 'tax' ] )->toFloat();
            $gross = ns()->currency->define( (float) $order->total )->toFloat();

            $rows[] = [
                'order_id' => (int) $order->id,
                'delivered_at' => Carbon::parse( $order->created_at )->toDateString(),

                /**
                 * The เลขที่ใบส่งฯ column. The full form tax invoice is the delivery note —
                 * that is what its combined title says — so its number is the reference the
                 * customer holds. An order delivered without one is listed by its own code,
                 * which is then the only reference either side can match on.
                 */
                'document_number' => $document[ 'number' ] ?? $order->code,

                /**
                 * The ลูกค้า column is a short reference, not a name. Nothing in this
                 * application stores the codes the shop's previous system used, so the
                 * customer id stands in: stable, unique, and enough to find the record.
                 */
                'customer_code' => $order->customer_id ? (string) $order->customer_id : '',
                'customer_name' => trim( sprintf( '%s %s', (string) $order->first_name, (string) $order->last_name ) ),
                'payment_term_days' => (int) ( $order->payment_term_days ?? 0 ),
                'net' => $net,
                'tax' => $tax,
                'total' => $gross,
            ];

            $totalNet += $net;
            $totalTax += $tax;
            $totalGross += $gross;
        }

        $totalNet = ns()->currency->define( $totalNet )->toFloat();
        $totalTax = ns()->currency->define( $totalTax )->toFloat();
        $totalGross = ns()->currency->define( $totalGross )->toFloat();

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'printed_at' => ns()->date->getNow()->toDateTimeString(),
            ],
            'type' => $type,
            'title' => $this->deliveryNoteRegisterTitle( $type ),
            'rows' => $rows,

            /**
             * Shaped like every other report in the app, so a generic table can render the
             * closing figures without knowing what this document is.
             */
            'summary' => [
                [ 'label' => __( 'จำนวนเงินสุทธิ' ), 'total' => $totalNet ],
                [ 'label' => __( 'ภาษี' ), 'total' => $totalTax ],
                [ 'label' => __( 'จำนวนเงินรวม' ), 'total' => $totalGross ],
            ],
            'totals' => [
                'documents' => count( $rows ),
                'net' => $totalNet,
                'tax' => $totalTax,
                'total' => $totalGross,
            ],
            'notes' => $this->deliveryNoteRegisterNotes( $type ),
        ];
    }

    /**
     * The VAT split of each order's lines, keyed by order id.
     *
     * Taxed with the rate frozen on each line rather than today's rate, so a register
     * reprinted after a rate change still reconciles against the till slip from the day.
     * Refunded lines are dropped: the line was reversed, so it produced no tax.
     *
     * @param  array<int, int>                             $orderIds
     * @return array<int, array{ net: float, tax: float }>
     */
    protected function deliveryNoteVatSplit( array $orderIds, ThaiVatService $vatService ): array
    {
        $productsTable = Hook::filter( 'ns-model-table', 'nexopos_orders_products' );
        $prefixed = Helper::getPrefixedTableName( $productsTable );

        $rows = DB::table( $productsTable )
            ->whereIn( 'order_id', $orderIds )
            ->where( 'status', '!=', 'refunded' )
            ->groupBy( 'order_id', 'vat_status', 'vat_rate' )
            ->select( [ 'order_id', 'vat_status', 'vat_rate' ] )
            ->selectRaw( 'SUM(' . $prefixed . '.total_price) as gross' )
            ->get();

        $split = [];

        foreach ( $rows as $row ) {
            $orderId = (int) $row->order_id;
            $gross = (float) $row->gross;
            $status = $vatService->normalizeStatus( $row->vat_status );

            $split[ $orderId ] ??= [ 'net' => 0.0, 'tax' => 0.0 ];

            if ( $status !== ThaiVatService::STATUS_STANDARD ) {
                /**
                 * Exempt and zero rated supplies carry no tax, so the whole amount is the
                 * value of the goods. They are not separated here: the paper register has
                 * one tax column, and the split that matters for filing lives in the PP30
                 * report instead.
                 */
                $split[ $orderId ][ 'net' ] += $gross;

                continue;
            }

            $rate = (float) $row->vat_rate;

            if ( $rate <= 0 ) {
                $rate = $vatService->rateFor( $status );
            }

            $split[ $orderId ][ 'net' ] += $vatService->netFromGross( $gross, $rate );
            $split[ $orderId ][ 'tax' ] += $vatService->vatFromGross( $gross, $rate );
        }

        return $split;
    }

    /**
     * The issued tax invoice for each order — its number and its own frozen VAT split.
     *
     * A full form invoice wins over an abbreviated one when an order has both, because
     * the full form is the copy the customer filed and therefore the number they will
     * quote back. Cancelled documents are ignored: the number was withdrawn.
     *
     * @param  array<int, int>                                             $orderIds
     * @return array<int, array{ number: string, net: float, tax: float }>
     */
    protected function deliveryNoteDocuments( array $orderIds ): array
    {
        $invoicesTable = Hook::filter( 'ns-model-table', 'nexopos_tax_invoices' );

        $documents = [];

        DB::table( $invoicesTable )
            ->whereIn( 'order_id', $orderIds )
            ->where( 'status', TaxInvoice::STATUS_ISSUED )
            /**
             * "abbreviated" sorts before "full", so ordering ascending and letting later
             * rows overwrite earlier ones leaves the full form in place.
             */
            ->orderBy( 'type' )
            ->orderBy( 'id' )
            ->select( [ 'order_id', 'number', 'total_net', 'total_vat', 'total_exempt', 'total_zero_rated' ] )
            ->get()
            ->each( function ( $invoice ) use ( &$documents ) {
                $documents[ (int) $invoice->order_id ] = [
                    'number' => (string) $invoice->number,

                    /**
                     * The register has one net column, so the untaxed supplies are folded
                     * into it. total_net on the invoice covers only the standard rated part;
                     * without the other two an exempt sale would print a net of zero beside
                     * a total of the full amount.
                     */
                    'net' => (float) $invoice->total_net
                        + (float) $invoice->total_exempt
                        + (float) $invoice->total_zero_rated,
                    'tax' => (float) $invoice->total_vat,
                ];
            } );

        return $documents;
    }

    protected function deliveryNoteRegisterTitle( string $type ): string
    {
        return $type === self::DELIVERY_NOTES_CONSIGNMENT
            ? __( 'รายงานการออกใบส่งสินค้า/ใบฝากสินค้าชั่วคราว' )
            : __( 'รายงานการออกใบส่งสินค้า/บิลเงินสด' );
    }

    /**
     * @return array<int, string>
     */
    protected function deliveryNoteRegisterNotes( string $type ): array
    {
        $notes = [
            __( 'ไม่นับบิลพัก ยกเลิก หรือคืนทั้งหมด บิลคืนบางส่วนยังแสดงยอดคงเหลือหลังคืน ไม่หักยอดคืนซ้ำ' ),
            __( 'ช่อง "เลขที่ใบส่งฯ" ใช้เลขใบกำกับภาษีแบบเต็มรูปถ้าออกไว้แล้ว ถ้ายังไม่ได้ออกจะใช้เลขบิลแทน' ),
            __( 'บิลที่ออกใบกำกับภาษีแล้ว ยอดสุทธิและภาษีจะดึงจากตัวใบกำกับภาษีโดยตรง จึงตรงกับใบที่ลูกค้าถืออยู่ทุกสตางค์' ),
        ];

        $notes[] = $type === self::DELIVERY_NOTES_CONSIGNMENT
            ? __( 'รายงานนี้คือบิลที่ส่งของออกไปแล้วแต่ยังไม่ได้รับเงินครบ ยอดรวมควรตรงกับสมุดลูกหนี้' )
            : __( 'รายงานนี้แสดงบิลชำระครบและบิลคืนบางส่วนตามวันที่ขาย บิลรับเงินไม่ครบอยู่ในทะเบียนใบฝากสินค้าชั่วคราว ยอดนี้ไม่ใช่ยอดรับเงินก่อนหักเงินทอน' );

        return $notes;
    }

    /**
     * @return array{ period: array, type: string, title: string, rows: array, summary: array, totals: array, notes: array }
     */
    protected function emptyDeliveryNoteRegister( string $type, string $startDate, string $endDate ): array
    {
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'printed_at' => ns()->date->getNow()->toDateTimeString(),
            ],
            'type' => $type,
            'title' => $this->deliveryNoteRegisterTitle( $type ),
            'rows' => [],
            'summary' => [
                [ 'label' => __( 'จำนวนเงินสุทธิ' ), 'total' => 0.0 ],
                [ 'label' => __( 'ภาษี' ), 'total' => 0.0 ],
                [ 'label' => __( 'จำนวนเงินรวม' ), 'total' => 0.0 ],
            ],
            'totals' => [
                'documents' => 0,
                'net' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ],
            'notes' => $this->deliveryNoteRegisterNotes( $type ),
        ];
    }
}
