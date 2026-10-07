<?php

namespace App\Services;

use App\Events\CashRegisterHistoryAfterAllDeletedEvent;
use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\Register;
use App\Models\RegisterHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CashRegistersService
{
    public function openRegister( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_CLOSED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to open "%s", as it\'s not closed.' ),
                    $register->name
                )
            );
        }

        $register->status = Register::STATUS_OPENED;
        $register->used_by = Auth::id();
        $register->save();

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_OPENING;
        $registerHistory->author_id = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = $amount;
        $registerHistory->balance_after = ns()->currency->define( $register->balance )->additionateBy( $amount )->toFloat();
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The register has been successfully opened' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function closeRegister( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to open "%s" *, as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( ns()->currency->getRaw( $register->balance ) === ns()->currency->getRaw( $amount ) ) {
            $diffType = 'unchanged';
        } else {
            $diffType = ns()->currency->getRaw( $register->balance ) < ns()->currency->getRaw( $amount ) ? 'positive' : 'negative';
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CLOSING;
        $registerHistory->transaction_type = $diffType;
        $registerHistory->balance_after = ns()->currency->define( $register->balance )->subtractBy( $amount )->toFloat();
        $registerHistory->value = ns()->currency->define( $amount )->toFloat();
        $registerHistory->balance_before = $register->balance;
        $registerHistory->author_id = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->save();

        $register->status = Register::STATUS_CLOSED;
        $register->used_by = null;
        $register->balance = 0;
        $register->save();

        return [
            'status' => 'success',
            'message' => __( 'The register has been successfully closed' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function cashIng( Register $register, float $amount, ?string $description ): array
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to cashing on "%s" *, as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( $amount <= 0 ) {
            throw new NotAllowedException( __( 'The provided amount is not allowed. The amount should be greater than "0". ' ) );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CASHING;
        $registerHistory->author_id = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = ns()->currency->define( $amount )->toFloat();
        $registerHistory->balance_after = ns()->currency->define( $register->balance )->additionateBy( $amount )->toFloat();
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The cash has successfully been stored' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function saveOrderPayment( OrderPayment $orderPayment )
    {
        $register = Register::find( $orderPayment->order->register_id );

        if ( ! $register instanceof Register ) {
            return [
                'status' => 'info',
                'message' => __( 'We can\'t attach a payment to a register as it\'s reference is not provided.' ),
            ];
        }

        $cashRegisterHistory = RegisterHistory::where( 'payment_id', $orderPayment->id )->first();

        /**
         * if the cash register history doesn't exists
         * then we'll create it for the payment.
         */
        if ( ! $cashRegisterHistory instanceof RegisterHistory ) {
            $cashRegisterHistory = new RegisterHistory;
            $cashRegisterHistory->register_id = $orderPayment->order->register_id;
            $cashRegisterHistory->payment_id = $orderPayment->id;

            /**
             * type() resolves the payment type by identifier, so it comes back null once
             * the shop deletes a payment type that historical payments still refer to.
             * Falling back to 0 — the column default — keeps the drawer tracking the
             * money instead of throwing at the till, and the payment identifier is still
             * on the payment row for any report that needs the channel.
             */
            $cashRegisterHistory->payment_type_id = $orderPayment->type?->id ?? 0;
            $cashRegisterHistory->order_id = $orderPayment->order_id;
            $cashRegisterHistory->action = RegisterHistory::ACTION_ORDER_PAYMENT;
            $cashRegisterHistory->author_id = $orderPayment->order->author_id;
            $cashRegisterHistory->balance_before = $register->balance;
            $cashRegisterHistory->value = ns()->currency->define( $orderPayment->value )->toFloat();
            $cashRegisterHistory->balance_after = ns()->currency->define( $register->balance )->additionateBy( $orderPayment->value )->toFloat();
            $cashRegisterHistory->save();

            return [
                'status' => 'success',
                'message' => __( 'The cash register history has been successfully updated' ),
                'data' => [
                    'register' => $register,
                    'history' => $cashRegisterHistory,
                ],
            ];
        }

        return [
            'status' => 'success',
            'message' => __( 'The cash register history has already been recorded' ),
            'data' => [
                'register' => $register,
                'history' => $cashRegisterHistory,
            ],
        ];
    }

    public function deleteRegisterHistoryUsingOrder( $order )
    {
        $deletedRecord = RegisterHistory::where( 'order_id', $order->id )->delete();

        /**
         * The order that is being deleted might have not been created
         * within a cash register. Therefore, there is no need to dispatch CashRegisterHistoryAfterAllDeletedEvent as
         * the delete transaction failed above.
         */
        if ( $deletedRecord ) {
            CashRegisterHistoryAfterAllDeletedEvent::dispatch( Register::find( $order->register_id ) );
        }

        return [
            'status' => 'success',
            'message' => __( 'The register history has been successfully deleted' ),
            'data' => [
                'order' => $order,
            ],
        ];
    }

    public function cashOut( Register $register, float $amount, ?string $description ): array
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to cashout on "%s", as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( $register->balance - $amount < 0 ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Not enough fund to cash out.' ),
                    $register->name
                )
            );
        }

        if ( $amount <= 0 ) {
            throw new NotAllowedException( __( 'The provided amount is not allowed. The amount should be greater than "0". ' ) );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CASHOUT;
        $registerHistory->author_id = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = ns()->currency->define( $register->balance )->toFloat();
        $registerHistory->value = ns()->currency->define( $amount )->toFloat();
        $registerHistory->balance_after = ns()->currency->define( $register->balance )->subtractBy( $amount )->toFloat();
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The cash has successfully been disbursed.' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    /**
     * Will update the cash register balance using the
     * register history model.
     */
    public function updateRegisterBalance( RegisterHistory $registerHistory )
    {
        $register = Register::find( $registerHistory->register_id );

        if ( $register instanceof Register && $register->status === Register::STATUS_OPENED ) {
            if ( in_array( $registerHistory->action, RegisterHistory::IN_ACTIONS ) ) {
                $register->balance += $registerHistory->value;
            } elseif ( in_array( $registerHistory->action, RegisterHistory::OUT_ACTIONS ) ) {
                $register->balance -= $registerHistory->value;
            }

            $register->save();
        }
    }

    /**
     * This will refresh the cash register
     * using all the in actions and out actions
     * that has been created after the last opening action.
     */
    public function refreshCashRegister( Register $cashRegister )
    {
        /**
         * if the cash register is closed then we'll
         * skip the process.
         */
        if ( $cashRegister->status === Register::STATUS_CLOSED ) {
            return [
                'status' => 'failed',
                'message' => __( 'Unable to refresh a cash register if it\'s not opened.' ),
            ];
        }

        /**
         * We need to pull the last opening action
         * as it's using the creation date we'll pull total in and out actions
         */
        $lastOpeningAction = RegisterHistory::where( 'register_id', $cashRegister->id )
            ->where( 'action', RegisterHistory::ACTION_OPENING )
            ->orderBy( 'id', 'desc' )
            ->first();

        $totalInActions = RegisterHistory::whereIn(
            'action', RegisterHistory::IN_ACTIONS
        )
            ->where( 'created_at', '>=', $lastOpeningAction->created_at )
            ->where( 'register_id', $cashRegister->id )->sum( 'value' );

        $totalOutActions = RegisterHistory::whereIn(
            'action', RegisterHistory::OUT_ACTIONS
        )
            ->where( 'created_at', '>=', $lastOpeningAction->created_at )
            ->where( 'register_id', $cashRegister->id )->sum( 'value' );

        $cashRegister->balance = ns()->currency->define( $totalInActions )->subtractBy( $totalOutActions )->toFloat();

        $cashRegister->save();

        return [
            'status' => 'success',
            'message' => _( 'The register has been successfully refreshed.' ),
        ];
    }

    /**
     * @todo For now we'll change the order change as cash
     * we'll late add support for two more change methods
     *
     * @return void
     */
    public function saveOrderChange( Order $order )
    {
        // If we might assume only paid orders are passed here,
        // we'll still need make sure to check the payment status
        if ( $order->payment_status == Order::PAYMENT_PAID && $order->change > 0 ) {
            $register = Register::find( $order->register_id );

            if ( $register instanceof Register ) {
                $registerHistory = RegisterHistory::where( 'order_id', $order->id )
                    ->where( 'action', RegisterHistory::ACTION_ORDER_CHANGE )
                    ->firstOrNew();

                $registerHistory->payment_type_id = ns()->option->get( 'ns_pos_registers_default_change_payment_type' );
                $registerHistory->register_id = $register->id;
                $registerHistory->order_id = $order->id;
                $registerHistory->action = RegisterHistory::ACTION_ORDER_CHANGE;
                $registerHistory->author_id = $order->author_id;
                $registerHistory->description = __( 'Change on cash' );
                $registerHistory->balance_before = $register->balance;
                $registerHistory->value = ns()->currency->define( $order->change )->toFloat();
                $registerHistory->balance_after = ns()->currency->define( $register->balance )->subtractBy( $order->change )->toFloat();
                $registerHistory->save();
            }
        }
    }

    /**
     * returns human readable labels
     * for all register actions.
     *
     * @todo we should add a custom action label besed
     * on the order payment type.
     */
    public function getActionLabel( string $label ): string
    {
        switch ( $label ) {
            case RegisterHistory::ACTION_CASHING:
                return __( 'Cash In' );
                break;
            case RegisterHistory::ACTION_CASHOUT:
                return __( 'Cash Out' );
                break;
            case RegisterHistory::ACTION_ORDER_CHANGE:
                return __( 'Change On Cash' );
                break;
            case RegisterHistory::ACTION_ACCOUNT_CHANGE:
                return __( 'Change On Customer Account' );
                break;
            case RegisterHistory::ACTION_CLOSING:
                return __( 'Closing' );
                break;
            case RegisterHistory::ACTION_OPENING:
                return __( 'Opening' );
                break;
            case RegisterHistory::ACTION_REFUND:
                return __( 'Refund' );
                break;
            case RegisterHistory::ACTION_ORDER_PAYMENT:
                return __( 'Cash Payment' );
                break;
            default:
                return $label;
                break;
        }
    }

    /**
     * Returns the register status for human
     */
    public function getRegisterStatusLabel( string $label ): string
    {
        switch ( $label ) {
            case Register::STATUS_CLOSED:
                return __( 'Closed' );
                break;
            case Register::STATUS_DISABLED:
                return __( 'Disabled' );
                break;
            case Register::STATUS_INUSE:
                return __( 'In Use' );
                break;
            case Register::STATUS_OPENED:
                return __( 'Opened' );
                break;
            default:
                return $label;
                break;
        }
    }

    /**
     * Update the register with various details.
     */
    public function getRegisterDetails( Register $register ): Register
    {
        $register->status_label = $this->getRegisterStatusLabel( $register->status );
        $register->opening_balance = 0;
        $register->total_sale_amount = 0;

        if ( $register->status === Register::STATUS_OPENED ) {
            $history = $register->history()
                ->where( 'action', RegisterHistory::ACTION_OPENING )
                ->orderBy( 'id', 'desc' )->first();

            $register->opening_balance = $history->value;

            $register->total_sale_amount = Order::paid()
                ->where( 'register_id', $register->id )
                ->where( 'created_at', '>=', $history->created_at )
                ->sum( 'total' );
        }

        return $register;
    }

    private function diffInTime( $start, $end )
    {
        $startTime = Carbon::parse( $start );
        $endTime = Carbon::parse( $end );

        /**
         * Rounded to a whole number of minutes before any integer arithmetic.
         *
         * diffInMinutes() returns a float, and intdiv() and % both take integers, so every shift
         * closing quietly logged two "implicit conversion from float loses precision" deprecations.
         * Made absolute as well: the caller passes the closing time first and the opening second, so
         * a signed difference would print a shift as "-9:-48".
         */
        $totalMinutes = (int) abs( round( $endTime->diffInMinutes( $startTime ) ) );

        $hours = intdiv( $totalMinutes, 60 );
        $minutes = $totalMinutes % 60;

        // Format the result
        $formattedTime = sprintf( '%d:%02d', $hours, $minutes );

        return $formattedTime;
    }

    /**
     * Everything that happened on one till between an opening and its closing — the shift closing
     * document (ใบปิดกะ).
     *
     * The figure that matters is the cash the drawer should hold, because that is the number the
     * person closing the till counts against. It is built from the movements that actually pass
     * through the drawer: the float it opened with, cash taken in payment, cash added during the
     * shift, less change handed back, cash removed, and cash refunded. Anything paid by transfer or
     * card is reported separately and deliberately left out of it — counting it would make every
     * drawer look short by the value of its card sales.
     */
    public function getZReport( Register $register )
    {
        $opening = RegisterHistory::where( 'register_id', $register->id )
            ->where( 'action', RegisterHistory::ACTION_OPENING )
            ->orderBy( 'id', 'desc' )
            ->first();

        /**
         * A till that has never been opened has no shift to report on. Without this the page died
         * on a fatal error reading ->id on null, which is what a brand new till or one whose
         * history was cleared would do.
         */
        if ( ! $opening instanceof RegisterHistory ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'ยังไม่มีการเปิดกะของเครื่อง "%s" จึงยังไม่มีใบปิดกะให้พิมพ์' ),
                    $register->name
                )
            );
        }

        $closing = RegisterHistory::where( 'register_id', $register->id )
            ->where( 'action', RegisterHistory::ACTION_CLOSING )
            ->where( 'id', '>', $opening->id )
            ->orderBy( 'id', 'desc' )
            ->first();

        /**
         * Bounded by row id at both ends rather than by timestamp. Two rows written in the same
         * second are ordered reliably by id but not by created_at, and a till opened again after
         * closing would otherwise pull the next shift's movements into this document.
         */
        $historyQuery = RegisterHistory::where( 'register_id', $register->id )
            ->where( 'id', '>=', $opening->id );

        if ( $closing instanceof RegisterHistory ) {
            $historyQuery->where( 'id', '<=', $closing->id );
        }

        $histories = $historyQuery->orderBy( 'id', 'desc' )->get();

        /**
         * The per-category and per-product figures below walk every line of every order and read
         * `$item->product->category`. Loading only `products` left both of those hops to lazy
         * loading, which meant two extra queries for every single line sold, every time a till was
         * closed — a shift with two hundred lines paid for its slip with four hundred round trips.
         *
         * Eager loaded here instead. The figures are computed whether or not anything prints them,
         * so this cost was being paid even after the slip stopped listing them.
         */
        $orders = Order::paid()
            ->with( 'products.product.category' )
            ->where( 'register_id', $register->id )
            ->whereBetween( 'created_at', [ $opening->created_at, $closing->created_at ?? now()->toDateTimeString() ] )
            ->get();

        $orderIds = $orders->pluck( 'id' );

        /**
         * Takings per channel, counted as well as totalled.
         *
         * Every channel the shop accepts gets a line whether or not it was used, because the person
         * checking the slip against the cash reads down a fixed list — a channel that silently
         * disappears on a quiet shift makes them wonder whether it was forgotten or simply not
         * taken. A channel that has been switched off since is still listed when this shift used it,
         * so the lines always add up to the takings.
         *
         * Grouped on the payments table alone. Joining the channel table to reach its label meant
         * grouping by a column both tables carry, and "ambiguous column name: identifier" took the
         * whole document down on SQLite while MySQL quietly guessed.
         */
        $paymentTotals = OrderPayment::whereIn( 'order_id', $orderIds )
            ->select(
                'identifier',
                DB::raw( 'SUM(value) as total_amount' ),
                DB::raw( 'COUNT(*) as times' )
            )
            ->groupBy( 'identifier' )
            ->get()
            ->keyBy( 'identifier' );

        $payments = PaymentType::orderBy( 'priority' )
            ->orderBy( 'id' )
            ->get()
            ->filter( fn ( PaymentType $type ): bool => (bool) $type->active || isset( $paymentTotals[ $type->identifier ] ) )
            ->map( fn ( PaymentType $type ): object => (object) [
                'label' => $type->label,
                'identifier' => $type->identifier,
                'times' => (int) ( $paymentTotals[ $type->identifier ]->times ?? 0 ),
                'total_amount' => (float) ( $paymentTotals[ $type->identifier ]->total_amount ?? 0 ),
            ] )
            ->values();

        $totalCashPayment = OrderPayment::whereIn( 'order_id', $orders->pluck( 'id' ) )
            ->where( 'identifier', OrderPayment::PAYMENT_CASH )
            ->sum( 'value' );

        $totalChange = $orders->sum( 'change' );

        /**
         * Cash moved by hand during the shift, and cash refunded. These were missing from the
         * expected figure, so any till that took a float top-up or had money removed for the bank
         * was reported as over or short by exactly that amount, and the person closing it was left
         * hunting for a discrepancy the report had invented.
         */
        $totalCashIn = $histories->where( 'action', RegisterHistory::ACTION_CASHING )->sum( 'value' );
        $totalCashOut = $histories->where( 'action', RegisterHistory::ACTION_CASHOUT )->sum( 'value' );
        $totalRefunds = $histories->where( 'action', RegisterHistory::ACTION_REFUND )->sum( 'value' );

        $cashOnHand = ns()->currency->define( $opening->value )
            ->additionateBy( $totalCashPayment )
            ->additionateBy( $totalCashIn )
            ->subtractBy( $totalChange )
            ->subtractBy( $totalCashOut )
            ->subtractBy( $totalRefunds )
            ->toFloat();

        /**
         * What was actually counted, and by how much it misses. Both are null while the shift is
         * still running, because there is nothing to count against yet — an ongoing shift showing
         * a difference of minus the whole drawer would read as a disaster rather than as "not
         * closed yet".
         */
        $countedCash = $closing instanceof RegisterHistory ? ns()->currency->define( $closing->value )->toFloat() : null;

        $cashDifference = $countedCash === null
            ? null
            : ns()->currency->define( $countedCash )->subtractBy( $cashOnHand )->toFloat();

        /**
         * The float left in the drawer for whoever opens next, and what is actually handed over with
         * the slip.
         *
         * The shop keeps the same float from one shift to the next, so what stays behind is what this
         * shift started with and the rest goes in with the paperwork. That is the number the person
         * receiving the money counts, and it is the reason this document exists: the slip and the
         * cash are handed over together and have to agree.
         */
        $floatCarriedForward = ns()->currency->define( $opening->value )->toFloat();

        $netCashToHandIn = $countedCash === null
            ? null
            : ns()->currency->define( $countedCash )->subtractBy( $floatCarriedForward )->toFloat();

        /**
         * How many times each thing happened, not only what it added up to. The shop's own slip has
         * a count beside every amount, and it is what makes a wrong figure traceable: eight hundred
         * baht over two card payments is a different problem from the same amount over twenty.
         */
        $cashPaymentCount = (int) ( $paymentTotals[ OrderPayment::PAYMENT_CASH ]->times ?? 0 );
        $cashInCount = $histories->where( 'action', RegisterHistory::ACTION_CASHING )->count();
        $cashOutCount = $histories->where( 'action', RegisterHistory::ACTION_CASHOUT )->count();
        $refundCount = $histories->where( 'action', RegisterHistory::ACTION_REFUND )->count();
        $changeCount = $orders->where( 'change', '>', 0 )->count();

        /**
         * Which shift of the day this is. A till closed at lunch and again at night produces two of
         * these documents for one date, and without a number on each the second is indistinguishable
         * from a reprint of the first.
         */
        $shiftNumber = RegisterHistory::where( 'register_id', $register->id )
            ->where( 'action', RegisterHistory::ACTION_OPENING )
            ->whereDate( 'created_at', Carbon::parse( $opening->created_at )->toDateString() )
            ->where( 'id', '<=', $opening->id )
            ->count();

        /**
         * The VAT split, taken from the lines rather than the order totals.
         *
         * A line carrying tax is inside the VAT system and its price already includes the tax; a line
         * carrying none is outside it. Splitting on the line is what lets the two figures add back up
         * to the net sales, which is the check the shop performs on the slip.
         */
        $lines = $orders->pluck( 'products' )->flatten();

        $taxableSales = ns()->currency->define(
            $lines->filter( fn ( $line ): bool => (float) $line->tax_value > 0 )->sum( 'total_price' )
        )->toFloat();

        $nonTaxableSales = ns()->currency->define(
            $lines->filter( fn ( $line ): bool => (float) $line->tax_value <= 0 )->sum( 'total_price' )
        )->toFloat();

        $vatAmount = ns()->currency->define( $lines->sum( 'tax_value' ) )->toFloat();

        $openedOn = ns()->date->getFormatted( $opening->created_at );
        $closedOn = $closing ? ns()->date->getFormatted( $closing->created_at ) : __( 'Session Ongoing' );

        $openingBalance = ns()->currency->define( $opening->value );
        $closingBalance = ns()->currency->define( $closing->value ?? 0 );

        $rawTotalSales = $orders->sum( 'total' );
        $rawTotalShippings = $orders->sum( 'shipping' );
        $rawTotalDiscounts = $orders->sum( 'discount' );
        $rawTotalGrossSales = $orders->sum( 'subtotal' );
        $rawTotalTaxes = $orders->sum( 'tax_value' );

        $totalDiscounts = ns()->currency->define( $rawTotalDiscounts );
        $totalSales = ns()->currency->define( $rawTotalSales );
        $totalGrossSales = ns()->currency->define( $rawTotalGrossSales );
        $totalShippings = ns()->currency->define( $rawTotalShippings );
        $totalTaxes = ns()->currency->define( $rawTotalTaxes );

        $sessionDuration = $this->diffInTime(
            $closing->created_at ?? now()->toDateTimeString(),
            $opening->created_at,
        );

        /**
         * The old "difference" compared the counted cash against total sales including the ones
         * paid by card and transfer, so it reported a shortage on every till that took a non-cash
         * payment. It was never rendered, and $cashDifference above replaces it with the only
         * comparison a drawer can be held to.
         */
        $orderCount = $orders->count();

        $categories = [];
        $products = [];

        $orders->each( function ( $order ) use ( &$categories, &$products ) {
            return $order->products->each( function ( $item ) use ( &$categories, &$products ) {
                /**
                 * Keyed by category id throughout. The check used to read the name while the write
                 * used the id, so it never found what it had just written and reset the running
                 * total on every line — each category ended up reporting only its last item's
                 * quantity instead of the shift's.
                 */
                $category = $item->product?->category;
                $categoryKey = $category->id ?? 'unassigned';

                if ( ! isset( $categories[ $categoryKey ] ) ) {
                    $categories[ $categoryKey ] = [
                        'name' => $category->name ?? __( 'ไม่ระบุหมวด' ),
                        'quantity' => 0,
                    ];
                }

                $categories[ $categoryKey ][ 'quantity' ] += $item->quantity;

                /**
                 * A line whose product has since been deleted still sold and still took money, so
                 * it is reported under the name recorded on the line rather than dropped or allowed
                 * to fatal on a null relation.
                 */
                $productKey = $item->product?->id ?? 'removed-' . $item->id;

                if ( ! isset( $products[ $productKey ] ) ) {
                    $products[ $productKey ] = [
                        'name' => $item->product?->name ?? $item->name ?? __( 'สินค้าที่ถูกลบแล้ว' ),
                        'total_price' => 0,
                        'quantity' => 0,
                        'tax_value' => 0,
                        'discount' => 0,
                    ];
                }

                $products[ $productKey ][ 'total_price' ] += $item->total_price;
                $products[ $productKey ][ 'quantity' ] += $item->quantity;
                $products[ $productKey ][ 'tax_value' ] += $item->tax_value;
                $products[ $productKey ][ 'discount' ] += $item->discount;
            } );
        } );

        /**
         * The person who opened the shift. A deleted account must not stop the document printing —
         * the shift still happened and the money still has to be accounted for.
         */
        $user = User::find( $opening->author_id );

        $cashier = $user instanceof User
            ? trim( $user->first_name . ' ' . $user->last_name ) . ' (' . $user->username . ')'
            : __( 'ไม่พบบัญชีผู้ใช้ที่เปิดกะ' );

        /** The branch this till belongs to, for the heading. Null on a single store install. */
        $branch = $register->branch;

        return (object) compact(
            'register',
            'branch',
            'opening',
            'closing',
            'openedOn',
            'closedOn',
            'shiftNumber',
            'histories',
            'orders',
            'orderCount',
            'cashPaymentCount',
            'cashInCount',
            'cashOutCount',
            'refundCount',
            'changeCount',
            'taxableSales',
            'nonTaxableSales',
            'vatAmount',
            /**
             * The plain numbers as well as the CurrencyService objects. The slip prints bare figures
             * with two decimals and no symbol, the way the shop's own paper does, and a formatter
             * object cannot be cast to a float to be totalled or compared.
             */
            'rawTotalSales',
            'rawTotalGrossSales',
            'rawTotalDiscounts',
            'rawTotalShippings',
            'rawTotalTaxes',
            'floatCarriedForward',
            'netCashToHandIn',
            'openingBalance',
            'closingBalance',
            'totalGrossSales',
            'totalDiscounts',
            'totalShippings',
            'totalTaxes',
            'totalSales',
            'totalCashPayment',
            'totalChange',
            'totalCashIn',
            'totalCashOut',
            'totalRefunds',
            'cashOnHand',
            'countedCash',
            'cashDifference',
            'categories',
            'cashier',
            'sessionDuration',
            'payments',
            'products',
            'user'
        );
    }
}
