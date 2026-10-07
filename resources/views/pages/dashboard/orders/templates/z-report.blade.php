@extends( 'layout.base' )
@section( 'layout.base.body' )
@php
    /**
     * The shift closing slip (ใบปิดกะ), laid out the way the shop already works.
     *
     * The employee hands this over together with the cash, so it is ordered the way that handover is
     * checked: what was sold, how it was paid, what passed through the drawer, and last the three
     * figures that matter to whoever receives the money — what the drawer should hold, what was
     * counted, and what is being handed in.
     *
     * Every line carries a count beside its amount. Eight hundred baht over two card payments is a
     * different problem from the same amount over twenty, and the count is what makes a wrong figure
     * traceable back to the transactions behind it.
     *
     * Only cash appears in the drawer block. Transfers, PromptPay and welfare cards are reported
     * above under their own channel, because counting them would make every drawer look short by the
     * value of its non-cash takings.
     */
    /**
     * Bare figures with two decimals, matching the shop's own paper. Tolerant of a CurrencyService
     * object as well as a number, because casting that object to a float is a fatal error and it is
     * the shape half of these figures arrive in.
     */
    $money = fn ( $amount ): string => is_object( $amount )
        ? (string) $amount
        : number_format( (float) $amount, 2 );
    $rowLabel = 'padding: 1px 0;';
    $rowCount = 'padding: 1px 4px; text-align: right; width: 46px;';
    $rowAmount = 'padding: 1px 0; text-align: right; width: 92px;';
    $heading = 'padding: 3px 0; font-weight: 700;';
@endphp
<div class="w-full h-full">
    <div class="w-full md:w-1/2 lg:w-1/3 bg-white p-2 mx-auto" style="font-family: 'Sarabun', 'TH Sarabun New', Tahoma, sans-serif; font-size: 12px; color: #000;">
        <div class="text-center">
            @if ( ! empty( ns()->option->get( 'ns_invoice_receipt_logo' ) ) )
            <img class="mx-auto" src="{{ ns()->option->get( 'ns_invoice_receipt_logo' ) }}" alt="{{ ns()->option->get( 'ns_store_name' ) }}">
            @endif
            <div style="font-weight: 700;">{{ ns()->option->get( 'ns_thai_tax_registered_name' ) ?: ns()->option->get( 'ns_store_name' ) }}</div>
            @if ( ns()->option->get( 'ns_store_phone' ) )
            <div>โทร {{ ns()->option->get( 'ns_store_phone' ) }}</div>
            @endif
            @if ( ns()->option->get( 'ns_thai_tax_id' ) )
            <div>เลขประจำตัวผู้เสียภาษี : {{ ns()->option->get( 'ns_thai_tax_id' ) }}</div>
            @endif
            <div style="margin-top: 4px; font-weight: 700;">รายงานยอดขายปิดกะ</div>
        </div>

        <table class="w-full" style="margin-top: 6px;">
            <tbody>
                @if ( $branch )
                <tr>
                    <td style="{{ $rowLabel }}">สาขา</td>
                    <td style="{{ $rowAmount }}">{{ $branch->name }}</td>
                </tr>
                @endif
                <tr>
                    <td style="{{ $rowLabel }}">จุดขาย</td>
                    <td style="{{ $rowAmount }}">{{ $register->name }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">ประจำวันที่ / กะที่</td>
                    <td style="{{ $rowAmount }}">{{ ns()->date->getFormatted( $opening->created_at ) }} / {{ $shiftNumber }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">เปิดกะ</td>
                    <td style="{{ $rowAmount }}">{{ $openedOn }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">ปิดกะ</td>
                    <td style="{{ $rowAmount }}">{{ $closedOn }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">ระยะเวลากะ</td>
                    <td style="{{ $rowAmount }}">{{ $sessionDuration }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">พนักงาน</td>
                    <td style="{{ $rowAmount }}">{{ $cashier }}</td>
                </tr>
            </tbody>
        </table>

        <table class="w-full" style="margin-top: 6px; border-top: 1px dashed #000;">
            <thead>
                <tr>
                    <td style="{{ $heading }}">รายละเอียด</td>
                    <td style="{{ $rowCount }} font-weight: 700;">จน.ครั้ง</td>
                    <td style="{{ $rowAmount }} font-weight: 700;">จำนวนเงิน</td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="{{ $rowLabel }}">ยอดเงินทอนเริ่มต้น</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $floatCarriedForward ) }}</td>
                </tr>

                <tr><td colspan="3" style="{{ $heading }}">สรุปการขาย</td></tr>
                <tr>
                    <td style="{{ $rowLabel }}">- ใบขาย</td>
                    <td style="{{ $rowCount }}">{{ $orderCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $rawTotalGrossSales ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- ส่วนลด</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $rawTotalDiscounts ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- ค่าส่ง</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $rawTotalShippings ) }}</td>
                </tr>
                <tr style="font-weight: 700;">
                    <td style="{{ $rowLabel }}">ยอดขายสุทธิ</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $rawTotalSales ) }}</td>
                </tr>

                <tr><td colspan="3" style="{{ $heading }}">การคิดภาษี</td></tr>
                <tr>
                    <td style="{{ $rowLabel }}">- สินค้าไม่คิดภาษี</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $nonTaxableSales ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- สินค้าคิดภาษี</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $taxableSales ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- มูลค่าภาษี</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $vatAmount ) }}</td>
                </tr>

                {{--
                    Every channel the shop accepts, used or not. The person checking the slip reads
                    down a fixed list, and a channel that vanishes on a quiet shift makes them
                    wonder whether it was forgotten or simply not taken.
                --}}
                <tr><td colspan="3" style="{{ $heading }}">การรับชำระเงิน</td></tr>
                @foreach( $payments as $payment )
                <tr>
                    <td style="{{ $rowLabel }}">- {{ $payment->label }}</td>
                    <td style="{{ $rowCount }}">{{ $payment->times }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $payment->total_amount ) }}</td>
                </tr>
                @endforeach
                <tr style="font-weight: 700;">
                    <td style="{{ $rowLabel }}">รวมรับชำระ</td>
                    <td style="{{ $rowCount }}">{{ collect( $payments )->sum( 'times' ) }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( collect( $payments )->sum( 'total_amount' ) ) }}</td>
                </tr>

                <tr><td colspan="3" style="{{ $heading }}">ลิ้นชักเก็บเงิน</td></tr>
                <tr>
                    <td style="{{ $rowLabel }}">- รับชำระเป็นเงินสด</td>
                    <td style="{{ $rowCount }}">{{ $cashPaymentCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $totalCashPayment ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- เติมเงินสดระหว่างกะ</td>
                    <td style="{{ $rowCount }}">{{ $cashInCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $totalCashIn ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- เงินทอน</td>
                    <td style="{{ $rowCount }}">{{ $changeCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $totalChange ) }}</td>
                </tr>
                <tr style="font-weight: 700;">
                    <td style="{{ $rowLabel }}">เงินสดจากการขายหลังทอน</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( ns()->currency->define( $totalCashPayment )->subtractBy( $totalChange )->toFloat() ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- นำเงินออก</td>
                    <td style="{{ $rowCount }}">{{ $cashOutCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $totalCashOut ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- คืนเงินลูกค้า</td>
                    <td style="{{ $rowCount }}">{{ $refundCount }}</td>
                    <td style="{{ $rowAmount }}">{{ $money( $totalRefunds ) }}</td>
                </tr>

                {{--
                    The handover. These three lines are what the slip is signed off against, so they
                    sit last and are the only ones ruled off.
                --}}
                <tr style="font-weight: 700; border-top: 1px solid #000;">
                    <td style="{{ $rowLabel }}">คงเหลือเงินในลิ้นชัก</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $cashOnHand ) }}</td>
                </tr>
                @if ( $countedCash !== null )
                <tr>
                    <td style="{{ $rowLabel }}">- เงินสดนับได้จริง (รวมทุนทอน)</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $countedCash ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">
                        - ผลต่าง
                        @if ( $cashDifference > 0 )
                        (เกิน)
                        @elseif ( $cashDifference < 0 )
                        (ขาด)
                        @endif
                    </td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $cashDifference ) }}</td>
                </tr>
                <tr>
                    <td style="{{ $rowLabel }}">- เงินทอนส่งต่อไป</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $floatCarriedForward ) }}</td>
                </tr>
                <tr style="font-weight: 700; border-top: 1px solid #000; border-bottom: 3px double #000;">
                    <td style="{{ $rowLabel }}">ยอดเงินนำส่งสุทธิ (เงินสด)</td>
                    <td style="{{ $rowCount }}"></td>
                    <td style="{{ $rowAmount }}">{{ $money( $netCashToHandIn ) }}</td>
                </tr>
                <tr>
                    <td colspan="3" style="{{ $rowLabel }}">ยอดนับและยอดนำส่งเป็นเงินสดเท่านั้น ไม่รวมยอดโอน/พร้อมเพย์/บัตร</td>
                </tr>
                @else
                <tr>
                    <td colspan="3" style="{{ $rowLabel }} text-align: center;">{{ __( 'Session Ongoing' ) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        {{--
            The slip stops at the money.

            It used to carry two more blocks, one listing sales per product category and one listing
            every product the shift sold, and the shop asked for both to be taken off. This document is
            handed over together with the cash, and the person receiving it checks one thing: that the
            notes in their hand match the figure above. What the shift sold, item by item, is a different
            question asked by a different person at a different time, and printing it here made the slip
            long enough that the number that matters needed hunting for.

            The figures themselves are not gone. CashRegistersService::getZReport() still returns
            `categories` and `products`, and ShiftClosingSummaryTest still pins that they accumulate
            correctly over a shift — two real faults were fixed there, and dropping the arithmetic would
            drop that cover with it. They are available to any report that wants them.
        --}}

        <div class="text-center" style="margin-top: 8px; border-top: 1px dashed #000; padding-top: 4px;">
            พิมพ์เมื่อ : {{ ns()->date->getNowFormatted() }}
        </div>
        <div style="margin-top: 18px;">
            <div>ผู้ส่งเงิน ................................................</div>
            <div style="margin-top: 14px;">ผู้รับเงิน ................................................</div>
        </div>
    </div>
</div>
@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
@endsection
