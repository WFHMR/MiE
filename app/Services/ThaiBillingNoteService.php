<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\BillingNote;
use App\Models\BillingNoteSequence;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TaxInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues provisional billing notes (ใบแจ้งหนี้/วางบิลค่าสินค้า ชั่วคราว).
 *
 * A credit customer settles in batches rather than per delivery. The shop lists the
 * delivery notes that have fallen due for one customer on a single sheet and leaves it
 * at their accounts desk to be checked, signed and scheduled for payment.
 *
 * The note is provisional and carries no tax. It neither creates nor discharges the
 * liability — the tax invoices it lists already did that — so nothing here writes to an
 * order or to a payment. What it does do is put figures in a customer's hands, and those
 * figures have to still read the same when the payment is reconciled weeks later, which
 * is why the document keeps its own copy of every line rather than re-reading the orders.
 */
class ThaiBillingNoteService
{
    public function __construct(
        protected ThaiTaxInvoiceService $taxInvoices,
    ) {}

    /**
     * Issues a billing note for a customer over a set of orders.
     *
     * @param  array<int, int>      $orderIds
     * @param  array<string, mixed> $attributes deduction, notes, appointment_date
     *
     * @throws NotAllowedException
     */
    public function issue(
        Customer $customer,
        array $orderIds,
        array $attributes = [],
        ?int $authorId = null,
    ): BillingNote {
        $orders = $this->resolveOrders( $customer, $orderIds );

        $lines = $this->buildLines( $orders );

        $billed = $this->round( $lines->sum( 'amount' ) );
        $deducted = $this->round( (float) ( $attributes[ 'deduction' ] ?? 0 ) );

        /**
         * A deduction larger than the sum being asked for would print a negative amount
         * to collect, which is not a billing note — it is a credit note, a different
         * document with different consequences.
         */
        if ( $deducted < 0 || $deducted > $billed ) {
            throw new NotAllowedException(
                sprintf( __( 'ยอดหักตามหมายเหตุต้องอยู่ระหว่าง 0 ถึง %s' ), number_format( $billed, 2 ) )
            );
        }

        /**
         * The number is allocated inside the transaction that writes the document, so a
         * failure cannot consume a number and leave an unexplained gap in the series.
         */
        return DB::transaction( function () use ( $customer, $lines, $billed, $deducted, $attributes, $authorId ) {
            $scope = $this->currentScope();

            $note = new BillingNote;
            $note->sequence_scope = $scope;
            $note->sequence_number = $this->allocateNumber( $scope );
            $note->number = $this->formatNumber( $scope, $note->sequence_number );

            $note->customer_id = $customer->id;
            $note->issued_at = ns()->date->getNow()->toDateTimeString();
            $note->appointment_date = ! empty( $attributes[ 'appointment_date' ] )
                ? Carbon::parse( $attributes[ 'appointment_date' ] )->toDateString()
                : null;

            $note->seller_name = ns()->option->get( 'ns_thai_tax_registered_name', '' )
                ?: ns()->option->get( 'ns_store_name', '' );
            $note->seller_address = ns()->option->get( 'ns_thai_tax_registered_address', '' )
                ?: ns()->option->get( 'ns_invoice_receipt_column_a', '' );
            $note->seller_phone = ns()->option->get( 'ns_store_phone', '' ) ?: null;
            $note->seller_fax = ns()->option->get( 'ns_store_fax', '' ) ?: null;

            $note->customer_name = $this->customerName( $customer );
            $note->customer_address = $this->customerAddress( $customer );
            $note->customer_code = (string) $customer->id;

            $note->lines = $lines->values()->toArray();
            $note->total_billed = $billed;
            $note->total_deducted = $deducted;
            $note->total_net = $this->round( $billed - $deducted );

            $note->notes = $attributes[ 'notes' ] ?? null;
            $note->status = BillingNote::STATUS_ISSUED;
            $note->author_id = $authorId ?? auth()->id();
            $note->save();

            return $note;
        } );
    }

    /**
     * The orders a note may be raised over, in the order they will print.
     *
     * @param  array<int, int> $orderIds
     * @return Collection<int, Order>
     *
     * @throws NotAllowedException
     */
    protected function resolveOrders( Customer $customer, array $orderIds ): Collection
    {
        $orderIds = collect( $orderIds )->filter()->unique()->values();

        if ( $orderIds->isEmpty() ) {
            throw new NotAllowedException( __( 'ต้องเลือกบิลที่จะวางบิลอย่างน้อย 1 รายการ' ) );
        }

        $orders = Order::whereIn( 'id', $orderIds )->orderBy( 'created_at' )->orderBy( 'id' )->get();

        if ( $orders->count() !== $orderIds->count() ) {
            throw new NotAllowedException( __( 'มีบิลที่เลือกไว้บางรายการหาไม่พบ' ) );
        }

        /**
         * A billing note is addressed to one accounts desk. Mixing customers on one sheet
         * would ask each of them to check the other's deliveries.
         */
        $foreign = $orders->firstWhere( 'customer_id', '!=', $customer->id );

        if ( $foreign instanceof Order ) {
            throw new NotAllowedException(
                sprintf( __( 'บิลเลขที่ %s ไม่ใช่ของลูกค้ารายนี้' ), $foreign->code )
            );
        }

        /**
         * A settled order has nothing left to ask for. Billing it again invites the
         * customer to pay twice and then reconcile it back out.
         */
        $settled = $orders->firstWhere( 'payment_status', Order::PAYMENT_PAID );

        if ( $settled instanceof Order ) {
            throw new NotAllowedException(
                sprintf( __( 'บิลเลขที่ %s ชำระครบแล้ว จึงวางบิลไม่ได้' ), $settled->code )
            );
        }

        $this->assertNotAlreadyBilled( $customer, $orders );

        return $orders;
    }

    /**
     * Refuses to list an order that an outstanding note already asks for.
     *
     * Checked in PHP over the customer's own issued notes rather than with a JSON query,
     * because the set is small — a shop raises a handful of notes per customer per month
     * — and whereJsonContains behaves differently across the databases this runs on.
     *
     * This is not a lock. Two clerks billing the same customer in the same second could
     * both pass; the consequence is a duplicated request that the customer's desk rejects
     * during the check, which is what the check is for. A lock here would serialise
     * billing for a whole customer to guard against something a signature already catches.
     *
     * @param Collection<int, Order> $orders
     *
     * @throws NotAllowedException
     */
    protected function assertNotAlreadyBilled( Customer $customer, Collection $orders ): void
    {
        $alreadyBilled = BillingNote::issued()
            ->where( 'customer_id', $customer->id )
            ->get()
            ->flatMap( fn ( BillingNote $note ) => collect( $note->lines ?? [] )->pluck( 'order_id' ) )
            ->filter()
            ->unique();

        $duplicate = $orders->first( fn ( Order $order ) => $alreadyBilled->contains( $order->id ) );

        if ( $duplicate instanceof Order ) {
            throw new NotAllowedException(
                sprintf( __( 'บิลเลขที่ %s อยู่ในใบวางบิลที่ยังไม่ถูกยกเลิกอยู่แล้ว' ), $duplicate->code )
            );
        }
    }

    /**
     * One printed row per delivery note being asked for.
     *
     * @param  Collection<int, Order> $orders
     * @return Collection<int, array{ order_id: int, document_number: string, document_date: string|null, payment_term_days: int, due_date: string|null, amount: float }>
     */
    protected function buildLines( Collection $orders ): Collection
    {
        return $orders->map( function ( Order $order ) {
            /**
             * The ใบส่งสินค้าเลขที่ column. The full form tax invoice is the delivery note
             * — that is what its combined title says — so its number is what the
             * customer's desk has on file. An order delivered without one is listed by
             * its own code, which is the only reference the customer could match.
             */
            $document = $this->taxInvoices->findForOrder( $order, TaxInvoice::TYPE_FULL )
                ?? $this->taxInvoices->findForOrder( $order );

            return [
                'order_id' => $order->id,
                'document_number' => $document?->number ?: $order->code,
                /**
                 * Parsed rather than read as a date object: the Order model overrides
                 * $casts without declaring created_at, so it arrives as a raw string.
                 */
                'document_date' => $order->created_at ? Carbon::parse( $order->created_at )->toDateString() : null,
                'payment_term_days' => $order->getPaymentTermDays(),
                'due_date' => $order->final_payment_date
                    ? Carbon::parse( $order->final_payment_date )->toDateString()
                    : null,
                'amount' => $this->round( (float) $order->total ),
            ];
        } );
    }

    /**
     * Cancels a note. Nothing is deleted and no number is reused, so that a customer
     * holding a copy can always be told what became of it — and so the orders it listed
     * become available to bill again.
     *
     * @throws NotAllowedException
     */
    public function cancel( BillingNote $note, string $reason ): BillingNote
    {
        if ( $note->isCancelled() ) {
            throw new NotAllowedException( sprintf( __( 'ใบวางบิลเลขที่ %s ถูกยกเลิกไปแล้ว' ), $note->number ) );
        }

        if ( trim( $reason ) === '' ) {
            throw new NotAllowedException( __( 'ต้องระบุเหตุผลในการยกเลิกใบวางบิล' ) );
        }

        $note->status = BillingNote::STATUS_CANCELLED;
        $note->cancellation_reason = $reason;
        $note->cancelled_at = ns()->date->getNow()->toDateTimeString();
        $note->save();

        return $note;
    }

    /**
     * The orders a customer could be billed for right now.
     *
     * @return Collection<int, Order>
     */
    public function billableOrders( Customer $customer ): Collection
    {
        $alreadyBilled = BillingNote::issued()
            ->where( 'customer_id', $customer->id )
            ->get()
            ->flatMap( fn ( BillingNote $note ) => collect( $note->lines ?? [] )->pluck( 'order_id' ) )
            ->filter()
            ->unique();

        return Order::where( 'customer_id', $customer->id )
            ->whereIn( 'payment_status', [ Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIALLY ] )
            ->orderBy( 'created_at' )
            ->get()
            ->reject( fn ( Order $order ) => $alreadyBilled->contains( $order->id ) )
            ->values();
    }

    /**
     * Allocates the next number in a series.
     *
     * The row is locked for the duration of the surrounding transaction, so two clerks
     * issuing at once queue instead of both reading the same last_number. The unique
     * index on (sequence_scope, sequence_number) is the second line of defence, for
     * databases without row level locking.
     *
     * Deliberately a copy of the allocators in ThaiTaxInvoiceService and
     * ThaiWithholdingTaxService rather than a shared trait: the three series are numbered
     * independently and differ in their model and their starting option, and coupling
     * them would mean a change to one document's numbering could disturb another's.
     */
    protected function allocateNumber( string $scope ): int
    {
        $sequence = BillingNoteSequence::where( 'scope', $scope )->lockForUpdate()->first();

        if ( ! $sequence instanceof BillingNoteSequence ) {
            $sequence = new BillingNoteSequence;
            $sequence->scope = $scope;
            $sequence->last_number = (int) ns()->option->get( 'ns_thai_billing_note_start_number', 0 );
        }

        $sequence->last_number = $sequence->last_number + 1;
        $sequence->save();

        return $sequence->last_number;
    }

    /**
     * The series a note issued today belongs to. Reset yearly, which keeps the printed
     * number short and matches the tax invoice series.
     */
    public function currentScope( ?Carbon $date = null ): string
    {
        $prefix = trim( (string) ns()->option->get( 'ns_thai_billing_note_prefix', 'BN' ) );
        $prefix = $prefix !== '' ? $prefix : 'BN';
        $date = $date ?: ns()->date->getNow();

        return $prefix . '-' . $date->format( 'Y' );
    }

    public function formatNumber( string $scope, int $number ): string
    {
        return $scope . '-' . str_pad( (string) $number, 6, '0', STR_PAD_LEFT );
    }

    protected function customerName( Customer $customer ): string
    {
        $name = trim( sprintf( '%s %s', $customer->first_name, (string) $customer->last_name ) );

        return $name !== '' ? $name : (string) $customer->id;
    }

    /**
     * The address block as printed, laid out the way the paper form runs it: street on
     * one line, province and postcode on the next.
     *
     * Falls back to nothing rather than to a placeholder. An empty box tells the runner to
     * write the address in by hand; a placeholder reads as a real address and gets filed.
     */
    protected function customerAddress( Customer $customer ): ?string
    {
        $address = $customer->billing;

        if ( $address === null ) {
            return null;
        }

        $lines = collect( [
            $address->address_1,
            $address->address_2,
            /**
             * pobox is what this schema calls the postcode field — there is no
             * postal_code column on nexopos_customers_addresses.
             */
            trim( sprintf( '%s %s', (string) $address->city, (string) $address->pobox ) ),
        ] )
            ->map( fn ( $line ) => trim( (string) $line ) )
            ->filter()
            ->values();

        return $lines->isNotEmpty() ? $lines->implode( "\n" ) : null;
    }

    protected function round( float $value ): float
    {
        $precision = (int) ns()->option->get( 'ns_currency_precision', 2 );

        return round( $value, $precision >= 0 ? $precision : 2 );
    }
}
