<template>
    <div class="h-full w-full flex flex-col">
        <div class="px-2 pt-2">
            <div class="elevation-surface border p-2">
                <label class="block text-sm text-fontcolor-soft pb-1" :for="inputId">
                    {{ hint }}
                </label>
                <input
                    :id="inputId"
                    v-model="reference"
                    type="text"
                    autocomplete="off"
                    :placeholder="placeholder"
                    class="w-full border rounded p-2 text-lg text-fontcolor bg-input-background">
                <p v-if="isRequired && reference.trim() === ''" class="text-sm pt-1 text-error-tertiary">
                    {{ __( 'ยังไม่ได้กรอกเลขอ้างอิง จะบันทึกได้แต่จะกระทบยอดกับใบแจ้งยอดไม่ได้' ) }}
                </p>
            </div>
        </div>
        <div class="flex-auto overflow-y-auto">
            <sample-payment
                :label="label"
                :identifier="identifier"
                :reference="reference"
                @submit="$emit( 'submit' )"></sample-payment>
        </div>
    </div>
</template>
<script>
import samplePayment from '~/pages/dashboard/pos/payments/sample-payment.vue';
import { __ } from '~/libraries/lang';

/**
 * The payment screen for the channels where the money does not arrive at the till:
 * PromptPay, a bank transfer, and the Thai state welfare card. Each of those lands on a
 * statement the shop receives later, so the till has to record something that identifies
 * the individual transfer — otherwise a mismatch at the end of the month cannot be traced
 * to a sale.
 *
 * The numpad itself is sample-payment, unchanged. This only adds the reference and hands
 * it down, so the amount entry behaves exactly as it does for every other channel.
 *
 * No QR code is generated for PromptPay on purpose: the shop confirms the transfer by
 * looking at its own banking app, which is how it was asked for. Showing a QR the system
 * cannot verify would suggest the payment had been checked when it had not.
 */
export default {
    name: 'reference-payment',
    props: [ 'label', 'identifier' ],
    components: { samplePayment },
    data() {
        return {
            reference: '',
        };
    },
    computed: {
        inputId() {
            return 'payment-reference-' + this.identifier;
        },

        /**
         * A welfare card charge is settled by the government, so its approval code is the
         * only way to match a sale to the money that eventually arrives. The other two
         * channels can be matched by amount and time at a push.
         */
        isRequired() {
            return this.identifier === 'welfare-card-payment';
        },

        hint() {
            switch ( this.identifier ) {
                case 'promptpay-payment':
                    return __( 'เลขอ้างอิงสลิปพร้อมเพย์ — ตรวจยอดเข้าในแอปธนาคารก่อนยืนยัน' );
                case 'welfare-card-payment':
                    return __( 'เลขอนุมัติจากเครื่อง EDC หรือแอปถุงเงิน (จำเป็นต่อการกระทบยอด)' );
                case 'bank-payment':
                    return __( 'เลขอ้างอิงหรือเวลาที่โอนเข้าบัญชีร้าน' );
                default:
                    return __( 'เลขอ้างอิงรายการ' );
            }
        },

        placeholder() {
            return this.isRequired
                ? __( 'เช่น 123456' )
                : __( 'ไม่บังคับ' );
        },
    },
    methods: { __ },
};
</script>
