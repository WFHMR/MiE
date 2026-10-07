<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues Thai tax invoices against orders.
 *
 * The document is built from what the order lines recorded at the time of the sale —
 * each line carries its own vat_status and the vat_rate that was in force — and the
 * result is stored on the invoice rather than recomputed on every print. That is not
 * caching: once a figure has been reported on a PP30 return it is a historical fact,
 * and reclassifying a product or extending the reduced-rate decree must not change a
 * document a customer is holding.
 */
class ThaiTaxInvoiceService
{
    public function __construct(
        protected ThaiVatService $vatService,
        protected CurrencyService $currency,
    ) {}

    /**
     * Issues a tax invoice for an order.
     *
     * @throws NotAllowedException
     */
    public function issue(
        Order $order,
        string $type = TaxInvoice::TYPE_ABBREVIATED,
        ?array $buyer = null,
        ?int $authorId = null,
    ): TaxInvoice {
        $this->assertCanIssue( $order, $type, $buyer );

        $totals = $this->computeTotals( $order );

        /**
         * The number is allocated inside the same transaction that writes the document,
         * so a failure cannot consume a number and leave a gap. Thai law expects the
         * series to be sequential; unexplained gaps are what an auditor asks about.
         */
        return DB::transaction( function () use ( $order, $type, $buyer, $authorId, $totals ) {
            $scope = $this->currentScope();
            $number = $this->allocateNumber( $scope );

            $seller = $this->vatService->taxpayerProfile();

            $invoice = new TaxInvoice;
            $invoice->type = $type;
            $invoice->sequence_scope = $scope;
            $invoice->sequence_number = $number;
            $invoice->number = $this->formatNumber( $scope, $number );
            $invoice->order_id = $order->id;
            $invoice->issued_at = ns()->date->getNow()->toDateTimeString();

            /**
             * The establishment that issued the document, which under Thai VAT is the branch the
             * sale was made at and not the company as a whole. The code and the address were read
             * from options shared by the whole install, so every branch printed the head office
             * details on its own tax invoices. The branch on the order answers instead, and falls
             * back to the options when it has nothing of its own — so an install that has not filled
             * the branch codes in behaves exactly as it did before.
             */
            $branch = $order->branch_id ? Branch::find( $order->branch_id ) : null;

            $invoice->seller_tax_id = $seller[ 'tax_id' ];
            $invoice->seller_name = $seller[ 'name' ] !== '' ? $seller[ 'name' ] : ns()->option->get( 'ns_store_name', '' );

            $invoice->seller_address = ! empty( $branch?->address )
                ? $branch->address
                : ( $seller[ 'address' ] !== '' ? $seller[ 'address' ] : ns()->option->get( 'ns_invoice_receipt_column_a', '' ) );

            $invoice->seller_branch_code = ! empty( $branch?->tax_branch_code )
                ? $this->vatService->normaliseBranchCode( $branch->tax_branch_code )
                : $seller[ 'branch_code' ];

            $invoice->buyer_name = $buyer[ 'name' ] ?? null;
            $invoice->buyer_address = $buyer[ 'address' ] ?? null;
            $invoice->buyer_tax_id = $buyer[ 'tax_id' ] ?? null;

            $this->fillDocumentHeader( $invoice, $order, $buyer );

            $invoice->vat_rate = $totals[ 'vat_rate' ];
            $invoice->total_net = $totals[ 'net' ];
            $invoice->total_vat = $totals[ 'vat' ];
            $invoice->total_gross = $totals[ 'gross' ];
            $invoice->total_exempt = $totals[ 'exempt' ];
            $invoice->total_zero_rated = $totals[ 'zero_rated' ];
            $invoice->lines = $totals[ 'lines' ];

            $invoice->status = TaxInvoice::STATUS_ISSUED;
            $invoice->author_id = $authorId ?? auth()->id();
            $invoice->save();

            return $invoice;
        } );
    }

    /**
     * Refuses to issue a document that would be invalid or duplicated.
     *
     * @throws NotAllowedException
     */
    protected function assertCanIssue( Order $order, string $type, ?array $buyer ): void
    {
        if ( ! in_array( $type, [ TaxInvoice::TYPE_ABBREVIATED, TaxInvoice::TYPE_FULL ], true ) ) {
            throw new NotAllowedException( sprintf( __( 'ประเภทเอกสารภาษีไม่ถูกต้อง: %s' ), $type ) );
        }

        /**
         * A shop below the 1.8M baht threshold is not registered and may not issue a tax
         * invoice at all — only a receipt. Printing one anyway would give the buyer a
         * document they cannot legally use and expose the shop.
         */
        if ( ! $this->vatService->isRegistered() ) {
            throw new NotAllowedException(
                __( 'ยังออกใบกำกับภาษีไม่ได้ เพราะร้านยังไม่ได้จดทะเบียนภาษีมูลค่าเพิ่ม หรือเลขประจำตัวผู้เสียภาษียังไม่ถูกต้อง — ตั้งค่าได้ที่ ตั้งค่า → ภาษีไทย' )
            );
        }

        /**
         * An unpaid order has nothing to invoice yet. The tax point for goods is delivery
         * or payment, whichever comes first, and at the counter that is payment.
         */
        if ( ! in_array( $order->payment_status, [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ], true ) ) {
            throw new NotAllowedException(
                sprintf( __( 'ออกใบกำกับภาษีได้เฉพาะบิลที่ชำระแล้ว สถานะปัจจุบันคือ "%s"' ), $order->payment_status )
            );
        }

        /**
         * Two documents for one sale means the same output tax reported twice.
         */
        $existing = TaxInvoice::issued()
            ->where( 'order_id', $order->id )
            ->where( 'type', $type )
            ->first();

        if ( $existing instanceof TaxInvoice ) {
            throw new NotAllowedException(
                sprintf( __( 'บิลนี้ออกเอกสารประเภทนี้ไปแล้ว เลขที่ %s — หากต้องการออกใหม่ ให้ยกเลิกใบเดิมก่อน' ), $existing->number )
            );
        }

        if ( $type === TaxInvoice::TYPE_FULL && empty( $buyer[ 'name' ] ?? null ) ) {
            throw new NotAllowedException(
                __( 'ใบกำกับภาษีแบบเต็มรูปต้องระบุชื่อผู้ซื้อ ตามมาตรา 86/4 แห่งประมวลรัษฎากร' )
            );
        }
    }

    /**
     * The remaining fields the printed A4 form carries.
     *
     * Frozen onto the document like everything else here: a customer can be renamed,
     * their credit terms renegotiated and the shop's phone number changed, and none of
     * that may alter a document already in someone's hands.
     *
     * Each column is written only if it exists. The columns arrive through a migration
     * that a running installation may not have applied yet, and refusing to issue any
     * invoice at all until it has would take the till out of service over a set of
     * header lines that are allowed to be blank. Once the migration has run this costs
     * one cached schema lookup.
     *
     * @param array<string, mixed>|null $buyer
     */
    protected function fillDocumentHeader( TaxInvoice $invoice, Order $order, ?array $buyer ): void
    {
        $values = [
            'buyer_code' => $buyer[ 'code' ] ?? $this->buyerCodeFor( $order ),
            'buyer_phone' => $buyer[ 'phone' ] ?? $order->customer?->phone,
            'buyer_branch' => $buyer[ 'branch' ] ?? null,
            'seller_phone' => ns()->option->get( 'ns_store_phone', '' ) ?: null,
            'payment_terms' => $buyer[ 'payment_terms' ] ?? $this->paymentTermsFor( $order ),
            'due_date' => $order->final_payment_date
                ? Carbon::parse( $order->final_payment_date )->toDateString()
                : null,
        ];

        foreach ( $values as $column => $value ) {
            if ( Schema::hasColumn( $invoice->getTable(), $column ) ) {
                $invoice->setAttribute( $column, $value );
            }
        }
    }

    /**
     * The reference printed in the รหัส box beside the buyer's name.
     *
     * The shop's own forms carry codes like "CM690500" or "0001" that come from their
     * previous system. Nothing in this application stores such a code, so the customer
     * id is used: it is stable, unique, and lets a clerk find the record again, which is
     * what the box is for. A walk-in sale has no customer and leaves it blank rather
     * than printing a placeholder that looks like a real reference.
     */
    protected function buyerCodeFor( Order $order ): ?string
    {
        return $order->customer_id ? (string) $order->customer_id : null;
    }

    /**
     * The wording for เงื่อนไขการชำระเงิน.
     *
     * A credit sale states the term because the buyer needs to know what they agreed
     * to; a counter sale states cash. Read from the customer group at issue time, since
     * the term can be renegotiated later.
     */
    protected function paymentTermsFor( Order $order ): string
    {
        $days = $order->getPaymentTermDays();

        if ( $order->isCreditSale() && $days > 0 ) {
            return sprintf( 'เครดิต %d วัน', $days );
        }

        return 'เงินสด';
    }

    /**
     * Splits an order across the three VAT classifications.
     *
     * Each line is taxed with the rate frozen on it, not with today's rate. Lines are
     * grouped by classification because the PP30 return reports them on separate
     * lines: an exempt supply is outside the VAT system, a zero rated one is inside it
     * at 0%, and merging them misstates the return even though both carry no tax.
     *
     * @return array{ vat_rate: float, net: float, vat: float, gross: float, exempt: float, zero_rated: float, lines: array }
     */
    public function computeTotals( Order $order ): array
    {
        $order->loadMissing( 'products' );

        $lines = [];
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;
        $exempt = 0.0;
        $zeroRated = 0.0;
        $standardRate = 0.0;

        foreach ( $order->products as $product ) {
            $line = $this->computeLine( $product );
            $lines[] = $line;

            $gross += $line[ 'gross' ];

            switch ( $line[ 'vat_status' ] ) {
                case ThaiVatService::STATUS_EXEMPT:
                    $exempt += $line[ 'gross' ];
                    break;

                case ThaiVatService::STATUS_ZERO_RATED:
                    $zeroRated += $line[ 'gross' ];
                    break;

                default:
                    $net += $line[ 'net' ];
                    $vat += $line[ 'vat' ];
                    $standardRate = $line[ 'vat_rate' ] ?: $standardRate;
                    break;
            }
        }

        return [
            /**
             * The rate printed on the document. Taken from the standard-rated lines; a
             * document with none has no rate to show.
             */
            'vat_rate' => $standardRate,
            'net' => $this->round( $net ),
            'vat' => $this->round( $vat ),
            'gross' => $this->round( $gross ),
            'exempt' => $this->round( $exempt ),
            'zero_rated' => $this->round( $zeroRated ),
            'lines' => $lines,
        ];
    }

    /**
     * One printed line, with its VAT split.
     *
     * @return array{ name: string, unit_name: string|null, quantity: float, unit_price: float, discount: float, vat_status: string, vat_rate: float, gross: float, net: float, vat: float }
     */
    protected function computeLine( OrderProduct $product ): array
    {
        $status = $this->vatService->normalizeStatus( $product->vat_status );

        /**
         * total_price is what the customer was charged for the line, discount included,
         * and Thai shelf prices are VAT inclusive — so it is the gross.
         */
        $lineGross = (float) $product->total_price;

        /**
         * The rate frozen on the line. Falling back to a lookup by the order date rather
         * than to today's rate keeps historical orders — including those imported before
         * the column existed — on the rate that applied when they were sold.
         */
        $rate = (float) $product->vat_rate;

        if ( $rate <= 0 && $status === ThaiVatService::STATUS_STANDARD ) {
            $rate = $this->vatService->rateFor( $status, $product->order?->created_at );
        }

        return [
            'name' => $product->name,
            'unit_name' => $product->unit_name,
            'quantity' => (float) $product->quantity,
            'unit_price' => (float) $product->unit_price,
            'discount' => (float) $product->discount,
            'vat_status' => $status,
            'vat_rate' => $status === ThaiVatService::STATUS_STANDARD ? $rate : 0.0,
            'gross' => $this->round( $lineGross ),
            'net' => $status === ThaiVatService::STATUS_STANDARD
                ? $this->vatService->netFromGross( $lineGross, $rate )
                : $this->round( $lineGross ),
            'vat' => $status === ThaiVatService::STATUS_STANDARD
                ? $this->vatService->vatFromGross( $lineGross, $rate )
                : 0.0,
        ];
    }

    /**
     * Cancels a document. Nothing is deleted and no number is reused.
     *
     * @throws NotAllowedException
     */
    public function cancel( TaxInvoice $invoice, string $reason ): TaxInvoice
    {
        if ( $invoice->isCancelled() ) {
            throw new NotAllowedException( sprintf( __( 'ใบกำกับภาษีเลขที่ %s ถูกยกเลิกไปแล้ว' ), $invoice->number ) );
        }

        if ( trim( $reason ) === '' ) {
            throw new NotAllowedException( __( 'ต้องระบุเหตุผลในการยกเลิกใบกำกับภาษี' ) );
        }

        $invoice->status = TaxInvoice::STATUS_CANCELLED;
        $invoice->cancellation_reason = $reason;
        $invoice->cancelled_at = ns()->date->getNow()->toDateTimeString();
        $invoice->save();

        return $invoice;
    }

    /**
     * Allocates the next number in a series.
     *
     * The row is locked for the duration of the surrounding transaction, so two tills
     * issuing at the same moment queue rather than both reading the same last_number.
     * A read-modify-write without the lock is how duplicate invoice numbers happen, and
     * a duplicated number on a filed return is not something that can be quietly fixed
     * later. The unique index on (sequence_scope, sequence_number) is the second line of
     * defence for databases where row locking is unavailable.
     */
    protected function allocateNumber( string $scope ): int
    {
        $sequence = TaxInvoiceSequence::where( 'scope', $scope )->lockForUpdate()->first();

        if ( ! $sequence instanceof TaxInvoiceSequence ) {
            $sequence = new TaxInvoiceSequence;
            $sequence->scope = $scope;
            $sequence->last_number = (int) ns()->option->get( 'ns_thai_tax_invoice_start_number', 0 );
        }

        $sequence->last_number = $sequence->last_number + 1;
        $sequence->save();

        return $sequence->last_number;
    }

    /**
     * The series a document issued today belongs to.
     *
     * Reset yearly by default, which is the common Thai practice and keeps the printed
     * number short. Changing this to a single perpetual series is a matter of returning
     * the prefix alone.
     */
    public function currentScope( ?Carbon $date = null ): string
    {
        $prefix = trim( (string) ns()->option->get( 'ns_thai_tax_invoice_prefix', 'TIV' ) );
        $prefix = $prefix !== '' ? $prefix : 'TIV';
        $date = $date ?: ns()->date->getNow();

        return $prefix . '-' . $date->format( 'Y' );
    }

    /**
     * The number as printed: series then a six digit running number.
     */
    public function formatNumber( string $scope, int $number ): string
    {
        return $scope . '-' . str_pad( (string) $number, 6, '0', STR_PAD_LEFT );
    }

    /**
     * The document already issued for an order, if any.
     */
    public function findForOrder( Order $order, ?string $type = null ): ?TaxInvoice
    {
        $query = TaxInvoice::issued()->where( 'order_id', $order->id );

        if ( $type !== null ) {
            $query->where( 'type', $type );
        }

        return $query->latest( 'id' )->first();
    }

    protected function round( float $value ): float
    {
        $precision = (int) ns()->option->get( 'ns_currency_precision', 2 );

        return round( $value, $precision >= 0 ? $precision : 2 );
    }
}
