<?php

/**
 * Adds the "คนละครึ่ง" co-payment channel.
 *
 * The shop takes it at the till alongside cash, transfers and the state welfare card, and the shift
 * closing slip lists every channel the shop accepts. Without a row for it the takings were being
 * recorded under another channel, which put the money in the wrong line on the slip and made the
 * amount handed over impossible to reconcile against what the state later reimburses.
 *
 * Written as a migration rather than typed into the live database so it exists in the code, applies
 * to every installation, and can be traced later. Idempotent, so running it twice changes nothing.
 *
 * @since v6.4.0
 **/

use App\Models\PaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDENTIFIER = 'half-half-payment';

    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_payments_types' ) ) {
            return;
        }

        $paymentType = PaymentType::where( 'identifier', self::IDENTIFIER )->first();

        if ( $paymentType instanceof PaymentType ) {
            return;
        }

        $paymentType = new PaymentType;
        $paymentType->label = 'คนละครึ่ง';
        $paymentType->identifier = self::IDENTIFIER;
        $paymentType->description = 'โครงการคนละครึ่ง รัฐร่วมจ่ายผ่านแอปเป๋าตัง';

        /**
         * Sits after the welfare card, which is the other state scheme, so the till lists the two
         * together and the slip reads in the same order the cashier is used to.
         */
        $paymentType->priority = 4;
        $paymentType->active = true;
        $paymentType->author_id = 1;
        $paymentType->save();
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'nexopos_payments_types' ) ) {
            return;
        }

        /**
         * Only removed while nothing has been taken through it. A channel with payments behind it is
         * kept, because deleting it would leave those payments pointing at a channel that no longer
         * exists and drop them off every report that groups by channel.
         */
        $paymentType = PaymentType::where( 'identifier', self::IDENTIFIER )->first();

        if ( ! $paymentType instanceof PaymentType ) {
            return;
        }

        $used = \App\Models\OrderPayment::where( 'identifier', self::IDENTIFIER )->exists();

        if ( ! $used ) {
            $paymentType->delete();
        }
    }
};
