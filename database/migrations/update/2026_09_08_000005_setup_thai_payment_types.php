<?php

/**
 * Table Migration
 *
 * Puts the four payment channels a Thai retail shop actually takes in place, and retires
 * the customer account channel.
 *
 *  - cash                  เงินสด
 *  - PromptPay             พร้อมเพย์ — confirmed by hand, no QR is generated
 *  - bank transfer         โอนเงินผ่านธนาคาร
 *  - state welfare card    บัตรสวัสดิการแห่งรัฐ
 *
 * The customer account channel is deactivated rather than deleted, deliberately.
 * RegisterHistory rows reference a payment type by id through OrderPayment::type(), and
 * CashRegistersService reads that relation without a null guard, so removing the row
 * would break register tracking for every account payment already taken. Deactivating
 * is enough to achieve what was asked: OrdersService::__checkOrderPayments builds its
 * whitelist from PaymentType::active(), and OrdersController only sends active types to
 * the till, so the channel disappears from the POS and can no longer be used on a new
 * order. The refund path keeps working, which matters — refunding to store credit is
 * still a thing a shop does.
 *
 * @since v6.3.0
 **/

use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * The channels, in the order they should appear on the payment popup. A lower
     * priority sorts first; every stock installation ships all three defaults on
     * priority 0, which leaves the till ordering down to insertion order.
     *
     * @var array<int, array{ identifier: string, label: string, priority: int, description: string }>
     */
    private array $channels = [
        [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'label' => 'เงินสด',
            'priority' => 0,
            'description' => 'รับเงินสดที่เคาน์เตอร์ เข้าลิ้นชักเงินสดของกะที่เปิดอยู่',
        ],
        [
            'identifier' => OrderPayment::PAYMENT_PROMPTPAY,
            'label' => 'พร้อมเพย์',
            'priority' => 1,
            'description' => 'ลูกค้าโอนผ่านพร้อมเพย์เอง แคชเชียร์ตรวจยอดเข้าในแอปธนาคารแล้วจึงยืนยัน ระบบไม่สร้าง QR code ให้ ควรกรอกเลขอ้างอิงสลิปไว้เพื่อกระทบยอดกับ statement',
        ],
        [
            'identifier' => OrderPayment::PAYMENT_BANK,
            'label' => 'โอนเงินผ่านธนาคาร',
            'priority' => 2,
            'description' => 'โอนเข้าบัญชีร้าน ควรกรอกเวลาหรือเลขอ้างอิงรายการไว้เพื่อกระทบยอดกับ statement',
        ],
        [
            'identifier' => OrderPayment::PAYMENT_WELFARE_CARD,
            'label' => 'บัตรสวัสดิการแห่งรัฐ',
            'priority' => 3,
            'description' => 'รูดบัตรผ่านเครื่อง EDC หรือแอปถุงเงิน เงินไม่ได้เข้าร้านทันทีแต่รัฐจะโอนคืนภายหลัง ต้องกรอกเลขอนุมัติทุกครั้งเพื่อกระทบยอดกับเงินที่รัฐโอนเข้ามา',
        ],
    ];

    /**
     * Labels the installer writes. A shop that renamed its own channel through the CRUD
     * keeps that name; only an untouched default gets translated, so the migration does
     * not overwrite somebody's deliberate choice.
     *
     * @var array<string, array<int, string>>
     */
    private array $untouchedDefaultLabels = [
        OrderPayment::PAYMENT_CASH => [ 'Cash' ],
        OrderPayment::PAYMENT_BANK => [ 'Bank Payment' ],
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $authorId = $this->resolveAuthorId();

        foreach ( $this->channels as $channel ) {
            $paymentType = PaymentType::identifier( $channel[ 'identifier' ] )->first();

            if ( ! $paymentType instanceof PaymentType ) {
                $paymentType = new PaymentType;
                $paymentType->identifier = $channel[ 'identifier' ];
                $paymentType->label = $channel[ 'label' ];
                $paymentType->author_id = $authorId;

                /**
                 * Locked the same way the stock channels are: these four are what the
                 * shop's tax reporting and its welfare card reconciliation are built on,
                 * so they should not disappear on a stray click. The label, the priority
                 * and the active flag stay editable in the CRUD.
                 */
                $paymentType->readonly = true;
            } elseif ( in_array( $paymentType->label, $this->untouchedDefaultLabels[ $channel[ 'identifier' ] ] ?? [], true ) ) {
                $paymentType->label = $channel[ 'label' ];
            }

            $paymentType->priority = $channel[ 'priority' ];
            $paymentType->description = $channel[ 'description' ];
            $paymentType->active = true;
            $paymentType->save();
        }

        $account = PaymentType::identifier( OrderPayment::PAYMENT_ACCOUNT )->first();

        if ( $account instanceof PaymentType ) {
            $account->active = false;
            $account->priority = 99;
            $account->description = 'ปิดใช้งานแล้ว ไม่แสดงที่หน้าคิดเงินและใช้ชำระบิลใหม่ไม่ได้ ยังคงเก็บไว้เพราะประวัติการชำระเดิมและการคืนเงินเข้าเครดิตลูกค้าอ้างถึงช่องทางนี้';
            $account->save();
        }

        $this->forgetPaymentCaches();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        PaymentType::whereIn( 'identifier', [
            OrderPayment::PAYMENT_PROMPTPAY,
            OrderPayment::PAYMENT_WELFARE_CARD,
        ] )->delete();

        $account = PaymentType::identifier( OrderPayment::PAYMENT_ACCOUNT )->first();

        if ( $account instanceof PaymentType ) {
            $account->active = true;
            $account->save();
        }

        $this->forgetPaymentCaches();
    }

    /**
     * The payment type table carries a non nullable author_id with no foreign key, so it
     * needs some user id. The administrator is who the installer attributes the stock
     * channels to.
     */
    private function resolveAuthorId(): int
    {
        $admin = Role::namespace( Role::ADMIN )?->users()->first();

        return $admin instanceof User
            ? $admin->id
            : (int) ( User::first()?->id ?? 1 );
    }

    /**
     * OrderPayment::getPaymentLabelAttribute() caches the identifier to label map for an
     * hour, so a till left open would keep printing the old labels without this.
     */
    private function forgetPaymentCaches(): void
    {
        Cache::forget( 'nexopos.pos.payments' );
        Cache::forget( 'nexopos.pos.payments-key' );
    }
};
