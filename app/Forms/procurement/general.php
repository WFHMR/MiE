<?php

use App\Crud\ProviderCrud;
use App\Models\Provider;
use App\Services\Helper;

return [
    'label' => __( 'Procurement' ),
    'fields' => [
        [
            'type' => 'text',
            'name' => 'invoice_reference',
            'value' => $procurement->invoice_reference ?? '',
            'label' => __( 'Invoice Number' ),
            'description' => __( 'If the procurement has been issued outside of RGBF, please provide a unique reference.' ),
        ], [
            'type' => 'date',
            'name' => 'delivery_time',
            'value' => $procurement->delivery_time ?? ns()->date->now()->format( 'Y-m-d' ),
            'label' => __( 'Delivery Time' ),
            'description' => __( 'If the procurement has to be delivered at a specific time, define the moment here.' ),
        ], [
            'type' => 'date',
            'name' => 'invoice_date',
            'value' => $procurement->invoice_date ?? ns()->date->now()->format( 'Y-m-d' ),
            'label' => __( 'Invoice Date' ),
            'description' => __( 'If you would like to define a custom invoice date.' ),
        ], [
            'type' => 'switch',
            'name' => 'automatic_approval',
            'value' => $procurement->automatic_approval ?? 1,
            'options' => Helper::kvToJsOptions( [
                0 => __( 'No' ),
                1 => __( 'Yes' ),
            ] ),
            'label' => __( 'Automatic Approval' ),
            'description' => __( 'Determine if the procurement should be marked automatically as approved once the Delivery Time occurs.' ),
        ], [
            'type' => 'select',
            'name' => 'delivery_status',
            'value' => $procurement->delivery_status ?? 'delivered',
            'validation' => 'required',
            'options' => Helper::kvToJsOptions( [
                'pending' => __( 'Pending' ),
                'delivered' => __( 'Delivered' ),
            ] ),
            'label' => __( 'Delivery Status' ),
            'description' => __( 'Determine what is the actual value of the procurement. Once "Delivered" the status can\'t be changed, and the stock will be updated.' ),
        ], [
            'type' => 'select',
            'name' => 'payment_status',
            'value' => $procurement->payment_status ?? 'paid',
            'validation' => 'required',
            'options' => Helper::kvToJsOptions( [
                'unpaid' => __( 'Unpaid' ),
                'paid' => __( 'Paid' ),
            ] ),
            'label' => __( 'Payment Status' ),
            'description' => __( 'Determine what is the actual payment status of the procurement.' ),
        ], [
            'type' => 'search-select',
            'name' => 'provider_id',
            'component' => 'nsCrudForm',
            'props' => ProviderCrud::getFormConfig(),
            'value' => $procurement->provider_id ?? '',
            'validation' => 'required',
            'options' => Helper::toJsOptions( Provider::get(), [ 'id', 'first_name' ] ),
            'label' => __( 'Provider' ),
            'description' => __( 'Determine what is the actual provider of the current procurement.' ),
        ],

        /**
         * The paperwork the purchase travels with, appended rather than interleaved.
         *
         * ns-procurement.vue::reloadEntities() re-merges tabs.general.fields **by numeric index**, so
         * inserting a field among the ones above would shift every field after it and load the wrong
         * saved value into it when editing. New fields go on the end for that reason alone.
         *
         * All optional: a purchase is raised before any of these documents exists. The delivery note
         * arrives with the goods, the billing note later still, so each is filled in as it turns up.
         */
        [
            'type' => 'text',
            'name' => 'purchase_order_number',
            'value' => $procurement->purchase_order_number ?? '',
            'label' => __( 'เลขที่ใบสั่งซื้อ' ),
            'description' => __( 'เลขที่ใบสั่งซื้อของทางร้าน ใช้อ้างอิงกับผู้ขาย เว้นว่างได้' ),
        ], [
            'type' => 'text',
            'name' => 'delivery_note_number',
            'value' => $procurement->delivery_note_number ?? '',
            'label' => __( 'เลขที่ใบส่งสินค้า' ),
            'description' => __( 'เลขที่ใบส่งสินค้าของผู้ขาย แผนกรับเข้าใช้เลขนี้ตรวจราคาและจำนวนกับของที่มาส่ง' ),
        ], [
            'type' => 'date',
            'name' => 'delivery_note_date',
            'value' => $procurement->delivery_note_date ?? '',
            'label' => __( 'วันที่ใบส่งสินค้า' ),
            'description' => __( 'วันที่บนใบส่งสินค้าของผู้ขาย ไม่ใช่วันที่รับของเข้าระบบ' ),
        ], [
            'type' => 'text',
            'name' => 'billing_note_number',
            'value' => $procurement->billing_note_number ?? '',
            'label' => __( 'เลขที่ใบวางบิล' ),
            'description' => __( 'เลขที่ใบวางบิลของผู้ขาย บัญชีใช้เลขนี้จับคู่กับใบรับของเพื่อทำจ่ายให้ตรงใบ' ),
        ], [
            'type' => 'date',
            'name' => 'billing_note_date',
            'value' => $procurement->billing_note_date ?? '',
            'label' => __( 'วันที่ใบวางบิล' ),
            'description' => __( 'วันที่บนใบวางบิลของผู้ขาย' ),
        ], [
            'type' => 'text',
            'name' => 'contact_person',
            'value' => $procurement->contact_person ?? '',
            'label' => __( 'ผู้ติดต่อ / ผู้รับเรื่อง' ),
            'description' => __( 'ชื่อหรือเบอร์ของคนที่ติดต่อเรื่องนี้ไว้ เวลามีปัญหาจะรู้ว่าตามใคร' ),
        ], [
            /**
             * A text field, not number: the browser number spinner invites a stray scroll to change a
             * payment term, and every other numeric field on this form is text for the same reason.
             */
            'type' => 'text',
            'name' => 'credit_days',
            'value' => $procurement->credit_days ?? '',
            'label' => __( 'เครดิต (วัน)' ),
            'description' => __( 'จำนวนวันเครดิตที่ตกลงกับผู้ขาย ใส่แล้วระบบคิดวันครบกำหนดให้เองถ้าเว้นช่องครบกำหนดไว้' ),
        ], [
            'type' => 'date',
            'name' => 'due_date',
            'value' => $procurement->due_date ?? '',
            'label' => __( 'ครบกำหนดชำระ' ),
            'description' => __( 'เว้นว่างได้ ระบบจะคิดจากวันที่ใบกำกับภาษีบวกจำนวนวันเครดิต กรอกเองได้ถ้าตกลงกันไว้ต่างจากนั้น' ),
        ],
    ],
];
