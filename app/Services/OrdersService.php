<?php

namespace App\Services;

use App\Classes\Cache;
use App\Classes\Currency;
use App\Classes\Hook;
use App\Events\DueOrdersEvent;
use App\Events\OrderAfterCheckPerformedEvent;
use App\Events\OrderAfterDeletedEvent;
use App\Events\OrderAfterInstalmentPaidEvent;
use App\Events\OrderAfterLoadedEvent;
use App\Events\OrderAfterProductRefundedEvent;
use App\Events\OrderAfterProductStockCheckedEvent;
use App\Events\OrderAfterRefundedEvent;
use App\Events\OrderAfterUpdatedDeliveryStatus;
use App\Events\OrderAfterUpdatedProcessStatus;
use App\Events\OrderBeforeDeleteEvent;
use App\Events\OrderBeforeDeleteProductEvent;
use App\Events\OrderProductAfterComputedEvent;
use App\Events\OrderRefundPaymentAfterCreatedEvent;
use App\Exceptions\NotAllowedException;
use App\Exceptions\NotFoundException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAccountHistory;
use App\Models\CustomerCoupon;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCoupon;
use App\Models\OrderInstalment;
use App\Models\OrderPayment;
use App\Models\OrderProduct;
use App\Models\OrderProductRefund;
use App\Models\OrderRefund;
use App\Models\OrderSetting;
use App\Models\OrderStorage;
use App\Models\OrderTax;
use App\Models\PaymentType;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductSubItem;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Role;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache as FacadesCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class OrdersService
{
    public function __construct(
        protected CustomerService $customerService,
        protected ProductService $productService,
        protected UnitService $unitService,
        protected DateService $dateService,
        protected CurrencyService $currencyService,
        protected Options $optionsService,
        protected TaxService $taxService,
        protected ReportService $reportService,
        protected MathService $mathService,
        protected ThaiVatService $thaiVatService
    ) {
        // ...
    }

    /**
     * Create an order on RGBF.
     *
     * @param  array      $fields
     * @param  Order|null $order  (optional)
     * @return array
     */
    /**
     * Records an order.
     *
     * Wrapped in a transaction so the stock check and the stock deduction cannot be
     * separated by another till's sale. The deduction does not happen inline: saving
     * the order flips payment_status, which dispatches
     * OrderAfterPaymentStatusChangedEvent, and its listener calls
     * saveOrderProductHistory() → ProductService::stockAdjustment(). That listener is
     * synchronous, so it runs inside this transaction and rolls back with it.
     *
     * Before this, a failure partway through left a persisted order whose stock had
     * never been deducted, and two tills selling the same last unit both passed the
     * check because nothing serialised them.
     */
    public function create( $fields, ?Order $order = null, bool $preserveAgreedPrices = false )
    {
        if (!$order && !$preserveAgreedPrices) {
            app(ScheduledPriceService::class)->guard($fields);
        }
        return DB::transaction( fn () => app( BranchLoyaltyService::class )->checkout( $fields, $order, fn ( $data, $existing ) => $this->performCreate( $data, $existing ) ) );
    }

    private function performCreate( $fields, ?Order $order = null )
    {
        $isNew = ! $order instanceof Order;

        /**
         * if the order is being edited, we need to
         * keep a reference to the previous order to be able to compare
         * the changes that has been made.
         */
        if ( $order instanceof Order ) {
            $order->load( 'payments' );
            $order->load( 'products' );
            $order->load( 'coupons' );

            $previousOrder = clone $order;
        }

        $customer = $this->__customerIsDefined( $fields );

        /**
         * Building the products. This ensure to links the orinal
         * products alongs with cogs and other details.
         */
        $fields[ 'products' ] = $this->__buildOrderProducts( $fields );
        $fields['_coupon_order_id'] = $order?->id;
        $fields = app( CouponPricingService::class )->prepare( $fields );

        /**
         * determine the value of the product
         * on the cart and compare it along with the payment made. This will
         * help to prevent partial or quote orders
         *
         * @param float  $total
         * @param float  $totalPayments
         * @param array  $payments
         * @param string $paymentStatus
         */
        extract( $this->__checkOrderPayments( $fields, $order, $customer ) );

        /**
         * We'll now check the attached coupon
         * and determine whether they can be processed.
         */
        $this->__checkAttachedCoupons( $fields );

        /**
         * As no payment might be provided
         * we make sure to build the products only in case the
         * order is just saved as hold, otherwise a check is made on the available stock
         */
        if ( in_array( $paymentStatus, [ 'paid', 'partially_paid', 'unpaid' ] ) ) {
            $fields[ 'products' ] = $this->__checkProductStock( $fields['products'], $order );
        }

        /**
         * check discount validity and throw an
         * error is something is not set correctly.
         */
        $this->__checkDiscountValidity( $fields );

        /**
         * check delivery informations before
         * proceeding
         */
        $fields = $this->__checkAddressesInformations( $fields );

        /**
         * Check if instalments are provided and if they are all
         * valid regarding the total order price
         */
        $this->__checkProvidedInstalments( $fields );

        /**
         * A customer whose group grants a credit term leaves with the goods and settles
         * later. That turns the order into an invoice with a due date, so the schedule
         * is stamped here rather than being left to whichever button the cashier used.
         */
        $fields = $this->__resolveCreditTerm( $fields, $customer, $paymentStatus, $total, $totalPayments );

        /**
         * If any other module wants to perform a verification
         * and block processing, they might use this event.
         */
        OrderAfterCheckPerformedEvent::dispatch( $fields, $order );

        /**
         * ------------------------------------------
         *                  WARNING
         * ------------------------------------------
         * All what follow will proceed database
         * modification. All verifications on current order
         * should be made prior this section
         */
        $order = $this->__initOrder( $fields, $paymentStatus, $order, $payments );

        /**
         * if we're editing an order. We need to loop the products in order
         * to recover all the products that has been deleted from the POS and therefore
         * aren't tracked no more. This also make stock adjustment for product which has changed.
         */
        $this->__deleteUntrackedProducts( $order, $fields[ 'products' ] );

        $addresses = $this->__saveAddressInformations( $order, $fields );

        if ( in_array( $paymentStatus, [
            Order::PAYMENT_PAID,
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_UNPAID,
        ] ) ) {
            $payments = $this->__saveOrderPayments( $order, $payments, $customer );
        }

        /**
         * save order instalments
         */
        $instalments = $this->__saveOrderInstalments( $order, $fields[ 'instalments' ] ?? [] );

        /**
         * save order coupons
         */
        $coupons = $this->__saveOrderCoupons( $order, $fields[ 'coupons' ] ?? [] );

        /**
         * @var Order $order
         * @var float $taxes
         * @var float $subTotal
         * @var array $orderProducts
         */
        extract( $this->__saveOrderProducts( $order, $fields[ 'products' ] ) );

        /**
         * register taxes for the order
         */
        $order->setRelations( [
            'products' => $orderProducts,
            'payments' => $payments,
            'coupons' => $coupons,
            'instalments' => $instalments,
            'addresses' => $addresses,
        ] );

        $taxes = $this->__registerTaxes( $order, $fields[ 'taxes' ] ?? [] );
        if ( count( $coupons ) > 0 ) {
            $this->__computeOrderTotal( $order, $fields['products'] );
        }

        /**
         * Those fields might be used while running a listener on
         * either the create or update event of the order.
         */
        $order->setData( $fields );

        $order->saveWithRelationships( [
            'products' => $orderProducts,
            'payments' => $payments,
            'coupons' => $coupons,
            'instalments' => $instalments,
            'taxes' => $taxes,
            'order_addresses' => $addresses,
        ] );

        $order->load( 'payments' );
        $order->load( 'products' );
        $order->load( 'coupons' );

        if ( count( $coupons ) > 0 ) {
            $order->coupons()->update( ['checkout_total_satang' => (int) round( $order->total * 100 )] );
        }

        return [
            'status' => 'success',
            'message' => $isNew ? __( 'The order has been placed.' ) : __( 'The order has been updated' ),
            'data' => compact( 'order' ),
        ];
    }

    /**
     * Will save order installments if
     * it's provider
     *
     * @param  array                             $instalments
     * @return Collection<OrderInstalment|array>
     */
    public function __saveOrderInstalments( Order $order, $instalments = [] )
    {
        if ( ! empty( $instalments ) ) {
            /**
             * delete previous instalments
             */
            $order->instalments()->delete();

            $tracked = [];

            return collect( $instalments )->map( function ( $instalment ) use ( $order, &$tracked ) {
                $newInstalment = new OrderInstalment;

                if ( isset( $instalment[ 'paid' ] ) && $instalment[ 'paid' ] ) {
                    $payment = OrderPayment::where( 'order_id', $order->id )
                        ->where( 'value', $instalment[ 'amount' ] )
                        ->whereNotIn( 'id', $tracked )
                        ->first();

                    if ( $payment instanceof OrderPayment ) {
                        /**
                         * We keep a reference to avoid
                         * having to track that twice.
                         */
                        $tracked[] = $payment->id;

                        /**
                         * let's attach the payment
                         * id to the instalment.
                         */
                        $newInstalment->payment_id = $payment->id ?? null;
                    }
                }

                $newInstalment->amount = $instalment[ 'amount' ];
                $newInstalment->paid = $instalment[ 'paid' ] ?? false;
                $newInstalment->date = Carbon::parse( $instalment[ 'date' ] )->toDateTimeString();

                return $newInstalment;
            } );
        }

        return collect( [] );
    }

    /**
     * Stamps the credit schedule on an order placed by a customer whose group grants a
     * payment term ("net 30").
     *
     * The shop hands over the goods and invoices the balance, so the order needs a due
     * date the rest of the system can act on. Reusing final_payment_date and a single
     * instalment means the existing machinery works unchanged: Order::paymentExpired()
     * finds the order once the term lapses, TrackLaidAwayOrdersJob flips it to "due" and
     * notifies, and the instalment can be settled from the order screen.
     *
     * This runs on the server rather than in the POS because the payment popup has two
     * ways out. "Layaway" builds its own schedule, while "Save As Unpaid" posts the
     * order directly and sends none, and the API is a third caller. Deciding here means
     * every route produces the same invoice.
     *
     * @param  array<string,mixed> $fields
     * @return array<string,mixed>
     *
     * @throws NotAllowedException
     */
    private function __resolveCreditTerm( array $fields, Customer $customer, string $paymentStatus, float $total, float $totalPayments ): array
    {
        $termDays = (int) ( $customer->group->payment_term_days ?? 0 );

        if (
            $termDays <= 0
            || ! in_array( $paymentStatus, [ Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIALLY ] )
        ) {
            return $fields;
        }

        /**
         * A caller that already described its own schedule keeps it. That is the layaway
         * path, where the cashier agreed on dates with the customer, and an order being
         * edited, which already carries its instalments.
         */
        if ( ! empty( $fields[ 'instalments' ] ) || ! empty( $fields[ 'final_payment_date' ] ) ) {
            return $fields;
        }

        /**
         * A group may require part of the invoice up front before releasing the goods.
         * Without this the setting only applied to layaways, so it could be set on a
         * credit group and silently do nothing.
         */
        $minimalPercentage = (float) ( $customer->group->minimal_credit_payment ?? 0 );

        if ( $minimalPercentage > 0 ) {
            $minimal = $this->currencyService->define( $total )
                ->multipliedBy( $minimalPercentage )
                ->dividedBy( 100 )
                ->toFloat();

            if ( $totalPayments < $minimal ) {
                throw new NotAllowedException( sprintf(
                    __( 'The customer group requires a minimum payment of %s before the goods can leave on credit.' ),
                    (string) $this->currencyService->define( $minimal )
                ) );
            }
        }

        $dueDate = $this->dateService->getNow()->addDays( $termDays );

        $fields[ 'final_payment_date' ] = $dueDate->toDateTimeString();
        $fields[ 'total_instalments' ] = 1;
        $fields[ 'instalments' ] = [
            [
                'amount' => $this->currencyService->define( $total )
                    ->subtractBy( $totalPayments )
                    ->toFloat(),
                'date' => $dueDate->toDateTimeString(),
                'paid' => false,
            ],
        ];

        return $fields;
    }

    /**
     * check if the provided instalments are
     * valid and verify it allong with the order
     * total.
     *
     * @param  array $fields
     * @return void
     *
     * @throws NotAllowedException
     */
    public function __checkProvidedInstalments( $fields )
    {
        if (
            isset( $fields[ 'instalments' ] ) &&
            ! empty( $fields[ 'instalments' ] ) &&
            ! in_array( $fields[ 'payment_status' ] ?? null, [ Order::PAYMENT_HOLD ] )
        ) {
            $instalments = collect( $fields[ 'instalments' ] );
            $customer = Customer::find( $fields[ 'customer_id' ] );

            if ( (float) $customer->group->minimal_credit_payment > 0 ) {
                $minimal = Currency::define( $fields[ 'total' ] )
                    ->multipliedBy( $customer->group->minimal_credit_payment )
                    ->dividedBy( 100 )
                    ->toFloat();

                /**
                 * if the minimal provided
                 * amount thoses match the required amount.
                 */
                if ( $minimal > Currency::raw( $fields[ 'tendered' ] ) && ns()->option->get( 'ns_orders_allow_unpaid' ) === 'no' ) {
                    throw new NotAllowedException(
                        sprintf(
                            __( 'The minimal payment of %s hasn\'t been provided.' ),
                            (string) Currency::define( $minimal )
                        )
                    );
                }
            }
        }
    }

    /**
     * Checks whether the attached coupons are valid
     */
    public function __checkAttachedCoupons( array $fields ): void
    {
        collect( $fields[ 'coupons' ] ?? [] )->each( function ( $coupon ) use ( $fields ) {
            $this->customerService->checkCouponExistence( $coupon, $fields );
        } );
    }

    /**
     * Computes the total of the provided coupons
     *
     * @param  array $fields
     * @param  float $subtotal
     * @return float
     */
    private function __computeOrderCoupons( $fields, $subtotal )
    {
        if ( isset( $fields[ 'coupons' ] ) ) {
            return collect( $fields[ 'coupons' ] )->map( function ( $coupon ) use ( $subtotal ) {
                if ( ! isset( $coupon[ 'value' ] ) ) {
                    return $this->__computeCouponValue( $coupon, $subtotal );
                }

                return $coupon[ 'value' ];
            } )->sum();
        }

        return 0;
    }

    private function __computeCouponValue( array $coupon, float|int $subtotal )
    {
        return match ( $coupon[ 'discount_type' ] ) {
            'percentage_discount' => $this->computeDiscountValues( $coupon[ 'discount_value' ], $subtotal ),
            'flat_discount' => $coupon[ 'discount_value' ]
        };
    }

    /**
     * Save the coupons by attaching them to the processed order
     *
     * @param  array $coupons
     * @return array
     */
    public function __saveOrderCoupons( Order $order, $coupons )
    {
        $savedCoupons = [];

        $order->total_coupons = 0;

        foreach ( $coupons as $arrayCoupon ) {
            $coupon = Coupon::find( $arrayCoupon[ 'coupon_id' ] );

            $customerCoupon = $this->customerService->assignCouponUsage(
                customer_id: $order->customer_id,
                coupon: $coupon
            );

            $existingCoupon = OrderCoupon::where( 'order_id', $order->id )
                ->where( 'coupon_id', $coupon->id )
                ->first();

            if ( ! $existingCoupon instanceof OrderCoupon ) {
                $existingCoupon = new OrderCoupon;
                $existingCoupon->coupon_id = $coupon[ 'id' ];
                $existingCoupon->customer_coupon_id = $customerCoupon->id;
                $existingCoupon->minimum_cart_value = $coupon[ 'minimum_cart_value' ] ?: 0;
                $existingCoupon->maximum_cart_value = $coupon[ 'maximum_cart_value' ] ?: 0;
                $existingCoupon->name = $coupon[ 'name' ] ?: 0;
                $existingCoupon->type = $coupon[ 'type' ] ?: 0;
                $existingCoupon->limit_usage = $coupon[ 'limit_usage' ] ?: 0;
                $existingCoupon->code = $coupon[ 'code' ];
                $existingCoupon->author_id = $order->author_id ?? Auth::id();
                $existingCoupon->discount_value = $coupon[ 'discount_value' ] ?: 0;
            }

            $existingCoupon->value = $arrayCoupon['value'];

            /**
             * that should compute
             * the coupons value automatically
             */
            $order->total_coupons += $existingCoupon->value;

            $savedCoupons[] = $existingCoupon;
        }

        /**
         * Every coupon that is not processed
         * should be deleted.
         */
        if ( ! $order->wasRecentlyCreated ) {
            OrderCoupon::where( 'order_id', $order->id )
                ->whereNotIn( 'id', collect( $savedCoupons )->pluck( 'id' )->filter()->all() )
                ->delete();
        }

        return $savedCoupons;
    }

    /**
     * Will compute the taxes assigned to an order
     */
    public function __saveOrderTaxes( Order $order, array $taxes ): array
    {
        if ( ! $order->wasRecentlyCreated ) {
            /**
             * if previous taxes had been registered,
             * we need to clear them
             */
            OrderTax::where( 'order_id', $order->id )->delete();
        }

        $taxCollection = [];

        if ( count( $taxes ) > 0 ) {
            $percentages = collect( $taxes )->map( fn( $tax ) => $tax[ 'rate' ] );
            $response = $this->taxService->getTaxesComputed(
                tax_type: $order->tax_type,
                rates: $percentages->toArray(),
                value: max( 0, ns()->currency->define( $order->subtotal )->subtractBy( $order->discount )->subtractBy( $order->total_coupons )->toFloat() )
            );

            foreach ( $taxes as $index => $tax ) {
                $orderTax = new OrderTax;
                $orderTax->tax_name = $tax[ 'name' ];
                $orderTax->tax_value = ( $response[ 'percentages' ][ $index ][ 'tax' ] ?? 0 );
                $orderTax->rate = $tax[ 'rate' ];
                $orderTax->tax_id = $tax[ 'tax_id' ];
                $orderTax->order_id = $order->id;

                $taxCollection[] = $orderTax;
            }
        }

        return $taxCollection;
    }

    /**
     * Assign taxes to the processed order
     *
     * @param array $taxes
     */
    public function __registerTaxes( Order $order, $taxes ): array
    {
        $orderTaxes = $this->__saveOrderTaxes( $order, $taxes );

        $order->products_tax_value = $this->getOrderProductsTaxes( $order );

        switch ( ns()->option->get( 'ns_pos_vat' ) ) {
            case 'flat_vat':
            case 'variable_vat':
                $order->tax_value = Currency::define( collect( $orderTaxes )->sum( 'tax_value' ) )->toFloat();
                break;
        }

        return $orderTaxes;
    }

    /**
     * will delete the products belonging to an order
     * that aren't tracked.
     */
    public function __deleteUntrackedProducts( Order $order, SupportCollection $products ): void
    {
        if ( $order instanceof Order && ! $order->wasRecentlyCreated ) {
            $ids = collect( $products )
                ->filter( fn( $product ) => isset( $product[ 'id' ] ) && isset( $product[ 'unit_id' ] ) )
                ->map( fn( $product ) => $product[ 'id' ] . '-' . $product[ 'unit_id' ] )
                ->toArray();

            /**
             * While the order is being edited, we'll check if the new quantity of
             * each product is different from the previous known quantity, to perform
             * adjustment accordingly. In that case we'll use adjustment-return & sale.
             */
            if ( $order->payment_status !== Order::PAYMENT_HOLD ) {
                $order->products->map( function ( OrderProduct $product ) use ( $products ) {
                    $productHistory = ProductHistory::where( 'operation_type', ProductHistory::ACTION_SOLD )
                        ->where( 'order_product_id', $product->id )
                        ->first();

                    /**
                     * We should restore or retreive quantities when the
                     * product has initially be marked as sold.
                     */
                    if ( $productHistory instanceof ProductHistory ) {
                        $products = collect( $products )
                            ->filter( fn( $product ) => isset( $product[ 'id' ] ) )
                            ->mapWithKeys( fn( $product ) => [ $product[ 'id' ] => $product ] )
                            ->toArray();

                        if ( in_array( $product->id, array_keys( $products ) ) ) {
                            if ( $product->quantity < $products[ $product->id ][ 'quantity' ] ) {
                                return [
                                    'operation' => 'add',
                                    'unit_price' => $products[ $product->id ][ 'unit_price' ],
                                    'total_price' => $products[ $product->id ][ 'total_price' ],
                                    'quantity' => $products[ $product->id ][ 'quantity' ] - $product->quantity,
                                    'orderProduct' => $product,
                                ];
                            } elseif ( $product->quantity > $products[ $product->id ][ 'quantity' ] ) {
                                return [
                                    'operation' => 'remove',
                                    'unit_price' => $products[ $product->id ][ 'unit_price' ],
                                    'total_price' => $products[ $product->id ][ 'total_price' ],
                                    'quantity' => $product->quantity - $products[ $product->id ][ 'quantity' ],
                                    'orderProduct' => $product,
                                ];
                            }
                        }
                    }

                    /**
                     * When no changes has been made
                     * on the order products.
                     */
                    return false;
                } )
                    ->filter( fn( $adjustment ) => $adjustment !== false )
                    ->each( function ( $adjustment ) use ( $order ) {
                        if ( $adjustment[ 'operation' ] === 'remove' ) {
                            $adjustment[ 'orderProduct' ]->quantity -= $adjustment[ 'quantity' ];

                            $this->productService->stockAdjustment(
                                ProductHistory::ACTION_ADJUSTMENT_RETURN, [
                                    'unit_id' => $adjustment[ 'orderProduct' ]->unit_id,
                                    'unit_price' => $adjustment[ 'orderProduct' ]->unit_price,
                                    'product_id' => $adjustment[ 'orderProduct' ]->product_id,
                                    'quantity' => $adjustment[ 'quantity' ],
                                    'orderProduct' => $adjustment[ 'orderProduct' ],
                                    'order_id' => $order->id,
                                ]
                            );
                        } else {
                            $adjustment[ 'orderProduct' ]->quantity += $adjustment[ 'quantity' ];

                            $this->productService->stockAdjustment(
                                ProductHistory::ACTION_ADJUSTMENT_SALE, [
                                    'unit_id' => $adjustment[ 'orderProduct' ]->unit_id,
                                    'unit_price' => $adjustment[ 'orderProduct' ]->unit_price,
                                    'product_id' => $adjustment[ 'orderProduct' ]->product_id,
                                    'orderProduct' => $adjustment[ 'orderProduct' ],
                                    'quantity' => $adjustment[ 'quantity' ],
                                    'order_id' => $order->id,
                                ]
                            );
                        }

                        /**
                         * for the product that was already tracked
                         * we'll just update the price and quantity
                         */
                        $adjustment[ 'orderProduct' ]->unit_price = $adjustment[ 'unit_price' ];
                        $adjustment[ 'orderProduct' ]->total_price = $adjustment[ 'total_price' ];
                        $adjustment[ 'orderProduct' ]->save();
                    } );
            }

            /**
             * Every product that is missing when the order is being
             * processed another time should be removed. If the order has
             * already affected the stock, we should make some adjustments.
             */
            $order->products->each( function ( $orderProduct ) use ( $ids, $order ) {
                /**
                 * if a product has the unit id changed
                 * the product he considered as new and the old is returned
                 * to the stock.
                 */
                $reference = $orderProduct->id . '-' . $orderProduct->unit_id;

                if ( ! in_array( $reference, $ids ) ) {
                    $orderProduct->delete();

                    /**
                     * If the order has changed the stock. The operation
                     * that update it should affect the stock as well.
                     */
                    if ( $order->payment_status !== Order::PAYMENT_HOLD ) {
                        $this->productService->stockAdjustment(
                            ProductHistory::ACTION_ADJUSTMENT_RETURN, [
                                'unit_id' => $orderProduct->unit_id,
                                'unit_price' => $orderProduct->unit_price,
                                'orderProduct' => $orderProduct,
                                'product_id' => $orderProduct->product_id,
                                'quantity' => $orderProduct->quantity,
                                'order_id' => $order->id,
                            ]
                        );
                    }
                }
            } );
        }
    }

    /**
     * get the current shipping
     * feels
     */
    private function __getShippingFee( array $fields ): float
    {
        return $this->currencyService->define( $fields['shipping'] ?? 0 )->toFloat();
    }

    /**
     * Check whether a discount is valid or
     * not
     *
     *
     * @throws NotAllowedException
     */
    public function __checkDiscountValidity( array $fields ): void
    {
        if ( ! empty( @$fields['discount_type'] ) ) {
            if ( $fields['discount_type'] === 'percentage' && ( floatval( $fields['discount_percentage'] ) < 0 ) || ( floatval( $fields['discount_percentage'] ) > 100 ) ) {
                throw new NotAllowedException( __( 'The percentage discount provided is not valid.' ) );
            } elseif ( $fields['discount_type'] === 'flat' ) {
                $productsTotal = $fields[ 'products' ]->map( function ( $product ) {
                    return $this->currencyService->define( $product['quantity'] )
                        ->multiplyBy( floatval( $product['unit_price'] ) )
                        ->toFloat();
                } )->sum();

                if ( $fields['discount'] > $productsTotal ) {
                    throw new NotAllowedException( __( 'A discount cannot exceed the sub total value of an order.' ) );
                }
            }
        }

        /**
         * We should also check product discount and make
         * sure the discount is only set as percentage and no longer as flat.
         */
        if ( isset( $fields[ 'products' ] ) ) {
            foreach ( $fields[ 'products' ] as $product ) {
                if ( isset( $product[ 'discount_type' ] ) && $product[ 'discount_type' ] === 'flat' ) {
                    throw new NotAllowedException( __( 'Product discount should be set as percentage.' ) );
                }
            }
        }
    }

    /**
     * Check defined address informations
     * and throw an error if a fields is not supported
     *
     * @return array $fields
     */
    private function __checkAddressesInformations( array $fields ): array
    {
        $allowedKeys = [
            'id',
            'first_name',
            'last_name',
            'phone',
            'address_1',
            'address_2',
            'country',
            'city',
            'pobox',
            'company',
            'email',
        ];

        /**
         * this will erase the unsupported
         * attribute before saving the customer addresses.
         */
        if ( ! empty( $fields[ 'addresses' ] ) ) {
            foreach ( ['shipping', 'billing'] as $type ) {
                if ( isset( $fields['addresses'][$type] ) ) {
                    $keys = array_keys( $fields['addresses'][$type] );
                    foreach ( $keys as $key ) {
                        if ( ! in_array( $key, $allowedKeys ) ) {
                            unset( $fields[ 'addresses' ][ $type ][ $key ] );
                        }
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Save address informations
     * for a specific order
     *
     * @return SupportCollection<OrderAddress>
     */
    private function __saveAddressInformations( Order $order, array $fields ): SupportCollection
    {
        $addresses = collect( ['shipping', 'billing'] )->map( function ( $type ) use ( $order, $fields ) {
            /**
             * if the id attribute is already provided
             * we should attempt to find the related addresses
             * and use that as a reference otherwise create a new instance.
             *
             * @todo add a verification to enforce address to be attached
             * to the processed order.
             */
            if ( isset( $fields[ 'addresses' ][ $type ][ 'id' ] ) ) {
                $orderShipping = OrderAddress::find( $fields[ 'addresses' ][ $type ][ 'id' ] );
            } else {
                $orderShipping = new OrderAddress;
            }

            $orderShipping->type = $type;

            if ( ! empty( $fields['addresses'][$type] ) ) {
                foreach ( $fields['addresses'][$type] as $key => $value ) {
                    $orderShipping->$key = $value;
                }
            }

            $orderShipping->author_id = $order->author_id ?? Auth::id();

            return $orderShipping;
        } );

        return $addresses;
    }

    private function __saveOrderPayments( Order $order, array $payments, $customer )
    {
        /**
         * Every payment in the submitted array becomes a row. Nothing is deleted and nothing is
         * de-duplicated here, and that is on purpose: two payments of the same amount can be a
         * genuine sale, a customer counting out two notes of the same denomination.
         *
         * The comment that used to sit here claimed this method deleted previous payments first. It
         * never did, and believing it would lead someone to assume a resubmitted order replaces its
         * payments rather than adding to them.
         *
         * Guarding against the accidental duplicate belongs where the accident happens — the till,
         * in POS::addPayment(), which refuses an identical payment repeated faster than hands can
         * work. By the time a payload arrives here the two cases are indistinguishable.
         */
        return collect( $payments )->map( function ( $payment ) use ( $order ) {
            return $this->__saveOrderSinglePayment( $payment, $order );
        } );
    }

    /**
     * Perform a single payment to a provided order
     * and ensure to display relevant events
     *
     * @param  array $payment
     * @return array
     *
     * @todo must be updated to records payment as __saveOrderSinglePayment no longer perform database operation
     */
    public function makeOrderSinglePayment( $payment, Order $order )
    {
        // Check if the order is already paid
        if ( $order->payment_status === Order::PAYMENT_PAID ) {
            throw new NotAllowedException( __( 'Unable to proceed as the order is already paid.' ) );
        }

        /**
         * We want to prevent making payment on register that are closed.
         * if the cash regsiter feature is enabled.
         */
        if ( ns()->option->get( 'ns_pos_registers_enabled', 'no' ) === 'yes' && isset( $payment[ 'register_id' ] ) ) {
            $register = Register::opened()->where( 'id', $payment[ 'register_id' ] )->count();

            if ( $register === 0 ) {
                throw new NotAllowedException( __( 'Unable to make a payment on a closed cash register.' ) );
            }
        }

        /**
         * We should check if the order allow instalments. This is only done if
         * we've enabled strict instalments.
         */
        if ( $order->instalments->count() > 0 && $order->support_instalments && ns()->option->get( 'ns_orders_strict_instalments', 'no' ) === true ) {
            $paymentToday = $order->instalments()
                ->where( 'paid', false )
                ->where( 'date', '>=', ns()->date->copy()->startOfDay()->toDateTimeString() )
                ->where( 'date', '<=', ns()->date->copy()->endOfDay()->toDateTimeString() )
                ->get();

            if ( $paymentToday->count() === 0 ) {
                throw new NotFoundException( __( 'No payment is expected at the moment. If the customer want to pay early, consider adjusting instalment payments date.' ) );
            }
        }

        $orderPayment = $this->__saveOrderSinglePayment( $payment, $order );
        $orderPayment->order_id = $order->id;
        $orderPayment->save();

        /**
         * let's refresh the order to check whether the
         * payment has made the order complete or not.
         */
        $order->register_id = $payment[ 'register_id' ] ?? 0;
        $order->save();
        $order->refresh();

        /**
         * @todo we should trigger it after an event
         */
        $this->refreshOrder( $order );

        return [
            'status' => 'success',
            'message' => __( 'The payment has been saved.' ),
            'data' => compact( 'payment', 'orderPayment' ),
        ];
    }

    /**
     * Save an order payment (or update). deplete customer
     * account if "account payment" is used.
     *
     * @param array $payment
     */
    private function __saveOrderSinglePayment( $payment, Order $order ): OrderPayment
    {
        $orderPayment = isset( $payment[ 'id' ] ) ? OrderPayment::find( $payment[ 'id' ] ) : false;

        if ( ! $orderPayment instanceof OrderPayment ) {
            $orderPayment = new OrderPayment;
        }

        $orderPayment->identifier = $payment['identifier'];
        $orderPayment->value = $this->currencyService->define( $payment['value'] )->toFloat();
        $orderPayment->author_id = $order->author_id;

        /**
         * What the cashier typed to identify the transfer on the other side — a PromptPay
         * slip reference, a welfare card approval code. Only channels that settle later
         * need one, so it stays null for cash rather than being required.
         */
        $reference = trim( (string) ( $payment[ 'reference' ] ?? '' ) );
        $orderPayment->reference = $reference === '' ? null : $reference;

        return $orderPayment;
    }

    /**
     * Checks the order payements and compare
     * it to the product values and determine
     * if the order can proceed
     */
    private function __checkOrderPayments( array $fields, ?Order $order, Customer $customer )
    {
        /**
         * we shouldn't process order if while
         * editing an order it seems that order is already paid.
         */
        if ( $order !== null && $order->payment_status === Order::PAYMENT_PAID ) {
            throw new NotAllowedException( __( 'Unable to edit an order that is completely paid.' ) );
        }

        /**
         * if the order was partially paid and we would like to change
         * some product, we need to make sure that the previously submitted
         * payment hasn't been deleted.
         */
        if ( $order instanceof Order ) {
            $paymentIds = collect( $fields[ 'payments' ] ?? [] )
                ->map( fn( $payment ) => $payment[ 'id' ] ?? false )
                ->filter( fn( $payment ) => $payment !== false )
                ->toArray();

            $order->payments->each( function ( $payment ) use ( $paymentIds ) {
                if ( ! in_array( $payment->id, $paymentIds ) ) {
                    throw new NotAllowedException( __( 'Unable to proceed as one of the previous submitted payment is missing from the order.' ) );
                }
            } );

            /**
             * if the order was no more "hold"
             * we shouldn't allow the order to switch to hold.
             */
            if ( $order->payment_status !== Order::PAYMENT_HOLD && isset( $fields[ 'payment_status' ] ) && $fields[ 'payment_status' ] === Order::PAYMENT_HOLD ) {
                throw new NotAllowedException( __( 'The order payment status cannot switch to hold as a payment has already been made on that order.' ) );
            }
        }

        $totalPayments = 0;

        $subtotal = ns()->currency->define( collect( $fields[ 'products' ] )->map( function ( $product ) {
            return floatval( $product['total_price'] );
        } )->sum() )->toFloat();

        $total = $this->currencyService->define(
            $subtotal + $this->__getShippingFee( $fields )
        )
            ->subtractBy( ( $fields[ 'discount' ] ?? $this->computeDiscountValues( $fields[ 'discount_percentage' ] ?? 0, $subtotal ) ) )
            ->subtractBy( $this->__computeOrderCoupons( $fields, $subtotal ) )
            ->toFloat();

        // Use the same discounted tax base as the saved coupon order.
        if ( ! empty( $fields['coupons'] ) && ( $fields['tax_type'] ?? '' ) === 'exclusive'
            && in_array( ns()->option->get( 'ns_pos_vat' ), ['flat_vat', 'variable_vat'] ) && ! empty( $fields['taxes'] ) ) {
            $tax = $this->taxService->getTaxesComputed(
                tax_type: 'exclusive', rates: array_column( $fields['taxes'], 'rate' ),
                value: max( 0, $subtotal - ( $fields['discount'] ?? 0 ) - $fields['total_coupons'] )
            );
            $total = round( $total + $tax['tax'], 2 );
        }

        $allowedPaymentsGateways = PaymentType::active()
            ->get()
            ->map( fn( $paymentType ) => $paymentType->identifier )
            ->toArray();

        if ( ! empty( $fields[ 'payments' ] ) ) {
            foreach ( $fields[ 'payments' ] as $payment ) {
                if ( in_array( $payment['identifier'], $allowedPaymentsGateways ) ) {
                    /**
                     * check if the customer account balance is enough for the account-payment
                     * when that payment is provided
                     */
                    if ( $payment[ 'identifier' ] === OrderPayment::PAYMENT_ACCOUNT && $customer->account_amount < floatval( $payment[ 'value' ] ) ) {
                        throw new NotAllowedException( __( 'The customer account funds aren\'t enough to process the payment.' ) );
                    }

                    $totalPayments = $this->currencyService->define( $totalPayments )
                        ->additionateBy( $payment['value'] )
                        ->get();
                } else {
                    throw new NotAllowedException( __( 'Unable to proceed. One of the submitted payment type is not supported.' ) );
                }
            }
        }

        /**
         * determine if according to the payment
         * we're free to proceed with that
         */
        if ( $totalPayments < $total ) {
            if (
                $this->optionsService->get( 'ns_orders_allow_partial', true ) === false &&
                $totalPayments > 0
            ) {
                throw new NotAllowedException( __( 'Unable to proceed. Partially paid orders aren\'t allowed. This option could be changed on the settings.' ) );
            } elseif (
                $this->optionsService->get( 'ns_orders_allow_incomplete', true ) === false &&
                $totalPayments === 0
            ) {
                throw new NotAllowedException( __( 'Unable to proceed. Unpaid orders aren\'t allowed. This option could be changed on the settings.' ) );
            }

            /**
             * We don't want the customer to be able to exceed a credit limit
             * granted to his account.
             *
             * What goes on credit is the part of the order that was not paid for, not the
             * order total. The check used to apply only when nothing at all was tendered,
             * so a customer at his ceiling could take an order of any size by handing over
             * a single coin: the payment was partial, the branch was skipped and the rest
             * was lent without a limit.
             */
            $onCredit = $this->currencyService->define( $total )
                ->subtractBy( $totalPayments )
                ->toFloat();

            /**
             * An order being edited is already counted in what the customer owes, so its
             * outstanding balance is taken back out before the new one is added. Otherwise
             * merely reopening an unpaid order and saving it would look like a second
             * order and trip the limit.
             */
            $alreadyOnCredit = $order instanceof Order
                ? $this->currencyService->define( $order->total )->subtractBy( $order->tendered )->toFloat()
                : 0;

            $owedAfterOrder = $this->currencyService->define( $customer->owed_amount )
                ->subtractBy( $alreadyOnCredit )
                ->additionateBy( $onCredit )
                ->toFloat();

            if (
                (float) $customer->credit_limit_amount > 0
                /**
                 * payment_status is optional on the payload, so reading it directly
                 * turned a legitimate "over the credit limit" refusal into a 500. Every
                 * other branch nearby already guards for its absence.
                 */
                && ( $fields[ 'payment_status' ] ?? null ) !== Order::PAYMENT_HOLD
                && (float) $customer->credit_limit_amount < $owedAfterOrder ) {
                throw new NotAllowedException( sprintf(
                    __( 'By proceeding this order, the customer will exceed the maximum credit allowed for his account: %s.' ),
                    (string) ns()->currency->fresh( $customer->credit_limit_amount )
                ) );
            }
        }

        if ( $totalPayments >= $total && count( $fields[ 'payments' ] ?? [] ) > 0 ) {
            $paymentStatus = Order::PAYMENT_PAID;
        } elseif ( $totalPayments < $total && $totalPayments > 0 ) {
            $paymentStatus = Order::PAYMENT_PARTIALLY;
        } elseif ( $totalPayments === 0 && ( ! isset( $fields[ 'payment_status' ] ) || ( $fields[ 'payment_status' ] !== Order::PAYMENT_HOLD ) ) ) {
            $paymentStatus = Order::PAYMENT_UNPAID;
        } elseif ( $totalPayments === 0 && ( isset( $fields[ 'payment_status' ] ) && ( $fields[ 'payment_status' ] === Order::PAYMENT_HOLD ) ) ) {
            $paymentStatus = Order::PAYMENT_HOLD;
        } else {
            $paymentStatus = Order::PAYMENT_HOLD;
        }

        /**
         * Ultimately, we'll check when a payment is provided
         * the logged user must have the rights to perform a payment
         */
        if ( $totalPayments > 0 ) {
            ns()->restrict( 'nexopos.make-payment.orders', __( 'You\'re not allowed to make payments.' ) );
        }

        return [
            'payments' => $fields['payments'] ?? [],
            'total' => $total,
            'totalPayments' => $totalPayments,
            'paymentStatus' => $paymentStatus,
        ];
    }

    /**
     * Compute an order total based
     * on provided data
     */
    protected function __computeOrderTotal( Order $order, SupportCollection $products ): Order
    {
        /**
         * increase the total with the
         * shipping fees and subtract the discounts
         */
        $order->total = Currency::fresh( $order->subtotal )
            ->additionateBy( $order->shipping )
            ->additionateBy(
                ( $order->tax_type === 'exclusive' ? $order->tax_value : 0 )
            )
            ->subtractBy(
                Currency::fresh( $order->total_coupons )
                    ->additionateBy( $order->discount )
                    ->toFloat()
            )
            ->toFloat();

        $order->total_with_tax = $order->total;
        $order->total_cogs = collect( $products )->sum( 'cogs' );

        /**
         * compute change
         */
        $order->change = Currency::fresh( $order->tendered )
            ->subtractBy( $order->total )
            ->toFloat();

        /**
         * Compute total with tax.
         *
         * @todo not accurate
         */
        $order->total_without_tax = Currency::fresh( $order->subtotal )
            ->subtractBy( $order->discount )
            ->subtractBy( $order->total_coupons )
            ->subtractBy( $order->tax_type === 'exclusive' ? 0 : $order->tax_value )
            ->toFloat();

        return $order;
    }

    /**
     * @param Order order instance
     * @param SupportCollection<OrderProduct> array of products
     * @return array [$subTotal, $orderProducts, $order]
     */
    private function __saveOrderProducts( Order $order, SupportCollection $products ): array
    {
        $subTotal = 0;
        $taxes = 0;
        $gross = 0;

        $orderProducts = $products->map( function ( $product ) use ( &$subTotal, &$taxes, &$order, &$gross ) {
            /**
             * if the product id is provided
             * then we can use that id as a reference.
             */
            if ( isset( $product[ 'id' ] ) ) {
                /**
                 * @var OrderProduct $orderProduct
                 */
                $orderProduct = OrderProduct::find( $product[ 'id' ] );
            } else {
                $orderProduct = new OrderProduct;
            }

            $orderProduct->load( 'product' );

            /**
             * We'll retreive the unit used for
             * the order product.
             *
             * @var Unit $unit
             */
            $unit = Unit::find( $product[ 'unit_id' ] );

            $orderProduct->unit_quantity_id = $product[ 'unit_quantity_id' ];
            $orderProduct->unit_name = $product[ 'unit_name' ] ?? $unit->name;
            $orderProduct->unit_id = $product[ 'unit_id' ];
            $orderProduct->mode = $product[ 'mode' ] ?? 'normal';
            $orderProduct->product_type = $product[ 'product_type' ] ?? 'product';
            $orderProduct->rate = $product[ 'rate' ] ?? 0;
            $orderProduct->product_id = $product[ 'product' ]->id ?? 0;
            $orderProduct->product_category_id = $product[ 'product' ]->category_id ?? 0;

            /**
             * Which purchase batch this line was taken from, when the till knows.
             *
             * The column has existed on this table all along and nothing ever wrote to it, so every
             * sold line on the shop's database carries null. ProductsController already resolves the
             * batch when a barcode identifies one — it sets `procurement_product_id` on the product it
             * hands to the cart and its own comment says the reason is to "track how products are
             * sold" — and then the value was dropped here and never reached the row.
             *
             * This is what makes a supplier answerable per sale: the batch names the procurement, the
             * procurement names the provider. Without it there is no factual link between a sale and
             * whom the goods were bought from, only a guess from the product's purchase history.
             *
             * Null stays null. A product sold from pooled stock has no single batch behind it, and
             * inventing one here would put a supplier's name against a sale on no evidence.
             */
            $orderProduct->procurement_product_id = $product[ 'procurement_product_id' ]
                ?? $product[ 'product' ]->procurement_product_id
                ?? null;
            $orderProduct->name = $product[ 'product' ]->name ?? $product[ 'name' ] ?? __( 'Unnamed Product' );
            $orderProduct->quantity = $product[ 'quantity' ];
            $orderProduct->price_gross = $product[ 'price_gross' ] ?? 0;
            $orderProduct->price_net = $product[ 'price_net' ] ?? 0;
            $orderProduct->total_price_gross = $product[ 'total_price_gross' ] ?? 0;
            $orderProduct->total_price_net = $product[ 'total_price_net' ] ?? 0;

            /**
             * We might need to have another consideration
             * on how we do compute the taxes
             */
            if ( $product[ 'product' ] instanceof Product && $product[ 'product' ]->tax_type !== 'disabled' && ! empty( $product[ 'product' ]->tax_group_id ) ) {
                $orderProduct->tax_group_id = $product[ 'product' ]->tax_group_id;
                $orderProduct->tax_type = $product[ 'product' ]->tax_type ?? 'inclusive';
                $orderProduct->tax_value = $product[ 'tax_value' ];
            } elseif ( isset( $product[ 'tax_type' ] ) && isset( $product[ 'tax_group_id' ] ) ) {
                $orderProduct->tax_group_id = $product[ 'tax_group_id' ];
                $orderProduct->tax_type = $product[ 'tax_type' ];
                $orderProduct->tax_value = $product[ 'tax_value' ];
            } else {
                $orderProduct->tax_group_id = 0;
                $orderProduct->tax_type = 'disabled';
                $orderProduct->tax_value = 0;
            }

            /**
             * The Thai VAT classification and the rate in force are frozen onto the
             * line, not looked up when the document is printed.
             *
             * A tax invoice already reported on a PP30 return has to reproduce
             * identically forever. Reclassifying a product, or the annual Royal
             * Decree changing the reduced rate, must not rewrite what a customer was
             * charged last month. The date used is the order creation date so a sale
             * recorded late still belongs to its own period.
             */
            $vatStatus = $this->thaiVatService->normalizeStatus(
                $product[ 'product' ] instanceof Product ? $product[ 'product' ]->vat_status : ( $product[ 'vat_status' ] ?? null )
            );

            $orderProduct->vat_status = $vatStatus;
            $orderProduct->vat_rate = $this->thaiVatService->rateFor(
                status: $vatStatus,
                date: $order->created_at ?? now()
            );

            /**
             * @todo we need to solve the issue with the
             * gross price and determine where we should pull it.
             */
            $orderProduct->unit_price = $this->currencyService->define( $product[ 'unit_price' ] )->toFloat();
            $orderProduct->discount_type = $product[ 'discount_type' ] ?? 'none';
            $orderProduct->discount = $product[ 'discount' ] ?? 0;
            $orderProduct->discount_percentage = $product[ 'discount_percentage' ] ?? 0;
            $orderProduct->total_purchase_price = 0;

            if ( $product[ 'product' ] instanceof Product ) {
                $orderProduct->total_purchase_price = $this->currencyService->define(
                    $product[ 'total_purchase_price' ] ?? Currency::fresh( $this->productService->getCogs(
                        product: $product[ 'product' ],
                        unit: $unit
                    ) )
                        ->multipliedBy( $product[ 'quantity' ] )
                        ->toFloat()
                )
                    ->toFloat();
            }

            /**
             * store the product that as it can be used while
             * listening to create and update events.
             */
            $orderProduct->setData( $product );

            $this->computeOrderProduct( $orderProduct, $product );

            if ( ns()->option->get( 'ns_pos_vat' ) === 'disabled' ) {
                $subTotal = $order->subtotal;
            } else {
                // Bill-level VAT is applied after the line subtotal. Adding each
                // line's gross price here would tax the already-taxed subtotal.
                if ( ns()->option->get( 'ns_pos_vat' ) === 'products_vat'
                    && ns()->option->get( 'ns_pos_prefered_price' ) === 'net_prices' ) {
                    $subTotal = $this->currencyService->define( $subTotal )
                        ->additionateBy( $orderProduct->total_price_gross )
                        ->get();
                } else {
                    $subTotal = $this->currencyService->define( $subTotal )
                        ->additionateBy( $orderProduct->total_price )
                        ->get();
                }
            }

            return $orderProduct;
        } );

        $order->subtotal = $subTotal;

        return compact( 'subTotal', 'order', 'orderProducts' );
    }

    public function saveOrderProductHistory( Order $order )
    {
        if (
            in_array( $order->payment_status, [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY, Order::PAYMENT_UNPAID ] )
        ) {
            $order->products()->get()->each( function ( OrderProduct $orderProduct ) use ( $order ) {
                $productCount = Product::where( 'id', $orderProduct->product_id )->count();

                if ( $productCount > 0 ) {
                    /**
                     * storing the product
                     * history as a sale
                     */
                    $history = [
                        'order_id' => $order->id,
                        'unit_id' => $orderProduct->unit_id,
                        'product_id' => $orderProduct->product_id,
                        'quantity' => $orderProduct->quantity,
                        'unit_price' => $orderProduct->unit_price,
                        'orderProduct' => $orderProduct,
                        'total_price' => $orderProduct->total_price,
                    ];

                    /**
                     * __deleteUntrackedProducts will delete all products that
                     * already exists and which are edited. We'll here only records
                     * products that doesn't exists yet.
                     */
                    $stockHistoryExists = ProductHistory::where( 'order_product_id', $orderProduct->id )
                        ->where( 'order_id', $order->id )
                        ->where( 'operation_type', ProductHistory::ACTION_SOLD )
                        ->count() === 1;

                    if ( ! $stockHistoryExists ) {
                        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, $history );
                    }
                }
            } );
        }
    }

    /**
     * Prebuild order products to be used for order creation or update.
     *
     * @return SupportCollection<array>
     */
    private function __buildOrderProducts( array $order ): SupportCollection
    {
        return collect( $order[ 'products' ] )->map( function ( $orderProduct ) use ( $order ) {
            /**
             * by default, we'll assume a quick
             * product is being created.
             */
            $product = null;
            $productUnitQuantity = null;

            if ( ! empty( $orderProduct[ 'sku' ] ?? null ) || ! empty( $orderProduct[ 'product_id' ] ?? null ) ) {
                $product = Cache::remember( 'store-' . ( $orderProduct['product_id'] ?? $orderProduct['sku'] ), 60, function () use ( $orderProduct ) {
                    if ( ! empty( $orderProduct['product_id'] ?? null ) ) {
                        return $this->productService->get( $orderProduct['product_id'] );
                    } elseif ( ! empty( $orderProduct['sku'] ?? null ) ) {
                        return $this->productService->getProductUsingSKUOrFail( $orderProduct['sku'] );
                    }
                } );

                $productUnitQuantity = ProductUnitQuantity::with( 'unit' )
                    ->lockForUpdate()
                    ->find( (int) ( $orderProduct[ 'unit_quantity_id' ] ?? 0 ) );

                // A cart can outlive a catalogue edit. Never guess a replacement pack size.
                if ( ! $productUnitQuantity
                    || ! $productUnitQuantity->unit
                    || (int) $productUnitQuantity->product_id !== (int) $product->id
                    || ( ! empty( $orderProduct['unit_id'] ) && (int) $orderProduct['unit_id'] !== (int) $productUnitQuantity->unit_id ) ) {
                    throw new NotAllowedException( sprintf(
                        __( 'หน่วยขายของสินค้า “%s” ในตะกร้าไม่ตรงกับข้อมูลปัจจุบัน กรุณาปิดหน้ารับเงิน นำสินค้ารายการนี้ออกจากตะกร้า แล้วค้นหาหรือสแกนเพิ่มใหม่ ตรวจสอบหน่วยและราคาก่อนชำระเงินอีกครั้ง' ),
                        $product->name
                    ) );
                }
            }

            $orderProduct = $this->__buildOrderProduct(
                $orderProduct,
                $productUnitQuantity,
                $product,
                $order
            );

            return $orderProduct;
        } );
    }

    /**
     * @return SupportCollection $items
     */
    private function __checkProductStock( SupportCollection $items, ?Order $order = null )
    {
        $session_identifier = Str::random( '10' );

        /**
         * here comes a loop.
         * We'll been fetching from the database
         * we need somehow to integrate a cache
         * we'll also populate the unit for the item
         * so that it can be reused
         */
        $items = $items->map( function ( array $orderProduct ) use ( $session_identifier ) {
            if ( $orderProduct[ 'product' ] instanceof Product ) {
                /**
                 * Checking inventory for the grouped products,
                 * by loading all the subitems and multiplying the quantity
                 * with the order quantity.
                 */
                if ( $orderProduct[ 'product' ]->type === Product::TYPE_GROUPED ) {
                    $orderProduct[ 'product' ]->load( 'sub_items.product' );
                    $orderProduct[ 'product' ]
                        ->sub_items
                        ->each( function ( ProductSubItem $subitem ) use ( $session_identifier, $orderProduct ) {
                            /**
                             * Stock management should be enabled
                             * for the sub item.
                             */
                            if ( $subitem->product->stock_management === Product::STOCK_MANAGEMENT_ENABLED ) {
                                /**
                                 * We need a fake orderProduct
                                 * that will have necessary attributes for verification.
                                 */
                                $parentUnit = $this->unitService->get( $orderProduct[ 'unit_id' ] );

                                /**
                                 * computing the exact quantity that will be pulled
                                 * from the actual product inventory.
                                 */
                                $quantity = $this->productService->computeSubItemQuantity(
                                    subItemQuantity: (float) $subitem->quantity,
                                    parentUnit: $parentUnit,
                                    parentQuantity: $orderProduct[ 'quantity' ]
                                );

                                $newFakeOrderProduct = new OrderProduct;
                                $newFakeOrderProduct->quantity = $quantity;
                                $newFakeOrderProduct->unit_quantity_id = $subitem->unit_quantity_id;

                                $this->checkQuantityAvailability(
                                    product: $subitem->product,
                                    productUnitQuantity: $subitem->unit_quantity,
                                    orderProduct: $newFakeOrderProduct->toArray(),
                                    session_identifier: $session_identifier
                                );
                            }
                        } );
                } else {
                    $this->checkQuantityAvailability(
                        product: $orderProduct[ 'product' ],
                        productUnitQuantity: $orderProduct[ 'unitQuantity' ],
                        orderProduct: $orderProduct,
                        session_identifier: $session_identifier
                    );
                }
            }

            return $orderProduct;
        } );

        OrderAfterProductStockCheckedEvent::dispatch( $items, $session_identifier );

        return $items;
    }

    /**
     * Prebuild a product that will be processed
     *
     * @param array Order Product
     * @return array Order Product (updated)
     */
    public function __buildOrderProduct( array $orderProduct, ?ProductUnitQuantity $productUnitQuantity = null, ?Product $product = null, array $order = [] )
    {
        /**
         * This will calculate the product default field
         * when they aren't provided.
         */
        $orderProduct = $this->computeProduct(
            rawProduct: $orderProduct,
            product: $product,
            productUnitQuantity: $productUnitQuantity,
            rawOrder: $order
        );

        $orderProduct[ 'unit_id' ] = $productUnitQuantity->unit->id ?? $orderProduct[ 'unit_id' ] ?? 0;
        $orderProduct[ 'unit_quantity_id' ] = $productUnitQuantity->id ?? 0;
        $orderProduct[ 'product' ] = $product;
        $orderProduct[ 'mode' ] = $orderProduct[ 'mode' ] ?? 'normal';
        $orderProduct[ 'product_type' ] = $orderProduct[ 'product_type' ] ?? 'product';
        $orderProduct[ 'rate' ] = $orderProduct[ 'rate' ] ?? 0;
        $orderProduct[ 'unitQuantity' ] = $productUnitQuantity;
        $orderProduct[ 'cogs' ] = $productUnitQuantity->cogs ?? 0;

        return $orderProduct;
    }

    /**
     * Works out which branch a sale belongs to.
     *
     * This used to read the session and fall back to the number one. The session key is only ever
     * filled when multi store mode is switched on, and it is off here, so every sale made at every
     * branch was stamped as the main branch. Sales made at Tha Chang appeared in the main branch's
     * takings, which is the figure the owner reads to decide anything.
     *
     * The till is the most reliable answer available: a register belongs to one branch and does not
     * move. The cashier's own branch is the next best, and both beat guessing. An explicitly
     * supplied branch still wins, because the API and the tests set it deliberately.
     *
     * @param array<string, mixed> $fields
     */
    private function resolveOrderBranch( array $fields, Order $order ): int
    {
        if ( ! empty( $fields[ 'branch_id' ] ) ) {
            return (int) $fields[ 'branch_id' ];
        }

        /**
         * The till outranks the branch picker.
         *
         * The session used to win, which made the picker able to move a sale to another branch
         * while it was being rung up on a till bolted to this one. A register belongs to one branch
         * and does not travel; the picker is a screen control anybody signed in could touch. With
         * two branches that mislaid takings, and every branch added multiplies the number of wrong
         * places a sale can land.
         *
         * The session still answers for a sale with no till behind it — a back office order, an
         * API call, an import.
         */
        if ( ! empty( $order->register_id ) ) {
            $registerBranch = Register::where( 'id', $order->register_id )->value( 'branch_id' );

            if ( ! empty( $registerBranch ) ) {
                return (int) $registerBranch;
            }
        }

        $selected = session( 'selected_branch_id' );

        if ( ! empty( $selected ) ) {
            return (int) $selected;
        }

        $userBranch = Auth::user()?->branch_id;

        if ( ! empty( $userBranch ) ) {
            return (int) $userBranch;
        }

        /**
         * A sale with no till and no signed in user is only reached by seeders and imports. The
         * main branch is the only sensible home for those, found by its flag. Where nothing is
         * flagged, the oldest branch stands in — still read from the table rather than assumed to
         * be numbered one, which is what broke when the main branch was renumbered.
         */
        return (int) (
            DB::table( 'nexopos_branches' )->where( 'is_main', 1 )->value( 'id' )
            ?? DB::table( 'nexopos_branches' )->orderBy( 'id' )->value( 'id' )
            ?? 1
        );
    }

    /**
     * Total already reserved by this session against the unit that carries the stock.
     *
     * Reservations are recorded in whichever unit the cashier scanned, so a cart holding a
     * carton and a single of the same goods records two rows measured on two different
     * scales. Both draw down the one piece count, so each row is converted before being
     * added up. Rows belonging to goods that keep their own separate stock are skipped,
     * which is what makes this identical to the previous per-row sum for the single unit
     * products that make up almost the whole catalogue.
     */
    private function sumReservedInStockBearingUnit( Product $product, string $session_identifier, int $stockBearingUnitId, ?int $branchId = null ): float
    {
        $reservations = OrderStorage::withIdentifier( $session_identifier )
            ->withProduct( $product->id )
            ->get( [ 'unit_id', 'quantity' ] );

        $reserved = 0.0;

        foreach ( $reservations as $reservation ) {
            $reservationBearing = $this->productService->resolveStockBearingUnit(
                $product->id,
                (int) $reservation->unit_id,
                $branchId
            );

            if ( (int) $reservationBearing[ 'unit_id' ] !== $stockBearingUnitId ) {
                continue;
            }

            $reserved += (float) $reservation->quantity * $reservationBearing[ 'factor' ];
        }

        return $reserved;
    }

    public function checkQuantityAvailability( Product $product, ?ProductUnitQuantity $productUnitQuantity, array $orderProduct, string $session_identifier ): void
    {
        if ( $product->stock_management === Product::STOCK_MANAGEMENT_ENABLED ) {
            /**
             * What we're doing here
             * 1 - Get the unit assigned to the product being sold
             * 2 - check if the units assigned is what has been stored on the product
             * 3 - If a group is assigned to a product, the we check if that unit belongs to the unit group
             */
            try {
                /**
                 * A product may keep its stock on a different unit from the one being sold:
                 * a shop that receives cartons and sells singles holds one piece count and
                 * treats the carton as a label with its own barcode and price. Everything
                 * below is therefore expressed in whichever unit carries the stock, so that
                 * scanning a carton is measured against the pieces that actually exist
                 * instead of being refused for lack of carton stock while the shelf is full.
                 *
                 * When the sold unit is the one carrying the stock, which is the case for
                 * every single unit product, the factor is 1 and this behaves as before.
                 */
                /**
                 * The branch is the one the scanned row belongs to. Without it the carton to piece
                 * conversion could be measured against a piece row held by a different branch, so
                 * a till would be cleared to sell goods sitting on another shop's shelf.
                 */
                $branchId = $productUnitQuantity->branch_id !== null
                    ? (int) $productUnitQuantity->branch_id
                    : null;

                $stockBearing = $this->productService->resolveStockBearingUnit(
                    $product->id,
                    $productUnitQuantity->unit_id,
                    $branchId
                );

                $storageQuantity = $this->sumReservedInStockBearingUnit(
                    product: $product,
                    session_identifier: $session_identifier,
                    stockBearingUnitId: $stockBearing[ 'unit_id' ],
                    branchId: $branchId
                );

                $orderProductQuantity = $orderProduct[ 'quantity' ];

                /**
                 * If the orderProduct has an id, that means we're editing an order. In order to extract what is the exact quantity
                 * we want to deduct from the stock, we need to know wether the quantity has changed (greater or not). We then need to pull
                 * the original reference of the orderProduct to compute the new quantity that will be deducted from inventory.
                 */
                $quantityToIgnore = 0;

                if ( isset( $orderProduct[ 'id' ] ) ) {
                    $stockWasDeducted = ProductHistory::where( 'order_product_id', $orderProduct[ 'id' ] )
                        ->where( 'operation_type', ProductHistory::ACTION_SOLD )
                        ->count() === 1;

                    /**
                     * Only when the stock was deducted, we'll ignore the quantity
                     * that is currently in use by the order product.
                     */
                    if ( $stockWasDeducted ) {
                        $orderProductRerefence = OrderProduct::find( $orderProduct[ 'id' ] );
                        $quantityToIgnore = $orderProductRerefence->quantity;
                    }
                }

                $availableUnitQuantity = $productUnitQuantity;

                if ( $stockBearing[ 'unit_id' ] !== (int) $productUnitQuantity->unit_id ) {
                    $baseQuery = ProductUnitQuantity::where( 'product_id', $product->id )
                        ->where( 'unit_id', $stockBearing[ 'unit_id' ] );

                    /**
                     * Stay on the branch that is selling. Dropping the branch here is what let the
                     * check read one shop's piece count while the deduction hit another's.
                     */
                    if ( $branchId !== null ) {
                        $baseQuery->where( 'branch_id', $branchId );
                    }

                    $availableUnitQuantity = $baseQuery->first() ?? $productUnitQuantity;
                }

                /**
                 * The request and the allowance released by an edit are both stated in the
                 * unit being sold, so both are scaled the same way the reservations already
                 * were. Leaving either unscaled would compare a carton against a piece.
                 */
                $requestedQuantity = abs( $orderProductQuantity ) * $stockBearing[ 'factor' ];
                $quantityToIgnore = $quantityToIgnore * $stockBearing[ 'factor' ];

                if ( ( $availableUnitQuantity->quantity - (float) $availableUnitQuantity->reserved_quantity + $quantityToIgnore ) - $storageQuantity < $requestedQuantity ) {
                    throw new NotAllowedException(
                        sprintf(
                            __( 'Unable to proceed, there is not enough stock for %s using the unit %s. Requested : %s, available %s' ),
                            $product->name,
                            $productUnitQuantity->unit->name,
                            abs( $orderProductQuantity ),
                            $stockBearing[ 'factor' ] > 0
                                ? ( $availableUnitQuantity->quantity - (float) $availableUnitQuantity->reserved_quantity - $storageQuantity ) / $stockBearing[ 'factor' ]
                                : $availableUnitQuantity->quantity - (float) $availableUnitQuantity->reserved_quantity - $storageQuantity
                        )
                    );
                }

                /**
                 * We keep reference on the database
                 * that's more easier.
                 */
                $storage = new OrderStorage;
                $storage->product_id = $product->id;
                $storage->unit_id = $productUnitQuantity->unit->id;
                $storage->unit_quantity_id = $orderProduct[ 'unit_quantity_id' ];
                $storage->quantity = $orderProduct[ 'quantity' ];
                $storage->session_identifier = $session_identifier;
                $storage->save();
            } catch ( NotFoundException $exception ) {
                throw new \Exception(
                    sprintf(
                        __( 'Unable to proceed, the product "%s" has a unit which cannot be retrieved. It might have been deleted.' ),
                        $product->name
                    )
                );
            }
        }
    }

    public function computeProduct( array $rawProduct, ?Product $product, ?ProductUnitQuantity $productUnitQuantity, array $rawOrder )
    {
        $sale_price = ( $rawProduct[ 'unit_price' ] ?? $productUnitQuantity->sale_price );

        /**
         * if the discount value wasn't provided, it would have
         * been calculated based on the "discount_percentage" & "discount_type"
         * informations.
         */
        if (
            isset( $rawProduct[ 'discount_percentage' ] ) &&
            isset( $rawProduct[ 'discount_type' ] ) &&
            $rawProduct[ 'discount_type' ] === 'percentage' ) {

            $rawProduct[ 'discount' ] = ( $rawProduct[ 'discount' ] ??
                ns()->currency->define(
                    ns()->currency->define(
                        ns()->currency->define( $sale_price )->multiplyBy( $rawProduct[ 'discount_percentage' ] )->toFloat()
                    )->dividedBy( 100 )->toFloat()
                )->multiplyBy( $rawProduct[ 'quantity' ] )->toFloat() );
        } else {
            $rawProduct[ 'discount' ] = $rawProduct[ 'discount' ] ?? 0;
        }

        /**
         * if the item is assigned to a tax group
         * it should compute the tax otherwise
         * the value is "0".
         */
        if ( empty( $rawProduct[ 'tax_value' ] ) ) {
            $rawProduct[ 'tax_value' ] = $this->currencyService->define(
                $this->taxService->getComputedTaxGroupValue(
                    tax_type: $rawProduct[ 'tax_type' ] ?? $product->tax_type ?? null,
                    tax_group_id: $rawProduct[ 'tax_group_id' ] ?? $product->tax_group_id ?? null,
                    price: $sale_price
                )
            )
                ->multiplyBy( floatval( $rawProduct[ 'quantity' ] ) )
                ->toFloat();
        }

        /**
         * If the total_price is not defined
         * let's compute that
         */
        if ( empty( $rawProduct[ 'total_price' ] ) ) {
            $rawProduct[ 'total_price' ] = (
                $sale_price * floatval( $rawProduct[ 'quantity' ] )
            ) - $rawProduct[ 'discount' ];
        }

        if ( isset( $rawOrder[ 'tax_group_id' ] ) ) {
            $rate = FacadesCache::remember( 'tax-rate-' . $rawOrder[ 'tax_group_id' ], now()->addMinute(), function () use ( $rawOrder ) {
                $tax = TaxGroup::with( 'taxes' )->find( $rawOrder[ 'tax_group_id' ] );

                return $tax->taxes->map( fn( Tax $tax ) => $tax->rate )->toArray();
            } );

            $response = $this->taxService->getTaxesComputed( $rawOrder[ 'tax_type' ], $rate, $rawProduct[ 'unit_price' ] );

            if ( empty( $response[ 'price_gross' ] ) ) {
                $rawProduct[ 'price_gross' ] = $response[ 'with-tax' ];
                $rawProduct[ 'total_price_gross' ] = ns()->currency->define( $rawProduct[ 'price_gross' ] )->multipliedBy( $rawProduct[ 'quantity' ] )->toFloat();
            }

            if ( empty( $response[ 'price_net' ] ) ) {
                $rawProduct[ 'price_net' ] = $response[ 'without-tax' ];
                $rawProduct[ 'total_price_net' ] = ns()->currency->define( $rawProduct[ 'price_net' ] )->multipliedBy( $rawProduct[ 'quantity' ] )->toFloat();
            }
        } else {
            $rawProduct[ 'price_net' ] = $sale_price;
            $rawProduct[ 'total_price_net' ] = ns()->currency->define( $rawProduct[ 'price_net' ] )->multipliedBy( $rawProduct[ 'quantity' ] )->toFloat();
            $rawProduct[ 'price_gross' ] = $sale_price;
            $rawProduct[ 'total_price_gross' ] = ns()->currency->define( $rawProduct[ 'price_gross' ] )->multipliedBy( $rawProduct[ 'quantity' ] )->toFloat();
        }

        return $rawProduct;
    }

    /**
     * @todo we need to be able to
     * change the code format
     */
    public function generateOrderCode( Order $order ): string
    {
        $now = Carbon::parse( $order->created_at );
        $today = $now->toDateString();
        $count = DB::table( Hook::filter( 'ns-model-table', 'nexopos_orders_count' ) )
            ->where( 'date', $today )
            ->value( 'count' );

        if ( $count === null ) {
            $count = 1;
            DB::table( Hook::filter( 'ns-model-table', 'nexopos_orders_count' ) )
                ->insert( [
                    'date' => $today,
                    'count' => $count,
                ] );
        }

        DB::table( Hook::filter( 'ns-model-table', 'nexopos_orders_count' ) )
            ->where( 'date', $today )
            ->increment( 'count' );

        return $now->format( 'y' ) . $now->format( 'm' ) . $now->format( 'd' ) . '-' . str_pad( $count, 3, 0, STR_PAD_LEFT );
    }

    protected function __initOrder( array $fields, string $paymentStatus, ?Order $order, array $payments ): Order
    {
        /**
         * if the order is not provided as a parameter
         * a new instance is initialized.
         */
        if ( ! $order instanceof Order ) {
            $order = new Order;

            /**
             * if the order has just been created
             * then we'll define the "created_at" column.
             */
            $order->created_at = $fields[ 'created_at' ] ?? ns()->date->getNow()->toDateTimeString();
        }

        /**
         * If any other attributes needs to be
         * saved while creating the order, it should be
         * explicitly allowed on this filter
         */
        foreach ( Hook::filter( 'ns-order-attributes', [] ) as $attribute ) {
            if ( ! in_array( $attribute, [
                'id',
            ] ) ) {
                $order->$attribute = $fields[ $attribute ] ?? null;
            }
        }

        /**
         * let's save the order at
         * his initial state
         */
        $order->customer_id = $fields['customer_id'];
        $order->shipping = $this->currencyService->define( $fields[ 'shipping' ] ?? 0 )->toFloat(); // if shipping is not provided, we assume it's free
        $order->subtotal = $this->currencyService->define( $fields[ 'subtotal' ] ?? 0 )->toFloat() ?: $this->computeSubTotal( $fields, $order );
        $order->discount_type = $fields['discount_type'] ?? null;
        $order->discount_percentage = $this->currencyService->define( $fields['discount_percentage'] ?? 0 )->toFloat();
        $order->discount = (
            $this->currencyService->define( $order->discount_type === 'flat' && isset( $fields['discount'] ) ? $fields['discount'] : 0 )->toFloat()
        ) ?: ( $order->discount_type === 'percentage' ? $this->computeOrderDiscount( $order, $fields ) : 0 );
        $order->total = $this->currencyService->define( $fields[ 'total' ] ?? 0 )->toFloat() ?: $this->computeTotal( $fields, $order );
        $order->type = $fields['type']['identifier'];
        /**
         * The format was 'Y-m-d h:m:s', which writes the month where the minutes belong
         * and drops the afternoon: 14:35 was stored as 02:09. Due dates compared against
         * now() were therefore wrong by up to twelve hours.
         */
        $order->final_payment_date = isset( $fields['final_payment_date' ] ) ? Carbon::parse( $fields['final_payment_date' ] )->format( 'Y-m-d H:i:s' ) : null; // when the order is not saved as laid away
        $order->total_instalments = $fields[ 'total_instalments' ] ?? 0;
        $order->register_id = $fields[ 'register_id' ] ?? null;
        $order->branch_id = $this->resolveOrderBranch( $fields, $order );
        $order->note = $fields[ 'note'] ?? null;
        $order->note_visibility = $fields[ 'note_visibility' ] ?? null;
        $order->updated_at = isset( $fields[ 'updated_at' ] ) ? Carbon::parse( $fields[ 'updated_at' ] )->format( 'Y-m-d h:m:s' ) : ns()->date->getNow()->toDateTimeString();
        $order->tax_group_id = $fields['tax_group_id' ] ?? null;
        $order->tax_type = $fields['tax_type' ] ?? null;
        $order->total_coupons = $fields['total_coupons'] ?? 0;
        $order->payment_status = $paymentStatus;
        $order->delivery_status = 'pending';
        $order->process_status = 'pending';
        $order->support_instalments = $fields[ 'support_instalments' ] ?? true; // by default instalments are supported
        $order->author_id = $fields[ 'author_id' ] ?? Auth::id(); // the author can now be changed
        $order->title = $fields[ 'title' ] ?? null;
        $order->tax_value = $this->currencyService->define( $fields[ 'tax_value' ] ?? 0 )->toFloat();
        $order->products_tax_value = $this->currencyService->define( $fields[ 'products_tax_value' ] ?? 0 )->toFloat();
        $order->code = $order->code ?: ''; // to avoid generating a new code
        $order->tendered = $this->currencyService->define( collect( $payments )->map( fn( $payment ) => floatval( $payment[ 'value' ] ) )->sum() )->toFloat();

        if ( $order->code === '' ) {
            $order->code = $this->generateOrderCode( $order ); // to avoid generating a new code
        }

        /**
         * compute order total
         */
        $this->__computeOrderTotal(
            order: $order,
            products: $fields[ 'products' ]
        );

        /**
         * Some order needs to have their
         * delivery and process status updated
         * according to the order type
         */
        $this->updateDeliveryStatus( $order );
        $this->updateProcessStatus( $order );

        return $order;
    }

    /**
     * Will update the prodcess status of an order
     */
    public function updateProcessStatus( Order $order ): void
    {
        if ( in_array( $order->type, [ 'delivery', 'takeaway' ] ) ) {
            if ( $order->type === 'delivery' ) {
                $order->process_status = 'pending';
            } else {
                $order->process_status = 'not-available';
            }
        }
    }

    /**
     * Will order the delivery status of an order
     */
    public function updateDeliveryStatus( Order $order ): void
    {
        if ( in_array( $order->type, [ 'delivery', 'takeaway' ] ) ) {
            if ( $order->type === 'delivery' ) {
                $order->delivery_status = 'pending';
            } else {
                $order->delivery_status = 'not-available';
            }
        }
    }

    /**
     * Compute the discount data.
     */
    public function computeOrderDiscount( Order $order, array $fields = [] ): float
    {
        $fields[ 'discount_type' ] = $fields[ 'discount_type' ] ?? $order->discount_type;
        $fields[ 'discount_percentage' ] = $fields[ 'discount_percentage' ] ?? $order->discount_percentage;
        $fields[ 'discount' ] = $fields[ 'discount' ] ?? $order->discount;
        $fields[ 'subtotal' ] = $fields[ 'subtotal' ] ?? $order->subtotal;
        $fields[ 'discount' ] = $fields[ 'discount' ] ?? $order->discount ?? 0;

        if ( ! empty( $fields[ 'discount_type' ] ) && ! empty( $fields[ 'discount_percentage' ] ) && $fields[ 'discount_type' ] === 'percentage' ) {
            return $this->currencyService->define(
                ns()->currency->define( $fields[ 'subtotal' ] )->multiplyBy( $fields[ 'discount_percentage' ] )->toFloat()
            )->dividedBy( 100 )->toFloat();
        } else {
            return $this->currencyService->define( $fields[ 'discount' ] )->toFloat();
        }
    }

    /**
     * Will compute a tax value using
     * the taxes assigned to an order
     *
     * @param  float  $value
     * @param  string $type
     * @return float  value
     */
    public function computeTaxFromOrderTaxes( Order $order, $value, $type = 'inclusive' )
    {
        return $order->taxes->map( function ( $tax ) use ( $value, $type ) {
            $result = $this->taxService->getVatValue(
                $type, $tax->rate, $value
            );

            return $result;
        } )->sum();
    }

    /**
     * will compute the taxes based
     * on the configuration and the products
     */
    public function computeOrderTaxes( Order $order )
    {
        $posVat = ns()->option->get( 'ns_pos_vat' );
        $taxValue = 0;

        if ( in_array( $posVat, [
            'products_vat',
        ] ) ) {
            $taxValue = $order
                ->products()
                ->validProducts()
                ->get()
                ->map( function ( $product ) {
                    return floatval( $product->tax_value );
                } )->sum();
        } elseif ( in_array( $posVat, [
            'flat_vat',
            'variable_vat',
        ] ) && $order->taxes->count() > 0 ) {
            $subTotal = $order->products()
                ->validProducts()
                ->sum( 'total_price' );

            $taxType = $order->tax_type;
            if ( $order->coupons()->exists() ) {
                $subTotal = max( 0, $subTotal - $order->discount - $order->total_coupons );
                $snapshot = $order->coupons()->whereNotNull( 'checkout_total_satang' )->value( 'checkout_total_satang' );
                $refunded = (float) $order->refunds()->sum( 'total' );
                if ( $snapshot !== null && $refunded > 0 ) {
                    // Refund amounts already include the discounted tax charged at checkout.
                    $subTotal = max( 0, $snapshot / 100 - $refunded - $order->shipping );
                    $taxType = 'inclusive';
                }
            }
            $response = $this->taxService->getTaxesComputed(
                tax_type: $taxType,
                rates: $order->taxes->map( fn( $tax ) => $tax->rate )->toArray(),
                value: $subTotal
            );

            $taxValue = $order->taxes->map( function ( $tax, $index ) use ( $response ) {
                $tax->tax_value = $response[ 'percentages' ][ $index ][ 'tax' ];
                $tax->save();

                return $tax->tax_value;
            } )->sum();
        }

        $order->tax_value = $taxValue;
    }

    /**
     * return the tax value for the products
     */
    public function getOrderProductsTaxes( Order $order ): float
    {
        return $this->currencyService->define( $order
            ->products
            ->map( fn( $product ) => $product->tax_value )->sum()
        )->toFloat();
    }

    public function computeTotal( array $fields, Order $order ): float
    {
        return $this->currencyService->define( $order->subtotal )
            ->subtractBy( $order->discount )
            ->additionateBy( $order->shipping )
            ->toFloat();
    }

    public function computeSubTotal( array $fields, Order $order ): float
    {
        return $this->currencyService->define(
            collect( $fields[ 'products' ] )
                ->map( fn( $product ) => floatval( $product[ 'total_price' ] ) )
                ->sum()
        )
            ->toFloat();
    }

    private function __customerIsDefined( array $fields ): Customer
    {
        try {
            return $this->customerService->get( $fields['customer_id'] );
        } catch ( NotFoundException $exception ) {
            throw new NotFoundException( __( 'Unable to find the customer using the provided ID. The order creation has failed.' ) );
        }
    }

    public function refundOrder( Order $order, array $fields ): array
    {
        return DB::transaction( function () use ( $order, $fields ) {
            Order::whereKey( $order->id )->lockForUpdate()->firstOrFail();
            $snapshot = $order->coupons()->whereNotNull( 'checkout_total_satang' )->value( 'checkout_total_satang' );
            if ( $snapshot !== null ) {
                $refunded = (float) $order->refunds()->sum( 'total' );
                $requested = collect( $fields['products'] ?? [] )->sum( fn ( $p ) => (float) $p['quantity'] * (float) $p['unit_price'] );
                $requested += ! empty( $fields['refund_shipping'] ) ? (float) $order->shipping : 0;
                $paid = min( $snapshot / 100, (float) $order->payments()->sum( 'value' ) );
                if ( round( ( $refunded + $requested ) * 100 ) > round( $paid * 100 ) ) {
                    throw new NotAllowedException( 'บิลโปรโมชั่นคืนเงินได้ไม่เกินยอดจ่ายจริงคงเหลือ ' . number_format( max( 0, $paid - $refunded ), 2 ) . ' บาท กรุณาระบุราคาคืนหลังส่วนลด' );
                }
            }
            $sale = DB::table( 'nexopos_loyalty_sales' )->where( 'order_id', $order->id )->lockForUpdate()->first();
            if ( $sale && $sale->settled ) {
                $refunded = (float) $order->refunds()->sum( 'total' );
                $requested = collect( $fields['products'] ?? [] )->sum( fn ( $p ) => (float) $p['quantity'] * (float) $p['unit_price'] );
                $requested += ! empty( $fields['refund_shipping'] ) ? (float) $order->shipping : 0;
                $paid = $sale->paid_satang / 100 + (float) $order->shipping + (float) $order->refunds()->sum( 'shipping' );
                if ( round( ( $refunded + $requested ) * 100 ) > round( $paid * 100 ) ) {
                    throw new NotAllowedException( 'บิลนี้ใช้คะแนนเป็นส่วนลด คืนเป็นเงินได้ไม่เกินยอดชำระจริงคงเหลือ ' . number_format( max( 0, $paid - $refunded ), 2 ) . ' บาท กรุณาปรับราคาคืนหลังหักส่วนลด คะแนนที่ใช้จะคืนเมื่อคืนเงินครบ' );
                }
            }

            return $this->performRefundOrder( $order, $fields );
        } );
    }

    private function performRefundOrder( Order $order, array $fields ): array
    {
        if ( ! in_array( $order->payment_status, [
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_UNPAID,
            Order::PAYMENT_PAID,
            Order::PAYMENT_PARTIALLY_REFUNDED,
        ] ) ) {
            throw new NotAllowedException( __( 'Unable to proceed a refund on an unpaid order.' ) );
        }

        /**
         * Validate every product before starting the refund so we don't
         * end up with a partial refund if one of the products is invalid.
         */
        foreach ( $fields[ 'products' ] as $product ) {
            $orderProduct = OrderProduct::find( $product[ 'id' ] );

            if ( ! $orderProduct instanceof OrderProduct ) {
                throw new NotFoundException( sprintf(
                    __( 'Unable to find the order product with the provided identifier: %s' ),
                    $product[ 'id' ]
                ) );
            }

            $refundQuantity = floatval( $product[ 'quantity' ] );

            if ( $refundQuantity <= 0 ) {
                throw new NotAllowedException( sprintf(
                    __( 'The refund quantity for "%s" must be greater than zero.' ),
                    $orderProduct->name
                ) );
            }

            if ( $refundQuantity > $orderProduct->quantity ) {
                throw new NotAllowedException( sprintf(
                    __( 'The refund quantity (%s) for "%s" exceeds the remaining refundable quantity (%s).' ),
                    $refundQuantity,
                    $orderProduct->name,
                    $orderProduct->quantity
                ) );
            }

            $refundUnitPrice = floatval( $product[ 'unit_price' ] );

            if ( $refundUnitPrice > $orderProduct->unit_price ) {
                throw new NotAllowedException( sprintf(
                    __( 'The refund unit price (%s) for "%s" cannot exceed the original unit price (%s).' ),
                    $refundUnitPrice,
                    $orderProduct->name,
                    $orderProduct->unit_price
                ) );
            }
        }

        $orderRefund = new OrderRefund;
        $orderRefund->author_id = Auth::id();
        $orderRefund->order_id = $order->id;
        $orderRefund->payment_method = $fields[ 'payment' ][ 'identifier' ];
        $orderRefund->shipping = ( isset( $fields[ 'refund_shipping' ] ) && $fields[ 'refund_shipping' ] ? $order->shipping : 0 );
        $orderRefund->total = 0;
        $orderRefund->save();

        OrderRefundPaymentAfterCreatedEvent::dispatch( $orderRefund );

        $results = [];

        foreach ( $fields[ 'products' ] as $product ) {
            $results[] = $this->refundSingleProduct( $order, $orderRefund, OrderProduct::find( $product[ 'id' ] ), $product );
        }

        /**
         * if the shipping is refunded
         * We'll do that here
         */
        $shipping = 0;

        if ( isset( $fields[ 'refund_shipping' ] ) && $fields[ 'refund_shipping' ] === true ) {
            $shipping = $order->shipping;
            $order->shipping = 0;
        }

        $taxValue = collect( $results )->map( function ( $result ) {
            $refundProduct = $result[ 'data' ][ 'productRefund' ];

            return $refundProduct->tax_value;
        } )->sum() ?: 0;

        $orderRefund->tax_value = ns()->currency->define( $taxValue )->toFloat();

        /**
         * let's update the order refund total
         */
        $orderRefund->load( 'refunded_products' );
        $orderRefund->total = Currency::define(
            $orderRefund->refunded_products->sum( 'total_price' )
        )->additionateBy( $shipping )->toFloat();

        $orderRefund->save();

        /**
         * check if the payment used is the customer account
         * so that we can withdraw the funds to the account
         */
        if ( $fields[ 'payment' ][ 'identifier' ] === OrderPayment::PAYMENT_ACCOUNT ) {
            $this->customerService->saveTransaction(
                customer: $order->customer,
                operation: CustomerAccountHistory::OPERATION_REFUND,
                amount: $fields[ 'total' ],
                description: __( 'The current credit has been issued from a refund.' ),
                details: [
                    'order_id' => $order->id,
                    'author_id' => Auth::id(),
                ]
            );
        }

        OrderAfterRefundedEvent::dispatch( $order, $orderRefund );

        return [
            'status' => 'success',
            'message' => __( 'The order has been successfully refunded.' ),
            'data' => compact( 'results', 'order', 'orderRefund' ),
        ];
    }

    /**
     * Refund a single product from an order.
     */
    public function refundSingleProduct( Order $order, OrderRefund $orderRefund, OrderProduct $orderProduct, array $details ): array
    {
        if ( ! in_array( $details[ 'condition' ], [
            OrderProductRefund::CONDITION_DAMAGED,
            OrderProductRefund::CONDITION_UNSPOILED,
        ] ) ) {
            throw new NotAllowedException( __( 'unable to proceed to a refund as the provided status is not supported.' ) );
        }

        if ( ! in_array( $order->payment_status, [
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_PARTIALLY_REFUNDED,
            Order::PAYMENT_UNPAID,
            Order::PAYMENT_PAID,
        ] ) ) {
            throw new NotAllowedException( __( 'Unable to proceed a refund on an unpaid order.' ) );
        }

        /**
         * proceeding a refund should reduce the quantity
         * available on the order for a specific product.
         */
        $orderProduct->status = 'returned';
        $orderProduct->quantity -= floatval( $details[ 'quantity' ] );

        $this->computeOrderProduct( $orderProduct, $details );

        $orderProduct->save();

        /**
         * Let's store a reference of
         * the refunded product
         */
        $productRefund = new OrderProductRefund;
        $productRefund->condition = $details[ 'condition' ];
        $productRefund->description = $details[ 'description' ];
        $productRefund->unit_price = $details[ 'unit_price' ];

        $productRefund->unit_id = $orderProduct->unit_id;
        $productRefund->total_price = $this->currencyService
            ->define( $productRefund->unit_price )->multipliedBy( $details[ 'quantity' ] )
            ->toFloat();

        $productRefund->quantity = $details[ 'quantity' ];
        $productRefund->author_id = Auth::id();
        $productRefund->order_id = $order->id;
        $productRefund->order_refund_id = $orderRefund->id;
        $productRefund->order_product_id = $orderProduct->id;
        $productRefund->product_id = $orderProduct->product_id;

        $productRefund->tax_value = $this->computeTaxFromOrderTaxes(
            $order,
            Currency::define( $details[ 'unit_price' ] )->multipliedBy( $details[ 'quantity' ] )->toFloat(),
            ns()->option->get( 'ns_pos_tax_type' )
        );

        $productRefund->save();

        event( new OrderAfterProductRefundedEvent( $order, $orderProduct, $productRefund ) );

        /**
         * We should adjust the stock only if a valid product
         * is being refunded.
         */
        if ( ! empty( $orderProduct->product_id ) ) {
            /**
             * we do proceed by doing an initial return
             */
            $this->productService->stockAdjustment( ProductHistory::ACTION_RETURNED, [
                'total_price' => $productRefund->total_price,
                'quantity' => $productRefund->quantity,
                'unit_price' => $productRefund->unit_price,
                'product_id' => $productRefund->product_id,
                'orderProduct' => $orderProduct,
                'unit_id' => $productRefund->unit_id,
                'order_id' => $order->id,
            ] );

            /**
             * If the returned stock is damaged
             * then we can pull this out from the stock
             */
            if ( $details[ 'condition' ] === OrderProductRefund::CONDITION_DAMAGED ) {
                $this->productService->stockAdjustment( ProductHistory::ACTION_DEFECTIVE, [
                    'total_price' => $productRefund->total_price,
                    'quantity' => $productRefund->quantity,
                    'unit_price' => $productRefund->unit_price,
                    'product_id' => $productRefund->product_id,
                    'orderProduct' => $orderProduct,
                    'unit_id' => $productRefund->unit_id,
                    'order_id' => $order->id,
                ] );
            }
        }

        return [
            'status' => 'success',
            'message' => sprintf(
                __( 'The product %s has been successfully refunded.' ),
                $orderProduct->name
            ),
            'data' => compact( 'productRefund', 'orderProduct' ),
        ];
    }

    /**
     * this method computes total for the current provided
     * order product
     *
     * @return void
     */
    public function computeOrderProduct( OrderProduct $orderProduct, array $product )
    {
        $orderProduct = $this->taxService->computeOrderProductTaxes( $orderProduct, $product );

        OrderProductAfterComputedEvent::dispatch(
            $orderProduct,
            $product
        );
    }

    /**
     * compute a discount value using
     * provided values
     *
     * @param  float $rate
     * @param  float $value
     * @return float
     */
    public function computeDiscountValues( $rate, $value )
    {
        if ( $rate > 0 ) {
            return ns()->currency->define(
                ns()->currency->define( $value )->multiplyBy( $rate )->toFloat()
            )->divideBy( 100 )->toFloat();
        }

        return 0;
    }

    /**
     * Return a single order product
     *
     * @param int product id
     */
    public function getOrderProduct( int $product_id ): OrderProduct
    {
        $product = OrderProduct::find( $product_id );

        if ( ! $product instanceof OrderProduct ) {
            throw new NotFoundException( __( 'Unable to find the order product using the provided id.' ) );
        }

        return $product;
    }

    /**
     * Get order products
     *
     * @param  mixed      $identifier
     * @return Collection
     */
    public function getOrderProducts( string $identifier, string $pivot = 'id' )
    {
        return $this->getOrder( $identifier, $pivot )
            ->products()
            ->validProducts()
            ->with( 'unit' )
            ->get();
    }

    /**
     * return a specific
     * order using a provided identifier and pivot
     *
     * @return Order
     */
    public function getOrder( string $identifier, string $as = 'id' )
    {
        if ( in_array( $as, ['id', 'code'] ) ) {
            $order = Order::where( $as, $identifier )
                ->with( 'payments' )
                ->with( 'shipping_address' )
                ->with( 'billing_address' )
                ->with( 'taxes' )
                ->with( 'instalments' )
                ->with( 'coupons' )
                ->with( 'products.product.tax_group.taxes' )
                ->with( 'products.unit' )
                ->with( 'products.product.unit_quantities' )
                ->with( 'customer.billing', 'customer.shipping' )
                ->first();

            if ( ! $order instanceof Order ) {
                throw new NotFoundException( sprintf(
                    __( 'Unable to find the requested order using "%s" as pivot and "%s" as identifier' ),
                    $as,
                    $identifier
                ) );
            }

            $order->products;

            OrderAfterLoadedEvent::dispatch( $order );

            return $order;
        }

        throw new NotAllowedException( __( 'Unable to fetch the order as the provided pivot argument is not supported.' ) );
    }

    /**
     * Get all the order that has been
     * already created
     */
    public function getOrders( string $filter = 'mixed' ): Collection
    {
        if ( in_array( $filter, ['paid', 'unpaid', 'refunded'] ) ) {
            return Order::where( 'payment_status', $filter )
                ->get();
        }

        return Order::get();
    }

    /**
     * Adding a product to an order
     */
    public function addProducts( Order $order, array $products ): array
    {
        $products = $this->__checkProductStock( collect( $products ), $order );

        /**
         * let's save the products
         * to the order now as the stock
         * seems to be okay
         *
         * @param array $orderProducts
         * @param Order $order
         * @param float $subTotal
         *
         * @todo make sure order are saved after this.
         */
        extract( $this->__saveOrderProducts( $order, $products ) );

        /**
         * Since __saveOrdeProducts no longer
         * saves products, we'll do that manually here
         */
        $order->saveWithRelationships( [
            'products' => $orderProducts,
        ] );

        /**
         * Now we should refresh the order
         * to have the total computed
         *
         * @todo should be triggered after an event
         */
        $this->refreshOrder( $order );

        return [
            'status' => 'success',
            'data' => compact( 'orderProducts', 'order' ),
            'message' => sprintf(
                __( 'The product has been added to the order "%s"' ),
                $order->code
            ),
        ];
    }

    /**
     * refresh an order by computing
     * all the product total, taxes and
     * shipping all together.
     *
     * @return array
     *
     * @todo test required
     */
    public function refreshOrder( Order $order )
    {
        $prevOrder = clone $order;

        $products = $this->getOrderProducts( $order->id );

        $productTotal = $products
            ->map( function ( OrderProduct $product ) {
                return floatval( $product->total_price );
            } )->sum();

        $productsQuantity = $products->map( function ( OrderProduct $product ) {
            return floatval( $product->quantity );
        } )->sum();

        $productTotalCogs = $products
            ->map( function ( OrderProduct $product ) {
                return floatval( $product->total_purchase_price );
            } )->sum();

        $productPriceWithoutTax = $products
            ->map( function ( OrderProduct $product ) {
                return floatval( $product->total_price_net );
            } )->sum();

        $productPriceWithTax = $products
            ->map( function ( OrderProduct $product ) {
                return floatval( $product->total_price_gross );
            } )->sum();

        $this->computeOrderTaxes( $order );

        $orderShipping = $order->shipping;
        $totalPayments = $order->payments->map( fn( $payment ) => $payment->value )->sum();
        $order->tendered = $totalPayments;

        /**
         * let's refresh all the order values
         */
        $order->subtotal = Currency::raw( $productTotal );
        $order->total_without_tax = $productPriceWithoutTax;
        $order->total_with_tax = $productPriceWithTax;
        $order->discount = $this->computeOrderDiscount( $order );
        $order->total_cogs = $productTotalCogs;
        $order->total = Currency::fresh( $order->subtotal )
            ->additionateBy( $orderShipping )
            ->additionateBy(
                ( $order->tax_type === 'exclusive' ? $order->tax_value : 0 )
            )
            ->subtractBy(
                ns()->currency->fresh( $order->discount )
                    ->additionateBy( $order->total_coupons )
                    ->toFloat()
            )
            ->toFloat();

        $order->change = Currency::fresh( $order->tendered )->subtractBy( $order->total )->toFloat();

        $refunds = $order->refunds;

        $totalRefunds = $refunds->map( fn( $refund ) => $refund->total )->sum();

        $promotionTotal = $order->coupons()->whereNotNull( 'checkout_total_satang' )->value( 'checkout_total_satang' );
        if ( $promotionTotal !== null && $totalRefunds > 0 ) {
            // A coupon is not deducted a second time from the remaining returned-goods balance.
            $order->total = round( max( 0, $promotionTotal / 100 - $totalRefunds ), 2 );
            $order->total_coupons = round( max( 0, $order->subtotal + $orderShipping
                + ( $order->tax_type === 'exclusive' ? $order->tax_value : 0 ) - $order->discount - $order->total ), 2 );
            $order->total_with_tax = $order->total;
            $order->total_without_tax = round( max( 0, $order->total - $order->tax_value ), 2 );
            $order->change = round( $order->tendered - $order->total, 2 );
        }

        /**
         * We believe if the product total is greater
         * than "0", then probably the order hasn't been paid yet.
         */
        if ( (float) $order->total == 0 && $totalRefunds > 0 ) {
            $order->payment_status = Order::PAYMENT_REFUNDED;
        } elseif ( $order->total > 0 && $totalRefunds > 0 ) {
            $order->payment_status = Order::PAYMENT_PARTIALLY_REFUNDED;
        } elseif ( $order->tendered >= $order->total && $order->payments->count() > 0 && $totalRefunds == 0 ) {
            $order->payment_status = Order::PAYMENT_PAID;
        } elseif ( (float) $order->tendered < (float) $order->total && (float) $order->tendered > 0 ) {
            $order->payment_status = Order::PAYMENT_PARTIALLY;
        } elseif ( $order->total == 0 && $order->tendered == 0 ) {
            $order->payment_status = Order::PAYMENT_UNPAID;
        }

        $order->save();

        return [
            'status' => 'success',
            'message' => __( 'the order has been successfully computed.' ),
            'data' => compact( 'order' ),
        ];
    }

    /**
     * Delete a specific order
     * and make product adjustment
     *
     * @param Order order
     * @return array response
     */
    public function deleteOrder( Order $order )
    {
        app( DailyReconciliationService::class )->assertDeletable( $order );
        if ( DB::table( 'nexopos_loyalty_sales' )->where( 'order_id', $order->id )->where( 'settled', true )->exists() ) {
            throw new NotAllowedException( 'บิลมีประวัติคะแนนสมาชิก กรุณายกเลิกบิลหรือคืนสินค้าเพื่อเก็บประวัติและคืนคะแนน แทนการลบ' );
        }
        $cachedOrder = (object) $order->load( [
            'user',
            'products',
            'payments',
            'customer',
            'taxes',
            'coupons',
            'instalments',
            'settings',
        ] )->toArray();

        OrderBeforeDeleteEvent::dispatch( $cachedOrder );

        /**
         * Because when an order is void,
         * the stock is already returned to the inventory.
         */
        if ( ! in_array( $order->payment_status, [ Order::PAYMENT_VOID ] ) ) {
            $order
                ->products()
                ->get()
                ->each( function ( OrderProduct $orderProduct ) use ( $order ) {
                    $orderProduct->load( 'product' );
                    $product = $orderProduct->product;
                    /**
                     * we do proceed by doing an initial return
                     * only if the product is not a quick product/service
                     * we'll also check if the linked product still exists.
                     */
                    if (
                        ( $orderProduct->product_id > 0 && $product instanceof Product ) &&
                        (
                            in_array(
                                $order->payment_status, [
                                    Order::PAYMENT_PAID,
                                    Order::PAYMENT_PARTIALLY,
                                    Order::PAYMENT_UNPAID,
                                    Order::PAYMENT_PARTIALLY_DUE,
                                    Order::PAYMENT_PARTIALLY_REFUNDED,
                                ] )
                        )
                    ) {
                        $this->productService->stockAdjustment( ProductHistory::ACTION_RETURNED, [
                            'total_price' => $orderProduct->total_price,
                            'product_id' => $orderProduct->product_id,
                            'unit_id' => $orderProduct->unit_id,
                            'orderProduct' => $orderProduct,
                            'quantity' => $orderProduct->quantity,
                            'unit_price' => $orderProduct->unit_price,
                        ] );
                    }

                    $orderProduct->delete();
                } );
        }

        OrderPayment::where( 'order_id', $order->id )->delete();

        $orderArray = $order->toArray();
        $order->delete();

        OrderAfterDeletedEvent::dispatch( (object) $orderArray );

        return [
            'status' => 'success',
            'message' => __( 'The order has been deleted.' ),
        ];
    }

    /**
     * Delete a product that is included
     * within a specific order and refresh the order
     *
     * @param  Order $order instance
     * @return array response
     */
    public function deleteOrderProduct( Order $order, int $product_id ): array
    {
        $hasDeleted = false;

        $order->products->map( function ( $product ) use ( $product_id, &$hasDeleted, $order ) {
            if ( $product->id === intval( $product_id ) ) {
                OrderBeforeDeleteProductEvent::dispatch( $order, $product );

                $product->delete();
                $hasDeleted = true;
            }
        } );

        if ( $hasDeleted ) {
            /**
             * @todo should be triggered after an event
             */
            $this->refreshOrder( $order );

            return [
                'status' => 'success',
                'message' => __( 'The product has been successfully deleted from the order.' ),
            ];
        }

        throw new NotFoundException( __( 'Unable to find the requested product on the provider order.' ) );
    }

    /**
     * get orders payments
     *
     * @param int order id
     * @return array of payments
     */
    public function getOrderPayments( int $orderID )
    {
        $order = $this->getOrder( $orderID );

        return $order->payments;
    }

    /**
     * Will return the active payments type.
     *
     * @return array
     */
    public function getPaymentTypes()
    {
        $payments = PaymentType::active()->get()->map( function ( $payment, $index ) {
            $payment->selected = $index === 0;

            return $payment;
        } );

        return collect( $payments )->mapWithKeys( function ( $payment ) {
            return [ $payment[ 'identifier' ] => $payment[ 'label' ] ];
        } )->toArray();
    }

    /**
     * It only returns what is the type of
     * the orders
     */
    public function getTypeLabel( string $type ): string
    {
        $types = $this->getTypeLabels();

        return $types[ $type ] ?? sprintf( __( 'Unknown Type (%s)' ), $type );
    }

    /**
     * It only returns what is the type of
     * the orders
     */
    public function getPaymentLabel( string $type ): string
    {
        $payments = config( 'nexopos.orders.statuses' );

        return $payments[ $type ] ?? sprintf( __( 'Unknown Status (%s)' ), $type );
    }

    /**
     * Returns the order payment labels
     */
    public function getPaymentLabels(): array
    {
        return config( 'nexopos.orders.statuses' );
    }

    /**
     * Return the label for the refunded products.
     */
    public function getRefundedOrderProductLabel( string $label ): string
    {
        return config( 'nexopos.orders.products.refunds' )[ $label ] ?? __( 'Unknown Product Status' );
    }

    /**
     * It only returns what is the type of
     * the orders
     */
    public function getShippingLabel( string $type ): string
    {
        $shipping = $this->getDeliveryStatuses();

        return $shipping[ $type ] ?? sprintf( _( 'Unknown Status (%s)' ), $type );
    }

    /**
     * It only returns the order process status
     */
    public function getProcessStatus( string $type ): string
    {
        $process = $this->getProcessStatuses();

        return $process[ $type ] ?? sprintf( _( 'Unknown Status (%s)' ), $type );
    }

    /**
     * It only returns the order process status
     */
    public function getTypeLabels(): array
    {
        $types = Hook::filter( 'ns-order-types-labels', collect( $this->getTypeOptions() )->mapWithKeys( function ( $option ) {
            return [
                $option[ 'identifier' ] => $option[ 'label' ],
            ];
        } )->toArray() );

        return $types;
    }

    /**
     * It only returns the order types
     */
    public function getTypeOptions(): array
    {
        return Hook::filter( 'ns-orders-types', [
            'takeaway' => [
                'identifier' => 'takeaway',
                'label' => __( 'Take Away' ),
                'icon' => '/images/groceries.png',
                'selected' => false,
            ],
            'delivery' => [
                'identifier' => 'delivery',
                'label' => __( 'Delivery' ),
                'icon' => '/images/delivery.png',
                'selected' => false,
            ],
        ] );
    }

    /**
     * Returns the order statuses
     *
     * @return array $statuses
     */
    public function getProcessStatuses()
    {
        return [
            'pending' => __( 'Pending' ),
            'ongoing' => __( 'Ongoing' ),
            'ready' => __( 'Ready' ),
            'not-available' => __( 'Not Available' ),
        ];
    }

    /**
     * Will return the delivery status for a defined
     * status provided as a string
     *
     * @param  string $status
     * @return string $response
     */
    public function getDeliveryStatus( $status )
    {
        $process = $this->getDeliveryStatuses();

        return $process[ $status ] ?? sprintf( _( 'Unknown Delivery (%s)' ), $status );
    }

    /**
     * Returns the order statuses
     *
     * @return array $statuses
     */
    public function getDeliveryStatuses()
    {
        return [
            'pending' => __( 'Pending' ),
            'ongoing' => __( 'Ongoing' ),
            'delivered' => __( 'Delivered' ),
            'error' => __( 'error' ),
            'not-available' => __( 'Not Available' ),
        ];
    }

    /**
     * parse and render options template
     * based on the provided values.
     */
    public function orderTemplateMapping( $option, Order $order )
    {
        $template = ns()->option->get( $option, '' );
        $availableTags = [
            'store_name' => ns()->option->get( 'ns_store_name' ),
            'store_email' => ns()->option->get( 'ns_store_email' ),
            'store_phone' => ns()->option->get( 'ns_store_phone' ),
            'cashier_name' => $order->user->username,
            'cashier_id' => $order->author_id,
            'order_code' => $order->code,
            'order_type' => $this->getTypeLabel( $order->type ),
            'order_date' => ns()->date->getFormatted( $order->created_at ),
            'order_date_only' => ns()->date->getFormatted( $order->created_at, 'date' ),
            'order_time' => ns()->date->getFormatted( $order->created_at, 'time' ),
            'customer_first_name' => $order->customer->first_name,
            'customer_last_name' => $order->customer->last_name,
            'customer_email' => $order->customer->email,
            'shipping_' . 'first_name' => $order->shipping_address->first_name,
            'shipping_' . 'last_name' => $order->shipping_address->last_name,
            'shipping_' . 'phone' => $order->shipping_address->phone,
            'shipping_' . 'address_1' => $order->shipping_address->address_1,
            'shipping_' . 'address_2' => $order->shipping_address->address_2,
            'shipping_' . 'country' => $order->shipping_address->country,
            'shipping_' . 'city' => $order->shipping_address->city,
            'shipping_' . 'pobox' => $order->shipping_address->pobox,
            'shipping_' . 'company' => $order->shipping_address->company,
            'shipping_' . 'email' => $order->shipping_address->email,
            'billing_' . 'first_name' => $order->billing_address->first_name,
            'billing_' . 'last_name' => $order->billing_address->last_name,
            'billing_' . 'phone' => $order->billing_address->phone,
            'billing_' . 'address_1' => $order->billing_address->address_1,
            'billing_' . 'address_2' => $order->billing_address->address_2,
            'billing_' . 'country' => $order->billing_address->country,
            'billing_' . 'city' => $order->billing_address->city,
            'billing_' . 'pobox' => $order->billing_address->pobox,
            'billing_' . 'company' => $order->billing_address->company,
            'billing_' . 'email' => $order->billing_address->email,
        ];

        $availableTags = Hook::filter( 'ns-orders-template-mapping', $availableTags, $order );

        foreach ( $availableTags as $tag => $value ) {
            $template = ( str_replace( '{' . $tag . '}', $value ?: '', $template ) );
        }

        return $template;
    }

    /**
     * notify administrator when order
     * turned due (for layaway)
     *
     * @return array
     */
    public function notifyExpiredLaidAway()
    {
        $orders = Order::paymentExpired()->get();

        if ( ! $orders->isEmpty() ) {
            /**
             * The status changes according to the fact
             * if some orders has received a payment.
             */
            $orders->each( function ( $order ) {
                if ( $order->paid > 0 ) {
                    $order->payment_status = Order::PAYMENT_PARTIALLY_DUE;
                } else {
                    $order->payment_status = Order::PAYMENT_DUE;
                }

                $order->save();
            } );

            $notificationID = 'ns.due-orders-notifications';

            /**
             * let's clear previously emitted notification
             * with the specified identifier
             */
            Notification::identifiedBy( $notificationID )->delete();

            /**
             * @var NotificationService
             */
            $notificationService = app()->make( NotificationService::class );

            $notificationService->create(
                title: __( 'Unpaid Orders Turned Due' ),
                identifier: $notificationID,
                url: ns()->route( 'ns.dashboard.orders' ),
                description: sprintf(
                    __( '%s order(s) either unpaid or partially paid has turned due. This occurs if none has been completed before the expected payment date.' ),
                    $orders->count()
                )
            )->dispatchForGroup( [
                Role::namespace( 'admin' ),
                Role::namespace( 'nexopos.store.administrator' ),
            ] );

            DueOrdersEvent::dispatch( $orders );

            return [
                'status' => 'success',
                'message' => __( 'The operation was successful.' ),
            ];
        }

        return [
            'status' => 'error',
            'message' => __( 'No orders to handle for the moment.' ),
        ];
    }

    /**
     * Void a specific order
     * by keeping a trace of what has happened.
     */
    public function void( Order $order, string $reason ): array
    {
        $order->payment_status = Order::PAYMENT_VOID;
        $order->voidance_reason = $reason;
        $order->save();

        return [
            'status' => 'success',
            'message' => __( 'The order has been correctly voided.' ),
        ];
    }

    public function returnVoidProducts( Order $order )
    {
        $order->products()
            ->get()
            ->each( function ( OrderProduct $orderProduct ) {

                /**
                 * we do proceed by doing an initial return
                 * only if the product is not a quick product/service
                 */
                if ( $orderProduct->product_id > 0 ) {
                    /**
                     * we do proceed by doing an initial return
                     */
                    $this->productService->stockAdjustment( ProductHistory::ACTION_VOID_RETURN, [
                        'total_price' => $orderProduct->total_price,
                        'product_id' => $orderProduct->product_id,
                        'unit_id' => $orderProduct->unit_id,
                        'orderProduct' => $orderProduct,
                        'quantity' => $orderProduct->quantity,
                        'unit_price' => $orderProduct->unit_price,
                    ] );
                }
            } );

        return [
            'status' => 'success',
            'message' => __( 'The products has been returned to the stock.' ),
        ];
    }

    /**
     * get orders sold during a specific perdiod
     *
     * @param  string     $startDate range starts
     * @param  string     $endDate   range ends
     * @return Collection
     */
    public function getPaidSales( $startDate, $endDate )
    {
        return Order::paid()
            ->where( 'created_at', '>=', Carbon::parse( $startDate )->toDateTimeString() )
            ->where( 'created_at', '<=', Carbon::parse( $endDate )->toDateTimeString() )
            ->get();
    }

    /**
     * get sold stock during a specific period
     *
     * @param  string     $startDate range starts
     * @param  string     $endDate   range ends
     * @return Collection
     */
    /**
     * Sold stock for a period, optionally narrowed to particular suppliers.
     *
     * Two suppliers are read for every sold line and both are returned, because they do not mean the
     * same thing and a report used to close the books must not blur them.
     *
     * `provider_id` is the provider on the procurement the line was taken from: order product ->
     * procurement product -> procurement -> provider. That chain is a fact about a delivery that was
     * paid for. It only exists for lines that carry a batch.
     *
     * `catalogue_provider_id` is the supplier recorded on the product itself — where the shop normally
     * buys it. That is a habit, not a record of this sale, so it is never merged into the column above.
     * The caller decides which to show and has to say which one it used.
     *
     * Nothing is guessed. A line with neither is reported as unattributed rather than being pinned on
     * whoever the shop last bought that product from, which would put a supplier's name against money
     * they had nothing to do with.
     *
     * `$providers` accepts provider ids, and the string 'unattributed' for lines that have neither
     * supplier. Filtering follows the same order of preference as the reporting, so every line lands in
     * exactly one supplier's view and the filtered subsets still add up to the unfiltered total.
     *
     * @param  array<int, int|string> $providers
     * @return Collection
     */
    public function getSoldStock( $startDate, $endDate, $categories = [], $units = [], $providers = [] )
    {
        /**
         * The connection prefix has to be written in by hand here.
         *
         * `getTable()` returns the name without it, and the query builder adds it when it composes a
         * statement — but not inside a raw expression, which is passed through untouched. The test
         * database runs with an `ns_` prefix, so the subquery below named tables that do not exist there
         * and the whole report failed with "no such table". Live has no prefix, so it would have worked
         * there and broken only under test, which is the worse way round.
         */
        /**
         * Two forms of the same table name, and they are not interchangeable.
         *
         * The query builder adds the connection prefix itself for anything it composes — join targets,
         * qualified columns, where clauses. Inside DB::raw it adds nothing, because raw is passed through
         * untouched. Prefixing both produced `ns_ns_nexopos_...` under test; prefixing neither produced
         * a missing table on a prefixed connection. So the builder gets the bare name and the raw
         * fragments get the prefixed one.
         */
        $orderProductTable = ( new OrderProduct )->getTable();
        $procurementProductTable = ( new ProcurementProduct )->getTable();
        $procurementTable = ( new Procurement )->getTable();
        $productTable = ( new Product )->getTable();
        $orderProductRaw = DB::getTablePrefix() . $orderProductTable;

        /**
         * The supplier arrives through a join, not a correlated subquery.
         *
         * A subquery here read `procurement_product_id` off the row being grouped, and MySQL running
         * with ONLY_FULL_GROUP_BY — which this server does — refuses that: "procurement_product_id
         * isn't in GROUP BY". SQLite accepts it, so the report passed every test and then failed on the
         * live database, which is the worse way round to find out.
         *
         * Joining means every column below has to be qualified. `product_id`, `unit_id`, `name` and
         * `created_at` all exist on the procurement products table too, and unqualified they are
         * ambiguous.
         */
        /**
         * Grouped by supplier as well as by product and unit, so one product bought from two suppliers
         * and sold from both reports a line for each. Collapsing them would produce a single row that
         * belongs to neither supplier, which is the opposite of what this report is for.
         *
         * The product's own supplier is grouped by as well. It is constant per product, so it splits
         * nothing that `product_id` had not already split — but ONLY_FULL_GROUP_BY requires every
         * selected column to be either aggregated or grouped, and this one is neither otherwise.
         */
        $groupColumns = [
            $orderProductTable . '.product_id',
            $orderProductTable . '.unit_id',
            $orderProductTable . '.name',
            $orderProductTable . '.unit_name',
            'pcm.provider_id',
            'prd.provider_id',
        ];

        /**
         * Written out rather than spread from the group list, because both suppliers are called
         * `provider_id` on their own table and the second would silently overwrite the first on the
         * result. Each gets an explicit alias so the caller can tell them apart.
         *
         * The two aliased columns are plain strings, not DB::raw. On a prefixed connection the builder
         * renames the join aliases as well — `pcm` becomes `ns_pcm` — and it applies that same renaming
         * to any qualified column it composes, so the two agree. A raw fragment is passed through
         * untouched, so `pcm.provider_id` written raw refers to a table alias that does not exist and the
         * whole report dies with "no such column". The SUMs below are raw and therefore carry the
         * prefixed real table name by hand.
         */
        $selectedColumns = [
            $orderProductTable . '.product_id',
            $orderProductTable . '.unit_id',
            $orderProductTable . '.name',
            $orderProductTable . '.unit_name',
            'pcm.provider_id as provider_id',
            'prd.provider_id as catalogue_provider_id',
            DB::raw( 'SUM(' . $orderProductRaw . '.quantity) as quantity' ),
            DB::raw( 'SUM(' . $orderProductRaw . '.tax_value) as tax_value' ),
            DB::raw( 'SUM(' . $orderProductRaw . '.total_purchase_price) as total_purchase_price' ),
            DB::raw( 'SUM(' . $orderProductRaw . '.total_price) as total_price' ),
        ];
        $rangeStarts = Carbon::parse( $startDate )->toDateTimeString();
        $rangeEnds = Carbon::parse( $endDate )->toDateTimeString();

        $products = OrderProduct::whereHas( 'order', function ( Builder $query ) {
            $query->where( 'payment_status', Order::PAYMENT_PAID );
        } )
            ->leftJoin( $procurementProductTable . ' as pp', 'pp.id', '=', $orderProductTable . '.procurement_product_id' )
            ->leftJoin( $procurementTable . ' as pcm', 'pcm.id', '=', 'pp.procurement_id' )
            /**
             * Left joined, because a sold line whose product has since been deleted still has to appear
             * on the report. An inner join would drop the sale, and the totals would quietly shrink.
             */
            ->leftJoin( $productTable . ' as prd', 'prd.id', '=', $orderProductTable . '.product_id' )
            ->select( $selectedColumns )
            /**
             * groupBy rather than groupByRaw, so the same column names work in both places: the builder
             * prefixes them here exactly as it does in the select above. Raw would need them prefixed by
             * hand and the two lists would then have to be kept in different forms.
             */
            ->groupBy( $groupColumns )
            ->where( $orderProductTable . '.created_at', '>=', $rangeStarts )
            ->where( $orderProductTable . '.created_at', '<=', $rangeEnds );

        if ( ! empty( $categories ) ) {
            $products->whereIn( $orderProductTable . '.product_category_id', $categories );
        }

        if ( ! empty( $units ) ) {
            $products->whereIn( $orderProductTable . '.unit_id', $units );
        }

        if ( ! empty( $providers ) ) {
            $providerIds = array_values( array_filter( $providers, fn ( $value ) => is_numeric( $value ) ) );
            $includeUnattributed = in_array( 'unattributed', $providers, true );

            /**
             * Filtered on the joined columns, so it means the same thing as the columns the rows are
             * grouped and reported by. A separate `whereHas` would ask a different question and could
             * disagree with what the row says its supplier is.
             *
             * The order of preference is written into the conditions, not just into the display. A line
             * that has a batch is matched on the batch supplier only; the product's default supplier is
             * consulted solely when there is no batch. Without that, a line bought from one supplier and
             * normally bought from another would answer to both filters, appear twice across the
             * supplier views, and the subsets would add up to more than the report.
             *
             * Unattributed means neither is known. That covers a line with no batch recorded, a batch
             * since deleted, a purchase with no provider on it, and a product with no default supplier.
             * They read the same to whoever is looking at the report: nothing says where the goods came
             * from.
             */
            $products->where( function ( $query ) use ( $providerIds, $includeUnattributed ) {
                if ( ! empty( $providerIds ) ) {
                    $query->whereIn( 'pcm.provider_id', $providerIds )
                        ->orWhere( function ( $fallback ) use ( $providerIds ) {
                            $fallback->whereNull( 'pcm.provider_id' )
                                ->whereIn( 'prd.provider_id', $providerIds );
                        } );
                }

                if ( $includeUnattributed ) {
                    $query->orWhere( function ( $neither ) {
                        $neither->whereNull( 'pcm.provider_id' )
                            ->whereNull( 'prd.provider_id' );
                    } );
                }
            } );
        }

        return $products->get();
    }

    public function trackOrderCoupons( Order $order )
    {
        $order->coupons()->where( 'counted', false )->each( function ( OrderCoupon $orderCoupon ) {
            $customerCoupon = CustomerCoupon::find( $orderCoupon->customer_coupon_id );

            if ( ! $customerCoupon instanceof CustomerCoupon ) {
                throw new NotFoundException( sprintf(
                    __( 'Unable to find a reference of the provided coupon : %s' ),
                    $orderCoupon->name
                ) );
            }

            $this->customerService->increaseCouponUsage( $customerCoupon );

            $orderCoupon->counted = true;
            $orderCoupon->save();
        } );
    }

    /**
     * Will resolve instalments attached to an order
     *
     * @return void
     */
    public function resolveInstalments( Order $order )
    {
        if ( in_array( $order->payment_status, [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] ) ) {
            $orderInstalments = $order->instalments()
                ->where( 'date', '>=', ns()->date->copy()->startOfDay()->toDateTimeString() )
                ->where( 'date', '<=', ns()->date->copy()->endOfDay()->toDateTimeString() )
                ->where( 'paid', false )
                ->get();

            $paidInstalments = $order->instalments()->where( 'paid', true )->sum( 'amount' );

            if ( $orderInstalments->count() > 0 ) {
                $payableDifference = Currency::define( $order->tendered )
                    ->subtractBy( $paidInstalments )
                    ->toFloat();

                $orderInstalments
                    ->each( function ( $instalment ) use ( &$payableDifference ) {
                        if ( $payableDifference - $instalment->amount >= 0 ) {
                            $instalment->paid = true;
                            $instalment->save();
                            $payableDifference -= $instalment->amount;
                        }
                    } );
            }
        }
    }

    /**
     * Will update an existing instalment.
     */
    public function updateInstalment( Order $order, OrderInstalment $instalment, array $fields ): array
    {
        if ( $instalment->paid ) {
            throw new NotAllowedException( __( 'Unable to edit an already paid instalment.' ) );
        }

        foreach ( $fields as $field => $value ) {
            if ( in_array( $field, [ 'date', 'amount' ] ) ) {
                $instalment->$field = $value;
            }
        }

        $instalment->save();

        return [
            'status' => 'success',
            'message' => __( 'The instalment has been saved.' ),
            'data' => compact( 'instalment' ),
        ];
    }

    /**
     * Will make an instalment as paid
     */
    public function markInstalmentAsPaid( Order $order, OrderInstalment $instalment, string $paymentType = OrderPayment::PAYMENT_CASH ): array
    {
        if ( $instalment->paid ) {
            throw new NotAllowedException( __( 'Unable to edit an already paid instalment.' ) );
        }

        $payment = [
            'order_id' => $order->id,
            'identifier' => $paymentType,
            'author_id' => Auth::id(),
            'value' => $instalment->amount,
        ];

        $result = $this->makeOrderSinglePayment( $payment, $order );
        $payment = $result[ 'data' ][ 'orderPayment' ];

        $instalment->paid = true;
        $instalment->payment_id = $payment->id;
        $instalment->save();

        OrderAfterInstalmentPaidEvent::dispatch( $instalment, $order );

        return [
            'status' => 'success',
            'message' => __( 'The instalment has been saved.' ),
            'data' => compact( 'instalment', 'payment' ),
        ];
    }

    /**
     * Will delete an instalment.
     */
    public function deleteInstalment( Order $order, OrderInstalment $instalment ): array
    {
        $instalment->delete();

        $this->refreshInstalmentCount( $order );

        return [
            'status' => 'success',
            'message' => __( 'The instalment has been deleted.' ),
        ];
    }

    /**
     * Will refresh the total instalments count for an order.
     */
    public function refreshInstalmentCount( Order $order ): void
    {
        $order->total_instalments = $order->instalments()->count();
        $order->save();
    }

    /**
     * Creates an instalment
     */
    public function createInstalment( Order $order, array $fields ): array
    {
        $totalInstalment = $order->instalments->map( fn( $instalment ) => $instalment->amount )->sum();

        if ( Currency::raw( $fields[ 'amount' ] ) <= 0 ) {
            throw new NotAllowedException( __( 'The defined amount is not valid.' ) );
        }

        if ( Currency::raw( $totalInstalment ) >= $order->total ) {
            throw new NotAllowedException( __( 'No further instalments is allowed for this order. The total instalment already covers the order total.' ) );
        }

        $orderInstalment = new OrderInstalment;
        $orderInstalment->order_id = $order->id;
        $orderInstalment->amount = $fields[ 'amount' ];
        $orderInstalment->date = $fields[ 'date' ];
        $orderInstalment->save();

        $this->refreshInstalmentCount( $order );

        return [
            'status' => 'success',
            'message' => __( 'The instalment has been created.' ),
            'data' => [
                'instalment' => $orderInstalment,
            ],
        ];
    }

    /**
     * Changes the order processing status
     */
    public function changeProcessingStatus( Order $order, string $status ): array
    {
        if ( ! in_array( $status, [
            Order::PROCESSING_PENDING,
            Order::PROCESSING_ONGOING,
            Order::PROCESSING_READY,
            Order::PROCESSING_FAILED,
        ] ) ) {
            throw new NotAllowedException( __( 'The provided status is not supported.' ) );
        }

        $order->process_status = $status;
        $order->save();

        OrderAfterUpdatedProcessStatus::dispatch( $order );

        return [
            'status' => 'success',
            'message' => __( 'The order has been successfully updated.' ),
        ];
    }

    /**
     * Changes the order delivery status
     */
    public function changeDeliveryStatus( Order $order, string $status ): array
    {
        if ( ! in_array( $status, [
            Order::DELIVERY_DELIVERED,
            Order::DELIVERY_FAILED,
            Order::DELIVERY_ONGOING,
            Order::DELIVERY_PENDING,
        ] ) ) {
            throw new NotAllowedException( __( 'The provided status is not supported.' ) );
        }

        $order->delivery_status = $status;
        $order->save();

        OrderAfterUpdatedDeliveryStatus::dispatch( $order );

        return [
            'status' => 'success',
            'message' => __( 'The order has been successfully updated.' ),
        ];
    }

    /**
     * Money taken per payment channel over a period.
     *
     * Three things this deliberately does differently from a naive breakdown:
     *
     *  - It counts the payment, not the order. A partially paid order still moved real
     *    money into the drawer, and leaving it out understates the day's takings. The
     *    only orders excluded are the ones that were never a sale.
     *  - It reports a channel that has been switched off but still has history. Keying
     *    the report off PaymentType::active() makes a deactivated channel's past takings
     *    disappear from every period that already closed, which silently rewrites the
     *    shop's own numbers.
     *  - It groups by identifier, not by label. Two channels can share a label, and the
     *    label is editable at any time, so it is not an identity.
     *
     * The age bands a receivable is sorted into.
     *
     * Thirty-day steps because that is the granularity credit is granted in. The two ends
     * are not ages at all but the two ways an invoice can sit outside the ladder: not yet
     * due, and never given a date to be due by.
     *
     * @return array<string, string>
     */
    public function getReceivableBuckets(): array
    {
        return [
            'not_due' => __( 'Not Due Yet' ),
            '1_30' => __( '1 - 30 Days' ),
            '31_60' => __( '31 - 60 Days' ),
            '61_90' => __( '61 - 90 Days' ),
            'over_90' => __( 'Over 90 Days' ),
            'no_term' => __( 'No Due Date' ),
        ];
    }

    /**
     * What the shop is owed, and how long it has been waiting.
     *
     * Selling on credit without this is selling on hope: the orders screen can list unpaid
     * orders but not say which debts have gone stale, which customer is the exposure, or
     * how much of the total is already beyond collecting comfortably. The answer to "who
     * do we chase on Monday" is the whole point of the report.
     *
     * An order is a receivable when money is still outstanding on it, whatever the label
     * on its payment status. The two "due" states are the same debt after the sweep has
     * run, and a partially paid order is a debt for the remainder, so all four count.
     *
     * @param  string|null                                                                                       $asOf the date the ageing is measured from, defaults to now
     * @return array{ as_of: string, buckets: array, customers: array, orders: array, total: float, count: int }
     */
    public function getReceivablesAging( ?string $asOf = null ): array
    {
        $asOfDate = $asOf !== null && $asOf !== ''
            ? Carbon::parse( $asOf )
            : $this->dateService->getNow();

        $labels = $this->getReceivableBuckets();
        $emptyBuckets = array_fill_keys( array_keys( $labels ), 0 );

        $orders = Order::whereIn( 'payment_status', [
            Order::PAYMENT_UNPAID,
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_DUE,
            Order::PAYMENT_PARTIALLY_DUE,
        ] )
            ->where( 'created_at', '<=', $asOfDate->toDateTimeString() )
            ->with( [ 'customer.group' ] )
            ->orderBy( 'created_at' )
            ->get();

        $bucketTotals = $emptyBuckets;
        $customers = [];
        $entries = [];
        $total = 0;

        foreach ( $orders as $order ) {
            $outstanding = $this->currencyService->define( $order->total )
                ->subtractBy( $order->tendered )
                ->toFloat();

            /**
             * A rounding remainder is not a debt. Without this the report lists invoices
             * owing a hundredth of a baht, which no one is going to collect and which
             * makes the real ones harder to see.
             */
            if ( $outstanding <= 0 ) {
                continue;
            }

            /**
             * Read the raw column: the cast renders the date using the store's display
             * format, which cannot be parsed back reliably.
             */
            $dueOn = $order->getRawOriginal( 'final_payment_date' );

            [ $bucket, $daysOverdue ] = $this->resolveReceivableBucket( $dueOn, $asOfDate );

            $bucketTotals[ $bucket ] = $this->currencyService->define( $bucketTotals[ $bucket ] )
                ->additionateBy( $outstanding )
                ->toFloat();

            $total = $this->currencyService->define( $total )
                ->additionateBy( $outstanding )
                ->toFloat();

            $customerId = (int) $order->customer_id;

            if ( ! isset( $customers[ $customerId ] ) ) {
                $customers[ $customerId ] = [
                    'customer_id' => $customerId,
                    'name' => trim( ( $order->customer->first_name ?? '' ) . ' ' . ( $order->customer->last_name ?? '' ) ) ?: __( 'Unknown Customer' ),
                    'phone' => $order->customer->phone ?? null,
                    'credit_limit_amount' => (float) ( $order->customer->credit_limit_amount ?? 0 ),
                    'payment_term_days' => (int) ( $order->customer->group->payment_term_days ?? 0 ),
                    'buckets' => $emptyBuckets,
                    'orders_count' => 0,
                    'oldest_days' => 0,
                    'total' => 0,
                ];
            }

            $customers[ $customerId ][ 'buckets' ][ $bucket ] = $this->currencyService
                ->define( $customers[ $customerId ][ 'buckets' ][ $bucket ] )
                ->additionateBy( $outstanding )
                ->toFloat();

            $customers[ $customerId ][ 'total' ] = $this->currencyService
                ->define( $customers[ $customerId ][ 'total' ] )
                ->additionateBy( $outstanding )
                ->toFloat();

            $customers[ $customerId ][ 'orders_count' ]++;
            $customers[ $customerId ][ 'oldest_days' ] = max( $customers[ $customerId ][ 'oldest_days' ], $daysOverdue );

            $entries[] = [
                'id' => $order->id,
                'code' => $order->code,
                'customer_id' => $customerId,
                'customer_name' => $customers[ $customerId ][ 'name' ],
                'created_at' => $order->created_at,
                'due_on' => $dueOn,
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
                'bucket_label' => $labels[ $bucket ],
                'payment_status' => $order->payment_status,
                'total' => $this->currencyService->define( $order->total )->toFloat(),
                'paid' => $this->currencyService->define( $order->tendered )->toFloat(),
                'outstanding' => $outstanding,
            ];
        }

        /**
         * The biggest exposure first: that is the order the shop would work the list in.
         */
        $customers = collect( $customers )->sortByDesc( 'total' )->values()->toArray();

        /**
         * The oldest debt first, for the same reason.
         */
        usort( $entries, fn( $left, $right ) => $right[ 'days_overdue' ] <=> $left[ 'days_overdue' ] );

        return [
            'as_of' => $asOfDate->toDateTimeString(),
            'buckets' => collect( $labels )->map( fn( $label, $key ) => [
                'key' => $key,
                'label' => $label,
                'total' => $bucketTotals[ $key ],
            ] )->values()->toArray(),
            'customers' => $customers,
            'orders' => $entries,
            'total' => $total,
            'count' => count( $entries ),
        ];
    }

    /**
     * Sorts one receivable into an age band.
     *
     * @param  string|null              $dueOn the raw final_payment_date, null when no term was agreed
     * @return array{0: string, 1: int} the bucket key and how many days it is overdue
     */
    private function resolveReceivableBucket( ?string $dueOn, Carbon $asOfDate ): array
    {
        if ( empty( $dueOn ) ) {
            return [ 'no_term', 0 ];
        }

        $due = Carbon::parse( $dueOn );

        if ( $due->greaterThanOrEqualTo( $asOfDate ) ) {
            return [ 'not_due', 0 ];
        }

        $daysOverdue = (int) $due->diffInDays( $asOfDate );

        /**
         * An invoice that lapsed earlier today is one day late rather than zero, so it
         * does not read as "not due" alongside the invoices that genuinely are not.
         */
        $daysOverdue = max( 1, $daysOverdue );

        return [
            match ( true ) {
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => 'over_90',
            },
            $daysOverdue,
        ];
    }

    /**
     * @return array{ summary: array, total: float, count: int, reconciliation: array, entries: array }
     */
    public function getPaymentTypesReport( string $startRange, string $endRange ): array
    {
        /**
         * A payment against an order that was voided or held was never taken. Everything
         * else represents money that changed hands: paid and partially paid obviously, the
         * two refund states because a refunded order was paid first and then partly given
         * back, and the two due states because an order on credit past its date can still
         * have had a deposit put down on it.
         */
        $countedOrderStatuses = [
            Order::PAYMENT_PAID,
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_REFUNDED,
            Order::PAYMENT_PARTIALLY_REFUNDED,
            Order::PAYMENT_DUE,
            Order::PAYMENT_PARTIALLY_DUE,
        ];

        $payments = OrderPayment::where( 'created_at', '>=', $startRange )
            ->where( 'created_at', '<=', $endRange )
            ->whereHas( 'order', fn( $query ) => $query->whereIn( 'payment_status', $countedOrderStatuses ) )
            ->with( [ 'order' => fn( $query ) => $query->select( 'id', 'code', 'payment_status', 'register_id' ) ] )
            ->get();

        $usedIdentifiers = $payments->pluck( 'identifier' )->unique();

        /**
         * Active channels always appear, even on zero, so a cashier can see that a
         * channel took nothing rather than wondering whether the row is missing. Inactive
         * ones appear only when they have takings in the period.
         */
        $paymentTypes = PaymentType::where( 'active', true )
            ->orWhereIn( 'identifier', $usedIdentifiers )
            ->orderBy( 'priority', 'asc' )
            ->get();

        /**
         * A payment whose type row is gone would otherwise vanish from the report while
         * still counting towards the total, which is how a report stops adding up.
         */
        $orphanIdentifiers = $usedIdentifiers->diff( $paymentTypes->pluck( 'identifier' ) );

        $summary = $paymentTypes->map( function ( PaymentType $paymentType ) use ( $payments ) {
            return $this->summarizePaymentChannel(
                identifier: $paymentType->identifier,
                label: $paymentType->label,
                active: (bool) $paymentType->active,
                payments: $payments->where( 'identifier', $paymentType->identifier ),
            );
        } )->merge(
            $orphanIdentifiers->map( fn( $identifier ) => $this->summarizePaymentChannel(
                identifier: $identifier,
                label: sprintf( __( 'ช่องทางที่ถูกลบแล้ว (%s)' ), $identifier ),
                active: false,
                payments: $payments->where( 'identifier', $identifier ),
            ) )
        )->values();

        return [
            'summary' => $summary->toArray(),
            'total' => ns()->currency->define( $payments->sum( 'value' ) )->toFloat(),
            'count' => $payments->count(),

            /**
             * The channels where the money does not arrive at the till. PromptPay, a bank
             * transfer and a state welfare card charge all land on a statement the shop
             * receives later, so reconciling means matching payments one at a time — which
             * needs the reference the cashier recorded, and needs to name the payments that
             * have none.
             */
            'reconciliation' => $summary
                ->filter( fn( array $channel ) => in_array( $channel[ 'identifier' ], $this->settledLaterChannels(), true ) )
                ->filter( fn( array $channel ) => $channel[ 'count' ] > 0 )
                ->values()
                ->toArray(),
            'entries' => $payments->map( fn( OrderPayment $payment ) => [
                'id' => $payment->id,
                'identifier' => $payment->identifier,
                'reference' => $payment->reference,
                'value' => ns()->currency->define( $payment->value )->toFloat(),
                'order_code' => $payment->order?->code,
                'created_at' => (string) $payment->created_at,
            ] )->toArray(),
        ];
    }

    /**
     * The channels whose money reaches the shop after the sale, on a statement that has
     * to be matched against the till.
     *
     * @return array<int, string>
     */
    public function settledLaterChannels(): array
    {
        return [
            OrderPayment::PAYMENT_PROMPTPAY,
            OrderPayment::PAYMENT_BANK,
            OrderPayment::PAYMENT_WELFARE_CARD,
        ];
    }

    /**
     * @param  SupportCollection<int, OrderPayment>                                                                                                               $payments
     * @return array{ identifier: string, label: string, active: bool, total: float, count: int, with_reference: int, missing_reference: int, references: array }
     */
    private function summarizePaymentChannel( string $identifier, string $label, bool $active, SupportCollection $payments ): array
    {
        $withReference = $payments->filter( fn( OrderPayment $payment ) => ! empty( $payment->reference ) );

        return [
            'identifier' => $identifier,
            'label' => $label,
            'active' => $active,
            'total' => ns()->currency->define( $payments->sum( 'value' ) )->toFloat(),
            'count' => $payments->count(),
            'with_reference' => $withReference->count(),

            /**
             * Surfaced rather than left implied: a welfare card payment recorded without
             * its approval code cannot be matched against the government's transfer, and
             * the shop needs to know that before the statement arrives, not after.
             */
            'missing_reference' => $payments->count() - $withReference->count(),
            'references' => $withReference->map( fn( OrderPayment $payment ) => [
                'reference' => $payment->reference,
                'value' => ns()->currency->define( $payment->value )->toFloat(),
                'order_code' => $payment->order?->code,
                'created_at' => (string) $payment->created_at,
            ] )->values()->toArray(),
        ];
    }

    /**
     * Will return the product that
     * that has been refunded
     */
    public function getOrderRefundedProducts( Order $order )
    {
        return $order->refundedProducts;
    }

    /**
     * Will return the order refund along
     * with the product refunded
     *
     * @return OrderRefund
     */
    public function getOrderRefunds( Order $order )
    {
        $order->load( 'refunds.refunded_products.product', 'refunds.refunded_products.unit', 'refunds.author' );

        return $order;
    }

    public function handlePOSRoute( bool $bool, Request $request, $next ): bool|RedirectResponse
    {
        if ( $request->routeIs( ns()->routeName( 'ns.dashboard.pos' ) ) ) {
            if ( PaymentType::count() === 0 ) {
                return redirect()->route( ns()->routeName( 'ns.dashboard.orders-create-types' ) )->with(
                    'errorMessage',
                    __( 'You need to define at least one payment type before proceeding.' )
                );
            }
        }

        return $bool;
    }

    /**
     * This will delete the order settings attached to the order.
     */
    public function deleteOrderSettings( stdClass $order ): array
    {
        OrderSetting::where( 'order_id', $order->id )->delete();

        return [
            'status' => 'success',
            'message' => __( 'The order settings has been deleted.' ),
        ];
    }

    public function saveOrderSettings( Order $order )
    {
        $order->settings()->delete();

        $settings = $order->settings();

        $settings->create( [
            'key' => 'ns_pos_prefered_price',
            'value' => ns()->option->get( 'ns_pos_prefered_price' ),
        ] );

        $settings->create( [
            'key' => 'ns_pos_vat',
            'value' => ns()->option->get( 'ns_pos_vat' ),
        ] );

        return [
            'status' => 'success',
            'message' => __( 'The order settings has been saved.' ),
        ];
    }
}
