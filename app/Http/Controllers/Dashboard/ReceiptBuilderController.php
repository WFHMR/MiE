<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;

class ReceiptBuilderController extends DashboardController
{
    /**
     * Display the receipt builder settings page.
     */
    public function index()
    {
        return view( 'pages.dashboard.settings.receipt-builder', [
            'title' => 'ตั้งค่าใบเสร็จ',
            'description' => 'กำหนดรูปแบบใบเสร็จ/บิลสำหรับร้านค้า RGBF',
        ] );
    }

    /**
     * Save receipt builder settings.
     */
    public function save( Request $request )
    {
        ns()->restrict( [ 'manage.options' ] );

        $options = ns()->option;

        if ( $request->has( 'store_name' ) ) {
            $options->set( 'ns_store_name', $request->input( 'store_name' ) );
        }

        if ( $request->has( 'store_address' ) ) {
            $options->set( 'ns_invoice_receipt_column_a', $request->input( 'store_address' ) );
        }

        if ( $request->has( 'store_phone' ) ) {
            $options->set( 'ns_store_phone', $request->input( 'store_phone' ) );
        }

        if ( $request->has( 'tax_id' ) ) {
            $options->set( 'ns_store_tax_id', $request->input( 'tax_id' ) );
        }

        if ( $request->has( 'footer_message' ) ) {
            $options->set( 'ns_invoice_receipt_footer', $request->input( 'footer_message' ) );
        }

        if ( $request->has( 'logo_url' ) ) {
            $options->set( 'ns_invoice_receipt_logo', $request->input( 'logo_url' ) );
        }

        return response()->json( [
            'status' => 'success',
            'message' => 'บันทึกการตั้งค่าใบเสร็จสำเร็จ',
        ] );
    }
}
