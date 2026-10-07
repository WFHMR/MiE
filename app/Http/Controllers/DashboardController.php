<?php

/**
 * RGBF Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DashboardDay;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\DateService;
use App\Services\ModulesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

class DashboardController extends Controller
{
    public function __construct(
        protected DateService $dateService
    ) {
        // ...
    }

    public function home()
    {
        return View::make( 'pages.dashboard.home', [
            'title' => __( 'Dashboard' ),
        ] );
    }

    /**
     * The branch this dashboard is reporting on.
     *
     * SetDefaultBranch puts a branch in the session on every authenticated request and re-checks it
     * against Branch::selectableBy on the way, so a user tied to one branch cannot be carrying another
     * one — the session is already the authority and there is no second rule to invent here. A user with
     * no branch of their own, the owner and the administrator, is seated on the main branch and moves with
     * the picker in the header.
     */
    private function dashboardBranchId(): ?int
    {
        $selected = session( 'selected_branch_id' );

        if ( ! empty( $selected ) ) {
            return (int) $selected;
        }

        $userBranch = Auth::user()?->branch_id;

        return empty( $userBranch ) ? null : (int) $userBranch;
    }

    /**
     * Orders belonging to the branch being reported on.
     *
     * Branchless orders are included, for the same reason branchless stock rows are: an install that has
     * never been split into branches carries null on every row, and enforcing a branch there would report
     * a shop that trades every day as having taken nothing. Once the rows genuinely carry a branch — as
     * they do here, on all of them — the condition does the work.
     */
    private function ordersForBranch(): Builder
    {
        $query = Order::query();

        $branchId = $this->dashboardBranchId();

        if ( $branchId !== null && Schema::hasColumn( 'nexopos_orders', 'branch_id' ) ) {
            $query->where(
                fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
            );
        }

        return $query;
    }

    /**
     * The figures the two cards at the top of the dashboard show, for this branch alone.
     *
     * This used to hand back a row of the dashboard_days table, and that table has no branch column at
     * all, so every branch was shown the whole company's takings. Two of the four figures the cards read
     * off it did not exist under those names either: the incomplete-orders card asked for total_unpaid and
     * today_unpaid, which the table does not carry, so it printed nothing but zero, and the sales card
     * printed day_paid_orders — an amount of money — as its count of bills.
     *
     * Counted from the orders instead, which is the only place that knows which branch a sale belongs to.
     *
     * @return array<string, mixed>
     */
    public function getCards(): array
    {
        $todayStarts = $this->dateService->copy()->startOfDay()->toDateTimeString();
        $todayEnds = $this->dateService->copy()->endOfDay()->toDateTimeString();

        $takenToday = $this->ordersForBranch()
            ->from( $todayStarts )
            ->to( $todayEnds )
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] );

        /**
         * Anything with money still owed on it. A partially paid bill belongs on both cards: what was
         * handed over is takings, what is left is outstanding.
         */
        $owingStatuses = [
            Order::PAYMENT_UNPAID,
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_DUE,
            Order::PAYMENT_PARTIALLY_DUE,
        ];

        $outstanding = fn ( Builder $query ): float => (float) $query
            ->whereIn( 'payment_status', $owingStatuses )
            ->get( [ 'total', 'tendered' ] )
            ->reduce( fn ( float $carry, Order $order ): float => $carry + max( 0, (float) $order->total - (float) $order->tendered ), 0.0 );

        return [
            'day_income' => (float) $takenToday->clone()->sum( 'total' ),
            'day_orders_count' => (int) $takenToday->clone()->count(),
            'total_unpaid' => $outstanding( $this->ordersForBranch() ),
            'today_unpaid' => $outstanding( $this->ordersForBranch()->from( $todayStarts )->to( $todayEnds ) ),
        ];
    }

    /**
     * The customers who have spent the most in this branch.
     *
     * Read from Customer::purchases_amount before, which is a running company-wide total kept on the
     * customer, so every branch was shown the same five names and the same figures. Summed from the bills
     * this branch actually rang up instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBestCustomers(): array
    {
        $spendPerCustomer = $this->ordersForBranch()
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] )
            ->whereNotNull( 'customer_id' )
            ->selectRaw( 'customer_id, sum(total) as spent' )
            ->groupBy( 'customer_id' )
            ->orderByDesc( 'spent' )
            ->limit( 5 )
            ->get();

        $customers = Customer::whereIn( 'id', $spendPerCustomer->pluck( 'customer_id' ) )
            ->get()
            ->keyBy( 'id' );

        return $spendPerCustomer
            ->map( function ( $row ) use ( $customers ) {
                $customer = $customers->get( $row->customer_id );

                return [
                    'id' => (int) $row->customer_id,
                    'first_name' => $customer->first_name ?? __( 'Unknown Customer' ),
                    'last_name' => $customer->last_name ?? '',

                    /**
                     * Named as the widget reads it, so the column keeps its meaning: what this customer
                     * spent here, not what they have spent everywhere.
                     */
                    'purchases_amount' => (float) $row->spent,
                ];
            } )
            ->values()
            ->toArray();
    }

    public function dispatchTelemetry()
    {
        $version = config( 'nexopos.version' );
        $modules = app()->make( ModulesService::class )->get();

        $modulesData = [];
        foreach ( $modules as $module ) {
            if ( $module['enabled'] ?? false ) {
                $modulesData[] = [
                    'namespace' => $module['namespace'],
                    'version' => $module['version'],
                ];
            }
        }

        $payload = [
            'version' => $version,
            'modules' => $modulesData,
            'host' => request()->getHost(),
        ];

        $url = config( 'nexopos.telemetry.url' );

        if ( empty( $url ) ) {
            return response()->json( [
                'status' => 'success',
                'message' => 'Telemetry is not configured.',
            ] );
        }

        try {
            Http::post( $url, $payload );
            ns()->option->set( 'ns_telemetry_last_sent', now()->toDateTimeString() );
        } catch ( \Exception $e ) {
            Log::warning( 'Telemetry failed: ' . $e->getMessage() );

            /**
             * Telemetry is a background courtesy ping. A failure to reach the
             * endpoint is not a server error, and reporting it as one puts an
             * error on every dashboard page.
             */
            return response()->json( [
                'status' => 'error',
                'message' => 'Telemetry could not be delivered.',
            ] );
        }

        return response()->json( ['status' => 'success'] );
    }

    /**
     * The last ten bills rung up in this branch. It used to be the last ten in the company, so a branch
     * with one till a day saw a list belonging mostly to somewhere else.
     */
    public function getRecentsOrders()
    {
        return $this->ordersForBranch()
            ->orderBy( 'created_at', 'desc' )
            ->with( 'user' )
            ->limit( 10 )
            ->get();
    }

    /**
     * Today's takings per branch.
     *
     * The widget that shows this used to carry six branch names written into the
     * component, and put the whole day's income against the second one. That reads
     * as five branches trading nothing, which is worse than showing nothing: it
     * invents shops that do not exist and hides the fact that the figure is not
     * broken down at all.
     *
     * Derived from the orders themselves so a branch appears once it exists and its
     * total is its own.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranchesRevenue()
    {
        $todayStarts = $this->dateService->copy()->startOfDay()->toDateTimeString();
        $todayEnds = $this->dateService->copy()->endOfDay()->toDateTimeString();

        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            return [];
        }

        $ordersAreBranchAware = Schema::hasColumn( 'nexopos_orders', 'branch_id' );

        /**
         * Only paid and partially paid orders count as takings. Held and voided
         * orders would otherwise inflate the day.
         */
        $takings = $ordersAreBranchAware
            ? Order::whereBetween( 'created_at', [ $todayStarts, $todayEnds ] )
                ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] )
                ->selectRaw( 'branch_id, count(*) as orders_count, sum(total) as revenue' )
                ->groupBy( 'branch_id' )
                ->get()
                ->keyBy( 'branch_id' )
            : collect();

        /**
         * Only the branches this user is allowed in. A user tied to one branch used to be shown every
         * branch's takings side by side on their own dashboard, which is the one panel on the page whose
         * whole purpose is to compare them. Branch::selectableBy is the same rule the header picker and
         * SetDefaultBranch already use, so a cashier sees their own row and the owner still sees them all.
         */
        return Branch::selectableBy( Auth::user() )
            ->orderByDesc( 'is_main' )
            ->orderBy( 'name' )
            ->get()
            ->map( function ( Branch $branch ) use ( $takings ) {
                $row = $takings->get( $branch->id );

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_main' => (bool) $branch->is_main,
                    'is_main_warehouse' => (bool) $branch->is_main_warehouse,
                    'orders' => $row ? (int) $row->orders_count : 0,
                    'revenue' => $row ? (float) $row->revenue : 0.0,
                ];
            } )
            ->values()
            ->toArray();
    }

    /**
     * Takings for today, this month, or this year.
     *
     * The widget that shows these three figures had no endpoint to ask. It called dashboard/day for all
     * three tabs and read fields off the reply that the dashboard_days table does not have — total_orders,
     * total_customers, day_orders, day_customers — so the order and customer counts were always zero, and
     * it printed day_paid_orders, which is an amount of money, as a number of bills. For the month and the
     * year it read total_income, which ReportService builds as a running sum of every day since the shop
     * opened, so both tabs showed the same all-time figure under two different headings.
     *
     * Counted from the orders themselves rather than from the report tables. Those are filled by a job on
     * a schedule, and a dashboard that quietly reports yesterday's takings is worse than one that takes an
     * extra query. Paid and partially paid only, which is the rule getBranchesRevenue already applies, so
     * the two panels on the same page agree.
     *
     * Scoped to the branch being reported on, like every other figure on this dashboard.
     *
     * @return array<string, mixed>
     */
    public function getSalesSummary( Request $request ): array
    {
        $period = $request->input( 'period', 'day' );

        $now = $this->dateService->copy();

        [ $starts, $ends ] = match ( $period ) {
            'month' => [ $now->copy()->startOfMonth(), $now->copy()->endOfMonth() ],
            'year' => [ $now->copy()->startOfYear(), $now->copy()->endOfYear() ],
            default => [ $now->copy()->startOfDay(), $now->copy()->endOfDay() ],
        };

        /**
         * Recognised periods only. Asking for something else used to be impossible because there was no
         * endpoint; now it has to answer rather than silently fall back to the day and label it as a year.
         */
        if ( ! in_array( $period, [ 'day', 'month', 'year' ], true ) ) {
            $period = 'day';
        }

        $orders = $this->ordersForBranch()
            ->from( $starts->toDateTimeString() )
            ->to( $ends->toDateTimeString() )
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] );

        return [
            'period' => $period,
            'range_starts' => $starts->toDateTimeString(),
            'range_ends' => $ends->toDateTimeString(),
            'income' => (float) $orders->clone()->sum( 'total' ),
            'orders' => (int) $orders->clone()->count(),

            /**
             * Distinct buyers, not bills. Counting rows would make this box a second, slightly different
             * copy of the one beside it. customer_id is not nullable on this table — a walk-in sale is
             * still filed against a customer record — so the null guard is there for the schema changing
             * rather than for anything present today.
             */
            'customers' => (int) $orders->clone()
                ->whereNotNull( 'customer_id' )
                ->distinct()
                ->count( 'customer_id' ),
        ];
    }

    /**
     * Who rang up the most in this branch.
     *
     * Read from User::total_sales before, which is a company-wide running total, and restricted to the
     * cashier role — so a branch saw the same league table as every other branch, and a sale rung up by a
     * manager or by the owner counted for nobody. Summed from the bills of this branch and attributed to
     * whoever actually took them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBestCashiers(): array
    {
        $salesPerAuthor = $this->ordersForBranch()
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] )
            ->selectRaw( 'author_id, sum(total) as sold' )
            ->groupBy( 'author_id' )
            ->orderByDesc( 'sold' )
            ->limit( 10 )
            ->get();

        $users = User::whereIn( 'id', $salesPerAuthor->pluck( 'author_id' ) )
            ->get()
            ->keyBy( 'id' );

        return $salesPerAuthor
            ->map( fn ( $row ) => [
                'id' => (int) $row->author_id,
                'username' => $users->get( $row->author_id )->username ?? __( 'Unknown User' ),

                /** Named as the widget reads it: sold here, not sold everywhere. */
                'total_sales' => (float) $row->sold,
            ] )
            ->values()
            ->toArray();
    }

    public function getWeekReports()
    {
        $weekMap = [
            0 => [
                'label' => __( 'Sunday' ),
                'value' => 'SU',
            ],
            1 => [
                'label' => __( 'Monday' ),
                'value' => 'MO',
            ],
            2 => [
                'label' => __( 'Tuesday' ),
                'value' => 'TU',
            ],
            3 => [
                'label' => __( 'Wednesday' ),
                'value' => 'WE',
            ],
            4 => [
                'label' => __( 'Thursday' ),
                'value' => 'TH',
            ],
            5 => [
                'label' => __( 'Friday' ),
                'value' => 'FR',
            ],
            6 => [
                'label' => __( 'Saturday' ),
                'value' => 'SA',
            ],
        ];

        $currentWeekStarts = $this->dateService->copy()->startOfWeek();
        $currentWeekEnds = $this->dateService->copy()->endOfWeek();
        $lastWeekStarts = $currentWeekStarts->copy()->subDay()->startOfWeek();
        $lastWeekEnds = $currentWeekStarts->copy()->subDay()->endOfWeek();

        DashboardDay::from( $currentWeekStarts->toDateTimeString() )
            ->to( $currentWeekEnds->toDateTimeString() )
            ->get()
            ->each( function ( $report ) use ( &$weekMap ) {
                if ( ! isset( $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'current' ][ 'entries' ] ) ) {
                    $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'current' ][ 'entries' ] = [];
                }

                $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'current' ][ 'entries' ][] = $report;
            } );

        DashboardDay::from( $lastWeekStarts->toDateTimeString() )
            ->to( $lastWeekEnds->toDateTimeString() )
            ->get()
            ->each( function ( $report ) use ( &$weekMap ) {
                if ( ! isset( $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'previous' ][ 'entries' ] ) ) {
                    $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'previous' ][ 'entries' ] = [];
                }

                $weekMap[ Carbon::parse( $report->range_starts )->dayOfWeek ][ 'previous' ][ 'entries' ][] = $report;
            } );

        return [
            'range_starts' => $lastWeekStarts->toDateTimeString(),
            'range_ends' => $currentWeekEnds->toDateTimeString(),
            'result' => $weekMap,
        ];
    }

    public function createSymbolicLinks( Request $request )
    {
        // we might delete the notification that is provided on the query parameters
        if ( $request->has( 'notification_id' ) ) {
            $notification = Notification::find( $request->get( 'notification_id' ) );
            if ( $notification ) {
                $notification->delete();
            }
        }

        ns()->createSymbolicLinks();

        return [
            'message' => __( 'Symbolic links have been created successfully.' ),
            'status' => 'success',
        ];
    }
}
